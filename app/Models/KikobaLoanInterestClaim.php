<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per (loan, cycle) a financial-year close finalize() actually
 * claimed interest for. KikobaLoan.interest_claimed_amount is the fast-read
 * running total; this table is the source of truth behind it, letting
 * KikobaFinancialYearCloseReportService::unlock() reverse exactly what one
 * cycle claimed without touching amounts claimed in other cycles.
 */
class KikobaLoanInterestClaim extends Model
{
    use HasFactory;

    protected $fillable = [
        'kikoba_loan_id', 'group_financial_year_id', 'amount', 'claimed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'claimed_at' => 'datetime',
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(KikobaLoan::class, 'kikoba_loan_id');
    }

    public function groupFinancialYear(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupFinancialYear::class, 'group_financial_year_id');
    }
}
