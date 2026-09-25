<?php

namespace App\Observers;

use App\Models\Company;
use App\Models\Subscription;

/**
 * Keeps a company's own status in sync with its subscription, in both
 * directions, from a single place — so every place a Subscription gets
 * saved (the daily expiry-check command, AzamPay auto-activation, admin
 * approval, manual renewal, ...) stays correct without each of them
 * having to remember to touch the company row too.
 *
 * A company that was manually suspended or deleted is left alone either
 * way — those are deliberate admin actions, not something a subscription
 * change should silently override.
 */
class SubscriptionObserver
{
    public function saved(Subscription $subscription): void
    {
        $company = $subscription->company;

        if (! $company) {
            return;
        }

        if ($subscription->status === 'active' && $company->company_status === Company::STATUS_EXPIRED) {
            $company->company_status = Company::STATUS_ACTIVE;
            $company->save();
        } elseif ($subscription->status === 'expired' && $company->company_status === Company::STATUS_ACTIVE) {
            $company->company_status = Company::STATUS_EXPIRED;
            $company->save();
        }
    }
}
