<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Collateral extends Model
{
    use HasFactory;

    protected $connection = 'customers_db';
    protected $table = 'collateral';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'name',
        'value',
        'company',
        'customer',
        'status',
    ];
}
