<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LoanPaymentSchedules extends Model
{
    protected $connection = 'loan_db';
    protected $table = 'loan_payment_schedule';
    
}
