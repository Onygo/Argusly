<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditWallet extends Model
{
    use HasUuids;

    protected $table = 'site_credit_allocations';

    protected $fillable = [
        'client_site_id',
        'workspace_id',
        'balance_cached',
        'reserved_cached',
        'allocated_credits',
        'used_cached',
    ];

    protected $casts = [
        'allocated_credits' => 'integer',
        'reserved_cached' => 'integer',
        'used_cached' => 'integer',
    ];

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class, 'credit_wallet_id');
    }

    public function getAvailableAttribute(): int
    {
        return (int) $this->allocated_credits - (int) $this->reserved_cached;
    }

    public function getBalanceCachedAttribute(): int
    {
        return (int) ($this->attributes['allocated_credits'] ?? 0);
    }

    public function setBalanceCachedAttribute($value): void
    {
        $this->attributes['allocated_credits'] = (int) $value;
    }
}
