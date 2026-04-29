<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Accounts extends Model
{
    use HasFactory;
    
    protected $table = 'accounts';
    protected $primaryKey = 'id';
    public $timestamps = true;
    
    // Account status constants
    const STATUS_ACTIVE = 1;
    const STATUS_INACTIVE = 2;
    const STATUS_DELETED = 3;
    
    // Account types
    const TYPE_GENERAL = 'general';
    const TYPE_BRANCH = 'branch';
    const TYPE_ESCROW = 'escrow';
    const TYPE_FLOATING = 'floating';
    
    protected $fillable = [
        'account_name',
        'account_number',
        'account_type',
        'account_balance',
        'account_status',
        'branch_id',
        'zone_id',
        'company_id',
        'parent_account_id',
        'currency',
        'minimum_balance',
        'maximum_balance',
        'description',
        'created_by',
        'approved_by',
        'approved_at',
    ];
    
    protected $casts = [
        'account_balance' => 'float',
        'minimum_balance' => 'float',
        'maximum_balance' => 'float',
        'approved_at' => 'datetime',
    ];
    
    /**
     * Get the branch that owns the account
     */
    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
    
    /**
     * Get the zone that owns the account
     */
    public function zone()
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }
    
    /**
     * Get the company that owns the account
     */
    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
    
    /**
     * Get the parent account
     */
    public function parentAccount()
    {
        return $this->belongsTo(Accounts::class, 'parent_account_id');
    }
    
    /**
     * Get child accounts
     */
    public function childAccounts()
    {
        return $this->hasMany(Accounts::class, 'parent_account_id');
    }
    
    /**
     * Get account history
     */
    public function history()
    {
        return $this->hasMany(AccountHistory::class, 'account_id');
    }
    
    /**
     * Get loans funded by this account
     */
    public function loans()
    {
        return $this->hasMany(Loans::class, 'funding_account_id');
    }
    
    /**
     * Scope for active accounts
     */
    public function scopeActive($query)
    {
        return $query->where('account_status', self::STATUS_ACTIVE);
    }
    
    /**
     * Scope for branch accounts
     */
    public function scopeBranchAccounts($query)
    {
        return $query->where('account_type', self::TYPE_BRANCH);
    }
    
    /**
     * Scope for general accounts
     */
    public function scopeGeneralAccounts($query)
    {
        return $query->where('account_type', self::TYPE_GENERAL);
    }
    
    /**
     * Check if account has sufficient balance
     */
    public function hasSufficientBalance($amount)
    {
        return $this->account_balance >= $amount;
    }
    
    /**
     * Decrement account balance
     */
    public function decrementBalance($amount)
    {
        if (!$this->hasSufficientBalance($amount)) {
            throw new \Exception("Insufficient balance in account: {$this->account_name}");
        }
        
        $this->decrement('account_balance', $amount);
        return $this;
    }
    
    /**
     * Increment account balance
     */
    public function incrementBalance($amount)
    {
        $this->increment('account_balance', $amount);
        return $this;
    }
    
    /**
     * Get formatted balance
     */
    public function getFormattedBalanceAttribute()
    {
        return number_format($this->account_balance, 0, '.', ',') . ' ' . ($this->currency ?? 'TZS');
    }
}