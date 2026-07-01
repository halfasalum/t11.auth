<?php
// database/seeders/TrialPlanSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Plan;

class TrialPlanSeeder extends Seeder
{
    public function run()
    {
        // Create Trial Plan if not exists
        Plan::firstOrCreate(
            ['name' => 'Trial'],
            [
                'price' => 0,
                'customer_limit' => 10,
                'branch_limit' => 1,
                'zone_limit' => 2,
                'user_limit' => 3,
                'loans_limit' => 20,
                'description' => '7-day trial plan to explore our platform',
                'telegram_notifications' => true,
                'sms_notifications' => true,
                'trace_customer' => true,
                'has_advanced_reports' => true,
                'has_support_tickets' => true,
                'has_api_access' => false,
                'has_export_data' => true,
                'has_mobile_app' => true,
                'has_audit_logs' => true,
                'has_custom_reports' => false,
                'has_multi_currency' => false,
                'has_bulk_operations' => false,
                'has_priority_support' => false,
            ]
        );
    }
}