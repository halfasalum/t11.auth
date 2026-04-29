<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Income extends Model
{
    use HasFactory;
    protected $table = 'incomes';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'income_category',
        'loan_number',
        'income_amount',
        'income_date',
        'registered_by',
        'income_company',
        'income_branch',
        'income_description',
        'paid_account'
    ];
}
