<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    use SoftDeletes;

    protected $table = 'support_tickets';

    protected $fillable = [
        'ticket_number', 'company_id', 'branch_id', 'user_id', 'subject', 'category',
        'title', 'description', 'priority', 'status', 'assigned_to', 'opened_at',
        'closed_at', 'resolved_at', 'first_response_at', 'response_time_hours',
        'resolution_time_hours', 'admin_notes'
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'resolved_at' => 'datetime',
        'first_response_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($ticket) {
            if (!$ticket->ticket_number) {
                $ticket->ticket_number = self::generateTicketNumber();
            }
            if (!$ticket->opened_at) {
                $ticket->opened_at = now();
            }
        });

        static::updating(function ($ticket) {
            if ($ticket->isDirty('status') && $ticket->status === 'resolved' && !$ticket->resolved_at) {
                $ticket->resolved_at = now();
                if ($ticket->opened_at) {
                    $ticket->resolution_time_hours = $ticket->opened_at->diffInHours($ticket->resolved_at);
                }
            }
            if ($ticket->isDirty('status') && in_array($ticket->status, ['closed', 'resolved']) && !$ticket->closed_at) {
                $ticket->closed_at = now();
            }
            if ($ticket->isDirty('status') && $ticket->status === 'reopened') {
                $ticket->closed_at = null;
                $ticket->resolved_at = null;
            }
        });
    }

    public static function generateTicketNumber(): string
    {
        $prefix = 'TKT';
        $year = date('Y');
        $month = date('m');
        $lastTicket = self::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastTicket) {
            $lastNumber = intval(substr($lastTicket->ticket_number, -4));
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return "{$prefix}-{$year}{$month}-{$newNumber}";
    }

    // Relationships
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class, 'ticket_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'ticket_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(TicketActivityLog::class, 'ticket_id')->orderBy('created_at', 'desc');
    }

    // Scopes
    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['open', 'in_progress', 'pending']);
    }

    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    // Accessors
    public function getStatusBadgeAttribute(): string
    {
        return match($this->status) {
            'open' => '<span class="badge bg-primary">Open</span>',
            'in_progress' => '<span class="badge bg-warning">In Progress</span>',
            'pending' => '<span class="badge bg-info">Pending</span>',
            'resolved' => '<span class="badge bg-success">Resolved</span>',
            'closed' => '<span class="badge bg-secondary">Closed</span>',
            default => '<span class="badge bg-dark">' . ucfirst($this->status) . '</span>',
        };
    }

    public function getPriorityBadgeAttribute(): string
    {
        return match($this->priority) {
            'low' => '<span class="badge bg-secondary">Low</span>',
            'medium' => '<span class="badge bg-info">Medium</span>',
            'high' => '<span class="badge bg-warning">High</span>',
            'urgent' => '<span class="badge bg-danger">Urgent</span>',
            default => '<span class="badge bg-secondary">' . ucfirst($this->priority) . '</span>',
        };
    }

    public function getCategoryLabelAttribute(): string
    {
        $categories = [
            'technical' => 'Technical Issue',
            'billing' => 'Billing Problem',
            'feature_request' => 'Feature Request',
            'bug' => 'Bug Report',
            'general' => 'General Inquiry',
            'other' => 'Other'
        ];
        return $categories[$this->category] ?? ucfirst($this->category);
    }
}