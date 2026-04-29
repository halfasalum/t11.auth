<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentSubmissions extends Model
{
    use HasFactory;
    
    protected $table = 'payment_submissions';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'loan_number',
        'schedule_id',
        'company',
        'branch',
        'zone',
        'amount',
        'submitted_date',
        'submitted_by',
        'submission_status',
        'is_sms_sent',
        'is_sms_failed',
        'principal_interest_update_yn',
        'batch_processed',
        'update_loan_paid_yn',
        'paid_principal',
        'paid_interest',
        'paid_account'
    ];

    protected $casts = [
        'schedule_id' => 'integer',
        'amount'=> 'decimal:2',
        'paid_principal'=> 'decimal:2',
        'paid_interest'=> 'decimal:2',
        // ... other casts
    ];

    public function loan()
    {
        return $this->belongsTo(Loans::class, 'loan_number', 'loan_number');
    }

    public function schedule()
    {
        return $this->belongsTo(LoanPaymentSchedules::class, 'schedule_id', 'id');
    }

    public function customer()
    {
        return $this->belongsTo(Customers::class, 'customer_id', 'id');
    }
}
