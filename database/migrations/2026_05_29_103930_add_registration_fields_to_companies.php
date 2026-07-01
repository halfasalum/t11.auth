<?php
// database/migrations/2026_05_29_000001_add_registration_fields_to_companies.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'company_address')) {
                $table->text('company_address')->nullable();
            }
            if (!Schema::hasColumn('companies', 'company_city')) {
                $table->string('company_city')->nullable();
            }
            if (!Schema::hasColumn('companies', 'company_country')) {
                $table->string('company_country')->nullable();
            }
            if (!Schema::hasColumn('companies', 'registration_ip')) {
                $table->string('registration_ip')->nullable();
            }
            if (!Schema::hasColumn('companies', 'registration_token')) {
                $table->string('registration_token')->nullable()->unique();
            }
            if (!Schema::hasColumn('companies', 'registration_completed_at')) {
                $table->timestamp('registration_completed_at')->nullable();
            }
            if (!Schema::hasColumn('companies', 'registered_by')) {
                $table->foreignId('registered_by')->nullable()->constrained('users')->onDelete('set null');
            }
            if (!Schema::hasColumn('companies', 'trial_used')) {
                $table->boolean('trial_used')->default(false);
            }
        });
    }

    public function down()
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'company_address',
                'company_city',
                'company_country',
                'registration_ip',
                'registration_token',
                'registration_completed_at',
                'registered_by',
                'trial_used'
            ]);
        });
    }
};