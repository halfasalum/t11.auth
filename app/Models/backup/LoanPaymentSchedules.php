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
}
