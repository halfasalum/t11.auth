<?php
// database/migrations/2026_05_26_000005_create_notification_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->nullable()->constrained()->onDelete('set null');
            $table->string('type'); // overdue, default, write_off, foreclosure, reminder
            $table->text('message');
            $table->string('recipient_type'); // loan_officer, branch_manager, customer, admin
            $table->foreignId('recipient_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('recipient_phone')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('channel')->default('database'); // database, sms, email, push
            $table->boolean('is_sent')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['loan_id', 'type']);
            $table->index(['recipient_type', 'is_sent']);
            $table->index('sent_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('notification_logs');
    }
};