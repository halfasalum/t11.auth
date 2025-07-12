<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FundsAllocation extends Model
{
    use HasFactory;
    protected $table = 'branch_funds_allocation';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['branch', 'company', 'allocated_amount','allocated_by'];
}
