<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kikoba_account_transactions', function (Blueprint $table) {
            $table->enum('source', ['manual', 'contribution', 'loan_disbursement', 'loan_repayment', 'interest_income'])
                ->default('manual')->change();
        });
    }

    public function down(): void
    {
        Schema::table('kikoba_account_transactions', function (Blueprint $table) {
            $table->enum('source', ['manual', 'contribution', 'loan_disbursement', 'loan_repayment'])
                ->default('manual')->change();
        });
    }
};
