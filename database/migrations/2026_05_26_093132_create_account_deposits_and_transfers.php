<?php
// database/migrations/2026_05_26_000001_create_account_deposits_and_transfers.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('account_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->date('deposit_date');
            $table->string('reference_number', 100)->nullable();
            $table->string('payment_method', 50)->default('cash');
            $table->text('description')->nullable();
            $table->foreignId('registered_by')->constrained('users');
            $table->foreignId('company_id')->constrained('companies');
            $table->timestamps();
            $table->index(['account_id']);
            $table->index(['deposit_date']);
            $table->index('reference_number');
        });

        Schema::create('account_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_account_id')->constrained('accounts')->onDelete('cascade');
            $table->foreignId('to_account_id')->constrained('accounts')->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->date('transfer_date');
            $table->string('reference_number', 100)->nullable();
            $table->text('description')->nullable();
            $table->foreignId('registered_by')->constrained('users');
            $table->foreignId('company_id')->constrained('companies');
            $table->timestamps();
            $table->index(['from_account_id']);
            $table->index(['to_account_id']);
            $table->index(['transfer_date']);
            $table->index('reference_number');
        });
    }

    public function down()
    {
        Schema::dropIfExists('account_transfers');
        Schema::dropIfExists('account_deposits');
    }
};