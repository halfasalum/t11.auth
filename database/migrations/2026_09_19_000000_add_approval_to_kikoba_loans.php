<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kikoba_loans', function (Blueprint $table) {
            $table->decimal('approved_amount', 15, 2)->nullable()->after('eligible_amount');
            $table->date('start_date')->nullable()->after('loan_period');

            $table->foreignId('approved_by')->nullable()->after('applied_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');

            $table->foreignId('rejected_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');
            $table->string('rejection_reason')->nullable()->after('rejected_at');
        });

        // Widen the status enum to cover the approval outcomes.
        Schema::table('kikoba_loans', function (Blueprint $table) {
            $table->enum('status', ['pending', 'active', 'rejected', 'cancelled'])
                ->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('kikoba_loans', function (Blueprint $table) {
            $table->enum('status', ['pending', 'cancelled'])->default('pending')->change();
        });

        Schema::table('kikoba_loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['approved_amount', 'start_date', 'approved_at', 'rejected_at', 'rejection_reason']);
        });
    }
};
