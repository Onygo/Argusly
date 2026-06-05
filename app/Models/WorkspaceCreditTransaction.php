<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceCreditTransaction extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_credit_wallet_id',
        'workspace_id',
        'organization_id',
        'client_site_id',
        'site_credit_allocation_id',
        'credit_reservation_id',
        'type',
        'source',
        'amount',
        'remaining',
        'expires_at',
        'reference_type',
        'reference_id',
        'metadata',
        'idempotency_key',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'amount' => 'integer',
        'remaining' => 'integer',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(WorkspaceCreditWallet::class, 'workspace_credit_wallet_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(SiteCreditAllocation::class, 'site_credit_allocation_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(CreditReservation::class, 'credit_reservation_id');
    }
}
