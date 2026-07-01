<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_contact_id')->constrained()->onDelete('cascade');
            $table->enum('state', [
                'new',                  // Just messaged, show menu
                'awaiting_choice',      // Menu sent, waiting for 1/2/3
                'self_registration',    // Chose 1 - filling own details
                'assisted_registration',// Chose 2 - admin will help
                'collecting_details',   // Actively sending company details
                'completed',            // Registration done
                'info_requested',       // Chose 3 - more info sent
            ])->default('new');
            $table->json('collected_data')->nullable(); // Partial registration data
            $table->timestamp('state_updated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
    }
};