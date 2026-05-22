<?php
// app/Models/TicketAttachment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAttachment extends Model
{
    protected $table = 'ticket_attachments';

    protected $fillable = [
        'ticket_id', 'user_id', 'filename', 'original_name', 'mime_type', 'size', 'path'
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}