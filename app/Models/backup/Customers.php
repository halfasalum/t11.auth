<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Customers extends Model
{
    use HasFactory;
    protected $table = 'customers';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'fullname',
        'email',
        'phone',
        'customer_phone',
        'nida',
        'address',
        'city',
        'gender',
        'marital_status',
        'income',
        'employment_type',
        'experience',
        'education_level',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
        'is_defaulted',
        'is_referee_verified',
        'is_attachments_verified',
        'customer_image',
        'date_of_birth',
        'is_group',
        'group_id',
        'is_leader',
    ];
}
