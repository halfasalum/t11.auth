<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('kikoba_contributions', function (Blueprint $table) {
            $table->integer('units')->nullable()->default(0)->after('contribution_schedule_id');
            $table->decimal('unit_value', 15, 2)->nullable()->default(0)->after('units');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kikoba_contributions', function (Blueprint $table) {
            $table->dropColumn('units');
            $table->dropColumn('unit_value');
        });
    }
};
