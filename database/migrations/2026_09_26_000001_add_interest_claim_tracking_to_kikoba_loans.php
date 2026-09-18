<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kikoba_loans', function (Blueprint $table) {
            // Running total of this loan's interest already locked into a
            // FINALIZED financial-year close report. A closure only ever
            // pools (interest recognized so far - interest_claimed_amount),
            // so the same interest is never distributed twice — regardless
            // of the product's interest_recognition mode, or whether the
            // loan keeps earning more claimable interest across later cycles
            // (cash_collected mode).
            $table->decimal('interest_claimed_amount', 15, 2)->default(0)->after('upfront_interest_amount');

            // Audit only — the most recent cycle this loan contributed to.
            $table->foreignId('last_claimed_financial_year_id')->nullable()->after('interest_claimed_amount')
                ->constrained('kikoba_group_financial_years')->nullOnDelete();
            $table->timestamp('interest_claimed_at')->nullable()->after('last_claimed_financial_year_id');
        });
    }

    public function down(): void
    {
        Schema::table('kikoba_loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_claimed_financial_year_id');
            $table->dropColumn(['interest_claimed_amount', 'interest_claimed_at']);
        });
    }
};
