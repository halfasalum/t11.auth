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
    protected $fillable = ['zone_name', 'branch', 'registered_by', 'company', 'status'];

    /**
     * Get the branch that this zone belongs to
     */
    public function zone_branch()
    {
        return $this->belongsTo(BranchModel::class, 'branch', 'id');
    }

    public function loans()
    {
        return $this->hasMany(Loans::class, 'zone', 'id');
    }

    /**
     * Get the zone assignments (pivot table)
     */
    public function zoneAssignments()
    {
        return $this->hasMany(ZoneUser::class, 'zone_id', 'id');
    }
    
    /**
     * Get the officers assigned to this zone (through ZoneUser)
     */
    public function zone_officers()
    {
        return $this->belongsToMany(User::class, 'zone_users', 'zone_id', 'user_id')
         ->where('zone_users.status', 1);
            
    }
    
  
    
    /**
     * Get the company that this zone belongs to
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company', 'id');
    }
}
