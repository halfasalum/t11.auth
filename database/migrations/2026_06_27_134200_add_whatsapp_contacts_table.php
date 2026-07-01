<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->unique();         // e.g. 255799713285
            $table->string('name')->nullable();        // from WhatsApp profile
            $table->enum('status', ['active', 'blocked'])->default('active');
            $table->timestamp('last_seen')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_contacts');
    }
};