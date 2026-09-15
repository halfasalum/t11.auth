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
        'submission_day_of_week', 'submission_month_option', 'submission_day_of_month',
        'used_as_income', 'product_type', 'income_calculation', 'status',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'mandatory_contribution' => 'boolean',
        'used_as_income' => 'boolean',
        'submission_frequency' => 'integer',
        'submission_day_of_week' => 'integer',
        'submission_day_of_month' => 'integer',
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

    // e.g. "Every 2 weeks on Wednesday" / "Every 1 month on day 29 (or last day)"
    public function getSubmissionScheduleLabelAttribute(): string
    {
        $unit = $this->submission_frequency > 1 ? "{$this->submission_unit}s" : $this->submission_unit;
        $label = "Every {$this->submission_frequency} {$unit}";

        if ($this->submission_unit === 'week' && $this->submission_day_of_week !== null) {
            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $label .= " on {$days[$this->submission_day_of_week]}";
        }

        if ($this->submission_unit === 'month') {
            $label .= $this->submission_month_option === 'specific_date' && $this->submission_day_of_month
                ? " on day {$this->submission_day_of_month} (or the last day of shorter months)"
                : ' on the last day of the month';
        }

        return $label;
    }

    
}
