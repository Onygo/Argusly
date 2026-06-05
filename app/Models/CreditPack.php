<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CreditPack extends Model
{
    use HasUuids;

    protected $fillable = [
        'key',
        'name',
        'credits_amount',
        'price_cents',
        'currency',
        'vat_included',
        'expires_in_months',
        'never_expires',
        'is_active',
        'provider',
        'provider_product_id',
        'meta',
    ];

    protected $casts = [
        'credits_amount' => 'integer',
        'price_cents' => 'integer',
        'vat_included' => 'boolean',
        'expires_in_months' => 'integer',
        'never_expires' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];
}
