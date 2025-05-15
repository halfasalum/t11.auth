<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentSubmissions extends Model
{
    protected $connection = 'loan_db';
    protected $table = 'payment_submissions';
    
}
