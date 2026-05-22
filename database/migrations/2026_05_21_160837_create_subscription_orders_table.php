<?php
// database/migrations/2026_05_21_000001_create_subscription_orders_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Subscription orders table for tracking payment requests
        Schema::create('subscription_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            $table->foreignId('plan_id')->constrained('plans')->onDelete('cascade');
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->onDelete('set null');

            // Payment details
            $table->string('receipt_number')->required();
            $table->string('receipt_file')->nullable(); // Path to uploaded receipt image
            $table->text('payment_notes')->nullable(); // Additional notes from client

            // Order details
            $table->decimal('amount', 10, 2);
            $table->string('currency')->default('TZS');
            $table->string('status')->default('pending'); // pending, verified, approved, rejected, cancelled
            $table->timestamp('payment_date')->nullable();

            // Approval details
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('admin_notes')->nullable();

            // Tracking
            $table->timestamp('start_date')->nullable();
            $table->timestamp('end_date')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index('order_number');
            $table->index('receipt_number');
        });

        // Add subscription_id to existing subscriptions table migration
        // If you need to track subscription periods separately
        Schema::table('subscriptions', function (Blueprint $table) {
            if (!Schema::hasColumn('subscriptions', 'subscription_order_id')) {
                $table->foreignId('subscription_order_id')->nullable()->after('id')
                    ->constrained('subscription_orders')->onDelete('set null');
            }
            if (!Schema::hasColumn('subscriptions', 'features')) {
                $table->json('features')->nullable()->after('end_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropForeign(['subscription_order_id']);
            $table->dropColumn(['subscription_order_id', 'features']);
        });
        Schema::dropIfExists('subscription_orders');
    }
};
