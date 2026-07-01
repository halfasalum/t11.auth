<?php
// app/Models/Loans.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Loans extends Model
{
    use HasFactory;

    // Status Constants
    public const STATUS_SUBMITTED = 4;
    public const STATUS_ACTIVE = 5;
    public const STATUS_COMPLETED = 6;
    public const STATUS_DEFAULTED = 7;
    public const STATUS_OVERDUE = 12;
    public const STATUS_REJECTED = 9;
    public const STATUS_WRITTEN_OFF = 13;
    public const STATUS_FORECLOSURE = 14;
    public const STATUS_EARLY_SETTLED = 15;
    public const STATUS_RESTRUCTURED = 16;

    protected $table = 'loans';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        // Existing fields
        'product',
        'customer',
        'company',
        'loan_number',
        'principal_amount',
        'interest_amount',
        'total_loan',
        'penalty_amount',
        'start_date',
        'end_date',
        'loan_period',
        'status',
        'registered_by',
        'zone',
        'token',
        'loan_paid',
        'remarks',
        'principal_paid',
        'interest_paid',
        'loan_purpose',
        'recommendations',
        'reason',
        'loan_file',
        'loan_score',
        'is_defaulted',
        'write_off_reason',
        
        // Write Off fields
        'written_off_date',
        'written_off_amount',
        'written_off_reason',
        'written_off_by_system',
        
        // Default fields
        'defaulted_date',
        'defaulted_reason',
        'defaulted_by_system',
        
        // Foreclosure fields
        'foreclosure_date',
        'foreclosure_status',
        'foreclosure_reason',
        'foreclosure_initiated_by_system',
        'foreclosure_notice_date',
        'foreclosure_redemption_date',
        'foreclosure_sale_amount',
        'foreclosure_completed_at',
        
        // Overdue fields
        'overdue_date',
        'days_overdue',
        
        // Restructure fields
        'restructured_at',
        'restructured_by',
        'restructure_reason',
        'restructure_count',
        
        // Extension fields
        'extension_count',
        'extension_reason',
        'original_end_date',
        
        // Early settlement fields
        'settlement_date',
        'settlement_amount',
        'settlement_discount',
        
        // Payment holiday fields
        'payment_holiday_months',
        'payment_holiday_reason',
        
        // Funding fields
        'funding_account_id',
        'disbursed_at',
        'disbursed_by',
    ];

    protected $casts = [
        // Existing casts
        'principal_amount' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'total_loan' => 'decimal:2',
        'penalty_amount' => 'decimal:2',
        'loan_paid' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'principal_paid' => 'decimal:2',
        'interest_paid' => 'decimal:2',
        
        // New casts
        'written_off_date' => 'datetime',
        'written_off_amount' => 'decimal:2',
        'written_off_by_system' => 'boolean',
        'defaulted_date' => 'datetime',
        'defaulted_by_system' => 'boolean',
        'foreclosure_date' => 'datetime',
        'foreclosure_initiated_by_system' => 'boolean',
        'foreclosure_notice_date' => 'datetime',
        'foreclosure_redemption_date' => 'datetime',
        'foreclosure_sale_amount' => 'decimal:2',
        'foreclosure_completed_at' => 'datetime',
        'overdue_date' => 'datetime',
        'days_overdue' => 'integer',
        'restructured_at' => 'datetime',
        'restructured_by' => 'integer',
        'restructure_count' => 'integer',
        'extension_count' => 'integer',
        'original_end_date' => 'date',
        'settlement_date' => 'datetime',
        'settlement_amount' => 'decimal:2',
        'settlement_discount' => 'decimal:2',
        'payment_holiday_months' => 'integer',
        'funding_account_id' => 'integer',
        'disbursed_at' => 'datetime',
        'disbursed_by' => 'integer',
    ];

    // ============================================
    // Relationships
    // ============================================

    public function customerZone()
    {
        return $this->belongsTo(CustomersZone::class, 'customer', 'id');
    }

    public function loan_customer()
    {
        return $this->belongsTo(Customers::class, 'customer', 'id');
    }

    public function loan_product()
    {
        return $this->belongsTo(LoansProducts::class, 'product', 'id');
    }

    public function product()
    {
        return $this->belongsTo(LoansProducts::class, 'product', 'id');
    }

    public function loan_zone()
    {
        return $this->belongsTo(Zone::class, 'zone', 'id');
    }

    public function loan_branch()
    {
        return $this->hasOneThrough(
            BranchModel::class,
            Zone::class,
            'id',
            'id',
            'zone',
            'branch'
        );
    }

    public function loan_company()
    {
        return $this->belongsTo(Company::class, 'company', 'id');
    }

    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by', 'id');
    }

    public function disbursedBy()
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    public function fundingAccount()
    {
        return $this->belongsTo(Accounts::class, 'funding_account_id');
    }

    public function schedules()
    {
        return $this->hasMany(LoanPaymentSchedules::class, 'loan_number', 'loan_number');
    }

    public function payments()
    {
        return $this->hasMany(PaymentSubmissions::class, 'loan_number', 'loan_number');
    }

    public function customerRelation()
    {
        return $this->belongsTo(Customers::class, 'customer', 'id');
    }

    public function customer()
    {
        return $this->belongsTo(Customers::class, 'customer', 'id');
    }

    // ============================================
    // Accessors
    // ============================================

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_SUBMITTED => 'Submitted',
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_DEFAULTED => 'Defaulted',
            self::STATUS_OVERDUE => 'Overdue',
            self::STATUS_WRITTEN_OFF => 'Written Off',
            self::STATUS_FORECLOSURE => 'Foreclosure',
            self::STATUS_EARLY_SETTLED => 'Early Settled',
            self::STATUS_RESTRUCTURED => 'Restructured',
            default => 'Unknown',
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_SUBMITTED => 'info',
            self::STATUS_ACTIVE => 'success',
            self::STATUS_COMPLETED => 'secondary',
            self::STATUS_OVERDUE => 'warning',
            self::STATUS_DEFAULTED => 'danger',
            self::STATUS_WRITTEN_OFF => 'danger',
            self::STATUS_FORECLOSURE => 'dark',
            self::STATUS_EARLY_SETTLED => 'primary',
            self::STATUS_RESTRUCTURED => 'info',
            default => 'secondary',
        };
    }

    public function getForeclosureStatusLabelAttribute(): string
    {
        return match ($this->foreclosure_status) {
            'initiated' => 'Initiated',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            default => 'Not Started',
        };
    }

    public function getOutstandingBalanceAttribute(): float
    {
        return ($this->total_loan + ($this->penalty_amount ?? 0)) - ($this->loan_paid ?? 0);
    }

    public function getBalanceAttribute(): float
    {
        return $this->outstanding_balance;
    }

    // ============================================
    // Scopes
    // ============================================

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company', $companyId);
    }

    public function scopeByZone($query, $zoneIds)
    {
        return $query->whereIn('zone', (array) $zoneIds);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_OVERDUE]);
    }

    public function scopeDefaulted($query)
    {
        return $query->where('status', self::STATUS_DEFAULTED);
    }

    public function scopeWrittenOff($query)
    {
        return $query->where('status', self::STATUS_WRITTEN_OFF);
    }

    public function scopeForeclosure($query)
    {
        return $query->where('status', self::STATUS_FORECLOSURE);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('loan_number', 'LIKE', "%{$search}%")
                ->orWhereHas('customerZone.customer', function ($cq) use ($search) {
                    $cq->where('fullname', 'LIKE', "%{$search}%")
                        ->orWhere('customer_phone', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%");
                });
        });
    }

    // ============================================
    // Helper Methods
    // ============================================

    public function shouldBeDefaulted(): bool
    {
        $product = $this->product()->first();
        return $product ? $product->shouldBeDefaulted($this) : false;
    }

    public function shouldBeWrittenOff(): bool
    {
        $product = $this->product()->first();
        return $product ? $product->shouldBeWrittenOff($this) : false;
    }

    public function shouldBeForeclosed(): bool
    {
        $product = $this->product()->first();
        return $product ? $product->shouldBeForeclosed($this) : false;
    }

    public function getRequiredApprovalLevel($action): string
    {
        $product = $this->product()->first();
        return $product ? $product->getApprovalLevelForAction($action) : 'manager';
    }

    public function markAsDisbursed($fundingAccountId, $userId)
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
            'funding_account_id' => $fundingAccountId,
            'disbursed_at' => now(),
            'disbursed_by' => $userId,
        ]);
    }

    public function markAsCompleted()
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'closed_date' => now(),
        ]);
    }

    public function markAsDefaulted($reason, $bySystem = true)
    {
        $this->update([
            'status' => self::STATUS_DEFAULTED,
            'defaulted_date' => now(),
            'defaulted_reason' => $reason,
            'defaulted_by_system' => $bySystem,
        ]);
    }

    public function markAsWrittenOff($reason, $bySystem = true)
    {
        $this->update([
            'status' => self::STATUS_WRITTEN_OFF,
            'written_off_date' => now(),
            'written_off_amount' => $this->outstanding_balance,
            'written_off_reason' => $reason,
            'written_off_by_system' => $bySystem,
        ]);
    }

    public function markAsForeclosure($reason, $noticeDays = 30, $redemptionPeriod = 30, $bySystem = true)
    {
        $this->update([
            'status' => self::STATUS_FORECLOSURE,
            'foreclosure_date' => now(),
            'foreclosure_status' => 'initiated',
            'foreclosure_reason' => $reason,
            'foreclosure_initiated_by_system' => $bySystem,
            'foreclosure_notice_date' => now()->addDays($noticeDays),
            'foreclosure_redemption_date' => now()->addDays($noticeDays + $redemptionPeriod),
        ]);
    }
}