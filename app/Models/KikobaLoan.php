<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KikobaLoan extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'kikoba_group_id', 'kikoba_group_member_id', 'kikoba_loan_product_id',
        'loan_number', 'share_value_at_application', 'multiplier', 'eligible_amount', 'requested_amount',
        'loan_period', 'purpose', 'document_path', 'notes', 'status', 'applied_by',
        'approved_amount', 'start_date', 'approved_by', 'approved_at',
        'rejected_by', 'rejected_at', 'rejection_reason',
    ];

    protected $casts = [
        'share_value_at_application' => 'decimal:2',
        'multiplier' => 'decimal:2',
        'eligible_amount' => 'decimal:2',
        'requested_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'loan_period' => 'integer',
        'start_date' => 'date:Y-m-d',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    protected $appends = ['interest_total', 'total_loan', 'paid_amount', 'balance'];

    // These four are only meaningful once a schedule exists (i.e. the loan is
    // active) — null before that since interest isn't computed until approval.
    public function getInterestTotalAttribute(): ?float
    {
        if ($this->status !== 'active') {
            return null;
        }

        return round((float) $this->schedules->sum('interest_amount'), 2);
    }

    public function getTotalLoanAttribute(): ?float
    {
        if ($this->status !== 'active' || $this->approved_amount === null) {
            return null;
        }

        return round((float) $this->approved_amount + $this->interest_total, 2);
    }

    // No repayment-recording phase exists yet, so every active loan is
    // currently unpaid — this is a real (if always-zero) value, not a stub.
    public function getPaidAmountAttribute(): ?float
    {
        return $this->status === 'active' ? 0.0 : null;
    }

    public function getBalanceAttribute(): ?float
    {
        if ($this->total_loan === null) {
            return null;
        }

        return round($this->total_loan - $this->paid_amount, 2);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(KikobaGroup::class, 'kikoba_group_id');
    }

    public function groupMember(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupMember::class, 'kikoba_group_member_id');
    }

    public function loanProduct(): BelongsTo
    {
        return $this->belongsTo(KikobaLoanProduct::class, 'kikoba_loan_product_id');
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(KikobaLoanSchedule::class)->orderBy('installment_no');
    }
}
