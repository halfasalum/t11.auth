<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KikobaLoanRepayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'kikoba_loan_id', 'kikoba_loan_schedule_id',
        'amount', 'paid_date', 'reference', 'payment_method', 'notes', 'received_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_date' => 'date:Y-m-d',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(KikobaLoan::class, 'kikoba_loan_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(KikobaLoanSchedule::class, 'kikoba_loan_schedule_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
