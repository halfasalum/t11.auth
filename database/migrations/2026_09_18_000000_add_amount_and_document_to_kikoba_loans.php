<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kikoba_loans', function (Blueprint $table) {
            // The ceiling (share value x multiplier, capped by the product's max)
            // is now kept separate from what was actually requested, since the
            // applicant may ask for less than the ceiling but never more.
            $table->decimal('eligible_amount', 15, 2)->after('multiplier');

            // Optional supporting document (scanned application form, ID, etc.)
            $table->string('document_path')->nullable()->after('purpose');
        });

        // Backfill: for any rows created before this migration, requested_amount
        // was always the full eligible amount.
        DB::table('kikoba_loans')->update(['eligible_amount' => DB::raw('requested_amount')]);
    }

    public function down(): void
    {
        Schema::table('kikoba_loans', function (Blueprint $table) {
            $table->dropColumn(['eligible_amount', 'document_path']);
        });
    }
};
