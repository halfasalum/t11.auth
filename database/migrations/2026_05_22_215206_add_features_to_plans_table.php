<?php
// database/migrations/2026_05_22_000003_add_features_to_plans_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('plans', function (Blueprint $table) {
            // Feature toggles
            if (!Schema::hasColumn('plans', 'has_advanced_reports')) {
                $table->boolean('has_advanced_reports')->default(false)->after('trace_customer');
            }
            if (!Schema::hasColumn('plans', 'has_support_tickets')) {
                $table->boolean('has_support_tickets')->default(false)->after('has_advanced_reports');
            }
            if (!Schema::hasColumn('plans', 'has_api_access')) {
                $table->boolean('has_api_access')->default(false)->after('has_support_tickets');
            }
            if (!Schema::hasColumn('plans', 'has_export_data')) {
                $table->boolean('has_export_data')->default(false)->after('has_api_access');
            }
            if (!Schema::hasColumn('plans', 'has_mobile_app')) {
                $table->boolean('has_mobile_app')->default(false)->after('has_export_data');
            }
            if (!Schema::hasColumn('plans', 'has_audit_logs')) {
                $table->boolean('has_audit_logs')->default(false)->after('has_mobile_app');
            }
            if (!Schema::hasColumn('plans', 'has_custom_reports')) {
                $table->boolean('has_custom_reports')->default(false)->after('has_audit_logs');
            }
            if (!Schema::hasColumn('plans', 'has_multi_currency')) {
                $table->boolean('has_multi_currency')->default(false)->after('has_custom_reports');
            }
            if (!Schema::hasColumn('plans', 'has_bulk_operations')) {
                $table->boolean('has_bulk_operations')->default(false)->after('has_multi_currency');
            }
            if (!Schema::hasColumn('plans', 'has_priority_support')) {
                $table->boolean('has_priority_support')->default(false)->after('has_bulk_operations');
            }
        });
    }

    public function down()
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'has_advanced_reports',
                'has_support_tickets',
                'has_api_access',
                'has_export_data',
                'has_mobile_app',
                'has_audit_logs',
                'has_custom_reports',
                'has_multi_currency',
                'has_bulk_operations',
                'has_priority_support'
            ]);
        });
    }
};