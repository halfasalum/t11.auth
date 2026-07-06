<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KikobaContribution extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'group_member_product_id', 'kikoba_contribution_schedule_id',
        'amount', 'paid_date', 'reference', 'payment_method',
        'received_by', 'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_date' => 'date',
    ];

    public function memberProduct(): BelongsTo
    {
        return $this->belongsTo(KikobaGroupMemberProduct::class, 'group_member_product_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(KikobaContributionSchedule::class, 'kikoba_contribution_schedule_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
