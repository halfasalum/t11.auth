<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_contribution_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('group_member_product_id')
                ->constrained('kikoba_group_member_products')
                ->cascadeOnDelete();

            $table->foreignId('group_financial_year_id')
                ->constrained('kikoba_group_financial_years')
                ->cascadeOnDelete();

            $table->unsignedInteger('sequence'); // 1st, 2nd, 3rd due date in the cycle

            $table->date('due_date');

            $table->decimal('expected_amount', 15, 2);
            $table->decimal('paid_amount', 15, 2)->default(0);

            $table->enum('status', ['pending', 'partial', 'paid', 'overdue', 'waived'])
                ->default('pending');

            $table->boolean('penalty_applied')->default(false);

            $table->timestamps();

            
            $table->index(['due_date']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_contribution_schedules');
    }
};
