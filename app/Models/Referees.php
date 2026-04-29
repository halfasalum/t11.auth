<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Referees extends Model
{
    use HasFactory;

    

    protected $table = 'referees';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'fullname', 'email', 'phone', 'referee_phone', 'nida', 'address', 'city',
        'gender', 'status', 'created_by', 'updated_by', 'deleted_by',
        'referee_image', 'date_of_birth'
    ];

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    // ============================================
    // Status Constants
    // ============================================
    
    const STATUS_ACTIVE = 1;
    const STATUS_INACTIVE = 2;
    const STATUS_DELETED = 3;

    const GENDER_MALE = 1;
    const GENDER_FEMALE = 2;

    // ============================================
    // Relationships
    // ============================================
    
    public function customers()
    {
        return $this->belongsToMany(Customers::class, 'refeee_to_customer', 'referee_id', 'customer_id')
            ->withPivot('status', 'company_id', 'branch_id', 'zone_id', 'from_group', 'group_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by', 'id');
    }

    // ============================================
    // Accessors
    // ============================================
    
    public function getFullNameAttribute(): string
    {
        return ucwords(strtolower($this->fullname));
    }

    public function getAgeAttribute(): ?int
    {
        if (!$this->date_of_birth) return null;
        return $this->date_of_birth->age;
    }

    public function getGenderLabelAttribute(): string
    {
        return match($this->gender) {
            self::GENDER_MALE => 'Male',
            self::GENDER_FEMALE => 'Female',
            default => 'Not specified',
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_INACTIVE => 'Inactive',
            self::STATUS_DELETED => 'Deleted',
            default => 'Unknown',
        };
    }

    public function getFormattedPhoneAttribute(): string
    {
        $phone = $this->referee_phone ?? $this->phone;
        if (strlen($phone) === 10) {
            return substr($phone, 0, 3) . ' ' . substr($phone, 3, 3) . ' ' . substr($phone, 6);
        }
        return $phone;
    }

    public function getInitialsAttribute(): string
    {
        $parts = explode(' ', $this->fullname);
        $initials = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            if (!empty($part)) {
                $initials .= strtoupper($part[0]);
            }
        }
        return $initials ?: 'R';
    }

    // ============================================
    // Scopes
    // ============================================
    
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeSearch($query, $search)
    {
        if (empty($search)) return $query;
        
        return $query->where(function($q) use ($search) {
            $q->where('fullname', 'LIKE', "%{$search}%")
              ->orWhere('referee_phone', 'LIKE', "%{$search}%")
              ->orWhere('phone', 'LIKE', "%{$search}%")
              ->orWhere('email', 'LIKE', "%{$search}%")
              ->orWhere('nida', 'LIKE', "%{$search}%");
        });
    }

    // ============================================
    // Helper Methods
    // ============================================
    
    public function markAsActive(): bool
    {
        return $this->update(['status' => self::STATUS_ACTIVE]);
    }

    public function markAsInactive(): bool
    {
        return $this->update(['status' => self::STATUS_INACTIVE]);
    }
}