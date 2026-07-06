<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KikobaGroupProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'kikoba_group_id', 'kikoba_product_id',
        'value_override', 'min_unit_override', 'max_unit_override',
        'mandatory_override', 'status',
    ];

    protected $casts = [
        'value_override' => 'decimal:2',
        'mandatory_override' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(KikobaGroup::class, 'kikoba_group_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(KikobaProduct::class, 'kikoba_product_id');
    }

    public function memberProducts(): HasMany
    {
        return $this->hasMany(KikobaGroupMemberProduct::class);
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(KikobaPenalty::class);
    }

    public function getEffectiveValueAttribute(): float
    {
        return (float) ($this->value_override ?? $this->product->value);
    }

    public function getEffectiveMinUnitAttribute(): int
    {
        return (int) ($this->min_unit_override ?? $this->product->min_unit);
    }

    public function getEffectiveMaxUnitAttribute(): ?int
    {
        return $this->max_unit_override ?? $this->product->max_unit;
    }

    public function getEffectiveMandatoryAttribute(): bool
    {
        return (bool) ($this->mandatory_override ?? $this->product->mandatory_contribution);
    }
}
