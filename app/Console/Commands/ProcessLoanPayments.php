<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Loans;
use App\Models\PaymentSubmissions;

class ProcessLoanPayments extends Command
{
    protected $signature   = 'payments:process';
    protected $description = 'Process payment submissions in batches';

    private const CHUNK_SIZE  = 100;
    private const LOG_CHANNEL = 'loan_payments';

    private array $stats = [
        'total_payments'   => 0,
        'processed'        => 0,
        'skipped_no_loan'  => 0,
        'loans_completed'  => 0,
        'total_interest'   => 0.0,
        'total_principal'  => 0.0,
        'chunks_processed' => 0,
        'errors'           => 0,
    ];

    public function handle(): int
    {
        $runId     = uniqid('PAY_RUN_', true);
        $startedAt = now();

        $this->auditLog('info', 'JOB_STARTED', [
            'run_id'     => $runId,
            'started_at' => $startedAt->toDateTimeString(),
            'chunk_size' => self::CHUNK_SIZE,
        ]);

        try {
            PaymentSubmissions::select(['id', 'loan_number', 'amount'])
                ->where('submission_status', 11)
                ->where('update_loan_paid_yn', 0)
                ->orderBy('id')
                ->chunkById(
                    self::CHUNK_SIZE,
                    function ($payments) use ($runId) {
                        $this->processChunk($payments, $runId);
                    },
                    'id'
                );

        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->auditLog('error', 'JOB_FAILED', [
                'run_id'    => $runId,
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => $e->getTraceAsString(),
            ]);
            $this->error("Job failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $duration = $startedAt->diffInSeconds(now());

        $this->auditLog('info', 'JOB_COMPLETED', [
            'run_id'           => $runId,
            'duration_seconds' => $duration,
            'stats'            => $this->stats,
        ]);

        $this->info("Done in {$duration}s — {$this->stats['processed']} payments processed.");

        return self::SUCCESS;
    }

    // =========================================================================
    // Chunk Processing
    // =========================================================================

    private function processChunk($payments, string $runId): void
    {
        $chunkIndex = $this->stats['chunks_processed'] + 1;
        $paymentIds = $payments->pluck('id')->toArray();

        $this->auditLog('info', 'CHUNK_STARTED', [
            'run_id'      => $runId,
            'chunk_index' => $chunkIndex,
            'payment_ids' => $paymentIds,
            'count'       => count($paymentIds),
        ]);

        $loanNumbers = $payments
            ->pluck('loan_number')
            ->filter()
            ->unique()
            ->values();

        try {
            DB::transaction(function () use ($payments, $loanNumbers, $runId, $chunkIndex) {

                // Step 1: Lock loans
                $loans = $this->fetchAndLockLoans($loanNumbers, $runId, $chunkIndex);

                // Step 2: Calculate splits
                [$paymentUpdates, $loanDeltas] = $this->calculateSplits(
                    $payments, $loans, $runId, $chunkIndex
                );

                // Step 3: Persist payment rows
                if (!empty($paymentUpdates)) {
                    $this->bulkUpdatePayments($paymentUpdates, $runId, $chunkIndex);
                }

                // Step 4: Persist loan interest_paid + principal_paid only
                $this->updateLoans($loanDeltas, $loans, $runId, $chunkIndex);
            });

        } catch (\Throwable $e) {
            $this->stats['errors']++;
            $this->auditLog('error', 'CHUNK_TRANSACTION_FAILED', [
                'run_id'      => $runId,
                'chunk_index' => $chunkIndex,
                'payment_ids' => $paymentIds,
                'exception'   => $e->getMessage(),
                'file'        => $e->getFile(),
                'line'        => $e->getLine(),
            ]);
            throw $e;
        }

        $this->stats['chunks_processed']++;
        $this->stats['total_payments'] += count($paymentIds);

        $this->auditLog('info', 'CHUNK_COMPLETED', [
            'run_id'      => $runId,
            'chunk_index' => $chunkIndex,
        ]);

        unset($payments, $loans, $loanDeltas, $paymentUpdates, $loanNumbers);
        gc_collect_cycles();
    }

    // =========================================================================
    // Step 1 — Fetch & Lock Loans
    // =========================================================================

    private function fetchAndLockLoans($loanNumbers, string $runId, int $chunkIndex)
    {
        $this->auditLog('info', 'LOANS_LOCK_START', [
            'run_id'       => $runId,
            'chunk_index'  => $chunkIndex,
            'loan_numbers' => $loanNumbers->toArray(),
        ]);

        $loans = Loans::select([
                'id',
                'loan_number',
                'principal_amount',
                'principal_paid',
                'interest_amount',
                'interest_paid',
                'total_loan',   // still needed for completion check
                'status',
                // loan_paid intentionally excluded — not our concern
            ])
            ->whereIn('loan_number', $loanNumbers)
            ->lockForUpdate()
            ->get()
            ->keyBy('loan_number');

        $found   = $loans->keys()->toArray();
        $missing = $loanNumbers->diff($found)->values()->toArray();

        $this->auditLog('info', 'LOANS_LOCK_DONE', [
            'run_id'        => $runId,
            'chunk_index'   => $chunkIndex,
            'loans_locked'  => count($found),
            'loans_missing' => $missing,
        ]);

        if (!empty($missing)) {
            $this->auditLog('warning', 'LOANS_NOT_FOUND', [
                'run_id'               => $runId,
                'chunk_index'          => $chunkIndex,
                'missing_loan_numbers' => $missing,
            ]);
        }

        return $loans;
    }

    // =========================================================================
    // Step 2 — Calculate Interest / Principal Split
    // =========================================================================

    private function calculateSplits($payments, $loans, string $runId, int $chunkIndex): array
    {
        $paymentUpdates = [];
        $loanDeltas     = [];

        foreach ($payments as $payment) {
            $loan = $loans->get($payment->loan_number);

            if (!$loan) {
                $this->stats['skipped_no_loan']++;
                $this->auditLog('warning', 'PAYMENT_SKIPPED_NO_LOAN', [
                    'run_id'      => $runId,
                    'chunk_index' => $chunkIndex,
                    'payment_id'  => $payment->id,
                    'loan_number' => $payment->loan_number,
                ]);
                continue;
            }

            // Running delta for this loan within this chunk only
            // No loan_paid — we only track interest and principal
            $delta = $loanDeltas[$payment->loan_number] ?? [
                'interest_paid'  => 0.0,
                'principal_paid' => 0.0,
            ];

            $remainingInterest  = max(0,
                (float) $loan->interest_amount
                - (float) ($loan->interest_paid  ?? 0)
                - $delta['interest_paid']
            );
            $remainingPrincipal = max(0,
                (float) $loan->principal_amount
                - (float) ($loan->principal_paid ?? 0)
                - $delta['principal_paid']
            );
            $totalLoan = (float) $loan->total_loan;

            $this->auditLog('info', 'PAYMENT_SPLIT_START', [
                'run_id'              => $runId,
                'chunk_index'         => $chunkIndex,
                'payment_id'          => $payment->id,
                'loan_number'         => $payment->loan_number,
                'payment_amount'      => (float) $payment->amount,
                'loan_total'          => $totalLoan,
                'remaining_interest'  => $remainingInterest,
                'remaining_principal' => $remainingPrincipal,
                'delta_so_far'        => $delta,
            ]);

            $amount = (float) $payment->amount;

            if ($totalLoan > 0) {
                $interestRatio  = (float) $loan->interest_amount / $totalLoan;
                $principalRatio = (float) $loan->principal_amount / $totalLoan;
            } else {
                $this->auditLog('warning', 'PAYMENT_ZERO_TOTAL_LOAN', [
                    'run_id'      => $runId,
                    'chunk_index' => $chunkIndex,
                    'payment_id'  => $payment->id,
                    'loan_number' => $payment->loan_number,
                ]);
                $interestRatio  = 0.0;
                $principalRatio = 1.0;
            }

            // Cap each side at what is still owed
            $interestPaid  = min(round($amount * $interestRatio,  2), $remainingInterest);
            $principalPaid = min(round($amount * $principalRatio, 2), $remainingPrincipal);

            // Rounding remainder correction
            $totalApplied = $interestPaid + $principalPaid;
            $remainder    = round($amount - $totalApplied, 2);

            if ($remainder > 0) {
                $principalRoom = round($remainingPrincipal - $principalPaid, 2);
                $interestRoom  = round($remainingInterest  - $interestPaid,  2);

                if ($principalRoom > 0) {
                    $correction    = min($remainder, $principalRoom);
                    $principalPaid = round($principalPaid + $correction, 2);
                    $remainder     = round($remainder - $correction, 2);
                }

                if ($remainder > 0 && $interestRoom > 0) {
                    $interestPaid = round($interestPaid + $remainder, 2);
                    $remainder    = 0.0;
                }

                if ($remainder > 0) {
                    $this->auditLog('warning', 'PAYMENT_OVERPAYMENT', [
                        'run_id'            => $runId,
                        'chunk_index'       => $chunkIndex,
                        'payment_id'        => $payment->id,
                        'loan_number'       => $payment->loan_number,
                        'payment_amount'    => $amount,
                        'applied_interest'  => $interestPaid,
                        'applied_principal' => $principalPaid,
                        'overpayment'       => $remainder,
                    ]);
                }
            }

            $this->auditLog('info', 'PAYMENT_SPLIT_RESULT', [
                'run_id'              => $runId,
                'chunk_index'         => $chunkIndex,
                'payment_id'          => $payment->id,
                'loan_number'         => $payment->loan_number,
                'payment_amount'      => $amount,
                'interest_ratio_pct'  => round($interestRatio  * 100, 4),
                'principal_ratio_pct' => round($principalRatio * 100, 4),
                'paid_interest'       => $interestPaid,
                'paid_principal'      => $principalPaid,
                'total_applied'       => round($interestPaid + $principalPaid, 2),
            ]);

            $paymentUpdates[] = [
                'id'                  => $payment->id,
                'paid_interest'       => $interestPaid,
                'paid_principal'      => $principalPaid,
                'update_loan_paid_yn' => 1,
            ];

            // Accumulate interest and principal only
            $loanDeltas[$payment->loan_number] = [
                'interest_paid'  => $delta['interest_paid']  + $interestPaid,
                'principal_paid' => $delta['principal_paid'] + $principalPaid,
            ];

            $this->stats['processed']++;
            $this->stats['total_interest']  += $interestPaid;
            $this->stats['total_principal'] += $principalPaid;
        }

        return [$paymentUpdates, $loanDeltas];
    }

    // =========================================================================
    // Step 3 — Bulk Update payment_submissions
    // =========================================================================

    private function bulkUpdatePayments(array $updates, string $runId, int $chunkIndex): void
    {
        $this->auditLog('info', 'BULK_PAYMENT_UPDATE_START', [
            'run_id'       => $runId,
            'chunk_index'  => $chunkIndex,
            'payment_rows' => count($updates),
            'payment_ids'  => array_column($updates, 'id'),
        ]);

        $ids               = array_column($updates, 'id');
        $interestCase      = 'CASE id';
        $principalCase     = 'CASE id';
        $interestBindings  = [];
        $principalBindings = [];

        foreach ($updates as $row) {
            $interestCase       .= " WHEN {$row['id']} THEN ?";
            $principalCase      .= " WHEN {$row['id']} THEN ?";
            $interestBindings[]  = $row['paid_interest'];
            $principalBindings[] = $row['paid_principal'];
        }

        $interestCase  .= ' END';
        $principalCase .= ' END';
        $inClause       = implode(',', $ids);

        DB::statement("
            UPDATE payment_submissions
            SET
                paid_interest       = {$interestCase},
                paid_principal      = {$principalCase},
                update_loan_paid_yn = 1
            WHERE id IN ({$inClause})
        ", [
            ...$interestBindings,
            ...$principalBindings,
        ]);

        $this->auditLog('info', 'BULK_PAYMENT_UPDATE_DONE', [
            'run_id'       => $runId,
            'chunk_index'  => $chunkIndex,
            'rows_updated' => count($updates),
        ]);
    }

    // =========================================================================
    // Step 4 — Update Loan interest_paid + principal_paid only
    // =========================================================================

    private function updateLoans(array $loanDeltas, $loans, string $runId, int $chunkIndex): void
    {
        foreach ($loanDeltas as $loanNumber => $delta) {
            $loan = $loans->get($loanNumber);

            // Derive new totals from what we know (interest + principal only)
            // loan_paid is owned by a separate process — never touched here
            $newInterestPaid  = round((float)($loan->interest_paid  ?? 0) + $delta['interest_paid'],  2);
            $newPrincipalPaid = round((float)($loan->principal_paid ?? 0) + $delta['principal_paid'], 2);
            $totalRepaid      = round($newInterestPaid + $newPrincipalPaid, 2);
            $totalLoan        = (float) $loan->total_loan;
            $isCompleted      = $totalRepaid >= $totalLoan;

            $this->auditLog('info', 'LOAN_UPDATE_START', [
                'run_id'                => $runId,
                'chunk_index'           => $chunkIndex,
                'loan_number'           => $loanNumber,
                'loan_id'               => $loan->id,
                'before_interest_paid'  => (float) $loan->interest_paid,
                'before_principal_paid' => (float) $loan->principal_paid,
                'delta_interest'        => $delta['interest_paid'],
                'delta_principal'       => $delta['principal_paid'],
                'new_interest_paid'     => $newInterestPaid,
                'new_principal_paid'    => $newPrincipalPaid,
                'total_repaid'          => $totalRepaid,
                'total_loan'            => $totalLoan,
                'will_complete'         => $isCompleted,
            ]);

            // Only columns this command is responsible for
            $update = [
                'interest_paid'  => DB::raw("interest_paid  + {$delta['interest_paid']}"),
                'principal_paid' => DB::raw("principal_paid + {$delta['principal_paid']}"),
                // loan_paid intentionally omitted — owned by separate process
            ];

            if ($isCompleted) {
                $update['status'] = Loans::STATUS_COMPLETED;
                $this->stats['loans_completed']++;

                $this->auditLog('info', 'LOAN_MARKED_COMPLETED', [
                    'run_id'       => $runId,
                    'chunk_index'  => $chunkIndex,
                    'loan_number'  => $loanNumber,
                    'loan_id'      => $loan->id,
                    'total_repaid' => $totalRepaid,
                    'total_loan'   => $totalLoan,
                ]);
            }

            DB::table('loans')
                ->where('loan_number', $loanNumber)
                ->update($update);

            $this->auditLog('info', 'LOAN_UPDATE_DONE', [
                'run_id'      => $runId,
                'chunk_index' => $chunkIndex,
                'loan_number' => $loanNumber,
                'loan_id'     => $loan->id,
            ]);
        }
    }

    // =========================================================================
    // Audit Logger
    // =========================================================================

    private function auditLog(string $level, string $event, array $context = []): void
    {
        $context['event'] = $event;

        Log::channel(self::LOG_CHANNEL)->$level(
            "[ProcessLoanPayments] {$event}",
            $context
        );
    }
}