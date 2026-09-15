<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KikobaMemberProductYearSummary extends Model
{
    use HasFactory;
 
    protected $fillable = [
        'kikoba_group_id',
        'group_financial_year_id',
        'kikoba_group_member_id',
        'kikoba_group_product_id',
        'product_type',
        'units',
        'unit_value',
        'total_amount',
        'total_paid_amount',
        'generated_at',
    ];

    protected $casts = [
        'units' => 'integer',
        'unit_value' => 'float',
        'total_amount' => 'float',
        'total_paid_amount' => 'float',
        'generated_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(KikobaGroup::class, 'kikoba_group_id');
    }

    public function groupFinancialYear(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupFinancialYear::class, 'group_financial_year_id');
    }

    public function groupMember(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupMember::class, 'kikoba_group_member_id');
    }

    public function groupProduct(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupProduct::class, 'kikoba_group_product_id');
    }

    /**
     * Difference between the expected (units * unit_value) and what was
     * actually paid — non-zero flags a reconciliation issue worth reviewing.
     */
    public function getVarianceAttribute(): float
    {
        return round($this->total_amount - $this->total_paid_amount, 2);
    }
}
