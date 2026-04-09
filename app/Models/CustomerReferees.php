<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CustomerReferees extends Model
{
    use HasFactory;
    protected $connection = 'customers_db';

    protected $table = 'refeee_to_customer';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'referee_id', 'customer_id', 'company_id', 'branch_id', 'zone_id',
        'status', 'from_group', 'group_id'
    ];

    protected $casts = [
        'from_group' => 'boolean',
    ];

    // ============================================
    // Status Constants
    // ============================================
    
    const STATUS_ACTIVE = 1;
    const STATUS_INACTIVE = 2;
    const STATUS_DELETED = 3;

    // ============================================
    // Relationships
    // ============================================
    
    public function referee()
    {
        return $this->belongsTo(Referees::class, 'referee_id', 'id');
    }

    public function customer()
    {
        return $this->belongsTo(Customers::class, 'customer_id', 'id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'id');
    }

    public function branch()
    {
        return $this->belongsTo(BranchModel::class, 'branch_id', 'id');
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class, 'zone_id', 'id');
    }

    public function group()
    {
        return $this->belongsTo(Customers::class, 'group_id', 'id');
    }

    // ============================================
    // Scopes
    // ============================================
    
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeByCompany($query, $companyId)
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }
}