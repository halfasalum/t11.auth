<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL requires redefining the full ENUM when adding new values
        DB::statement("ALTER TABLE whatsapp_conversations MODIFY COLUMN state ENUM(
            'new',
            'awaiting_choice',
            'registration_menu',
            'training_menu',
            'self_registration',
            'assisted_registration',
            'collecting_details',
            'completed',
            'info_requested',
            'support_requested',
            'pricing_requested',
            'training_requested',
            'support_completed',
            'pricing_completed',
            'training_completed'
        ) NOT NULL DEFAULT 'new'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE whatsapp_conversations MODIFY COLUMN state ENUM(
            'new',
            'awaiting_choice',
            'self_registration',
            'assisted_registration',
            'collecting_details',
            'completed',
            'info_requested',
            'support_requested',
            'pricing_requested',
            'training_requested',
            'support_completed',
            'pricing_completed',
            'training_completed',
        ) NOT NULL DEFAULT 'new'");
    }
};
