<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // e.g. share value, or fixed savings/penalty amount per unit
            $table->decimal('value', 15, 2);

            $table->unsignedInteger('min_unit')->default(1);
            $table->unsignedInteger('max_unit')->nullable(); // null = unlimited

            $table->boolean('mandatory_contribution')->default(false);

            // time unit for submission cycle, e.g. "every 2 weeks" = unit(week) + frequency(2)
            $table->enum('submission_unit', ['day', 'week', 'month'])->default('month');
            $table->unsignedInteger('submission_frequency')->default(1);

            $table->boolean('used_as_income')->default(false);

            $table->enum('product_type', ['share', 'saving', 'penalty'])->default('saving');

            // only relevant when used_as_income is true
            // share_value = prorated by member's units/shares vs total units/shares
            // flat_rate   = split equally among active members
            $table->enum('income_calculation', ['share_value', 'flat_rate'])->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id']);
            $table->index(['product_type']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_products');
    }
};
