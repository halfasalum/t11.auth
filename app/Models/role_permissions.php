<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class role_permissions extends Model
{
    protected $table = 'role_permissions';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['role_id', 'permission_id', 'permission_status'];
}
