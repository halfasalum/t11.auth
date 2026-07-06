<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KikobaProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'description', 'value', 'min_unit', 'max_unit',
        'mandatory_contribution', 'submission_unit', 'submission_frequency',
        'used_as_income', 'product_type', 'income_calculation', 'status',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'mandatory_contribution' => 'boolean',
        'used_as_income' => 'boolean',
        'submission_frequency' => 'integer',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function groupProducts(): HasMany
    {
        return $this->hasMany(KikobaGroupProduct::class);
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(KikobaGroup::class, 'kikoba_group_products')
            ->withPivot(['id', 'value_override', 'min_unit_override', 'max_unit_override', 'mandatory_override', 'status'])
            ->withTimestamps();
    }

    // e.g. "Every 2 weeks"
    public function getSubmissionScheduleLabelAttribute(): string
    {
        $unit = $this->submission_frequency > 1 ? "{$this->submission_unit}s" : $this->submission_unit;

        return "Every {$this->submission_frequency} {$unit}";
    }
}
