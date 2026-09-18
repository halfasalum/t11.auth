<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (loan, cycle) a finalize() actually claimed interest
        // for — the source of truth behind KikobaLoan.interest_claimed_amount
        // (a running total, kept for fast reads), so unlocking a cycle can
        // precisely reverse only what THAT cycle claimed, even for a
        // cash_collected loan whose interest was claimed incrementally
        // across several cycles.
        Schema::create('kikoba_loan_interest_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kikoba_loan_id')->constrained('kikoba_loans')->cascadeOnDelete();
            $table->foreignId('group_financial_year_id')->constrained('kikoba_group_financial_years')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamp('claimed_at');
            $table->timestamps();

            $table->unique(['kikoba_loan_id', 'group_financial_year_id'], 'kikoba_loan_interest_claims_loan_gfy_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_loan_interest_claims');
    }
};
