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
        Schema::table('plans', function (Blueprint $table) {
            $table->integer('loans_limit')->default(0);
            $table->boolean('telegram_notifications')->default(false);
            $table->boolean('sms_notifications')->default(false);
            $table->boolean('trace_customer')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('loans_limit');
            $table->dropColumn('telegram_notifications');
            $table->dropColumn('sms_notifications');
            $table->dropColumn('trace_customer');
        });
    }
};
