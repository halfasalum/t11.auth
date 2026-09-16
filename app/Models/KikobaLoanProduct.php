<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KikobaLoanProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'description',
        'interest_mode', 'interest_rate', 'interest_amount',
        'min_loan_amount', 'max_loan_amount',
        'min_loan_period', 'max_loan_period', 'loan_period_unit',
        'repayment_interval', 'repayment_interval_unit',
        'skip_sat', 'skip_sun',
        'penalty_type', 'fixed_penalty_amount', 'penalty_percentage',
        'share_multipliers', 'status', 'created_by',
    ];

    protected $casts = [
        'interest_rate' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'min_loan_amount' => 'decimal:2',
        'max_loan_amount' => 'decimal:2',
        'min_loan_period' => 'integer',
        'max_loan_period' => 'integer',
        'repayment_interval' => 'integer',
        'skip_sat' => 'boolean',
        'skip_sun' => 'boolean',
        'fixed_penalty_amount' => 'decimal:2',
        'penalty_percentage' => 'decimal:2',
        'share_multipliers' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // e.g. "1x, 2x, 3x"
    public function getShareMultipliersLabelAttribute(): string
    {
        return collect($this->share_multipliers ?? [])
            ->map(fn ($m) => rtrim(rtrim(number_format((float) $m, 2), '0'), '.') . 'x')
            ->implode(', ');
    }

    // Eligible loan amount for a given paid-share value and a chosen multiplier,
    // capped by the product's absolute max_loan_amount when set.
    public function eligibleAmountFor(float $shareValue, float $multiplier): float
    {
        $amount = $shareValue * $multiplier;

        if ($this->max_loan_amount !== null) {
            $amount = min($amount, (float) $this->max_loan_amount);
        }

        return round($amount, 2);
    }
}
