<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_expiry_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('subscription_id')->constrained('subscriptions')->onDelete('cascade');
            $table->unsignedSmallInteger('days_remaining');
            $table->timestamp('sent_at');
            $table->timestamps();

            // One reminder per subscription per milestone — the scheduler
            // can run more than once without ever double-sending.
            $table->unique(['subscription_id', 'days_remaining'], 'sub_expiry_notif_sub_days_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_expiry_notifications');
    }
};
