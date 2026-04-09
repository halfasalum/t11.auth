<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class modules extends Model
{
    use HasFactory;
    protected $table = 'modules';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['module_name', 'module_url', 'module_icon', 'module_decsription', 'module_status'];
}
