<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Expenses extends Model
{
    use HasFactory;
    protected $connection = 'expense_db';
    protected $table = 'expenses';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'expense_date',
        'category_id',
        'user_id',
        'description',
        'amount',
        'status',
        'company_id',
        'branch_id',
        'zone_id',
        'registered_by'
    ];

}
