<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class LoanPayments extends Model
{
    use HasFactory;
    protected $connection = 'loan_db';
    protected $table = 'loan_payments';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'schedule_id',
        'customer',
        'company',
        'branch',
        'zone',
        'loan_number',
        'payment_date',
        'amount_paid',
        'payment_method',
        'received_by',
        'officer_status',
        'branch_status',
        'manager_status',
        'status',
    ];
}
