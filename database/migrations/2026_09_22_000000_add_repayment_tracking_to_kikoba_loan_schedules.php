<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kikoba_loan_schedules', function (Blueprint $table) {
            $table->decimal('paid_amount', 15, 2)->default(0)->after('total_amount');
            $table->enum('status', ['pending', 'partial', 'paid'])->default('pending')->after('paid_amount');
        });
    }

    public function down(): void
    {
        Schema::table('kikoba_loan_schedules', function (Blueprint $table) {
            $table->dropColumn(['paid_amount', 'status']);
        });
    }
};
