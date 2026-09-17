<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kikoba_loan_products', function (Blueprint $table) {
            // add_on (default): interest is added on top of the requested
            // amount — the member is disbursed the full approved amount and
            // repays principal + interest.
            // deducted_upfront: interest is taken out of the disbursement —
            // the approved amount IS what the member repays in total, but
            // they only receive approved_amount - interest in hand.
            $table->enum('interest_application', ['add_on', 'deducted_upfront'])
                ->default('add_on')->after('interest_amount');
        });
    }

    public function down(): void
    {
        Schema::table('kikoba_loan_products', function (Blueprint $table) {
            $table->dropColumn('interest_application');
        });
    }
};
