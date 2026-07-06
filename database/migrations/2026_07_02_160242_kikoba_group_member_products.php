<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_group_member_products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kikoba_group_member_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('kikoba_group_product_id')
                ->constrained('kikoba_group_products')
                ->cascadeOnDelete();

            $table->unsignedInteger('units')->default(1); // e.g. number of shares held

            $table->date('enrolled_date');
            $table->date('exit_date')->nullable();

            $table->enum('status', ['active', 'inactive'])->default('active');

            $table->timestamps();

           
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_group_member_products');
    }
};
