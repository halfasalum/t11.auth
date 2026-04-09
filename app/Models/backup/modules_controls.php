<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class modules_controls extends Model
{
    protected $table = 'modules_controls';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['control_name', 'module_id', 'module_control_status'];
}
