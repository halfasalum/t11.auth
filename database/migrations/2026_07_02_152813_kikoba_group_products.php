<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_group_products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kikoba_group_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('kikoba_product_id')
                ->constrained()
                ->cascadeOnDelete();

            // Nullable overrides — falls back to product defaults when null
            $table->decimal('value_override', 15, 2)->nullable();
            $table->unsignedInteger('min_unit_override')->nullable();
            $table->unsignedInteger('max_unit_override')->nullable();
            $table->boolean('mandatory_override')->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

            
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_group_products');
    }
};
