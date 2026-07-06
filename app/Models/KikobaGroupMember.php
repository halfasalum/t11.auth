<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KikobaGroupMember extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'kikoba_group_id', 'kikoba_member_id', 'role',
        'joined_date', 'exit_date', 'status',
    ];

    protected $casts = [
        'joined_date' => 'date',
        'exit_date' => 'date',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(KikobaGroup::class, 'kikoba_group_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(KikobaMember::class, 'kikoba_member_id');
    }

    public function memberProducts(): HasMany
    {
        return $this->hasMany(KikobaGroupMemberProduct::class);
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(KikobaPenalty::class);
    }
}
