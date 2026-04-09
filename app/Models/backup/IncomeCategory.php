<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class IncomeCategory extends Model
{
    use HasFactory;
    protected $table = 'income_categories';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'category_name',
        'category_company',
        'category_status',
        'loan_related',
        
    ];
}
