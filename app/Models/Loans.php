<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Loans extends Model
{
    use HasFactory;
    //protected $connection = 'loan_db';

    // Status Constants
    public const STATUS_SUBMITTED = 4;
    public const STATUS_ACTIVE = 5;
    public const STATUS_COMPLETED = 6;
    public const STATUS_DEFAULTED = 7;
    public const STATUS_OVERDUE = 12;
    public const STATUS_REJECTED = 9;

    protected $table = 'loans';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
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
        'is_defaulted'
    ];

    protected $casts = [
        'principal_amount' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'total_loan' => 'decimal:2',
        'penalty_amount' => 'decimal:2',
        'loan_paid' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    // ============================================
    // Relationships
    // ============================================

    /**
     * Get the customer zone assignment (this is the direct relationship)
     * loans.customer → customers_zones.id
     */
    public function customerZone()
    {
        return $this->belongsTo(CustomersZone::class, 'customer', 'id');
    }

    /**
     * Get the actual customer data (through customers_zones)
     */
    public function loan_customer()
    {
        return $this->belongsTo(Customers::class, 'customer', 'id');
    }

    /**
     * Get the loan product
     */
    public function loan_product()
    {
        return $this->belongsTo(LoansProducts::class, 'product', 'id');
    }

    /**
     * Get the zone
     */
    public function loan_zone()
    {
        return $this->belongsTo(Zone::class, 'zone', 'id');
    }

    /**
     * Get the branch through the zone (nested relationship)
     */
    public function loan_branch()
    {
        return $this->hasOneThrough(
            BranchModel::class,  // Target model
            Zone::class,         // Intermediate model
            'id',                // Foreign key on zones table (local key in zones)
            'id',                // Foreign key on branches table (local key in branches)
            'zone',              // Local key on loans table
            'branch'             // Local key on zones table
        );
    }

    /**
     * Get the company
     */
    public function loan_company()
    {
        return $this->belongsTo(Company::class, 'company', 'id');
    }

    /**
     * Get the user who registered this loan
     */
    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by', 'id');
    }

    /**
     * Get payment schedules for this loan
     */
    public function schedules()
    {
        return $this->hasMany(LoanPaymentSchedules::class, 'loan_number', 'loan_number');
    }

    /**
     * Get payments for this loan
     */
    public function payments()
    {
        return $this->hasMany(PaymentSubmissions::class, 'loan_number', 'loan_number');
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
            default => 'secondary',
        };
    }

    public function getBalanceAttribute(): float
    {
        return ($this->total_loan + ($this->penalty_amount ?? 0)) - ($this->loan_paid ?? 0);
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
}
