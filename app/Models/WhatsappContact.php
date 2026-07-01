<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappContact extends Model
{
    protected $fillable = [
        'phone',
        'name',
        'status',
        'last_seen',
    ];

    protected $casts = [
        'last_seen' => 'datetime',
    ];

    public function conversation(): HasOne
    {
        return $this->hasOne(WhatsappConversation::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(WhatsappRegistration::class);
    }
}