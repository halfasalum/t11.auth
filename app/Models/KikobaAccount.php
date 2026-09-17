<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KikobaAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'kikoba_group_id', 'account_name', 'account_number',
        'balance', 'currency', 'status', 'is_primary', 'description', 'created_by',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'is_primary' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(KikobaGroup::class, 'kikoba_group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(KikobaAccountTransaction::class)->orderByDesc('id');
    }

    public function hasSufficientBalance(float $amount): bool
    {
        return (float) $this->balance >= $amount;
    }
}
