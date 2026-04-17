<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AccountHistory extends Model
{
    use HasFactory;
    
    protected $table = 'account_histories';
    public $timestamps = true;
    
    protected $fillable = [
        'account_id',
        'period_start',
        'period_end',
        'opening_balance',
        'transaction_amount',
        'closing_balance',
        'loan_number',
        'customer_id',
        'company_id',
        'branch_id',
        'zone_id',
        'transaction_date',
        'transaction_type',
        'is_reverse',
        'reference_number',
        'description',
        'registered_by',
        'schedule_id',
    ];
    
    protected $casts = [
        'opening_balance' => 'decimal:2',
        'transaction_amount' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'transaction_date' => 'date',
        'is_reverse' => 'boolean',
    ];
    
    /**
     * Get the account
     */
    public function account()
    {
        return $this->belongsTo(Accounts::class, 'account_id');
    }
    
    /**
     * Get the loan
     */
    public function loan()
    {
        return $this->belongsTo(Loans::class, 'loan_number', 'loan_number');
    }
    
    /**
     * Get the customer
     */
    public function customer()
    {
        return $this->belongsTo(Customers::class, 'customer_id');
    }
    
    /**
     * Get the branch
     */
    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
    
    /**
     * Get the zone
     */
    public function zone()
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }
    
    /**
     * Get the user who registered
     */
    public function registeredBy()
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}