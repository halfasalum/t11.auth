<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
