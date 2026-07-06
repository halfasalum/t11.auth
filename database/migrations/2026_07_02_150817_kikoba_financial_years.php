<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kikoba_financial_years', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            $table->string('name'); // e.g. "FY 2026" or "2026/2027"
            $table->date('start_date');
            $table->date('end_date');

            $table->enum('status', ['upcoming', 'active', 'closed'])->default('upcoming');
            $table->boolean('is_current')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id']);
            $table->unique(['name']);
            $table->index(['company_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kikoba_financial_years');
    }
};
