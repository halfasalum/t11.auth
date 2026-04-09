<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $connection = 'auth_db';
    protected $table = 'branches';
}
