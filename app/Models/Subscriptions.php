<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Subscriptions extends Model
{
    use HasFactory;
    protected $table = 'subscriptions';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'company_id',
        'plan_id',
        'status',
        'start_date',
        'end_date',
    ];
}
