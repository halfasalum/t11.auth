<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappRegistration extends Model
{
    protected $fillable = [
        'whatsapp_contact_id',
        'company_name',
        'email',
        'phone',
        'region',
        'ceo_name',
        'registration_type',
        'status',
        'terminalxi_account_id',
        'trial_starts_at',
        'trial_ends_at',
        'notes',
    ];

    protected $casts = [
        'trial_starts_at' => 'datetime',
        'trial_ends_at'   => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsappContact::class, 'whatsapp_contact_id');
    }
}