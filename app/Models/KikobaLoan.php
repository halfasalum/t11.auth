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
        'company_id', 'kikoba_group_id', 'kikoba_group_member_id', 'kikoba_loan_product_id', 'kikoba_account_id',
        'loan_number', 'share_value_at_application', 'multiplier', 'eligible_amount', 'requested_amount',
        'loan_period', 'purpose', 'document_path', 'notes', 'status', 'applied_by',
        'approved_amount', 'disbursement_amount', 'upfront_interest_amount', 'start_date', 'approved_by', 'approved_at',
        'disbursed_at', 'disbursed_by',
        'interest_claimed_amount', 'last_claimed_financial_year_id', 'interest_claimed_at',
        'closed_at', 'closed_by', 'closure_reason',
        'rejected_by', 'rejected_at', 'rejection_reason',
    ];

    protected $casts = [
        'share_value_at_application' => 'decimal:2',
        'multiplier' => 'decimal:2',
        'eligible_amount' => 'decimal:2',
        'requested_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'disbursement_amount' => 'decimal:2',
        'upfront_interest_amount' => 'decimal:2',
        'interest_claimed_amount' => 'decimal:2',
        'loan_period' => 'integer',
        'start_date' => 'date:Y-m-d',
        'approved_at' => 'datetime',
        'disbursed_at' => 'datetime',
        'closed_at' => 'datetime',
        'rejected_at' => 'datetime',
        'interest_claimed_at' => 'datetime',
    ];

    protected $appends = ['interest_total', 'total_loan', 'paid_amount', 'balance', 'is_overdue', 'overdue_amount'];

    // Any status past approval has a real, generated schedule to report on.
    public const SCHEDULED_STATUSES = ['active', 'completed', 'early_settled', 'defaulted', 'written_off'];

    // These are only meaningful once a schedule exists — null before that
    // since interest isn't computed until approval. All three fold in
    // upfront_interest_amount ('deducted_upfront' products only — 0/null
    // for 'add_on') since that interest is real but lives outside the
    // schedule itself, collected once at disbursement instead of spread
    // across installments.
    public function getInterestTotalAttribute(): ?float
    {
        if (! in_array($this->status, self::SCHEDULED_STATUSES, true)) {
            return null;
        }

        return round((float) $this->schedules->sum('interest_amount') + (float) $this->upfront_interest_amount, 2);
    }

    public function getTotalLoanAttribute(): ?float
    {
        if (! in_array($this->status, self::SCHEDULED_STATUSES, true)) {
            return null;
        }

        // Full cost of credit: the schedule's principal (always the full
        // approved amount) + interest, wherever that interest is collected.
        return round((float) $this->schedules->sum('total_amount') + (float) $this->upfront_interest_amount, 2);
    }

    public function getPaidAmountAttribute(): ?float
    {
        if (! in_array($this->status, self::SCHEDULED_STATUSES, true)) {
            return null;
        }

        // Upfront interest counts as paid only once it's actually been
        // collected, i.e. once the loan is disbursed — not merely approved.
        $upfrontPaid = $this->disbursed_at !== null ? (float) $this->upfront_interest_amount : 0.0;

        return round((float) $this->schedules->sum('paid_amount') + $upfrontPaid, 2);
    }

    public function getBalanceAttribute(): ?float
    {
        if ($this->total_loan === null) {
            return null;
        }

        return round($this->total_loan - $this->paid_amount, 2);
    }

    // Only a currently-active (disbursed-or-not, still-open) loan can be
    // overdue — a closed loan (completed/early_settled/defaulted/written_off)
    // isn't "overdue" anymore regardless of its schedule's due dates.
    public function getIsOverdueAttribute(): bool
    {
        return $this->overdue_amount > 0;
    }

    public function getOverdueAmountAttribute(): float
    {
        if ($this->status !== 'active') {
            return 0.0;
        }

        $today = now()->toDateString();

        return round(
            (float) $this->schedules
                ->filter(fn ($s) => $s->due_date->toDateString() < $today)
                ->sum(fn ($s) => max(0, (float) $s->total_amount - (float) $s->paid_amount)),
            2
        );
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

    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(KikobaLoanRepayment::class)->orderByDesc('paid_date');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(KikobaAccount::class, 'kikoba_account_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(KikobaLoanSchedule::class)->orderBy('installment_no');
    }

    public function lastClaimedFinancialYear(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupFinancialYear::class, 'last_claimed_financial_year_id');
    }
}
