<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\DB;

class LoansProducts extends Model
{
    use HasFactory;
    
    protected $table = 'loans_products';
    protected $primaryKey = 'id';
    public $timestamps = true;
    
    protected $fillable = [
        // Existing fields
        'product_name',
        'company',
        'interest_mode',
        'interest_rate',
        'interest_threshold',
        'max_loan_period',
        'min_loan_period',
        'max_loan_amount',
        'min_loan_amount',
        'loan_period_unit',
        'repayment_interval',
        'repayment_interval_unit',
        'skip_sat',
        'skip_sun',
        'penalty_type',
        'fixed_penalty_amount',
        'penalty_percentage',
        'status',
        'registered_by',
        'interest_amount',
        
        // Default Settings
        'default_days_overdue',
        'default_missed_payments',
        'default_percentage_of_term',
        
        // Write Off Settings
        'write_off_enabled',
        'write_off_days_overdue',
        'write_off_missed_payments',
        'write_off_requires_approval',
        'write_off_approval_level',
        'write_off_auto_process',
        'write_off_recovery_attempts',
        
        // Foreclosure Settings
        'foreclosure_enabled',
        'foreclosure_days_overdue',
        'foreclosure_missed_payments',
        'foreclosure_requires_collateral',
        'foreclosure_legal_required',
        'foreclosure_approval_level',
        'foreclosure_notice_days',
        'foreclosure_redemption_period',
        
        // Restructure Settings
        'restructure_enabled',
        'restructure_days_overdue',
        'restructure_max_times',
        'restructure_approval_level',
        
        // Notification Settings
        'notify_on_overdue_days',
        'notify_on_default',
        'notify_on_write_off',
        'notify_on_foreclosure',
        
        // Recovery Settings
        'recovery_enabled',
        'recovery_max_attempts',
        'recovery_assign_to_agency_days',
    ];

    protected $casts = [
        // Existing casts
        'interest_rate' => 'decimal:2',
        'interest_threshold' => 'decimal:2',
        'max_loan_amount' => 'decimal:2',
        'min_loan_amount' => 'decimal:2',
        'fixed_penalty_amount' => 'decimal:2',
        'penalty_percentage' => 'decimal:2',
        'skip_sat' => 'boolean',
        'skip_sun' => 'boolean',
        'status' => 'integer',
        'interest_amount' => 'decimal:2',
        
        // New casts
        'default_days_overdue' => 'integer',
        'default_missed_payments' => 'integer',
        'default_percentage_of_term' => 'integer',
        'write_off_enabled' => 'boolean',
        'write_off_days_overdue' => 'integer',
        'write_off_missed_payments' => 'integer',
        'write_off_requires_approval' => 'boolean',
        'write_off_auto_process' => 'boolean',
        'write_off_recovery_attempts' => 'integer',
        'foreclosure_enabled' => 'boolean',
        'foreclosure_days_overdue' => 'integer',
        'foreclosure_missed_payments' => 'integer',
        'foreclosure_requires_collateral' => 'boolean',
        'foreclosure_legal_required' => 'boolean',
        'foreclosure_notice_days' => 'integer',
        'foreclosure_redemption_period' => 'integer',
        'restructure_enabled' => 'boolean',
        'restructure_days_overdue' => 'integer',
        'restructure_max_times' => 'integer',
        'notify_on_overdue_days' => 'integer',
        'notify_on_default' => 'boolean',
        'notify_on_write_off' => 'boolean',
        'notify_on_foreclosure' => 'boolean',
        'recovery_enabled' => 'boolean',
        'recovery_max_attempts' => 'integer',
        'recovery_assign_to_agency_days' => 'integer',
    ];

    // ========== RELATIONSHIPS ==========
    
    public function loans()
    {
        return $this->hasMany(Loans::class, 'product', 'id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company', 'id');
    }

    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by', 'id');
    }

    // ========== SCOPES ==========
    
    public function scopeActive($query)
    {
        return $query->where('status', 1);
    }

    public function scopeHasDefaultSettings($query)
    {
        return $query->whereNotNull('default_days_overdue');
    }

    public function scopeHasWriteOffEnabled($query)
    {
        return $query->where('write_off_enabled', true);
    }

    public function scopeHasForeclosureEnabled($query)
    {
        return $query->where('foreclosure_enabled', true);
    }

    // ========== ACCESSORS ==========
    
    public function getDefaultThresholdsAttribute(): array
    {
        return [
            'days_overdue' => $this->default_days_overdue ?? 90,
            'missed_payments' => $this->default_missed_payments ?? 3,
            'percentage_of_term' => $this->default_percentage_of_term ?? null,
        ];
    }

    public function getWriteOffThresholdsAttribute(): array
    {
        return [
            'enabled' => $this->write_off_enabled ?? true,
            'days_overdue' => $this->write_off_days_overdue ?? 180,
            'missed_payments' => $this->write_off_missed_payments ?? 6,
            'requires_approval' => $this->write_off_requires_approval ?? true,
            'approval_level' => $this->write_off_approval_level ?? 'manager',
            'auto_process' => $this->write_off_auto_process ?? false,
            'recovery_attempts' => $this->write_off_recovery_attempts ?? 3,
        ];
    }

    public function getForeclosureThresholdsAttribute(): array
    {
        return [
            'enabled' => $this->foreclosure_enabled ?? false,
            'days_overdue' => $this->foreclosure_days_overdue ?? 210,
            'missed_payments' => $this->foreclosure_missed_payments ?? 7,
            'requires_collateral' => $this->foreclosure_requires_collateral ?? true,
            'legal_required' => $this->foreclosure_legal_required ?? true,
            'approval_level' => $this->foreclosure_approval_level ?? 'manager',
            'notice_days' => $this->foreclosure_notice_days ?? 30,
            'redemption_period' => $this->foreclosure_redemption_period ?? 30,
        ];
    }

    public function getRestructureThresholdsAttribute(): array
    {
        return [
            'enabled' => $this->restructure_enabled ?? true,
            'days_overdue' => $this->restructure_days_overdue ?? 30,
            'max_times' => $this->restructure_max_times ?? 2,
            'approval_level' => $this->restructure_approval_level ?? 'manager',
        ];
    }

    public function getNotificationSettingsAttribute(): array
    {
        return [
            'overdue_days' => $this->notify_on_overdue_days ?? 7,
            'on_default' => $this->notify_on_default ?? true,
            'on_write_off' => $this->notify_on_write_off ?? true,
            'on_foreclosure' => $this->notify_on_foreclosure ?? true,
        ];
    }

    public function getRecoverySettingsAttribute(): array
    {
        return [
            'enabled' => $this->recovery_enabled ?? true,
            'max_attempts' => $this->recovery_max_attempts ?? 5,
            'assign_to_agency_days' => $this->recovery_assign_to_agency_days ?? 120,
        ];
    }

    // ========== HELPER METHODS ==========
    
    /**
     * Check if loan should be defaulted based on product settings
     */
    public function shouldBeDefaulted($loan): bool
    {
        $thresholds = $this->default_thresholds;
        
        // Check days overdue
        if ($thresholds['days_overdue']) {
            $oldestUnpaidSchedule = $loan->schedules()
                ->where('status', 1)
                ->where('payment_due_date', '<', now())
                ->where('paid_amount', '<', DB::raw('payment_total_amount'))
                ->orderBy('payment_due_date', 'asc')
                ->first();
                
            if ($oldestUnpaidSchedule) {
                $daysOverdue = $oldestUnpaidSchedule->payment_due_date->diffInDays(now());
                if ($daysOverdue >= $thresholds['days_overdue']) {
                    return true;
                }
            }
        }
        
        // Check missed payments
        if ($thresholds['missed_payments']) {
            $missedPayments = $loan->schedules()
                ->where('status', 1)
                ->where('payment_due_date', '<', now())
                ->where(function($q) {
                    $q->where('paid_amount', 0)
                      ->orWhereNull('paid_amount');
                })
                ->count();
                
            if ($missedPayments >= $thresholds['missed_payments']) {
                return true;
            }
        }
        
        // Check percentage of term passed
        if ($thresholds['percentage_of_term']) {
            $termPassed = $loan->start_date->diffInDays(now()) / $loan->start_date->diffInDays($loan->end_date) * 100;
            if ($termPassed >= $thresholds['percentage_of_term'] && $loan->outstanding_balance > 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if loan should be written off based on product settings
     */
    public function shouldBeWrittenOff($loan): bool
    {
        if (!$this->write_off_enabled) {
            return false;
        }
        
        $thresholds = $this->write_off_thresholds;
        
        // Check days overdue
        if ($thresholds['days_overdue']) {
            $oldestUnpaidSchedule = $loan->schedules()
                ->where('status', 1)
                ->where('payment_due_date', '<', now())
                ->orderBy('payment_due_date', 'asc')
                ->first();
                
            if ($oldestUnpaidSchedule) {
                $daysOverdue = $oldestUnpaidSchedule->payment_due_date->diffInDays(now());
                if ($daysOverdue >= $thresholds['days_overdue']) {
                    return true;
                }
            }
        }
        
        // Check missed payments
        if ($thresholds['missed_payments']) {
            $missedPayments = $loan->schedules()
                ->where('status', 1)
                ->where('payment_due_date', '<', now())
                ->where('paid_amount', '<', DB::raw('payment_total_amount'))
                ->count();
                
            if ($missedPayments >= $thresholds['missed_payments']) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Check if loan should be foreclosed based on product settings
     */
    public function shouldBeForeclosed($loan): bool
    {
        if (!$this->foreclosure_enabled) {
            return false;
        }
        
        // Check if loan has collateral
        if ($this->foreclosure_requires_collateral && !$loan->collateral_id) {
            return false;
        }
        
        $thresholds = $this->foreclosure_thresholds;
        
        $oldestUnpaidSchedule = $loan->schedules()
            ->where('status', 1)
            ->where('payment_due_date', '<', now())
            ->orderBy('payment_due_date', 'asc')
            ->first();
            
        if ($oldestUnpaidSchedule) {
            $daysOverdue = $oldestUnpaidSchedule->payment_due_date->diffInDays(now());
            if ($daysOverdue >= $thresholds['days_overdue']) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get the required approval level for an action
     */
    public function getApprovalLevelForAction(string $action): string
    {
        return match($action) {
            'write_off' => $this->write_off_approval_level ?? 'manager',
            'foreclosure' => $this->foreclosure_approval_level ?? 'manager',
            'restructure' => $this->restructure_approval_level ?? 'manager',
            default => 'supervisor',
        };
    }
}