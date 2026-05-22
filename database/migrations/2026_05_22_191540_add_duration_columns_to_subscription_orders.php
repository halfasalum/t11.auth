<?php
// database/migrations/2026_05_22_000001_add_duration_columns_to_subscription_orders.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('subscription_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('subscription_orders', 'duration_months')) {
                $table->integer('duration_months')->default(1)->after('plan_id');
            }
            if (!Schema::hasColumn('subscription_orders', 'subtotal')) {
                $table->decimal('subtotal', 12, 2)->nullable()->after('amount');
            }
            if (!Schema::hasColumn('subscription_orders', 'discount')) {
                $table->decimal('discount', 12, 2)->default(0)->after('subtotal');
            }
        });
    }

    public function down()
    {
        Schema::table('subscription_orders', function (Blueprint $table) {
            $table->dropColumn(['duration_months', 'subtotal', 'discount']);
        });
    }
};