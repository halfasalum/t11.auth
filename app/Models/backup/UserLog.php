<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserLog extends Model
{
    use HasFactory;
    protected $table = 'user_logs';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['user_id', 'action', 'ip_address','user_agent', 'details','company'];
}
