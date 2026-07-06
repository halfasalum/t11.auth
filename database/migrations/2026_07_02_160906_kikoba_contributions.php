<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_contributions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('group_member_product_id')
                ->constrained('kikoba_group_member_products')
                ->cascadeOnDelete();

            $table->foreignId('contribution_schedule_id')
                ->nullable()
                ->constrained('kikoba_contribution_schedules')
                ->nullOnDelete();

            $table->decimal('amount', 15, 2);
            $table->date('paid_date');

            $table->string('reference')->nullable(); // receipt no / transaction ref
            $table->string('payment_method')->nullable(); // cash, mobile money, bank, etc.

            $table->foreignId('received_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['group_member_product_id']);
            $table->index(['contribution_schedule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_contributions');
    }
};
