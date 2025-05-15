<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CustomersZone extends Model
{
    use HasFactory;
    protected $connection = 'customers_db';
    protected $table = 'customers_zones';
}
