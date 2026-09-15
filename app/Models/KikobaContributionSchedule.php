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
        'group_member_product_id',
        'group_financial_year_id',
        'sequence',
        'due_date',
        'expected_amount',
        'paid_amount',
        'status',
        'penalty_applied',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'expected_amount' => 'float',
        'paid_amount' => 'float',
        'penalty_applied' => 'boolean',
    ];

    public function memberProduct(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupMemberProduct::class, 'group_member_product_id');
    }

    public function groupFinancialYear(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupFinancialYear::class, 'group_financial_year_id');
    }



    public function groupMember()
    {
        return $this->hasOneThrough(
            KikobaGroupMember::class,
            KikobaGroupMemberProduct::class,
            'group_member_product_id',
            'kikoba_group_member_id'
        );
    }

    public function member()
    {
        return $this->hasOneThrough(
            KikobaMember::class,
            KikobaGroupMember::class,
            'group_member_product_id',   // on Schedule
            'kikoba_group_member_id',    // on GroupMemberProduct
            'id',                        // on Schedule
            'kikoba_member_id'           // on GroupMember
        );
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
