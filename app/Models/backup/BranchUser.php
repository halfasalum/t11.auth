<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BranchUser extends Model
{
    use HasFactory;
    protected $table = 'branch_users';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['branch_id', 'user_id', 'status'];
}
