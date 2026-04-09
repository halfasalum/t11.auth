<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Loans extends Model
{
    use HasFactory;
    protected $table = 'loans';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'product',
        'customer',
        'company',
        'loan_number',
        'principal_amount',
        'interest_amount',
        'total_loan',
        'penalty_amount',
        'start_date',
        'end_date',
        'loan_period',
        'status',
        'registered_by',
        'zone',
        'token',
        'loan_paid',
        'remarks',
        'principal_paid',
        'interest_paid',
        'loan_purpose',
        'recommendations',
        'reason',
        'loan_file',
        'loan_score',
        'is_defaulted'
    ];
}
