<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kikoba_loan_products', function (Blueprint $table) {
            // How a product's interest income is split across active members
            // at financial-year close — mirrors kikoba_products.income_calculation:
            // flat_rate = split equally, share_value = proportional to each
            // member's share units for that cycle.
            $table->enum('interest_distribution', ['flat_rate', 'share_value'])
                ->default('flat_rate')->after('interest_application');

            // When a loan's interest becomes eligible to be pooled into a
            // financial-year close (see KikobaFinancialYearCloseReportService):
            // on_disbursement = the full interest counts as soon as the loan
            //   is disbursed, whether or not it's been repaid yet.
            // on_completion (default, safest) = only once the loan is fully
            //   repaid (completed/early_settled); defaulted/written_off loans
            //   never contribute.
            // cash_collected = only the interest actually collected so far
            //   (upfront lump + the interest portion of paid installments).
            $table->enum('interest_recognition', ['on_disbursement', 'on_completion', 'cash_collected'])
                ->default('on_completion')->after('interest_distribution');
        });
    }

    public function down(): void
    {
        Schema::table('kikoba_loan_products', function (Blueprint $table) {
            $table->dropColumn(['interest_distribution', 'interest_recognition']);
        });
    }
};
