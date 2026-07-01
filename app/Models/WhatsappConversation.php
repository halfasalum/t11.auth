<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappConversation extends Model
{
    protected $fillable = [
        'whatsapp_contact_id',
        'state',
        'collected_data',
        'state_updated_at',
    ];

    protected $casts = [
        'collected_data'   => 'array',
        'state_updated_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(WhatsappContact::class, 'whatsapp_contact_id');
    }

    // Helper: update state and timestamp together
    public function updateState(string $state, array $data = []): void
    {
        $this->state            = $state;
        $this->state_updated_at = now();

        if (!empty($data)) {
            $existing             = $this->collected_data ?? [];
            $this->collected_data = array_merge($existing, $data);
        }

        $this->save();
    }
}