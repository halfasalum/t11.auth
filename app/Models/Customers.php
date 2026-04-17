<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;

class Customers extends Model
{
    use HasFactory;
    protected $connection = 'customers_db';

    protected $table = 'customers';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'fullname',
        'email',
        'phone',
        'customer_phone',
        'nida',
        'address',
        'city',
        'gender',
        'marital_status',
        'income',
        'employment_type',
        'experience',
        'education_level',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
        'is_defaulted',
        'is_referee_verified',
        'is_attachments_verified',
        'customer_image',
        'date_of_birth',
        'is_group',
        'group_id',
        'is_leader'
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'income' => 'decimal:2',
        'is_group' => 'boolean',
        'is_leader' => 'boolean',
        'is_defaulted' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ============================================
    // Status Constants
    // ============================================

    public const STATUS_ACTIVE = 1;
    public const STATUS_INACTIVE = 2;
    public const STATUS_DELETED = 3;
    public const STATUS_PENDING = 4;
    public const STATUS_REJECTED = 9;

    public const GENDER_MALE = 1;
    public const GENDER_FEMALE = 2;

    public const MARITAL_SINGLE = 1;
    public const MARITAL_MARRIED = 2;
    public const MARITAL_DIVORCED = 3;
    public const MARITAL_WIDOWED = 4;

    public const EMPLOYMENT_SALARIED = 1;
    public const EMPLOYMENT_SELF = 2;
    public const EMPLOYMENT_BUSINESS = 3;
    public const EMPLOYMENT_UNEMPLOYED = 4;

    public const EDUCATION_PRIMARY = 1;
    public const EDUCATION_SECONDARY = 2;
    public const EDUCATION_DIPLOMA = 3;
    public const EDUCATION_DEGREE = 4;
    public const EDUCATION_MASTERS = 5;
    public const EDUCATION_PHD = 6;

    // ============================================
    // Relationships
    // ============================================

    /**
     * Get the customer's zone assignment
     */
    public function zoneAssignment()
    {
        return $this->hasOne(CustomersZone::class, 'customer_id', 'id')
        ->where('status', '!=', 3)
        ->where('status', '!=', 9);
    }

    /**
     * Get the zone where this customer belongs
     */
    public function zone()
    {
        return $this->hasOneThrough(Zone::class, CustomersZone::class, 'customer_id', 'id', 'id', 'zone_id');
    }

    /**
     * Get all loans for this customer
     */
    public function loans()
    {
        return $this->hasMany(Loans::class, 'customer', 'id');
    }

    /**
     * Get active loan for this customer
     */
    public function activeLoan()
    {
        return $this->hasOne(Loans::class, 'customer', 'id')
            ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE]);
    }

    /**
     * Get completed loans for this customer
     */
    public function completedLoans()
    {
        return $this->hasMany(Loans::class, 'customer', 'id')
            ->where('status', Loans::STATUS_COMPLETED);
    }

    /**
     * Get pending loans for this customer
     */
    public function pendingLoans()
    {
        return $this->hasMany(Loans::class, 'customer', 'id')
            ->where('status', Loans::STATUS_SUBMITTED);
    }

    /**
     * Get referees for this customer
     */
    public function referees()
    {
        return $this->belongsToMany(Referees::class, 'refeee_to_customer', 'customer_id', 'referee_id')
            ->withPivot('status', 'from_group', 'group_id', 'company_id', 'branch_id', 'zone_id')
            ->wherePivot('status', 1);
    }

    /**
     * Get attachments for this customer
     */
    public function attachments()
    {
        return $this->hasMany(Attachements::class, 'customer_id', 'id')
            ->where('status', 1);
    }

    /**
     * Get collaterals for this customer
     */
    public function collaterals()
    {
        return $this->hasMany(Collateral::class, 'customer', 'id')
            ->where('status', 1);
    }

    /**
     * Get the group if this is a group member
     */
    public function group()
    {
        return $this->belongsTo(Customers::class, 'group_id', 'id');
    }

    /**
     * Get group members if this is a group
     */
    public function members()
    {
        return $this->hasMany(Customers::class, 'group_id', 'id');
    }

    /**
     * Get the user who created this customer
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    // ============================================
    // Accessors
    // ============================================

    /**
     * Get full name with proper capitalization
     */
    public function getFormattedNameAttribute(): string
    {
        return ucwords(strtolower($this->fullname));
    }

    /**
     * Get customer age
     */
    public function getAgeAttribute(): ?int
    {
        if (!$this->date_of_birth) return null;
        return $this->date_of_birth->age;
    }

    /**
     * Get gender label
     */
    public function getGenderLabelAttribute(): string
    {
        return match ($this->gender) {
            self::GENDER_MALE => 'Male',
            self::GENDER_FEMALE => 'Female',
            default => 'Not specified',
        };
    }

    /**
     * Get marital status label
     */
    public function getMaritalStatusLabelAttribute(): string
    {
        return match ($this->marital_status) {
            self::MARITAL_SINGLE => 'Single',
            self::MARITAL_MARRIED => 'Married',
            self::MARITAL_DIVORCED => 'Divorced',
            self::MARITAL_WIDOWED => 'Widowed',
            default => 'Not specified',
        };
    }

    /**
     * Get employment type label
     */
    public function getEmploymentLabelAttribute(): string
    {
        return match ($this->employment_type) {
            self::EMPLOYMENT_SALARIED => 'Salaried',
            self::EMPLOYMENT_SELF => 'Self Employed',
            self::EMPLOYMENT_BUSINESS => 'Business Owner',
            self::EMPLOYMENT_UNEMPLOYED => 'Unemployed',
            default => 'Not specified',
        };
    }

    /**
     * Get education level label
     */
    public function getEducationLabelAttribute(): string
    {
        return match ($this->education_level) {
            self::EDUCATION_PRIMARY => 'Primary',
            self::EDUCATION_SECONDARY => 'Secondary',
            self::EDUCATION_DIPLOMA => 'Diploma',
            self::EDUCATION_DEGREE => 'Degree',
            self::EDUCATION_MASTERS => 'Masters',
            self::EDUCATION_PHD => 'PhD',
            default => 'Not specified',
        };
    }

    /**
     * Get status label
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_INACTIVE => 'Inactive',
            self::STATUS_DELETED => 'Deleted',
            self::STATUS_PENDING => 'Pending Approval',
            self::STATUS_REJECTED => 'Rejected',
            default => 'Unknown',
        };
    }

    /**
     * Get status color for badges
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'success',
            self::STATUS_INACTIVE => 'warning',
            self::STATUS_PENDING => 'info',
            self::STATUS_REJECTED, self::STATUS_DELETED => 'danger',
            default => 'secondary',
        };
    }

    /**
     * Get customer initials for avatar
     */
    public function getInitialsAttribute(): string
    {
        $parts = explode(' ', $this->fullname);
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            if (!empty($part)) {
                $initials .= strtoupper($part[0]);
            }
        }
        return $initials ?: 'U';
    }

    /**
     * Get avatar color based on name
     */
    public function getAvatarColorAttribute(): string
    {
        $colors = ['primary', 'success', 'info', 'warning', 'danger', 'secondary', 'dark'];
        $index = ord($this->fullname[0] ?? 'A') % count($colors);
        return $colors[$index];
    }

    /**
     * Get profile completeness percentage
     */
    public function getProfileCompletenessAttribute(): int
    {
        $score = 0;
        $fields = [
            'phone' => 15,
            'email' => 10,
            'nida' => 15,
            'address' => 10,
            'city' => 5,
            'income' => 15,
            'date_of_birth' => 10,
            'customer_image' => 10,
            'attachments' => 5,
            'referees' => 5,
        ];

        foreach ($fields as $field => $weight) {
            if ($field === 'attachments' && $this->attachments()->count() > 0) {
                $score += $weight;
            } elseif ($field === 'referees' && $this->referees()->count() > 0) {
                $score += $weight;
            } elseif (!empty($this->$field)) {
                $score += $weight;
            }
        }

        return min($score, 100);
    }

    /**
     * Get formatted phone number
     */
    public function getFormattedPhoneAttribute(): string
    {
        $phone = $this->customer_phone ?? $this->phone;
        if (strlen($phone) === 10) {
            return substr($phone, 0, 3) . ' ' . substr($phone, 3, 3) . ' ' . substr($phone, 6);
        }
        return $phone;
    }

    /**
     * Get formatted income
     */
    public function getFormattedIncomeAttribute(): string
    {
        return number_format($this->income, 0, '.', ',');
    }

    // ============================================
    // Scopes
    // ============================================

    /**
     * Scope to get active customers
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope to get pending customers
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to get individual customers (not groups)
     */
    public function scopeIndividuals($query)
    {
        return $query->where('is_group', false);
    }

    /**
     * Scope to get groups
     */
    public function scopeGroups($query)
    {
        return $query->where('is_group', true);
    }

    /**
     * Scope to search customers
     */
    public function scopeSearch($query, $search)
    {
        if (empty($search)) return $query;

        return $query->where(function ($q) use ($search) {
            $q->where('fullname', 'LIKE', "%{$search}%")
                ->orWhere('phone', 'LIKE', "%{$search}%")
                ->orWhere('customer_phone', 'LIKE', "%{$search}%")
                ->orWhere('email', 'LIKE', "%{$search}%")
                ->orWhere('nida', 'LIKE', "%{$search}%");
        });
    }

    /**
     * Scope to filter by company via zone
     */
    public function scopeByCompany($query, $companyId)
    {
        return $query->whereHas('zoneAssignment', function ($q) use ($companyId) {
            $q->where('company_id', $companyId);
        });
    }

    /**
     * Scope to filter by zone
     */
    public function scopeByZone($query, $zoneId)
    {
        return $query->whereHas('zoneAssignment', function ($q) use ($zoneId) {
            $q->where('zone_id', $zoneId);
        });
    }

    /**
     * Scope to filter by branch
     */
    public function scopeByBranch($query, $branchId)
    {
        return $query->whereHas('zoneAssignment', function ($q) use ($branchId) {
            $q->where('branch_id', $branchId);
        });
    }

    /**
     * Scope to get customers without active loans
     */
    public function scopeLoanFree($query)
    {
        return $query->whereDoesntHave('loans', function ($q) {
            $q->whereIn('status', [Loans::STATUS_SUBMITTED, Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE]);
        });
    }

    // ============================================
    // Helper Methods
    // ============================================

    /**
     * Check if customer has active loan
     */
    public function hasActiveLoan(): bool
    {
        return $this->activeLoan()->exists();
    }

    /**
     * Check if customer can apply for new loan
     */
    public function canApplyForLoan(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && !$this->is_defaulted
            && !$this->hasActiveLoan();
    }

    /**
     * Get total loan balance across all active loans
     */
    public function getTotalLoanBalance(): float
    {
        return $this->loans()
            ->whereIn('status', [Loans::STATUS_ACTIVE, Loans::STATUS_OVERDUE])
            ->get()
            ->sum(function ($loan) {
                return ($loan->total_loan + $loan->penalty_amount) - $loan->loan_paid;
            });
    }

    /**
     * Get total amount repaid
     */
    public function getTotalRepaid(): float
    {
        return $this->loans()->sum('loan_paid');
    }

    /**
     * Check if customer has referee
     */
    public function hasReferee(): bool
    {
        return $this->referees()->count() > 0;
    }

    /**
     * Check if customer has attachments
     */
    public function hasAttachments(): bool
    {
        return $this->attachments()->count() > 0;
    }

    /**
     * Check if customer has collateral
     */
    public function hasCollateral(): bool
    {
        return $this->collaterals()->count() > 0;
    }

    /**
     * Mark customer as active
     */
    public function markAsActive(): bool
    {
        return $this->update(['status' => self::STATUS_ACTIVE]);
    }

    /**
     * Mark customer as pending
     */
    public function markAsPending(): bool
    {
        return $this->update(['status' => self::STATUS_PENDING]);
    }

    /**
     * Mark customer as rejected
     */
    public function markAsRejected(): bool
    {
        return $this->update(['status' => self::STATUS_REJECTED]);
    }

    /**
     * Mark customer as defaulted
     */
    public function markAsDefaulted(): bool
    {
        return $this->update(['is_defaulted' => true]);
    }

    /**
     * Get readiness score for loan application
     */
    public function getReadinessScore(): array
    {
        $hasReferee = $this->hasReferee();
        $hasAttachments = $this->hasAttachments();
        $hasCollateral = $this->hasCollateral();

        $score = 0;
        $recommendations = [];

        if ($hasReferee) {
            $score += 33;
        } else {
            $recommendations[] = [
                'action' => 'Add Referee',
                'description' => 'Add at least one referee to improve loan eligibility',
                'priority' => 'high'
            ];
        }

        if ($hasAttachments) {
            $score += 33;
        } else {
            $recommendations[] = [
                'action' => 'Upload Documents',
                'description' => 'Upload identification documents to verify identity',
                'priority' => 'high'
            ];
        }

        if ($hasCollateral) {
            $score += 34;
        } else {
            $recommendations[] = [
                'action' => 'Register Collateral',
                'description' => 'Add collateral to increase loan approval chances',
                'priority' => 'medium'
            ];
        }

        return [
            'score' => $score,
            'level' => $score >= 66 ? 'Ready for loan' : ($score >= 33 ? 'Needs more docs' : 'Incomplete'),
            'recommendations' => $recommendations,
        ];
    }
}
