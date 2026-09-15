<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KikobaFinancialYearCloseReport extends Model
{
    use HasFactory;
 
    protected $fillable = [
        'kikoba_group_id',
        'group_financial_year_id',
        'kikoba_group_member_id',
        'total_savings_amount',
        'total_share_units',
        'total_share_amount',
        'profit_amount',
        'total_payout',
        'breakdown',
        'status',
        'generated_by',
        'generated_at',
        'finalized_at',
    ];

    protected $casts = [
        'total_savings_amount' => 'float',
        'total_share_units' => 'integer',
        'total_share_amount' => 'float',
        'profit_amount' => 'float',
        'total_payout' => 'float',
        'breakdown' => 'array',
        'generated_at' => 'datetime',
        'finalized_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(KikobaGroup::class, 'kikoba_group_id');
    }

    public function groupFinancialYear(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupFinancialYear::class, 'group_financial_year_id');
    }

    public function groupMember(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupMember::class, 'kikoba_group_member_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
