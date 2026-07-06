<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_penalties', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kikoba_contribution_schedule_id')
                ->constrained('kikoba_contribution_schedules')
                ->cascadeOnDelete();

            $table->foreignId('kikoba_group_member_id')
                ->constrained('kikoba_group_members')
                ->cascadeOnDelete();

            // the penalty-type product used to calculate the amount
            $table->foreignId('kikoba_group_product_id')
                ->constrained('kikoba_group_products')
                ->cascadeOnDelete();

            $table->decimal('amount', 15, 2);
            $table->date('issued_date');

            $table->enum('status', ['pending', 'paid', 'waived'])->default('pending');
            $table->date('paid_date')->nullable();

            $table->text('reason')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_penalties');
    }
};
