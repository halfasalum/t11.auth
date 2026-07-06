<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KikobaPenalty extends Model
{
    use HasFactory;

    protected $fillable = [
        'kikoba_contribution_schedule_id', 'kikoba_group_member_id',
        'kikoba_group_product_id', 'amount', 'issued_date',
        'status', 'paid_date', 'reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'issued_date' => 'date',
        'paid_date' => 'date',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(KikobaContributionSchedule::class, 'kikoba_contribution_schedule_id');
    }

    public function groupMember(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupMember::class, 'kikoba_group_member_id');
    }

    public function penaltyProduct(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupProduct::class, 'kikoba_group_product_id');
    }
}
