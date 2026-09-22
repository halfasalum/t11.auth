<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Flags the one "Company Admin" role (and its one assignment row)
        // that registration auto-creates for every new company, so the
        // onboarding checklist can tell that apart from a role the
        // manager actually created/assigned themselves through the UI —
        // even if they later create another role with the same name, or
        // manually assign this very same auto-created role to someone
        // else (a real action, and a new row, not flagged).
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_system_default')->default(false)->after('status');
        });

        Schema::table('users_roles', function (Blueprint $table) {
            $table->boolean('is_system_default')->default(false)->after('user_role_status');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('is_system_default');
        });

        Schema::table('users_roles', function (Blueprint $table) {
            $table->dropColumn('is_system_default');
        });
    }
};
