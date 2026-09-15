<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class KikobaMember extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'customer_id',
        'member_no',
        'first_name',
        'middle_name',
        'last_name',
        'gender',
        'date_of_birth',
        'phone',
        'email',
        'address',
        'id_type',
        'id_number',
        'next_of_kin_name',
        'next_of_kin_phone',
        'next_of_kin_relationship',
        'photo_path',
        'status',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function shareValue()
    {
        $groupMember = KikobaGroupMember::where('kikoba_group_id', $this->group->kikoba_group_id)->first();
        $groupProduct = KikobaGroupProduct::where('kikoba_group_id', $groupMember->kikoba_group_id)->where('product_type', 'shares')->first();
        $product = KikobaProduct::where('id', $groupProduct->kikoba_product_id)->where('product_type', 'shares')->first();

        return $product->share_value;
    }

    public function group()
    {
        return $this->belongsTo(KikobaGroupMember::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customers::class);
    }

    public function groupMemberships(): HasMany
    {
        return $this->hasMany(KikobaGroupMember::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
    }
}
