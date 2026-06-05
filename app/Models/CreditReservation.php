<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CreditReservation extends Model
{
    use HasUuids;

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_CAPTURED = 'captured';

    public const STATUS_RELEASED = 'released';

    public const STATUS_EXPIRED = 'expired';

    protected $table = 'credit_reservations';

    protected $fillable = [
        'organization_id',
        'workspace_id',
        'workspace_credit_wallet_id',
        'client_site_id',
        'site_credit_allocation_id',
        'credit_wallet_id',
        'user_id',
        'amount',
        'currency_unit',
        'status',
        'context_type',
        'context_id',
        'provider',
        'purpose',
        'idempotency_key',
        'reserved_at',
        'captured_at',
        'released_at',
        'expires_at',
        'failure_code',
        'failure_message',
        'reason',
        'admin_user_id',
        'reservation_ledger_entry_id',
        'capture_ledger_entry_id',
        'release_ledger_entry_id',
        'reservation_workspace_transaction_id',
        'capture_workspace_transaction_id',
        'release_workspace_transaction_id',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'integer',
        'organization_id' => 'integer',
        'user_id' => 'integer',
        'admin_user_id' => 'integer',
        'reserved_at' => 'datetime',
        'captured_at' => 'datetime',
        'released_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Default TTL for reservations in minutes.
     */
    public static function defaultTtlMinutes(): int
    {
        return (int) config('credits.reservation_ttl_minutes', 30);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CreditWallet::class, 'credit_wallet_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function workspaceCreditWallet(): BelongsTo
    {
        return $this->belongsTo(WorkspaceCreditWallet::class, 'workspace_credit_wallet_id');
    }

    public function clientSite(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(SiteCreditAllocation::class, 'site_credit_allocation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    public function reservationLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(CreditLedgerEntry::class, 'reservation_ledger_entry_id');
    }

    public function reservationWorkspaceTransaction(): BelongsTo
    {
        return $this->belongsTo(WorkspaceCreditTransaction::class, 'reservation_workspace_transaction_id');
    }

    public function captureLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(CreditLedgerEntry::class, 'capture_ledger_entry_id');
    }

    public function captureWorkspaceTransaction(): BelongsTo
    {
        return $this->belongsTo(WorkspaceCreditTransaction::class, 'capture_workspace_transaction_id');
    }

    public function releaseLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(CreditLedgerEntry::class, 'release_ledger_entry_id');
    }

    public function releaseWorkspaceTransaction(): BelongsTo
    {
        return $this->belongsTo(WorkspaceCreditTransaction::class, 'release_workspace_transaction_id');
    }

    public function isReserved(): bool
    {
        return $this->status === self::STATUS_RESERVED;
    }

    public function isCaptured(): bool
    {
        return $this->status === self::STATUS_CAPTURED;
    }

    public function isReleased(): bool
    {
        return $this->status === self::STATUS_RELEASED;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function isFinalized(): bool
    {
        return in_array($this->status, [
            self::STATUS_CAPTURED,
            self::STATUS_RELEASED,
            self::STATUS_EXPIRED,
        ], true);
    }

    public function isPastExpiry(): bool
    {
        if (! $this->expires_at) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    public function scopeReserved($query)
    {
        return $query->where('status', self::STATUS_RESERVED);
    }

    public function scopeCaptured($query)
    {
        return $query->where('status', self::STATUS_CAPTURED);
    }

    public function scopeReleased($query)
    {
        return $query->where('status', self::STATUS_RELEASED);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_EXPIRED);
    }

    public function scopeStale($query)
    {
        return $query->where('status', self::STATUS_RESERVED)
            ->where('expires_at', '<=', now());
    }

    public function scopeForWallet($query, string $walletId)
    {
        return $query->where('credit_wallet_id', $walletId);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForContext($query, string $contextType, string $contextId)
    {
        return $query->where('context_type', $contextType)
            ->where('context_id', $contextId);
    }

    public function scopeForPurpose($query, string $purpose)
    {
        return $query->where('purpose', $purpose);
    }
}
