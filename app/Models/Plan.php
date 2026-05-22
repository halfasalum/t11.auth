<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Plan extends Model
{
    use HasFactory;
    
    protected $table = 'plans';
    protected $primaryKey = 'id';
    public $timestamps = true;
    
    protected $fillable = [
        'name',
        'price',
        'customer_limit',
        'branch_limit',
        'zone_limit',
        'user_limit',
        'loans_limit',
        'description',
        'telegram_notifications',
        'sms_notifications',
        'trace_customer',
        'has_advanced_reports',
        'has_support_tickets',
        'has_api_access',
        'has_export_data',
        'has_mobile_app',
        'has_audit_logs',
        'has_custom_reports',
        'has_multi_currency',
        'has_bulk_operations',
        'has_priority_support',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'telegram_notifications' => 'boolean',
        'sms_notifications' => 'boolean',
        'trace_customer' => 'boolean',
        'has_advanced_reports' => 'boolean',
        'has_support_tickets' => 'boolean',
        'has_api_access' => 'boolean',
        'has_export_data' => 'boolean',
        'has_mobile_app' => 'boolean',
        'has_audit_logs' => 'boolean',
        'has_custom_reports' => 'boolean',
        'has_multi_currency' => 'boolean',
        'has_bulk_operations' => 'boolean',
        'has_priority_support' => 'boolean',
    ];

    // Relationship with discounts
    public function discounts()
    {
        return $this->hasMany(PlanDiscount::class);
    }
    
    // Get discount percentage for specific duration
    public function getDiscountForDuration($months)
    {
        $discount = $this->discounts()
            ->where('duration_months', $months)
            ->where('is_active', true)
            ->first();
            
        return $discount ? $discount->discount_percentage : 0;
    }
    
    // Get all active discounts as array
    public function getDiscountsArrayAttribute()
    {
        return $this->discounts()
            ->where('is_active', true)
            ->get()
            ->pluck('discount_percentage', 'duration_months')
            ->toArray();
    }

    // Get features list
    public function getFeaturesListAttribute(): array
    {
        $features = [];
        
        if ($this->customer_limit) {
            $features[] = "Up to {$this->customer_limit} customers";
        } elseif ($this->customer_limit === null) {
            $features[] = 'Unlimited customers';
        }
        
        if ($this->branch_limit) {
            $features[] = "Up to {$this->branch_limit} branches";
        } elseif ($this->branch_limit === null) {
            $features[] = 'Unlimited branches';
        }
        
        if ($this->zone_limit) {
            $features[] = "Up to {$this->zone_limit} zones";
        } elseif ($this->zone_limit === null) {
            $features[] = 'Unlimited zones';
        }
        
        if ($this->user_limit) {
            $features[] = "Up to {$this->user_limit} users";
        } elseif ($this->user_limit === null) {
            $features[] = 'Unlimited users';
        }
        
        if ($this->loans_limit && $this->loans_limit > 0) {
            $features[] = "Up to {$this->loans_limit} active loans";
        }
        
        if ($this->has_advanced_reports) $features[] = 'Advanced Reports';
        if ($this->has_support_tickets) $features[] = 'Support Ticket System';
        if ($this->has_api_access) $features[] = 'API Access';
        if ($this->has_export_data) $features[] = 'Data Export';
        if ($this->has_mobile_app) $features[] = 'Mobile App';
        if ($this->has_audit_logs) $features[] = 'Audit Logs';
        if ($this->has_custom_reports) $features[] = 'Custom Reports';
        if ($this->has_multi_currency) $features[] = 'Multi-Currency';
        if ($this->has_bulk_operations) $features[] = 'Bulk Operations';
        if ($this->has_priority_support) $features[] = 'Priority Support';
        if ($this->telegram_notifications) $features[] = 'Telegram Notifications';
        if ($this->sms_notifications) $features[] = 'SMS Notifications';
        if ($this->trace_customer) $features[] = 'Customer Tracking';
        
        return $features;
    }

    
    // Get premium features only (for highlighting)
    public function getPremiumFeaturesAttribute(): array
    {
        $premium = [];
        
        if ($this->has_advanced_reports) $premium[] = 'Advanced Reports';
        if ($this->has_api_access) $premium[] = 'API Access';
        if ($this->has_export_data) $premium[] = 'Data Export';
        if ($this->has_mobile_app) $premium[] = 'Mobile App';
        if ($this->has_custom_reports) $premium[] = 'Custom Reports';
        if ($this->has_multi_currency) $premium[] = 'Multi-Currency';
        if ($this->has_bulk_operations) $premium[] = 'Bulk Operations';
        if ($this->has_priority_support) $premium[] = 'Priority Support';
        
        return $premium;
    }
    
    // Check if plan has a specific feature
    public function hasFeature($feature): bool
    {
        return (bool) $this->{$feature};
    }
}