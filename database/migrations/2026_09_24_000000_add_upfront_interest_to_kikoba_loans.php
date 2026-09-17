<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kikoba_loans', function (Blueprint $table) {
            // Set (alongside disbursement_amount) at approval time for a
            // 'deducted_upfront' product — the interest portion collected
            // immediately at disbursement rather than spread across the
            // repayment schedule. Null/0 for an 'add_on' product.
            $table->decimal('upfront_interest_amount', 15, 2)->nullable()->after('disbursement_amount');
        });
    }

    public function down(): void
    {
        Schema::table('kikoba_loans', function (Blueprint $table) {
            $table->dropColumn('upfront_interest_amount');
        });
    }
};
