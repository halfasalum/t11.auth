<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {

        Schema::create('kikoba_member_product_year_summaries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kikoba_group_id')
                ->constrained('kikoba_groups')
                ->cascadeOnDelete()
                ->name('kmpys_kikoba_group_id_foreign');           // Short name

            $table->foreignId('group_financial_year_id')
                ->constrained('kikoba_group_financial_years')
                ->cascadeOnDelete()
                ->name('kmpys_group_fy_id_foreign');               // Short name

            $table->foreignId('kikoba_group_member_id')
                ->constrained('kikoba_group_members')
                ->cascadeOnDelete()
                ->name('kmpys_group_member_id_foreign');           // Short name

            $table->foreignId('kikoba_group_product_id')
                ->constrained('kikoba_group_products')
                ->cascadeOnDelete()
                ->name('kmpys_group_product_id_foreign');          // Short name

            $table->string('product_type', 20);
            $table->unsignedInteger('units')->default(0);
            $table->decimal('unit_value', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('total_paid_amount', 15, 2)->default(0);

            $table->timestamp('generated_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['group_financial_year_id'], 'kmpys_fy_index');
            $table->index(['product_type'], 'kmpys_product_type_index');
            $table->index(['kikoba_group_member_id'], 'kmpys_member_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_member_product_year_summaries');
    }
};