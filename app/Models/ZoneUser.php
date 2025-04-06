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
}
