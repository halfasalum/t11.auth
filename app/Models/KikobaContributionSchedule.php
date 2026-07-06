<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KikobaContributionSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_member_product_id', 'kikoba_group_financial_year_id',
        'sequence', 'due_date', 'expected_amount', 'paid_amount',
        'status', 'penalty_applied',
    ];

    protected $casts = [
        'due_date' => 'date',
        'expected_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'penalty_applied' => 'boolean',
    ];

    public function memberProduct(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupMemberProduct::class, 'group_member_product_id');
    }

    public function groupFinancialYear(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupFinancialYear::class, 'kikoba_group_financial_year_id');
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(KikobaContribution::class, 'kikoba_contribution_schedule_id');
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(KikobaPenalty::class, 'kikoba_contribution_schedule_id');
    }

    public function getBalanceAttribute(): float
    {
        return (float) $this->expected_amount - (float) $this->paid_amount;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date->isPast() && $this->balance > 0 && $this->status !== 'waived';
    }
}
