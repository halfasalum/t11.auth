<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KikobaAccountTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'kikoba_account_id', 'kikoba_group_id',
        'transaction_type', 'amount', 'opening_balance', 'closing_balance', 'transaction_date',
        'source', 'kikoba_contribution_id', 'kikoba_loan_id',
        'reference_number', 'description', 'registered_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'transaction_date' => 'date:Y-m-d',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(KikobaAccount::class, 'kikoba_account_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(KikobaGroup::class, 'kikoba_group_id');
    }

    public function contribution(): BelongsTo
    {
        return $this->belongsTo(KikobaContribution::class, 'kikoba_contribution_id');
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(KikobaLoan::class, 'kikoba_loan_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
