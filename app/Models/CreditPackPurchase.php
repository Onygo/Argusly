<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CreditPackPurchase extends Model
{
    use HasUuids;

    protected $fillable = [
        'client_site_id',
        'credit_pack_id',
        'status',
        'credits_amount',
        'price_cents',
        'currency',
        'provider',
        'provider_payment_id',
        'provider_customer_id',
        'credit_ledger_entry_id',
        'workspace_credit_transaction_id',
        'paid_at',
        'purchased_credit_expires_at',
        'failed_at',
        'refunded_at',
        'canceled_at',
        'meta',
    ];

    protected $casts = [
        'credits_amount' => 'integer',
        'price_cents' => 'integer',
        'paid_at' => 'datetime',
        'purchased_credit_expires_at' => 'datetime',
        'failed_at' => 'datetime',
        'refunded_at' => 'datetime',
        'canceled_at' => 'datetime',
        'meta' => 'array',
    ];

    public function creditPack()
    {
        return $this->belongsTo(CreditPack::class);
    }

    public function clientSite()
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function paymentIntents()
    {
        return $this->morphMany(PaymentIntent::class, 'billable');
    }

    public function invoice()
    {
        return $this->hasOne(Invoice::class);
    }

    public function workspaceCreditTransaction()
    {
        return $this->belongsTo(WorkspaceCreditTransaction::class, 'workspace_credit_transaction_id');
    }
}
