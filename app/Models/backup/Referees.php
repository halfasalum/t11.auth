<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Referees extends Model
{
    use HasFactory;
    protected $table = 'referees';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['fullname', 'email', 'phone','referee_phone','nida','address','city','gender','status','created_by','updated_by','deleted_by','referee_image','date_of_birth'];
}
