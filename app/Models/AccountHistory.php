<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AccountHistory extends Model
{
    use HasFactory;
    protected $table = 'account_histories';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'account_id',
        'period_start',
        'period_end',
        'opening_balance',
        'transaction_amount',
        'closing_balance',
        'loan_number',
        'customer',
        'company',
        'branch',
        'zone',
        'transaction_date',
        'is_reverse',
        'registered_by',
        'schedule_id'
    ];
}
