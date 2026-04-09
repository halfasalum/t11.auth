<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Model;

class Status extends Model
{
    use HasFactory;
    protected $table = 'statuses';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['status_name'];
}
