<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_loans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // Shares are tracked per group membership, so both the group and the
            // specific membership row are recorded (not just the member).
            $table->foreignId('kikoba_group_id')
                ->constrained('kikoba_groups')
                ->cascadeOnDelete();

            $table->foreignId('kikoba_group_member_id')
                ->constrained('kikoba_group_members')
                ->cascadeOnDelete();

            $table->foreignId('kikoba_loan_product_id')
                ->constrained('kikoba_loan_products')
                ->cascadeOnDelete();

            $table->string('loan_number')->unique();

            // Snapshot at application time — auditable even if the member's paid
            // shares or the product's settings change later.
            $table->decimal('share_value_at_application', 15, 2);
            $table->decimal('multiplier', 8, 2);
            $table->decimal('requested_amount', 15, 2);
            $table->unsignedInteger('loan_period');

            $table->text('purpose')->nullable();
            $table->text('notes')->nullable();

            // Phase 2 = application only; approval/disbursement statuses are
            // added by a later migration once that phase is built.
            $table->enum('status', ['pending', 'cancelled'])->default('pending');

            $table->foreignId('applied_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id']);
            $table->index(['kikoba_group_id']);
            $table->index(['kikoba_group_member_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_loans');
    }
};
