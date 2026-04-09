<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Zone extends Model
{
    use HasFactory;
    protected $table = 'zones';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['zone_name', 'branch', 'registered_by', 'company','status'];

     /**
     * Get the branch that this zone belongs to
     */
    public function branch()
    {
        return $this->belongsTo(BranchModel::class, 'branch', 'id');
    }
}
