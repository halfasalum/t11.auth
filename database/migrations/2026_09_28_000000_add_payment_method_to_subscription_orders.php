<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_orders', function (Blueprint $table) {
            // 'manual' (existing bank-transfer + receipt-upload flow) or
            // 'mobile_money' (AzamPay checkout — see payment_transactions).
            // receipt_number only makes sense for the manual path now.
            $table->string('payment_method')->default('manual')->after('currency');
        });

        // doctrine/dbal isn't installed, so modify the column via raw SQL
        // instead of Blueprint::change().
        DB::statement('ALTER TABLE subscription_orders MODIFY receipt_number VARCHAR(255) NULL');
    }

    public function down(): void
    {
        Schema::table('subscription_orders', function (Blueprint $table) {
            $table->dropColumn('payment_method');
        });

        DB::statement("UPDATE subscription_orders SET receipt_number = '' WHERE receipt_number IS NULL");
        DB::statement('ALTER TABLE subscription_orders MODIFY receipt_number VARCHAR(255) NOT NULL');
    }
};
