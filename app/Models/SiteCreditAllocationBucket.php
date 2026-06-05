<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteCreditAllocationBucket extends Model
{
    use HasUuids;

    protected $fillable = [
        'site_credit_allocation_id',
        'workspace_credit_transaction_id',
        'workspace_id',
        'client_site_id',
        'source',
        'amount',
        'remaining',
        'expires_at',
        'reference_type',
        'reference_id',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'remaining' => 'integer',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(SiteCreditAllocation::class, 'site_credit_allocation_id');
    }

    public function workspaceTransaction(): BelongsTo
    {
        return $this->belongsTo(WorkspaceCreditTransaction::class, 'workspace_credit_transaction_id');
    }
}
