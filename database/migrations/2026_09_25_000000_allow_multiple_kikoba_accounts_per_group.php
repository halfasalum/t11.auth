<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kikoba_accounts', function (Blueprint $table) {
            // The unique index backs the foreign key too, so it has to go
            // first — MySQL won't drop an index a FK still depends on.
            $table->dropForeign('kikoba_accounts_kikoba_group_id_foreign');
            $table->dropUnique('kikoba_accounts_kikoba_group_id_unique');
        });

        Schema::table('kikoba_accounts', function (Blueprint $table) {
            $table->index('kikoba_group_id');
            $table->foreign('kikoba_group_id')->references('id')->on('kikoba_groups')->cascadeOnDelete();

            // The account auto-posting (contribution credits, repayment
            // credits when a loan's own disbursement account is unknown)
            // targets when a group has more than one — exactly one per
            // group. Explicit choices (loan disbursement, manual deposit/
            // withdraw/transfer) are unaffected and always name an account.
            $table->boolean('is_primary')->default(false)->after('status');
        });

        // Every existing account was, by the old one-per-group constraint,
        // already its group's only account — so it's unambiguously primary.
        DB::table('kikoba_accounts')->update(['is_primary' => true]);
    }

    public function down(): void
    {
        Schema::table('kikoba_accounts', function (Blueprint $table) {
            $table->dropColumn('is_primary');
            $table->dropForeign('kikoba_accounts_kikoba_group_id_foreign');
            $table->dropIndex(['kikoba_group_id']);
        });

        Schema::table('kikoba_accounts', function (Blueprint $table) {
            $table->unique('kikoba_group_id', 'kikoba_accounts_kikoba_group_id_unique');
            $table->foreign('kikoba_group_id')->references('id')->on('kikoba_groups')->cascadeOnDelete();
        });
    }
};
