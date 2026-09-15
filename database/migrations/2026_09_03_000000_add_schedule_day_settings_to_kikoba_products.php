<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kikoba_products', function (Blueprint $table) {
            // Used when submission_unit = week: 0=Sunday .. 6=Saturday
            $table->unsignedTinyInteger('submission_day_of_week')->nullable()->after('submission_frequency');

            // Used when submission_unit = month
            $table->enum('submission_month_option', ['specific_date', 'end_of_month'])
                ->nullable()
                ->after('submission_day_of_week');

            // Used when submission_month_option = specific_date: 1 .. 31
            // Months shorter than this fall back to their last day.
            $table->unsignedTinyInteger('submission_day_of_month')->nullable()->after('submission_month_option');
        });
    }

    public function down(): void
    {
        Schema::table('kikoba_products', function (Blueprint $table) {
            $table->dropColumn([
                'submission_day_of_week',
                'submission_month_option',
                'submission_day_of_month',
            ]);
        });
    }
};
