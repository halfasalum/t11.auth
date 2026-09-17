<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // One account per group for now (phase 1) — keeps auto-posting
            // from contributions/loan approvals unambiguous. Multiple
            // accounts per group is a natural later phase if ever needed.
            $table->foreignId('kikoba_group_id')
                ->unique()
                ->constrained('kikoba_groups')
                ->cascadeOnDelete();

            $table->string('account_name');
            $table->string('account_number')->unique();
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('currency', 3)->default('TZS');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('description')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_accounts');
    }
};
