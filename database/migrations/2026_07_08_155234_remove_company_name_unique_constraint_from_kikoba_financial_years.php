<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('kikoba_financial_years', function (Blueprint $table) {
            // Drop the unique constraint
            $table->dropUnique(['name']);

            // Optional: Add a better index if needed
            // $table->index('company_id');
        });
    }

    public function down()
    {
        Schema::table('kikoba_financial_years', function (Blueprint $table) {
            $table->unique('name');
        });
    }
};
