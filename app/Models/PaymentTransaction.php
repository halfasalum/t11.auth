<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One payment attempt against a SubscriptionOrder — a mobile-money push can
 * time out or be retried, so each attempt is its own auditable row against
 * what the gateway actually reports, distinct from the order itself.
 */
class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_number', 'company_id', 'subscription_order_id',
        'provider', 'payment_method', 'mno_provider', 'msisdn',
        'amount', 'currency', 'external_id', 'provider_transaction_id',
        'status', 'status_message', 'raw_callback',
        'initiated_at', 'completed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'raw_callback' => 'array',
        'initiated_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (PaymentTransaction $transaction) {
            if (! $transaction->transaction_number) {
                $transaction->transaction_number = 'PMT-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
            }
            if (! $transaction->external_id) {
                $transaction->external_id = (string) Str::uuid();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function subscriptionOrder(): BelongsTo
    {
        return $this->belongsTo(SubscriptionOrder::class);
    }
}
