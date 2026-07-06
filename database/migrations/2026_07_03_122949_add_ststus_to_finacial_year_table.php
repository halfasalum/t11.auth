<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL requires redefining the full ENUM when adding new values
        DB::statement("ALTER TABLE kikoba_group_financial_years MODIFY COLUMN status ENUM(
           'upcoming', 'active', 'closed','terminated'
        ) NOT NULL DEFAULT 'upcoming'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE kikoba_group_financial_years MODIFY COLUMN status ENUM(
           'upcoming', 'active', 'closed'
        ) NOT NULL DEFAULT 'upcoming'");
    }
};
