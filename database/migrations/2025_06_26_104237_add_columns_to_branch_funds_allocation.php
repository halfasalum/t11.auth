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
        Schema::table('branch_funds_allocation', function (Blueprint $table) {
            $table->foreignId('allocated_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_funds_allocation', function (Blueprint $table) {
            $table->dropForeign(['allocated_by']);
            $table->dropColumn('allocated_by');
        });
    }
};
