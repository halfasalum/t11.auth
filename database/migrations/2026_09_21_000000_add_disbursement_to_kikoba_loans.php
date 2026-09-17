<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kikoba_loans', function (Blueprint $table) {
            $table->foreignId('kikoba_account_id')->nullable()->after('kikoba_loan_product_id')
                ->constrained('kikoba_accounts')->nullOnDelete();

            $table->timestamp('disbursed_at')->nullable()->after('approved_at');
            $table->foreignId('disbursed_by')->nullable()->after('disbursed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kikoba_loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kikoba_account_id');
            $table->dropConstrainedForeignId('disbursed_by');
            $table->dropColumn('disbursed_at');
        });
    }
};
