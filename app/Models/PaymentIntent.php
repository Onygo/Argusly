<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PaymentIntent extends Model
{
    use HasUuids;

    protected $fillable = [
        'billable_type',
        'billable_id',
        'provider',
        'status',
        'amount_cents',
        'currency',
        'provider_payment_id',
        'checkout_url',
        'idempotency_key',
        'last_provider_status',
        'paid_at',
        'failed_at',
        'canceled_at',
        'meta',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'meta' => 'array',
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
        'canceled_at' => 'datetime',
    ];

    public function billable()
    {
        return $this->morphTo();
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }
}
