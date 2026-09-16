<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_loan_products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->string('name', 150);
            $table->text('description')->nullable();

            // fixed = one flat interest_amount for the whole loan; percentage = interest_rate % per repayment_interval
            $table->enum('interest_mode', ['fixed', 'percentage'])->default('percentage');
            $table->decimal('interest_rate', 8, 2)->nullable();
            $table->decimal('interest_amount', 15, 2)->nullable();

            // Absolute bounds — the actual eligible ceiling is min(max_loan_amount, shares * chosen multiplier)
            $table->decimal('min_loan_amount', 15, 2);
            $table->decimal('max_loan_amount', 15, 2)->nullable();

            $table->unsignedInteger('min_loan_period');
            $table->unsignedInteger('max_loan_period');
            $table->enum('loan_period_unit', ['days', 'weeks', 'months'])->default('months');

            $table->unsignedInteger('repayment_interval')->default(1);
            $table->enum('repayment_interval_unit', ['days', 'weeks', 'months'])->default('months');

            $table->boolean('skip_sat')->default(false);
            $table->boolean('skip_sun')->default(false);

            $table->enum('penalty_type', ['none', 'fixed', 'percentage'])->default('none');
            $table->decimal('fixed_penalty_amount', 15, 2)->default(0);
            $table->decimal('penalty_percentage', 8, 2)->default(0);

            // Allowed multiples of a member's paid share value they may borrow against,
            // e.g. [1, 2, 3] — chosen at application time, capped by max_loan_amount.
            $table->json('share_multipliers');

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_loan_products');
    }
};
