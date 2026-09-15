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
        Schema::table('user_logs', function (Blueprint $table) {
            $table->string('method', 10)->nullable()->after('action');
            $table->string('route')->nullable()->after('method');
            $table->unsignedSmallInteger('status_code')->nullable()->after('route');

            // Allow activity entries that originate outside an authenticated
            // request (queued jobs, console commands, unresolved token).
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->unsignedBigInteger('company')->nullable()->change();

            $table->index(['user_id', 'created_at']);
            $table->index(['company', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['company', 'created_at']);
            $table->dropColumn(['method', 'route', 'status_code']);
        });
    }
};
