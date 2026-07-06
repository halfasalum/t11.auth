<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KikobaGroupFinancialYear extends Model
{
    use HasFactory;

    protected $fillable = [
        'kikoba_group_id', 'kikoba_financial_year_id',
        'start_date', 'end_date', 'status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(KikobaGroup::class, 'kikoba_group_id');
    }

    public function financialYear(): BelongsTo
    {
        return $this->belongsTo(KikobaFinancialYear::class, 'kikoba_financial_year_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(KikobaContributionSchedule::class);
    }
}
