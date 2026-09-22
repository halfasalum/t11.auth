<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per (subscription, milestone) reminder actually sent — the
 * uniqueness constraint on the table is what makes the daily scheduled
 * job idempotent if it ever runs twice for the same day.
 */
class SubscriptionExpiryNotification extends Model
{
    protected $table = 'subscription_expiry_notifications';

    protected $fillable = ['company_id', 'subscription_id', 'days_remaining', 'sent_at'];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
