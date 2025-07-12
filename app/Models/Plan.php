<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Plan extends Model
{
     use HasFactory;
    protected $table = 'plans';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'name',
        'price',
        'customer_limit',
        'branch_limit',
        'zone_limit',
        'user_limit',
        'description',
    ];
}
