<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kikoba_loans', function (Blueprint $table) {
            // Shared by every terminal transition reached from 'active':
            // completed (organic full repayment), early_settled (paid off
            // ahead of the final scheduled due date), defaulted, written_off.
            // closure_reason is required for defaulted/written_off, optional
            // for early_settled, unused for an organic completion.
            $table->timestamp('closed_at')->nullable()->after('disbursed_by');
            $table->foreignId('closed_by')->nullable()->after('closed_at')
                ->constrained('users')->nullOnDelete();
            $table->text('closure_reason')->nullable()->after('closed_by');
        });

        Schema::table('kikoba_loans', function (Blueprint $table) {
            $table->enum('status', [
                'pending', 'active', 'completed', 'early_settled',
                'defaulted', 'written_off', 'rejected', 'cancelled',
            ])->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('kikoba_loans', function (Blueprint $table) {
            $table->enum('status', ['pending', 'active', 'rejected', 'cancelled'])
                ->default('pending')->change();
        });

        Schema::table('kikoba_loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['closed_at', 'closure_reason']);
        });
    }
};
