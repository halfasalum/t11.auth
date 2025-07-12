<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoanSchedules extends Model
{
    protected $connection = 'loan_db';
    protected $table = 'loan_payment_schedule';
}
