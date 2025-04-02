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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->char("company_name", length:254);
            $table->char("company_phone", length:12)->nullable();
            $table->char("company_email", length:254)->nullable();
            $table->foreignId("subscription")->nullable()->default(1);
            $table->foreignId("company_status")->nullable()->default(2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
