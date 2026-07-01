<?php
// app/Models/Notification.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $table = 'notifications';
    
    protected $fillable = [
        'user_id',
        'title',
        'body',
        'data',
        'is_read',
        'type',
        'created_at'
    ];
    
    protected $casts = [
        'is_read' => 'boolean',
        'data' => 'array',
        'created_at' => 'datetime',
    ];
    
    protected $dates = ['created_at'];
    
    // Disable Laravel's default timestamps if you're using custom created_at
    public $timestamps = false;
    
    /**
     * Get the user that owns the notification
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
    
    /**
     * Scope for unread notifications
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }
    
    /**
     * Scope for read notifications
     */
    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead(): void
    {
        $this->update(['is_read' => true]);
    }
    
    /**
     * Mark notification as unread
     */
    public function markAsUnread(): void
    {
        $this->update(['is_read' => false]);
    }
}