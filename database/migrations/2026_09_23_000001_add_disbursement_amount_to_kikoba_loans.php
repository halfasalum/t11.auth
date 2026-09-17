<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kikoba_loans', function (Blueprint $table) {
            // What actually leaves the group's account at disbursement time.
            // Equal to approved_amount for an 'add_on' interest product, but
            // less than it (approved_amount minus interest) for a
            // 'deducted_upfront' one. Computed and stored at approval time,
            // alongside the schedule, so it can't drift from what was
            // actually promised even if the product changes later.
            $table->decimal('disbursement_amount', 15, 2)->nullable()->after('approved_amount');
        });
    }

    public function down(): void
    {
        Schema::table('kikoba_loans', function (Blueprint $table) {
            $table->dropColumn('disbursement_amount');
        });
    }
};
