<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE kikoba_loan_products MODIFY COLUMN interest_distribution "
            . "ENUM('flat_rate', 'share_value', 'applicant_percentage') NOT NULL DEFAULT 'flat_rate'"
        );

        Schema::table('kikoba_loan_products', function (Blueprint $table) {
            // Only meaningful when interest_distribution = 'applicant_percentage':
            // the share (0-100) of a loan's claimable interest credited directly
            // to the loan's own applicant (kikoba_loans.kikoba_group_member_id).
            // The remainder is pooled and split equally across every active
            // member, applicant included — same mechanic as flat_rate.
            $table->decimal('applicant_interest_percentage', 5, 2)->nullable()->after('interest_recognition');
        });
    }

    public function down(): void
    {
        Schema::table('kikoba_loan_products', function (Blueprint $table) {
            $table->dropColumn('applicant_interest_percentage');
        });

        DB::statement(
            "ALTER TABLE kikoba_loan_products MODIFY COLUMN interest_distribution "
            . "ENUM('flat_rate', 'share_value') NOT NULL DEFAULT 'flat_rate'"
        );
    }
};
