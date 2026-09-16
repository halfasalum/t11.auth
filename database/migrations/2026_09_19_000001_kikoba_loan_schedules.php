<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_loan_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kikoba_loan_id')
                ->constrained('kikoba_loans')
                ->cascadeOnDelete();

            $table->unsignedInteger('installment_no');
            $table->date('due_date');
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('interest_amount', 15, 2);
            $table->decimal('total_amount', 15, 2);

            $table->timestamps();

            $table->index(['kikoba_loan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_loan_schedules');
    }
};
