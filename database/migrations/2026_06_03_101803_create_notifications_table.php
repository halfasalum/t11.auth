<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id'); // Who is this for?
            $table->string('title');               // e.g., "Loan Approved"
            $table->text('body');                  // e.g., "Your loan of $500 has been approved."
            $table->json('data')->nullable();      // Extra data, like a loan ID
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
