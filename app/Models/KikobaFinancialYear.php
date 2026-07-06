<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KikobaFinancialYear extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'start_date', 'end_date', 'status', 'is_current',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_current' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function groupFinancialYears(): HasMany
    {
        return $this->hasMany(KikobaGroupFinancialYear::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(KikobaGroup::class, 'kikoba_group_financial_years')
            ->withPivot(['id', 'start_date', 'end_date', 'status'])
            ->withTimestamps();
    }
}
