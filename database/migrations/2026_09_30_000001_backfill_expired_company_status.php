<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time correction for companies whose subscription had already lapsed
 * before the Subscription <-> Company status sync (SubscriptionObserver)
 * existed to keep company_status current automatically. Only touches a
 * company that unambiguously matches: still marked active, has at least
 * one expired subscription, and has no currently active one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $companyIds = Company::where('company_status', Company::STATUS_ACTIVE)
            ->whereHas('subscriptions', function ($q) {
                $q->where('status', 'expired');
            })
            ->whereDoesntHave('activeSubscription')
            ->pluck('id');

        DB::table('companies')->whereIn('id', $companyIds)->update(['company_status' => Company::STATUS_EXPIRED]);
    }

    public function down(): void
    {
        // Not reversible with certainty — we don't know which of these
        // rows were genuinely active vs. already-stale before this ran.
    }
};
