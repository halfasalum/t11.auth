<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_contact_id')->constrained()->onDelete('cascade');
            $table->string('company_name');
            $table->string('email');
            $table->string('phone');
            $table->string('region');
            $table->string('ceo_name');
            $table->enum('registration_type', ['self', 'assisted'])->default('self');
            $table->enum('status', [
                'pending',      // Just submitted
                'processing',   // Admin is working on it
                'completed',    // Account created in TerminalXI
                'rejected',     // Rejected for some reason
            ])->default('pending');
            $table->string('terminalxi_account_id')->nullable(); // After account is created
            $table->timestamp('trial_starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();      // trial_starts_at + 7 days
            $table->text('notes')->nullable();                   // Admin notes
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_registrations');
    }
};