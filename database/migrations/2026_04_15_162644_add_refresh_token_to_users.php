// database/migrations/2026_04_15_000000_add_refresh_token_to_users.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('refresh_token')->nullable()->after('password');
            $table->timestamp('refresh_token_expiry')->nullable()->after('refresh_token');
            $table->timestamp('password_changed_at')->nullable()->after('refresh_token_expiry');
            $table->timestamp('password_expiry_date')->nullable()->after('password_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'refresh_token',
                'refresh_token_expiry',
                'password_changed_at',
                'password_expiry_date'
            ]);
        });
    }
};