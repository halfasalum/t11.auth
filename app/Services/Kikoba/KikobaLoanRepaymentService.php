<?php

namespace App\Services\Kikoba;

use App\Models\KikobaLoan;
use App\Models\KikobaLoanRepayment;
use App\Models\KikobaLoanSchedule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records a repayment against an active loan. Mirrors
 * KikobaContributionService's auto-allocation shape (earliest outstanding
 * installment first, overflow into the next), and additionally auto-closes
 * the loan once its schedule is fully paid — 'completed' if that happens on
 * or after the final installment's due date, 'early_settled' if before it.
 */
class KikobaLoanRepaymentService
{
    public function __construct(protected KikobaAccountService $accountService) {}

    public function recordPayment(KikobaLoan $loan, array $data): KikobaLoanRepayment
    {
        if ($loan->status !== 'active') {
            throw new InvalidArgumentException('Only an active loan can receive a repayment.');
        }

        $amount = (float) $data['amount'];
        if ($amount <= 0) {
            throw new InvalidArgumentException('Repayment amount must be greater than zero.');
        }

        return DB::transaction(function () use ($loan, $data, $amount) {
            $scheduleId = $data['kikoba_loan_schedule_id'] ?? null;

            $repayment = KikobaLoanRepayment::create([
                'company_id' => $loan->company_id,
                'kikoba_loan_id' => $loan->id,
                'kikoba_loan_schedule_id' => $scheduleId,
                'amount' => $amount,
                'paid_date' => $data['paid_date'] ?? now()->toDateString(),
                'reference' => $data['reference'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'notes' => $data['notes'] ?? null,
                'received_by' => $data['received_by'] ?? null,
            ]);

            if ($scheduleId) {
                $this->applyToSchedule($scheduleId, $amount);
            } else {
                $this->autoAllocate($loan, $amount);
            }

            // Credit the group's account back (money returning), same as a
            // contribution — additive, so a group with no account just
            // doesn't get a ledger entry.
            $this->accountService->creditLoanRepayment($loan, $amount, $repayment);

            $this->maybeClose($loan);

            return $repayment->fresh();
        });
    }

    protected function autoAllocate(KikobaLoan $loan, float $amount): void
    {
        $remaining = $amount;

        $outstanding = $loan->schedules()
            ->whereIn('status', ['pending', 'partial'])
            ->orderBy('due_date')
            ->get();

        foreach ($outstanding as $schedule) {
            if ($remaining <= 0) {
                break;
            }

            $balance = (float) $schedule->total_amount - (float) $schedule->paid_amount;
            $portion = min($balance, $remaining);

            $this->applyToSchedule($schedule->id, $portion);
            $remaining -= $portion;
        }

        // Any leftover (member overpaid beyond the full schedule) is simply
        // recorded on the repayment without a schedule link.
    }

    protected function applyToSchedule(int $scheduleId, float $amount): void
    {
        /** @var KikobaLoanSchedule|null $schedule */
        $schedule = KikobaLoanSchedule::lockForUpdate()->find($scheduleId);

        if (! $schedule) {
            return;
        }

        $schedule->paid_amount = (float) $schedule->paid_amount + $amount;

        if ($schedule->paid_amount >= $schedule->total_amount) {
            $schedule->status = 'paid';
        } elseif ($schedule->paid_amount > 0) {
            $schedule->status = 'partial';
        }

        $schedule->save();
    }

    protected function maybeClose(KikobaLoan $loan): void
    {
        $schedules = $loan->schedules()->get();
        $totalDue = (float) $schedules->sum('total_amount');
        $totalPaid = (float) $schedules->sum('paid_amount');

        if ($totalPaid < $totalDue) {
            return;
        }

        $finalDueDate = $schedules->max('due_date');
        $status = now()->toDateString() < $finalDueDate ? 'early_settled' : 'completed';

        $loan->update([
            'status' => $status,
            'closed_at' => now(),
        ]);
    }
}
