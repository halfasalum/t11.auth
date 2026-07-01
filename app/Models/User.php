<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;


class User extends Authenticatable implements JWTSubject
{
    use HasApiTokens;

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use HasProfilePhoto;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'first_name',
        'middle_name',
        'last_name',
        'user_company',
        'phone',
        'status',
        'user_phone',
        'refresh_token',
        'refresh_token_expiry',
        'password_changed_at',
        'password_expiry_date',
        'last_login_at'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function user_roles()
    {
        return $this->belongsToMany(users_roles::class,'users_roles', 'user_id', 'role_id');
    }

    public function user_zones()
    {
        return $this->belongsToMany(ZoneUser::class, 'user_id', 'zone_id');
    }

    public function user_branches()
    {
        return $this->belongsToMany(BranchUser::class, 'user_id', 'branch_id');
    }

     /**
     * Get notifications for this user
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class)->orderBy('created_at', 'desc');
    }
    
    /**
     * Get unread notifications count
     */
    public function unreadNotificationsCount(): int
    {
        return $this->notifications()->unread()->count();
    }
    
    /**
     * Get unread notifications
     */
    public function unreadNotifications()
    {
        return $this->notifications()->unread()->get();
    }
}
