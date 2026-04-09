<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ServicesModel extends Model
{
    use HasFactory;
    protected $connection = 'auth_db';
    protected $table = 'services';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['service_name', 'service_command', 'service_description'];
}
