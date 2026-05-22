<?php
// database/migrations/2026_05_23_000001_create_query_execution_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('query_execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->text('query');
            $table->json('bindings')->nullable();
            $table->string('query_type'); // SELECT, INSERT, UPDATE, DELETE, etc.
            $table->integer('row_count')->default(0);
            $table->float('execution_time', 8, 3);
            $table->string('status'); // success, error
            $table->text('error_message')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'created_at']);
            $table->index('query_type');
        });
    }

    public function down()
    {
        Schema::dropIfExists('query_execution_logs');
    }
};