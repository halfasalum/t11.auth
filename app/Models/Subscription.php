<?php
// app/Models/Subscription.php (Update existing)

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Subscription extends Model
{
    use HasFactory;
    
    protected $table = 'subscriptions';
    protected $primaryKey = 'id';
    public $timestamps = true;
    
    protected $fillable = [
        'company_id',
        'plan_id',
        'subscription_order_id',
        'status',
        'start_date',
        'end_date',
        'features'
    ];

    protected $casts = [
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'features' => 'array'
    ];

    // Relationships
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscriptionOrder()
    {
        return $this->belongsTo(SubscriptionOrder::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('end_date', '<', now());
    }

    public function scopeExpiringSoon($query, $days = 30)
    {
        return $query->where('end_date', '>=', now())
            ->where('end_date', '<=', now()->addDays($days));
    }

    // Accessors
    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active' && 
               $this->start_date <= now() && 
               $this->end_date >= now();
    }

    public function getDaysRemainingAttribute(): int
    {
        if (!$this->end_date || $this->end_date < now()) {
            return 0;
        }
        return now()->diffInDays($this->end_date);
    }

    public function getStatusBadgeAttribute(): string
    {
        if ($this->is_active) {
            return '<span class="badge bg-success">Active</span>';
        } elseif ($this->end_date < now()) {
            return '<span class="badge bg-danger">Expired</span>';
        }
        return match($this->status) {
            'pending' => '<span class="badge bg-warning">Pending</span>',
            'cancelled' => '<span class="badge bg-secondary">Cancelled</span>',
            default => '<span class="badge bg-dark">' . ucfirst($this->status) . '</span>',
        };
    }
}