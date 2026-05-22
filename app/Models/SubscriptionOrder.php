<?php
// app/Models/SubscriptionOrder.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SubscriptionOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'subscription_orders';

    protected $fillable = [
        'order_number',
        'company_id',
        'plan_id',
        'subscription_id',
        'duration_months',
        'subtotal',
        'discount',
        'receipt_number',
        'receipt_file',
        'payment_notes',
        'amount',
        'currency',
        'status',
        'payment_date',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'admin_notes',
        'start_date',
        'end_date'
    ];

    // Add accessor for formatted discount
    public function getFormattedDiscountAttribute(): string
    {
        if (!$this->discount) return 'TZS 0';
        return number_format($this->discount, 0, ',', '.') . ' TZS';
    }

    // Add accessor for formatted subtotal
    public function getFormattedSubtotalAttribute(): string
    {
        return number_format($this->subtotal, 0, ',', '.') . ' TZS';
    }

    protected $casts = [
        'payment_date' => 'datetime',
        'approved_at' => 'datetime',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'amount' => 'decimal:2'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (!$order->order_number) {
                $order->order_number = self::generateOrderNumber();
            }
        });
    }

    public static function generateOrderNumber(): string
    {
        $prefix = 'SUB';
        $year = date('Y');
        $month = date('m');
        $lastOrder = self::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastOrder) {
            $lastNumber = intval(substr($lastOrder->order_number, -4));
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return "{$prefix}-{$year}{$month}-{$newNumber}";
    }

    // Relationships
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeVerified($query)
    {
        return $query->where('status', 'verified');
    }

    // Accessors
    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'pending' => '<span class="badge bg-warning">Pending Verification</span>',
            'verified' => '<span class="badge bg-info">Verified</span>',
            'approved' => '<span class="badge bg-success">Approved</span>',
            'rejected' => '<span class="badge bg-danger">Rejected</span>',
            'cancelled' => '<span class="badge bg-secondary">Cancelled</span>',
            default => '<span class="badge bg-dark">' . ucfirst($this->status) . '</span>',
        };
    }

    public function getReceiptUrlAttribute(): ?string
    {
        return $this->receipt_file ? asset('storage/' . $this->receipt_file) : null;
    }
}
