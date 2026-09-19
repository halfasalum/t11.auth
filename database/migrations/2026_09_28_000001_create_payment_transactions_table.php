<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per payment ATTEMPT (as opposed to subscription_orders,
        // which is the one subscription request) — a mobile-money push can
        // time out or be cancelled and get retried, and each attempt should
        // be individually auditable against what the gateway actually says
        // happened.
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number')->unique();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('subscription_order_id')->nullable()
                ->constrained('subscription_orders')->nullOnDelete();

            // 'azampay' today; room for 'manual'/other gateways later.
            $table->string('provider')->default('azampay');
            // 'mobile_money' | 'bank_transfer' | 'card' | 'manual'
            $table->string('payment_method')->default('mobile_money');
            // Which mobile network: Mpesa | Tigo | Airtel | Halopesa | Azampesa
            $table->string('mno_provider')->nullable();
            $table->string('msisdn')->nullable();

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('TZS');

            // Our own idempotency key, sent to AzamPay as externalId and
            // used to match its callback back to this row.
            $table->string('external_id')->unique();
            // AzamPay's own reference for the transaction, populated once
            // the checkout call and/or callback return one.
            $table->string('provider_transaction_id')->nullable();

            // pending (push sent, awaiting PIN) -> success | failed | cancelled
            $table->string('status')->default('pending');
            $table->text('status_message')->nullable();
            // Full raw callback body, kept for audit/debugging while the
            // exact AzamPay payload shape is still being confirmed.
            $table->json('raw_callback')->nullable();

            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index('provider_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
