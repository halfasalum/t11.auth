<?php
// database/migrations/2026_05_26_000006_add_workflow_columns_to_loans_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('loans', function (Blueprint $table) {
            if (!Schema::hasColumn('loans', 'written_off_date')) {
                // Write Off columns
                $table->timestamp('written_off_date')->nullable()->after('end_date');
                $table->decimal('written_off_amount', 15, 2)->default(0)->after('written_off_date');
                $table->text('written_off_reason')->nullable()->after('written_off_amount');
                $table->boolean('written_off_by_system')->default(false)->after('written_off_reason');
            }

            if (!Schema::hasColumn('loans', 'defaulted_date')) {
                // Default columns
                $table->timestamp('defaulted_date')->nullable()->after('written_off_by_system');
                $table->text('defaulted_reason')->nullable()->after('defaulted_date');
                $table->boolean('defaulted_by_system')->default(false)->after('defaulted_reason');
            }

            if (!Schema::hasColumn('loans', 'foreclosure_date')) {
                // Foreclosure columns
                $table->timestamp('foreclosure_date')->nullable()->after('defaulted_by_system');
                $table->string('foreclosure_status')->nullable()->after('foreclosure_date'); // initiated, in_progress, completed
                $table->text('foreclosure_reason')->nullable()->after('foreclosure_status');
                $table->boolean('foreclosure_initiated_by_system')->default(false)->after('foreclosure_reason');
                $table->timestamp('foreclosure_notice_date')->nullable()->after('foreclosure_initiated_by_system');
                $table->timestamp('foreclosure_redemption_date')->nullable()->after('foreclosure_notice_date');
                $table->decimal('foreclosure_sale_amount', 15, 2)->default(0)->after('foreclosure_redemption_date');
                $table->timestamp('foreclosure_completed_at')->nullable()->after('foreclosure_sale_amount');
            }
            // Overdue columns
            if (!Schema::hasColumn('loans', 'overdue_date')) {
                $table->timestamp('overdue_date')->nullable()->after('foreclosure_completed_at');
                $table->integer('days_overdue')->default(0)->after('overdue_date');
            }

            if (!Schema::hasColumn('loans', 'restructured_at')) {
                // Restructure columns
                $table->timestamp('restructured_at')->nullable()->after('days_overdue');
                $table->integer('restructured_by')->nullable()->after('restructured_at');
                $table->text('restructure_reason')->nullable()->after('restructured_by');
                $table->integer('restructure_count')->default(0)->after('restructure_reason');
            }

            if (!Schema::hasColumn('loans', 'extension_count')) {
                // Extension columns
                $table->integer('extension_count')->default(0)->after('restructure_count');
                $table->text('extension_reason')->nullable()->after('extension_count');
                $table->timestamp('original_end_date')->nullable()->after('extension_reason');
            }

            if (!Schema::hasColumn('loans', 'settlement_date')) {
                // Early settlement columns
                $table->timestamp('settlement_date')->nullable()->after('original_end_date');
                $table->decimal('settlement_amount', 15, 2)->default(0)->after('settlement_date');
                $table->decimal('settlement_discount', 15, 2)->default(0)->after('settlement_amount');
            }

            if (!Schema::hasColumn('loans', 'payment_holiday_months')) {
                // Payment holiday columns
                $table->integer('payment_holiday_months')->default(0)->after('settlement_discount');
                $table->text('payment_holiday_reason')->nullable()->after('payment_holiday_months');
            }

            if (!Schema::hasColumn('loans', 'funding_account_id')) {
                // Funding account
                $table->foreignId('funding_account_id')->nullable()->after('payment_holiday_reason')->constrained('accounts')->onDelete('set null');
                $table->timestamp('disbursed_at')->nullable()->after('funding_account_id');
                $table->foreignId('disbursed_by')->nullable()->after('disbursed_at')->constrained('users')->onDelete('set null');
            }

            // Indexes for performance

            $table->index('defaulted_date');
            $table->index('written_off_date');
            $table->index('foreclosure_status');
            $table->index('overdue_date');
        });
    }

    public function down()
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropColumn([
                'written_off_date',
                'written_off_amount',
                'written_off_reason',
                'written_off_by_system',
                'defaulted_date',
                'defaulted_reason',
                'defaulted_by_system',
                'foreclosure_date',
                'foreclosure_status',
                'foreclosure_reason',
                'foreclosure_initiated_by_system',
                'foreclosure_notice_date',
                'foreclosure_redemption_date',
                'foreclosure_sale_amount',
                'foreclosure_completed_at',
                'overdue_date',
                'days_overdue',
                'restructured_at',
                'restructured_by',
                'restructure_reason',
                'restructure_count',
                'extension_count',
                'extension_reason',
                'original_end_date',
                'settlement_date',
                'settlement_amount',
                'settlement_discount',
                'payment_holiday_months',
                'payment_holiday_reason',
                'funding_account_id',
                'disbursed_at',
                'disbursed_by',
            ]);


            $table->dropIndex(['defaulted_date']);
            $table->dropIndex(['written_off_date']);
            $table->dropIndex(['foreclosure_status']);
            $table->dropIndex(['overdue_date']);
        });
    }
};
