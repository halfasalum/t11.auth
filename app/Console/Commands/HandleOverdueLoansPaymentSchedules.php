<?php

namespace App\Console\Commands;

use App\Models\LoanPaymentSchedules;
use App\Models\Loans;
use App\Models\ServicesModel;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HandleOverdueLoansPaymentSchedules extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loans:handle-overdue-schedules
                            {--chunk=100 : Number of loans to process per chunk}
                            {--dry-run : Run without making changes}
                            {--skip-validation : Skip schedule data validation}
                            {--force : Force execution even in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates payment schedules for overdue loans based on defined rules';

    /**
     * Statistics tracking
     */
    protected array $stats = [
        'total_loans' => 0,
        'skipped_by_day_rule' => 0,
        'no_schedule_found' => 0,
        'invalid_schedule' => 0,
        'created_schedules' => 0,
        'existing_schedules' => 0,
        'failed' => 0,
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startTime = microtime(true);
        $today = now()->toDateString();
        //$today = '2026-04-30';
        $dayName = Carbon::parse($today)->dayName;
        $dryRun = $this->option('dry-run');
        $chunkSize = (int) $this->option('chunk');

        $this->info('═══════════════════════════════════════════════════════════');
        $this->info("📅 Date: {$today} ({$dayName})");
        $this->info("🔄 Mode: " . ($dryRun ? 'DRY RUN (no changes)' : 'LIVE UPDATE'));
        $this->info("📦 Chunk size: {$chunkSize}");
        $this->info('═══════════════════════════════════════════════════════════');

        Log::info('Starting overdue loans payment schedules processing', [
            'date' => $today,
            'day_name' => $dayName,
            'dry_run' => $dryRun,
            'chunk_size' => $chunkSize
        ]);

        try {
            DB::beginTransaction();

            // Get count first for progress tracking
            $totalLoans = $this->getOverdueLoansQuery()->count();
            $this->stats['total_loans'] = $totalLoans;

            if ($totalLoans === 0) {
                $this->warn("No overdue loans found to process.");
                Log::info('No overdue loans found');
                $this->updateServiceLastRun();
                return 0;
            }

            $this->info("Found {$totalLoans} overdue loan(s) to process.\n");

            if ($dryRun) {
                $this->performDryRun($today);
                return 0;
            }

            // Process in chunks
            $processedCount = 0;
            $this->output->progressStart($totalLoans);

            $this->getOverdueLoansQuery()
                ->chunk($chunkSize, function ($loans) use (&$processedCount, $today, $chunkSize) {
                    foreach ($loans as $loan) {
                        $this->processSingleLoan($loan, $today);
                        $processedCount++;
                        $this->output->progressAdvance();
                    }

                    // Optional: Add delay between chunks to prevent database overload
                    if ($chunkSize > 0 && $processedCount < $this->stats['total_loans']) {
                        usleep(50000); // 50ms delay
                    }
                });

            $this->output->progressFinish();

            DB::commit();

            // Display final statistics
            $this->displayStatistics();

            $executionTime = round(microtime(true) - $startTime, 2);
            $this->info("\n✅ Process completed in {$executionTime} seconds.");

            Log::info('Processing completed successfully', [
                'stats' => $this->stats,
                'execution_time_seconds' => $executionTime
            ]);

            $this->updateServiceLastRun();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("\n❌ Error processing overdue loans: " . $e->getMessage());
            Log::error('Failed to process overdue loans', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'stats' => $this->stats
            ]);

            return 1;
        }

        return 0;
    }

    /**
     * Get the base query for overdue loans
     */
    protected function getOverdueLoansQuery()
    {
        return Loans::query()
            ->where('end_date', '<=', now()->toDateString())
            ->where('loans.status', 12)
            ->join('loans_products', 'loans_products.id', '=', 'loans.product')
            ->select(
                'loans.id',
                'loans.loan_number',
                'loans_products.skip_sat',
                'loans_products.skip_sun',
                'loans_products.product_name as product_name'
            )
            ->orderBy('loans.id');
    }

    /**
     * Process a single loan
     */
    protected function processSingleLoan($loan, string $today): void
    {
        $loanNumber = $loan->loan_number;
        $dayName = Carbon::parse($today)->dayName;

        try {
            // Check day skip rules
            if ($this->shouldSkipLoan($loan, $dayName)) {
                $this->stats['skipped_by_day_rule']++;
                Log::debug('Skipped loan due to day rule', [
                    'loan_number' => $loanNumber,
                    'day' => $dayName
                ]);
                return;
            }

            // Find existing schedule
            $schedule = LoanPaymentSchedules::where('loan_number', $loanNumber)
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$schedule) {
                $this->stats['no_schedule_found']++;
                Log::warning('No schedule found for loan', [
                    'loan_number' => $loanNumber,
                    'product' => $loan->product_name ?? 'Unknown'
                ]);
                return;
            }

            // Validate schedule data
            if (!$this->option('skip-validation') && !$this->isValidSchedule($schedule)) {
                $this->stats['invalid_schedule']++;
                Log::error('Invalid schedule data', [
                    'loan_number' => $loanNumber,
                    'schedule_id' => $schedule->id,
                    'missing_fields' => $this->getMissingFields($schedule)
                ]);
                return;
            }

            // Prepare schedule data
            $scheduleData = $this->prepareScheduleData($schedule, $today);

            // Create or get existing
            $existing = LoanPaymentSchedules::where('loan_number', $loanNumber)
                ->where('payment_due_date', $today)
                ->first();

            if ($existing) {
                $this->stats['existing_schedules']++;
                Log::debug('Schedule already exists', [
                    'loan_number' => $loanNumber,
                    'due_date' => $today
                ]);
                return;
            }

            // Create new schedule
            $created = LoanPaymentSchedules::create($scheduleData);
            $this->stats['created_schedules']++;

            Log::info('Created payment schedule', [
                'loan_number' => $loanNumber,
                'schedule_id' => $created->id,
                'amount' => $scheduleData['payment_total_amount'],
                'due_date' => $today
            ]);
        } catch (\Exception $e) {
            $this->stats['failed']++;
            Log::error('Failed to process loan', [
                'loan_number' => $loanNumber,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Perform dry run to show what would be done
     */
    protected function performDryRun(string $today): void
    {
        $dayName = Carbon::parse($today)->dayName;
        $this->info("\n📋 DRY RUN - Would process the following:\n");

        $loans = $this->getOverdueLoansQuery()->limit(20)->get();

        if ($loans->isEmpty()) {
            $this->line("No loans would be processed.");
            return;
        }

        $rows = [];
        foreach ($loans as $loan) {
            $skipReason = null;
            if ($this->shouldSkipLoan($loan, $dayName)) {
                $skipReason = "Skip day ({$dayName})";
            } else {
                $schedule = LoanPaymentSchedules::where('loan_number', $loan->loan_number)->first();
                if (!$schedule) {
                    $skipReason = "No schedule found";
                } elseif (!$this->isValidSchedule($schedule)) {
                    $skipReason = "Invalid schedule data";
                } else {
                    $skipReason = "✓ Would create schedule for {$today}";
                }
            }

            $rows[] = [
                $loan->loan_number,
                $loan->product_name ?? 'N/A',
                $skipReason ?? 'Unknown',
            ];
        }

        $this->table(
            ['Loan Number', 'Product', 'Action'],
            $rows
        );

        $totalLoans = $this->stats['total_loans'];
        if ($totalLoans > 20) {
            $this->line("\n... and " . ($totalLoans - 20) . " more loans");
        }

        $this->line("\n📊 Dry Run Summary:");
        $this->line("   • Total overdue loans: {$totalLoans}");
        $this->line("   • Would be processed: " . ($totalLoans - $this->stats['skipped_by_day_rule']));
        $this->line("   • Would be skipped due to day rules: {$this->stats['skipped_by_day_rule']}");
    }

    /**
     * Prepare schedule data array
     */
    protected function prepareScheduleData($schedule, string $today): array
    {
        return [
            'loan_number' => $schedule->loan_number,
            'payment_principal_amount' => $schedule->payment_principal_amount,
            'payment_interest_amount' => $schedule->payment_interest_amount,
            'payment_total_amount' => $schedule->payment_total_amount,
            'payment_due_date' => $today,
            'status' => 1,
            'company' => $schedule->company,
            'branch' => $schedule->branch,
            'zone' => $schedule->zone,
            'overdue_flag' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Determine if a loan should be skipped based on day rules.
     */
    protected function shouldSkipLoan($loan, string $dayName): bool
    {
        return ($dayName === 'Saturday' && $loan->skip_sat) ||
            ($dayName === 'Sunday' && $loan->skip_sun);
    }

    /**
     * Validate schedule data to ensure it contains required fields.
     */
    protected function isValidSchedule($schedule): bool
    {
        return !is_null($schedule->payment_principal_amount) &&
            !is_null($schedule->payment_interest_amount) &&
            !is_null($schedule->payment_total_amount) &&
            !is_null($schedule->company) &&
            !is_null($schedule->branch) &&
            !is_null($schedule->zone);
    }

    /**
     * Get missing fields from schedule for error reporting
     */
    protected function getMissingFields($schedule): array
    {
        $missing = [];
        $required = [
            'payment_principal_amount',
            'payment_interest_amount',
            'payment_total_amount',
            'company',
            'branch',
            'zone'
        ];

        foreach ($required as $field) {
            if (is_null($schedule->$field)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Display processing statistics
     */
    protected function displayStatistics(): void
    {
        $this->info("\n📊 Processing Statistics:");
        $this->info("─────────────────────────────────────────────────");
        $this->line("   • Total loans processed:     {$this->stats['total_loans']}");
        $this->line("   • ✓ New schedules created:   {$this->stats['created_schedules']}");
        $this->line("   • • Existing schedules:      {$this->stats['existing_schedules']}");
        $this->line("   • ⏭ Skipped (day rule):      {$this->stats['skipped_by_day_rule']}");
        $this->line("   • ⚠ No schedule found:       {$this->stats['no_schedule_found']}");
        $this->line("   • ❌ Invalid schedule:        {$this->stats['invalid_schedule']}");

        if ($this->stats['failed'] > 0) {
            $this->error("   • 💥 Failed:                 {$this->stats['failed']}");
        }

        $successRate = $this->stats['total_loans'] > 0
            ? round(($this->stats['created_schedules'] / $this->stats['total_loans']) * 100, 2)
            : 0;

        $this->line("   • Success rate:             {$successRate}%");
        $this->info("─────────────────────────────────────────────────");
    }

    /**
     * Update service last run timestamp
     */
    protected function updateServiceLastRun(): void
    {
        try {
            ServicesModel::where('id', 3)->update(['last_run' => now()]);
            Log::debug('Service last_run timestamp updated', ['service_id' => 3]);
        } catch (\Exception $e) {
            $this->warn("Failed to update service last_run: " . $e->getMessage());
            Log::warning('Failed to update service last_run', [
                'service_id' => 3,
                'error' => $e->getMessage()
            ]);
        }
    }
}
