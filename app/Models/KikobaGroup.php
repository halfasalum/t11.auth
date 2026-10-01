<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class KikobaGroup extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'code', 'description', 'status', 'created_by',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(KikobaGroupMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', 'active');
    }

    /**
     * Members who were part of this group at any point during a date range
     * — by joined_date/exit_date overlap, not the member's status right
     * now. Used for anything financial-year-related (payout close reports),
     * where activeMembers()'s "current status" meaning is wrong: a member
     * counts for a cycle if they joined on or before it ended AND didn't
     * exit before it started — so someone who joined mid-cycle (e.g. the
     * group's digital records only started partway through its nominal
     * start_date) is still included, while someone who joined only after
     * the cycle had already ended, or who'd already exited before it began,
     * is not. status/exit_date aren't guaranteed to be kept in sync
     * (exit_date can be left null even once status is no longer 'active'),
     * so a null exit_date is treated as "still a member" rather than
     * inferring anything from status.
     */
    public function membersDuringCycle($startDate, $endDate): HasMany
    {
        return $this->members()
            ->where('joined_date', '<=', $endDate)
            ->where(function ($query) use ($startDate) {
                $query->whereNull('exit_date')->orWhere('exit_date', '>=', $startDate);
            });
    }

    public function groupProducts(): HasMany
    {
        return $this->hasMany(KikobaGroupProduct::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(KikobaProduct::class, 'kikoba_group_products')
            ->withPivot(['id', 'value_override', 'min_unit_override', 'max_unit_override', 'mandatory_override', 'status'])
            ->withTimestamps();
    }

    public function groupFinancialYears(): HasMany
    {
        return $this->hasMany(KikobaGroupFinancialYear::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(KikobaAccount::class);
    }

    public function primaryAccount(): HasOne
    {
        return $this->hasOne(KikobaAccount::class)->where('is_primary', true);
    }

    public function financialYears(): BelongsToMany
    {
        return $this->belongsToMany(KikobaFinancialYear::class, 'kikoba_group_financial_years')
            ->withPivot(['id', 'start_date', 'end_date', 'status'])
            ->withTimestamps();
    }

    public function currentFinancialYear(): ?KikobaFinancialYear
    {
        return $this->financialYears()->wherePivot('status', 'active')->first();
    }
}
