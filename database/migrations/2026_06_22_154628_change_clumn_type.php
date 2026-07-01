<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_workflow_logs', function (Blueprint $table) {
            $table->dropForeign(['loan_number']);
            $table->string('loan_number', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::table('loan_workflow_logs', function (Blueprint $table) {
            $table->bigInteger('loan_number')->unsigned()->change();
            $table->foreign('loan_number')->references('id')->on('loans')->onDelete('cascade');
        });
    }
};
