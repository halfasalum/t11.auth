<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class users_roles extends Model
{
    protected $table = 'users_roles';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['user_id', 'role_id', 'user_role_status'];
}
