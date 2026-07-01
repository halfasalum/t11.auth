<?php
// app/Console/Commands/ProcessLoanWorkflowActions.php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Loans;
use App\Models\LoansProducts;
use App\Models\LoanPaymentSchedules;
use App\Models\PaymentSubmissions;
use App\Models\NotificationLog;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ProcessLoanWorkflowActions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loans:process-workflow
                            {--dry-run : Run without making actual changes}
                            {--product= : Process only specific product ID}
                            {--loan= : Process only specific loan ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process loan workflow actions (default, write-off, foreclosure) based on product settings';

    /**
     * Statistics counters
     */
    protected $stats = [
        'loans_checked' => 0,
        'loans_defaulted' => 0,
        'loans_written_off' => 0,
        'loans_foreclosed' => 0,
        'errors' => 0,
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('==========================================');
        $this->info('Loan Workflow Processor Started');
        $this->info('==========================================');
        $this->newLine();

        $dryRun = $this->option('dry-run');
        $productId = $this->option('product');
        $loanId = $this->option('loan');

        if ($dryRun) {
            $this->warn('⚠️  DRY RUN MODE - No actual changes will be made');
            $this->newLine();
        }

        // Get products to process
        $productsQuery = LoansProducts::where('status', 1); // Only active products

        if ($productId) {
            $productsQuery->where('id', $productId);
        }

        $products = $productsQuery->get();

        if ($products->isEmpty()) {
            $this->error('No active loan products found.');
            return 1;
        }

        $this->info("📋 Processing " . $products->count() . " loan products...");
        $this->newLine();

        /* foreach ($products as $product) {
            $this->info("🔍 Checking product: {$product->product_name} (ID: {$product->id})");

            $company = Company::where('id', $product->company)
                ->where('company_status', 1)
                ->first();
            $this->info("🔍 Company is not active: {$company->company_name} (ID: {$company->id})");

            $subscription = Subscription::where('company_id', $company->id)
                ->where('status', 'active')
                ->first();
            $this->info("🔍 Subscription is not active: {$subscription->company_name} (ID: {$subscription->id})");
            if (is_null($subscription)) {
                break;
            }

            // Get loans for this product
            $loansQuery = Loans::where('product', $product->id)
                ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE]);

            if ($loanId) {
                $loansQuery->where('loan_number', $loanId);
            }

            $loans = $loansQuery->get();

            if ($loans->isEmpty()) {
                $this->line("   └─ No active loans found for this product");
                $this->newLine();
                continue;
            }

            $this->line("   📊 Found {$loans->count()} active loans to check");

            // Process each loan
            foreach ($loans as $loan) {
                $this->processLoan($loan, $product, $dryRun);
            }

            $this->newLine();
        } */

        foreach ($products as $product) {
            $this->info("🔍 Checking product: {$product->product_name} (ID: {$product->id})");

            $company = Company::where('id', $product->company)
                ->where('company_status', 1)
                ->first();

            if (is_null($company)) {
                $this->warn("   └─ Company not found or inactive for product: {$product->product_name}");
                $this->newLine();
                continue; // Skip this product
            }

            $this->info("✅ Company is active: {$company->company_name} (ID: {$company->id})");

            $subscription = Subscription::where('company_id', $company->id)
                ->where('status', 'active')
                ->first();

            if (is_null($subscription)) {
                $this->warn("   └─ No active subscription found for company: {$company->company_name}");
                $this->newLine();
                continue; // Skip this product (not 'break' unless you want to stop all products)
            }

            $this->info("✅ Subscription is active: {$subscription->company_name} (ID: {$subscription->id})");

            // Get loans for this product
            $loansQuery = Loans::where('product', $product->id)
                ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE]);

            if ($loanId) {
                $loansQuery->where('loan_number', $loanId);
            }

            $loans = $loansQuery->get();

            if ($loans->isEmpty()) {
                $this->line("   └─ No active loans found for this product");
                $this->newLine();
                continue;
            }

            $this->line("   📊 Found {$loans->count()} active loans to check");

            // Process each loan
            foreach ($loans as $loan) {
                $this->processLoan($loan, $product, $dryRun);
            }

            $this->newLine();
        }

        // Print summary
        $this->newLine();
        $this->info('==========================================');
        $this->info('PROCESSING SUMMARY');
        $this->info('==========================================');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Loans Checked', $this->stats['loans_checked']],
                ['Loans Defaulted', $this->stats['loans_defaulted']],
                ['Loans Written Off', $this->stats['loans_written_off']],
                ['Loans Foreclosed', $this->stats['loans_foreclosed']],
                ['Errors Encountered', $this->stats['errors']],
            ]
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn('⚠️  This was a DRY RUN. Run without --dry-run to apply changes.');
        }

        $this->newLine();
        $this->info('✅ Loan workflow processing completed!');

        return 0;
    }

    /**
     * Process a single loan
     */
    protected function processLoan(Loans $loan, LoansProducts $product, bool $dryRun)
    {
        $this->stats['loans_checked']++;

        $this->line("   ├─ Loan #{$loan->loan_number} (ID: {$loan->id}) - Balance: " . number_format($loan->total_loan - $loan->loan_paid, 2));

        // Skip if loan is already processed
        if ($loan->status === Loans::STATUS_DEFAULTED) {
            $this->line("   │  └─ Already defaulted");
            return;
        }

        if ($loan->status === Loans::STATUS_WRITTEN_OFF) {
            $this->line("   │  └─ Already written off");
            return;
        }

        if ($loan->status === Loans::STATUS_FORECLOSURE) {
            $this->line("   │  └─ Already in foreclosure");
            return;
        }

        // Check in order of severity: Foreclosure > Write Off > Default
        // Foreclosure is most severe, then write off, then default

        // 1. Check for Foreclosure (most severe, requires collateral)
        if ($product->foreclosure_enabled) {
            if ($this->shouldForeclose($loan, $product)) {
                $this->executeForeclosure($loan, $product, $dryRun);
                return;
            }
        }

        // 2. Check for Write Off
        if ($product->write_off_enabled) {
            if ($this->shouldWriteOff($loan, $product)) {
                $this->executeWriteOff($loan, $product, $dryRun);
                return;
            }
        }

        // 3. Check for Default
        if ($this->shouldDefault($loan, $product)) {
            $this->executeDefault($loan, $product, $dryRun);
            return;
        }

        // Check if loan should be marked as Overdue (separate from default)
        $this->checkOverdueStatus($loan, $product, $dryRun);
    }

    /**
     * Check if loan should be defaulted
     */
    protected function shouldDefault(Loans $loan, LoansProducts $product): bool
    {
        $daysOverdue = $this->getDaysOverdue($loan);
        $missedPayments = $this->getMissedPaymentsCount($loan);

        $thresholdDays = $product->default_days_overdue ?? 90;
        $thresholdMissed = $product->default_missed_payments ?? 3;

        $should = false;
        $reason = [];

        if ($thresholdDays && $daysOverdue >= $thresholdDays) {
            $should = true;
            $reason[] = "{$daysOverdue} days overdue (threshold: {$thresholdDays})";
        }

        if ($thresholdMissed && $missedPayments >= $thresholdMissed) {
            $should = true;
            $reason[] = "{$missedPayments} missed payments (threshold: {$thresholdMissed})";
        }

        // Check percentage of term if configured
        $percentageTerm = $product->default_percentage_of_term;
        if ($percentageTerm && $loan->start_date && $loan->end_date) {
            $termProgress = $this->getTermProgressPercentage($loan);
            if ($termProgress >= $percentageTerm && ($loan->total_loan - $loan->loan_paid) > 0) {
                $should = true;
                $reason[] = "{$termProgress}% of term passed (threshold: {$percentageTerm}%)";
            }
        }

        if ($should) {
            $loan->default_reason = implode(', ', $reason);
        }

        return $should;
    }

    /**
     * Check if loan should be written off
     */
    protected function shouldWriteOff(Loans $loan, LoansProducts $product): bool
    {
        // Check if write off is enabled
        if (!$product->write_off_enabled) {
            return false;
        }

        $daysOverdue = $this->getDaysOverdue($loan);
        $missedPayments = $this->getMissedPaymentsCount($loan);

        $thresholdDays = $product->write_off_days_overdue ?? 180;
        $thresholdMissed = $product->write_off_missed_payments ?? 6;

        $should = false;
        $reason = [];

        if ($thresholdDays && $daysOverdue >= $thresholdDays) {
            $should = true;
            $reason[] = "{$daysOverdue} days overdue (threshold: {$thresholdDays})";
        }

        if ($thresholdMissed && $missedPayments >= $thresholdMissed) {
            $should = true;
            $reason[] = "{$missedPayments} missed payments (threshold: {$thresholdMissed})";
        }

        if ($should) {
            $loan->write_off_reason = implode(', ', $reason);
        }

        return $should;
    }

    /**
     * Check if loan should be foreclosed
     */
    protected function shouldForeclose(Loans $loan, LoansProducts $product): bool
    {
        // Check if foreclosure is enabled
        if (!$product->foreclosure_enabled) {
            return false;
        }

        // Check if loan has collateral
        /* if ($product->foreclosure_requires_collateral) {
            return false;
        } */

        $daysOverdue = $this->getDaysOverdue($loan);
        $missedPayments = $this->getMissedPaymentsCount($loan);

        $thresholdDays = $product->foreclosure_days_overdue ?? 210;
        $thresholdMissed = $product->foreclosure_missed_payments ?? 7;

        $should = false;
        $reason = [];

        if ($thresholdDays && $daysOverdue >= $thresholdDays) {
            $should = true;
            $reason[] = "{$daysOverdue} days overdue (threshold: {$thresholdDays})";
        }

        if ($thresholdMissed && $missedPayments >= $thresholdMissed) {
            $should = true;
            $reason[] = "{$missedPayments} missed payments (threshold: {$thresholdMissed})";
        }

        if ($should) {
            $loan->foreclosure_reason = implode(', ', $reason);
        }

        return $should;
    }

    /**
     * Execute default action on loan
     */
    protected function executeDefault(Loans $loan, LoansProducts $product, bool $dryRun)
    {
        $this->line("   │  ├─ 🚨 ACTION: Mark as DEFAULTED");
        $this->line("   │  │  └─ Reason: {$loan->default_reason}");

        if (!$dryRun) {
            try {
                DB::beginTransaction();

                $loan->status = Loans::STATUS_DEFAULTED;
                $loan->defaulted_date = Carbon::now();
                $loan->defaulted_reason = $loan->default_reason;
                $loan->defaulted_by_system = true;
                $loan->save();

                // Log the action
                $this->logWorkflowAction($loan, 'default', $loan->default_reason);

                // Send notification if enabled
                if ($product->notify_on_default) {
                    $this->sendNotification($loan, 'default');
                }

                DB::commit();
                $this->stats['loans_defaulted']++;
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("   │  └─ ❌ Error: " . $e->getMessage());
                Log::error("Failed to default loan {$loan->id}: " . $e->getMessage());
                $this->stats['errors']++;
            }
        } else {
            $this->line("   │  └─ [DRY RUN] Would mark as defaulted");
        }
    }

    /**
     * Execute write off action on loan
     */
    protected function executeWriteOff(Loans $loan, LoansProducts $product, bool $dryRun)
    {
        $this->line("   │  ├─ 💸 ACTION: Mark as WRITTEN OFF");
        $this->line("   │  │  └─ Reason: {$loan->write_off_reason}");

        if (!$dryRun) {
            try {
                DB::beginTransaction();

                $loan->status = Loans::STATUS_WRITTEN_OFF;
                $loan->written_off_date = Carbon::now();
                $loan->written_off_amount = ($loan->total_loan - $loan->loan_paid);
                $loan->written_off_reason = $loan->write_off_reason;
                $loan->written_off_by_system = true;
                $loan->save();

                // Close all pending schedules
                LoanPaymentSchedules::where('loan_number', $loan->loan_number)
                    ->where('status', 1)
                    ->where('is_submitted', 0)
                    ->update([
                        'status' => 3, // Cancelled
                        //'cancelled_reason' => 'Written off',
                        //'cancelled_at' => Carbon::now(),
                    ]);

                // Log the action
                $this->logWorkflowAction($loan, 'write_off', $loan->write_off_reason);

                // Send notification if enabled
                if ($product->notify_on_write_off) {
                    $this->sendNotification($loan, 'write_off');
                }

                DB::commit();
                $this->stats['loans_written_off']++;
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("   │  └─ ❌ Error: " . $e->getMessage());
                Log::error("Failed to write off loan {$loan->id}: " . $e->getMessage());
                $this->stats['errors']++;
            }
        } else {
            $this->line("   │  └─ [DRY RUN] Would mark as written off");
        }
    }

    /**
     * Execute foreclosure action on loan
     */
    protected function executeForeclosure(Loans $loan, LoansProducts $product, bool $dryRun)
    {
        $this->line("   │  ├─ ⚖️ ACTION: Initiate FORECLOSURE");
        $this->line("   │  │  └─ Reason: {$loan->foreclosure_reason}");

        if (!$dryRun) {
            try {
                DB::beginTransaction();

                $loan->status = Loans::STATUS_FORECLOSURE;
                $loan->foreclosure_date = Carbon::now();
                $loan->foreclosure_status = 'initiated';
                $loan->foreclosure_reason = $loan->foreclosure_reason;
                $loan->foreclosure_initiated_by_system = true;
                $loan->foreclosure_notice_date = Carbon::now()->addDays($product->foreclosure_notice_days ?? 30);
                $loan->foreclosure_redemption_date = Carbon::now()->addDays(($product->foreclosure_notice_days ?? 30) + ($product->foreclosure_redemption_period ?? 30));
                $loan->save();

                // Log the action
                $this->logWorkflowAction($loan, 'foreclosure', $loan->foreclosure_reason);

                // Send notification if enabled
                if ($product->notify_on_foreclosure) {
                    $this->sendNotification($loan, 'foreclosure');
                }

                DB::commit();
                $this->stats['loans_foreclosed']++;
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error("   │  └─ ❌ Error: " . $e->getMessage());
                Log::error("Failed to initiate foreclosure for loan {$loan->id}: " . $e->getMessage());
                $this->stats['errors']++;
            }
        } else {
            $this->line("   │  └─ [DRY RUN] Would initiate foreclosure");
        }
    }

    /**
     * Check and update overdue status
     */
    protected function checkOverdueStatus(Loans $loan, LoansProducts $product, bool $dryRun)
    {
        $daysOverdue = $this->getDaysOverdue($loan);

        if ($daysOverdue > 0 && $loan->status !== Loans::STATUS_OVERDUE) {
            $this->line("   │  └─ ⏰ Marking as OVERDUE ({$daysOverdue} days)");

            if (!$dryRun) {
                try {
                    $loan->status = Loans::STATUS_OVERDUE;
                    $loan->overdue_date = Carbon::now();
                    $loan->days_overdue = $daysOverdue;
                    $loan->save();

                    // Send overdue notification if configured
                    $notifyDays = $product->notify_on_overdue_days ?? 7;
                    if ($daysOverdue >= $notifyDays) {
                        $this->sendNotification($loan, 'overdue');
                    }
                } catch (\Exception $e) {
                    Log::error("Failed to mark loan {$loan->id} as overdue: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Get days overdue for a loan
     */
    protected function getDaysOverdue(Loans $loan): int
    {
        $oldestUnpaidSchedule = LoanPaymentSchedules::select('loan_payment_schedule.*')
            ->join('payment_submissions', 'loan_payment_schedule.loan_number', 'payment_submissions.loan_number')
            ->where('loan_payment_schedule.loan_number', $loan->loan_number)
            ->where('loan_payment_schedule.status', 1)
            ->where('loan_payment_schedule.overdue_flag', 1)
            ->where('loan_payment_schedule.payment_due_date', '<', Carbon::now())
            ->orderBy('loan_payment_schedule.payment_due_date', 'asc')
            ->first();

        if (!$oldestUnpaidSchedule) {
            return 0;
        }

        return Carbon::parse($oldestUnpaidSchedule->payment_due_date)->diffInDays(Carbon::now());
    }

    /**
     * Get missed payments count
     */
    protected function getMissedPaymentsCount(Loans $loan): int
    {
        return LoanPaymentSchedules::join('payment_submissions', 'loan_payment_schedule.loan_number', 'payment_submissions.loan_number')
            ->where('loan_payment_schedule.loan_number', $loan->loan_number)
            ->where('loan_payment_schedule.status', 1)
            ->where('loan_payment_schedule.overdue_flag', 1)
            ->where('loan_payment_schedule.penalty_amount', '>', 0)
            ->where('loan_payment_schedule.payment_due_date', '<', Carbon::now())
            ->where(function ($q) {
                $q->where('payment_submissions.amount', 0);
            })
            ->count();
    }

    /**
     * Get term progress percentage
     */
    protected function getTermProgressPercentage(Loans $loan): float
    {
        if (!$loan->start_date || !$loan->end_date) {
            return 0;
        }

        $totalTerm = $loan->start_date->diffInDays($loan->end_date);
        $elapsed = $loan->start_date->diffInDays(Carbon::now());

        if ($totalTerm <= 0) {
            return 0;
        }
        return ($elapsed / $totalTerm) * 100;
    }

    /**
     * Log workflow action
     */
    protected function logWorkflowAction(Loans $loan, string $action, string $reason)
    {
        DB::table('loan_workflow_logs')->insert([
            'loan_number' => $loan->loan_number,
            'action' => $action,
            'reason' => $reason,
            'metadata' => json_encode([
                'outstanding_balance' => $loan->outstanding_balance,
                'days_overdue' => $this->getDaysOverdue($loan),
                'missed_payments' => $this->getMissedPaymentsCount($loan),
            ]),
            'created_by_system' => true,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Send notification
     */
    protected function sendNotification(Loans $loan, string $type)
    {
        try {
            // Get loan officer/customer details
            $customer = $loan->customerRelation;
            $officer = $loan->registeredBy;

            $messages = [
                'default' => "Loan {$loan->loan_number} has been marked as DEFAULTED. Please contact the customer immediately.",
                'write_off' => "Loan {$loan->loan_number} has been WRITTEN OFF. Amount: " . number_format($loan->written_off_amount, 2),
                'foreclosure' => "Loan {$loan->loan_number} has entered FORECLOSURE process. Legal action will be initiated.",
                'overdue' => "Loan {$loan->loan_number} is {$loan->days_overdue} days overdue. Please follow up.",
            ];

            $message = $messages[$type] ?? "Loan {$loan->loan_number} requires attention.";

            // Store notification in database
            NotificationLog::create([
                'loan_number' => $loan->loan_number,
                'type' => $type,
                'message' => $message,
                'recipient_type' => 'loan_officer',
                'recipient_id' => $officer?->id,
                'sent_at' => Carbon::now(),
            ]);

            // Here you can also send SMS/Email if configured
            // $this->sendSMS($officer->phone, $message);
            // $this->sendEmail($officer->email, $message);

        } catch (\Exception $e) {
            Log::warning("Failed to send notification for loan {$loan->id}: " . $e->getMessage());
        }
    }
}
