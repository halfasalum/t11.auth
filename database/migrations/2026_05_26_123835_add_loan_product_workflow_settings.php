<?php
// database/migrations/2026_05_26_000002_add_loan_product_workflow_settings.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('loans_products', function (Blueprint $table) {
            // Default period settings
            $table->integer('default_days_overdue')->nullable()->default(90)->after('interest_amount');
            $table->integer('default_missed_payments')->nullable()->default(3)->after('default_days_overdue');
            $table->integer('default_percentage_of_term')->nullable()->after('default_missed_payments');
            
            // Write off settings
            $table->boolean('write_off_enabled')->default(true)->after('default_percentage_of_term');
            $table->integer('write_off_days_overdue')->nullable()->default(180)->after('write_off_enabled');
            $table->integer('write_off_missed_payments')->nullable()->default(6)->after('write_off_days_overdue');
            $table->boolean('write_off_requires_approval')->default(true)->after('write_off_missed_payments');
            $table->string('write_off_approval_level')->nullable()->default('manager')->after('write_off_requires_approval');
            $table->boolean('write_off_auto_process')->default(false)->after('write_off_approval_level');
            $table->integer('write_off_recovery_attempts')->default(3)->after('write_off_auto_process');
            
            // Foreclosure settings
            $table->boolean('foreclosure_enabled')->default(false)->after('write_off_recovery_attempts');
            $table->integer('foreclosure_days_overdue')->nullable()->default(210)->after('foreclosure_enabled');
            $table->integer('foreclosure_missed_payments')->nullable()->default(7)->after('foreclosure_days_overdue');
            $table->boolean('foreclosure_requires_collateral')->default(true)->after('foreclosure_missed_payments');
            $table->boolean('foreclosure_legal_required')->default(true)->after('foreclosure_requires_collateral');
            $table->string('foreclosure_approval_level')->nullable()->default('manager')->after('foreclosure_legal_required');
            $table->integer('foreclosure_notice_days')->default(30)->after('foreclosure_approval_level');
            $table->integer('foreclosure_redemption_period')->default(30)->after('foreclosure_notice_days');
            
            // Restructure settings
            $table->boolean('restructure_enabled')->default(true)->after('foreclosure_redemption_period');
            $table->integer('restructure_days_overdue')->nullable()->default(30)->after('restructure_enabled');
            $table->integer('restructure_max_times')->default(2)->after('restructure_days_overdue');
            $table->string('restructure_approval_level')->nullable()->default('manager')->after('restructure_max_times');
            
            // Notification settings
            $table->integer('notify_on_overdue_days')->nullable()->default(7)->after('restructure_approval_level');
            $table->boolean('notify_on_default')->default(true)->after('notify_on_overdue_days');
            $table->boolean('notify_on_write_off')->default(true)->after('notify_on_default');
            $table->boolean('notify_on_foreclosure')->default(true)->after('notify_on_write_off');
            
            // Recovery settings
            $table->boolean('recovery_enabled')->default(true)->after('notify_on_foreclosure');
            $table->integer('recovery_max_attempts')->default(5)->after('recovery_enabled');
            $table->integer('recovery_assign_to_agency_days')->nullable()->default(120)->after('recovery_max_attempts');
        });
    }

    public function down()
    {
        Schema::table('loans_products', function (Blueprint $table) {
            $table->dropColumn([
                'default_days_overdue',
                'default_missed_payments',
                'default_percentage_of_term',
                'write_off_enabled',
                'write_off_days_overdue',
                'write_off_missed_payments',
                'write_off_requires_approval',
                'write_off_approval_level',
                'write_off_auto_process',
                'write_off_recovery_attempts',
                'foreclosure_enabled',
                'foreclosure_days_overdue',
                'foreclosure_missed_payments',
                'foreclosure_requires_collateral',
                'foreclosure_legal_required',
                'foreclosure_approval_level',
                'foreclosure_notice_days',
                'foreclosure_redemption_period',
                'restructure_enabled',
                'restructure_days_overdue',
                'restructure_max_times',
                'restructure_approval_level',
                'notify_on_overdue_days',
                'notify_on_default',
                'notify_on_write_off',
                'notify_on_foreclosure',
                'recovery_enabled',
                'recovery_max_attempts',
                'recovery_assign_to_agency_days',
            ]);
        });
    }
};