<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Loan;
use App\Models\Loans;
use App\Models\PaymentSubmissions;
use Illuminate\Support\Facades\DB;

class FixLoanTotalPaid extends Command
{
    protected $signature = 'loans:fix-total-paid 
                            {--loan_number= : Fix only specific loan number}
                            {--dry-run : Show what would be updated without actually updating}';

    protected $description = 'Fix total_paid column in loans table by summing payment_submissions with status 11';

    public function handle()
    {
        $this->info('Starting to fix loan total_paid values...');

        $query = Loans::query();

        if ($this->option('loan_number')) {
            $query->where('loan_number', $this->option('loan_number'));
            $this->info("Processing only loan: {$this->option('loan_number')}");
        }

        $loans = $query->get();

        if ($loans->isEmpty()) {
            $this->warn('No loans found to process.');
            return 0;
        }

        $this->info("Found {$loans->count()} loans to process.");

        $updated = 0;
        $totalPaidSum = 0;

        foreach ($loans as $loan) {
            // Calculate total paid from payment_submissions
            $totalPaid = PaymentSubmissions::where('loan_number', $loan->loan_number)
                ->where('submission_status', 11)
                ->sum('amount');

            $oldTotalPaid = $loan->loan_paid;

            if ($this->option('dry-run')) {
                $this->line("Loan: {$loan->loan_number}");
                $this->line("  Current total_paid: " . number_format($oldTotalPaid, 2));
                $this->line("  Calculated total:   " . number_format($totalPaid, 2));
                $this->line("  Difference:         " . number_format($totalPaid - $oldTotalPaid, 2));
                $this->line("---");
            } else {
                
                // Update the loan
                $loan->loan_paid = $totalPaid;
                $loan->save();

                $updated++;
                $totalPaidSum += $totalPaid;

                $this->info("Updated {$loan->loan_number}: " .
                    number_format($oldTotalPaid, 2) . " → " .
                    number_format($totalPaid, 2));
            }
        }

        if (!$this->option('dry-run')) {
            $this->newLine();
            $this->info("✅ Updated {$updated} loans");
            $this->info("💰 Total paid across all loans: " . number_format($totalPaidSum, 2));

            // Verify the specific loan you mentioned
            if (!$this->option('loan_number')) {
                $specificLoan = Loans::where('loan_number', 'LN-2026-CO5-Z10-CU300-004')->first();
                if ($specificLoan) {
                    $this->newLine();
                    $this->info("🔍 Verification for LN-2026-CO5-Z10-CU300-004:");
                    $this->info("   New total_paid: " . number_format($specificLoan->total_paid, 2));

                    // Double check with direct SQL
                    $directSum = DB::table('payment_submissions')
                        ->where('loan_number', 'LN-2026-CO5-Z10-CU300-004')
                        ->where('submission_status', 11)
                        ->sum('amount');

                    $this->info("   Direct SQL sum:  " . number_format($directSum, 2));

                    if ($specificLoan->total_paid == $directSum) {
                        $this->info("   ✅ VERIFIED: Values match!");
                    } else {
                        $this->error("   ❌ MISMATCH: Values don't match!");
                    }
                }
            }
        } else {
            $this->newLine();
            $this->info("🏁 Dry run completed. Run without --dry-run to apply changes.");
        }

        return 0;
    }
}
