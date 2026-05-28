<?php
// app/Models/NotificationLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    use HasFactory;

    protected $table = 'notification_logs';
    
    protected $fillable = [
        'loan_id',
        'type',
        'message',
        'recipient_type',
        'recipient_id',
        'recipient_phone',
        'recipient_email',
        'channel',
        'is_sent',
        'sent_at',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'is_sent' => 'boolean',
        'sent_at' => 'datetime',
        'metadata' => 'array',
    ];

    // Relationships
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loans::class, 'loan_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    // Scopes
    public function scopeUnsent($query)
    {
        return $query->where('is_sent', false);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByRecipient($query, $recipientType, $recipientId = null)
    {
        $query->where('recipient_type', $recipientType);
        if ($recipientId) {
            $query->where('recipient_id', $recipientId);
        }
        return $query;
    }

    // Accessors
    public function getTypeLabelAttribute(): string
    {
        return match($this->type) {
            'overdue' => 'Overdue Alert',
            'default' => 'Default Notice',
            'write_off' => 'Write Off Notice',
            'foreclosure' => 'Foreclosure Notice',
            'reminder' => 'Payment Reminder',
            default => ucfirst($this->type),
        };
    }

    public function getTypeColorAttribute(): string
    {
        return match($this->type) {
            'overdue' => 'warning',
            'default' => 'danger',
            'write_off' => 'secondary',
            'foreclosure' => 'dark',
            'reminder' => 'info',
            default => 'primary',
        };
    }
}