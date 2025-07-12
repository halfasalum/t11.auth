<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PaymentToken extends Model
{
    use HasFactory;
    protected $table = 'payment_tokens';
    protected $primaryKey = 'id';
    public $timestamps = true;
    protected $fillable = [
        'access_token',
        'expires_at',
        'user_id',
        'company_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Check if token is still valid
     */
    public function isValid(): bool
    {
        return $this->expires_at->isFuture();
    }
}