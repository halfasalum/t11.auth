<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LoanPaymentSchedules extends Model
{
    use HasFactory;

    protected $table = 'loan_payment_schedule';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'loan_number',
        'payment_principal_amount',
        'payment_interest_amount',
        'payment_total_amount',
        'payment_due_date',
        'status',
        'company',
        'branch',
        'zone',
        'is_penalty',
        'penalty_amount',
        'is_submitted',
        'overdue_flag'
    ];

    protected $casts = [
        'payment_due_date' => 'date',
        'payment_total_amount' => 'decimal:2',
        'payment_principal_amount' => 'decimal:2',
        'payment_interest_amount' => 'decimal:2',
        'penalty_amount' => 'decimal:2',
    ];



    /**
     * Get the loan associated with this payment schedule
     */
    public function loan()
    {
        return $this->belongsTo(Loans::class, 'loan_number', 'loan_number');
    }

    /**
     * Get the payments for this schedule
     */
    public function payments()
    {
        return $this->hasMany(PaymentSubmissions::class, 'schedule_id', 'id');
    }

    /**
     * Get the latest payment submission
     */
    public function latestPayment()
    {
        return $this->hasOne(PaymentSubmissions::class, 'schedule_id', 'id')
            ->latest('submitted_date');
    }

    /**
     * Get the customer through the customer zone
     */
    public function customerRelation()
    {
        return $this->belongsTo(Customers::class, 'customer', 'id');
    }

    /**
     * Alternative simpler relationship name
     */
    public function customer()
    {
        return $this->belongsTo(Customers::class, 'customer', 'id');
    }
}
