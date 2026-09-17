<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KikobaLoanSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'kikoba_loan_id', 'installment_no', 'due_date',
        'principal_amount', 'interest_amount', 'total_amount',
        'paid_amount', 'status',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'principal_amount' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(KikobaLoan::class, 'kikoba_loan_id');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(KikobaLoanRepayment::class);
    }
}
