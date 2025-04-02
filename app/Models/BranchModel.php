<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BranchModel extends Model
{
    use HasFactory;
    protected $table = 'branches';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['branch_name', 'balance', 'registered_by', 'company','status'];
}
