<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CustomersZone extends Model
{
    use HasFactory;
    //protected $connection = 'customers_db';

    protected $table = 'customers_zones';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'customer_id', 'company_id', 'zone_id', 'created_by', 'updated_by',
        'deleted_by', 'status', 'branch_id', 'has_referee', 'has_attachments',
        'referee_id', 'credit_score', 'dti_ratio', 'score', 'customer_income'
    ];

    protected $casts = [
        'has_referee' => 'boolean',
        'has_attachments' => 'boolean',
        'credit_score' => 'decimal:2',
        'dti_ratio' => 'decimal:2',
        'score' => 'decimal:2',
        'customer_income' => 'decimal:2',
    ];

    // ============================================
    // Status Constants
    // ============================================
    
    const STATUS_ACTIVE = 1;
    const STATUS_INACTIVE = 2;
    const STATUS_DELETED = 3;
    const STATUS_PENDING = 4;
    const STATUS_REJECTED = 9;

    // ============================================
    // Relationships
    // ============================================
    
    public function customer()
    {
        return $this->belongsTo(Customers::class, 'customer_id', 'id');
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class, 'zone_id', 'id');
    }

    public function branch()
    {
        return $this->belongsTo(BranchModel::class, 'branch_id', 'id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    public function referee()
    {
        return $this->belongsTo(Referees::class, 'referee_id', 'id');
    }

    // ============================================
    // Accessors
    // ============================================
    
    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_INACTIVE => 'Inactive',
            self::STATUS_PENDING => 'Pending Approval',
            self::STATUS_REJECTED => 'Rejected',
            self::STATUS_DELETED => 'Deleted',
            default => 'Unknown',
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            self::STATUS_ACTIVE => 'success',
            self::STATUS_INACTIVE => 'warning',
            self::STATUS_PENDING => 'info',
            self::STATUS_REJECTED, self::STATUS_DELETED => 'danger',
            default => 'secondary',
        };
    }

    // ============================================
    // Scopes
    // ============================================
    
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByZone($query, $zoneId)
    {
        return $query->where('zone_id', $zoneId);
    }

    public function scopeByBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    // ============================================
    // Helper Methods
    // ============================================
    
    public function hasCompleteProfile(): bool
    {
        return $this->has_referee && $this->has_attachments;
    }

    public function canSubmit(): bool
    {
        return $this->status === self::STATUS_INACTIVE && $this->hasCompleteProfile();
    }

    public function approve(): bool
    {
        return $this->update(['status' => self::STATUS_ACTIVE]);
    }

    public function reject(): bool
    {
        return $this->update(['status' => self::STATUS_REJECTED]);
    }

    public function submit(): bool
    {
        if (!$this->canSubmit()) return false;
        return $this->update(['status' => self::STATUS_PENDING]);
    }
}