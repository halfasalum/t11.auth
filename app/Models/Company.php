<?php
// app/Models/Company.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Carbon\Carbon;

class Company extends Model
{
    use HasFactory;

    protected $table = 'companies';
    protected $primaryKey = 'id';
    public $timestamps = true;
    
    protected $fillable = [
        'company_name', 
        'company_phone', 
        'company_email', 
        'subscription', 
        'company_status',
        'company_address',
        'company_city',
        'company_country',
        'registration_ip',
        'registration_token',
        'registration_completed_at',
        'registered_by'
    ];

    protected $casts = [
        'registration_completed_at' => 'datetime',
    ];

    // Relationships
    public function users()
    {
        return $this->hasMany(User::class, 'user_company', 'id');
    }

    public function registeredByUser()
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)
            ->where('status', 'active')
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->latest();
    }

    /**
     * Generate registration token
     */
    public function generateRegistrationToken()
    {
        $this->registration_token = bin2hex(random_bytes(32));
        $this->save();
        return $this->registration_token;
    }

    /**
     * Check if registration is completed
     */
    public function isRegistrationComplete(): bool
    {
        return !is_null($this->registration_completed_at);
    }

    /**
     * Complete registration
     */
    public function completeRegistration()
    {
        $this->registration_completed_at = Carbon::now();
        $this->save();
    }
}