<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('loan_payment_schedule', function (Blueprint $table) {
            $table->index('id');
            $table->index('loan_number');
            $table->index('payment_due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loan_payment_schedule', function (Blueprint $table) {
            $table->dropIndex('id');
            $table->dropIndex('loan_number');
            $table->dropIndex('payment_due_date');
        });
    }
};
