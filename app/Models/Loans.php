<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Loans extends Model
{
    protected $connection = 'loan_db';
    protected $table = 'loans';
}
