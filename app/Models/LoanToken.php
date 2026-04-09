<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LoanToken extends Model
{
     use HasFactory;
     protected $connection = 'loan_db';
    protected $table = 'loan_sms_token';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'loan_customer',
        'loan_sms',
        'loan_token',
        'status',
        'user',
        'company'
    ];
}
