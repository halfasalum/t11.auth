<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_financial_year_close_reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kikoba_group_id')
                ->constrained('kikoba_groups')
                ->cascadeOnDelete()
                ->name('kfy_cr_group_id_foreign');

            $table->foreignId('group_financial_year_id')
                ->constrained('kikoba_group_financial_years')
                ->cascadeOnDelete()
                ->name('kfy_cr_fy_id_foreign');

            $table->foreignId('kikoba_group_member_id')
                ->constrained('kikoba_group_members')
                ->cascadeOnDelete()
                ->name('kfy_cr_member_id_foreign');

            $table->foreignId('generated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->name('kfy_cr_generated_by_foreign');

            // Main fields
            $table->decimal('total_savings_amount', 15, 2)->default(0);
            $table->unsignedInteger('total_share_units')->default(0);
            $table->decimal('total_share_amount', 15, 2)->default(0);
            $table->decimal('profit_amount', 15, 2)->default(0);
            $table->decimal('total_payout', 15, 2)->default(0);

            $table->json('breakdown')->nullable();

            $table->enum('status', ['draft', 'finalized', 'paid'])->default('draft');

            $table->timestamp('generated_at')->nullable();
            $table->timestamp('finalized_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['group_financial_year_id'], 'kfy_cr_fy_index');
            $table->index(['status'], 'kfy_cr_status_index');
            $table->index(['kikoba_group_member_id'], 'kfy_cr_member_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_financial_year_close_reports');
    }
};