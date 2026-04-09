<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Accounts extends Model
{
    use HasFactory;
    protected $table = 'accounts';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'account_name',
        'account_balance',
        'account_status',
        'company',
    ];
}
