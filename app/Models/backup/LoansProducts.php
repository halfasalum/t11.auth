<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LoansProducts extends Model
{
    use HasFactory;
    protected $table = 'loans_products';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'product_name',
        'company',
        'interest_mode',
        'interest_rate',
        'interest_threshold',
        'max_loan_period',
        'min_loan_period',
        'max_loan_amount',
        'min_loan_amount',
        'loan_period_unit',
        'repayment_interval',
        'repayment_interval_unit',
        'skip_sat',
        'skip_sun',
        'penalty_type',
        'fixed_penalty_amount',
        'penalty_percentage',
        'status',
        'registered_by',
        'interest_amount',
    ];
}
