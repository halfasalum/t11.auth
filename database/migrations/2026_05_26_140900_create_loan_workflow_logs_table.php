<?php
// database/migrations/2026_05_26_000004_create_loan_workflow_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('loan_workflow_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_number')->constrained('loans')->onDelete('cascade');
            $table->string('action'); // default, write_off, foreclosure, overdue
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('created_by_system')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            
            $table->index(['loan_number', 'action']);
            $table->index('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('loan_workflow_logs');
    }
};