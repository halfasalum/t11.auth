<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KikobaGroupMemberProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'kikoba_group_member_id', 'kikoba_group_product_id',
        'units', 'enrolled_date', 'exit_date', 'status',
    ];

    protected $casts = [
        'enrolled_date' => 'date',
        'exit_date' => 'date',
    ];

    public function groupMember(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupMember::class, 'kikoba_group_member_id');
    }

    public function groupProduct(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupProduct::class, 'kikoba_group_product_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(KikobaContributionSchedule::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(KikobaContribution::class);
    }

    // Resolves effective value using group-level override if set, else product default
    public function getEffectiveValueAttribute(): float
    {
        return $this->groupProduct->effective_value;
    }

    public function getExpectedAmountAttribute(): float
    {
        return $this->effective_value * $this->units;
    }
}
