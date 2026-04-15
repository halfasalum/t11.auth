<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ZoneUser extends Model
{
    use HasFactory;
    protected $table = 'zone_users';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['zone_id', 'user_id', 'status'];

    /**
     * Get the zone that this assignment belongs to
     */
    public function zone()
    {
        return $this->belongsTo(Zone::class, 'zone_id', 'id');
    }
    
    /**
     * Get the user assigned to this zone
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id')
         ->where('users.status', 1);
    }
}
