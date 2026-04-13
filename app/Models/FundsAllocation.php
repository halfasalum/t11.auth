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

    public function branch()
    {
        return $this->belongsTo(BranchModel::class, 'branch', 'id');
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class, 'zone', 'id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company', 'id');
    }

    public function allocatedBy()
    {
        return $this->belongsTo(User::class, 'allocated_by', 'id');
    }
}
