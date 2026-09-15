<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL requires redefining the full ENUM when adding new values
        DB::statement("ALTER TABLE kikoba_group_financial_years MODIFY COLUMN status ENUM(
           'upcoming', 'active','pending', 'closed','terminated'
        ) NOT NULL DEFAULT 'upcoming'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE kikoba_group_financial_years MODIFY COLUMN status ENUM(
           'upcoming', 'active', 'closed','terminated'
        ) NOT NULL DEFAULT 'upcoming'");
    }
};
