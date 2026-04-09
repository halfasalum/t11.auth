<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CustomerReferees extends Model
{
    use HasFactory;
    protected $table = 'refeee_to_customer';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = ['referee_id', 'customer_id', 'company_id', 'branch_id', 'zone_id', 'status','from_group', 'group_id'];
}
