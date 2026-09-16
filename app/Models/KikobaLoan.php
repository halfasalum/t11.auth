<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KikobaLoan extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'kikoba_group_id', 'kikoba_group_member_id', 'kikoba_loan_product_id',
        'loan_number', 'share_value_at_application', 'multiplier', 'eligible_amount', 'requested_amount',
        'loan_period', 'purpose', 'document_path', 'notes', 'status', 'applied_by',
    ];

    protected $casts = [
        'share_value_at_application' => 'decimal:2',
        'multiplier' => 'decimal:2',
        'eligible_amount' => 'decimal:2',
        'requested_amount' => 'decimal:2',
        'loan_period' => 'integer',
    ];

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
}
