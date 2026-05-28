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
        Schema::table('loans', function (Blueprint $table) {
            $table->foreignId('written_off_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('foreclosed_by')->nullable()->constrained('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropForeign(['written_off_by']);
            $table->dropForeign(['foreclosed_by']);
            $table->dropColumn(['written_off_by', 'foreclosed_by']);
        });
    }
};
