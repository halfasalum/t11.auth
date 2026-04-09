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
}
