<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    
    protected $table = 'branches';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['branch_name', 'balance', 'registered_by', 'company','status'];

    /**
     * Get the zones in this branch
     */
    public function zones()
    {
        return $this->hasMany(Zone::class, 'branch', 'id');
    }
}
