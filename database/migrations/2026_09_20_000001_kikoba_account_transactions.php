<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_account_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignId('kikoba_account_id')
                ->constrained('kikoba_accounts')
                ->cascadeOnDelete();

            // Denormalized for group-scoped ledger queries without a join.
            $table->foreignId('kikoba_group_id')
                ->constrained('kikoba_groups')
                ->cascadeOnDelete();

            $table->enum('transaction_type', ['credit', 'debit']);
            $table->decimal('amount', 15, 2);
            $table->decimal('opening_balance', 15, 2);
            $table->decimal('closing_balance', 15, 2);
            $table->date('transaction_date');

            // 'manual' = deposit/withdraw/transfer posted directly on the
            // account; 'contribution' and 'loan_disbursement' = auto-posted
            // from those flows.
            $table->enum('source', ['manual', 'contribution', 'loan_disbursement'])->default('manual');
            $table->foreignId('kikoba_contribution_id')->nullable()
                ->constrained('kikoba_contributions')->nullOnDelete();
            $table->foreignId('kikoba_loan_id')->nullable()
                ->constrained('kikoba_loans')->nullOnDelete();

            $table->string('reference_number')->nullable();
            $table->string('description')->nullable();

            $table->foreignId('registered_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['kikoba_account_id']);
            $table->index(['kikoba_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_account_transactions');
    }
};
