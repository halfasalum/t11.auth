<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_loan_repayments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->foreignId('kikoba_loan_id')
                ->constrained('kikoba_loans')
                ->cascadeOnDelete();

            // Nullable: a repayment can overflow across multiple installments
            // (see auto-allocation in KikobaLoanRepaymentService), so this
            // only names the installment it happened to land on, if single.
            $table->foreignId('kikoba_loan_schedule_id')->nullable()
                ->constrained('kikoba_loan_schedules')->nullOnDelete();

            $table->decimal('amount', 15, 2);
            $table->date('paid_date');
            $table->string('reference')->nullable();
            $table->string('payment_method')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('received_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['kikoba_loan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_loan_repayments');
    }
};
