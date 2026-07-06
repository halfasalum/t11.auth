<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_group_financial_years', function (Blueprint $table) {
            $table->id();

            $table->foreignId('kikoba_group_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('kikoba_financial_year_id')
                ->constrained('kikoba_financial_years')
                ->cascadeOnDelete();

            // group's own cycle within this financial year, in case it doesn't
            // run the full FY span (e.g. joined mid-year)
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            $table->enum('status', ['upcoming', 'active', 'closed'])->default('active');

            $table->timestamps();

            $table->index(['kikoba_financial_year_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_group_financial_years');
    }
};
