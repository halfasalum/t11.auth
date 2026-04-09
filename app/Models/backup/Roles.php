<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Roles extends Model
{
    use HasFactory;
    protected $table = 'roles';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['role_name', 'company', 'status'];
}
