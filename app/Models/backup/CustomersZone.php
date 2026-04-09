<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CustomersZone extends Model
{
    use HasFactory;
    protected $table = 'customers_zones';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'customer_id',
        'company_id',
        'zone_id',
        'created_by',
        'updated_by',
        'deleted_by',
        'status',
        'branch_id',
        "has_referee",
        "has_attachments",
        'referee_id',
        'credit_score',
        'dti_ratio',
        'score',
        'customer_income'
    ];
}
