<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class CreditWalletTransaction extends Model
{
    use HasUuids;

    protected static ?bool $usesLegacyLedgerMirrorColumn = null;

    protected $fillable = [
        'credit_wallet_id',
        'client_site_id',
        'workspace_id',
        'organization_id',
        'type',
        'source',
        'amount',
        'remaining',
        'expires_at',
        'period_start',
        'period_end',
        'source_type',
        'source_id',
        'purchase_payment_id',
        'consumed_from_entry_id',
        'brief_id',
        'user_id',
        'meta',
        'idempotency_key',
    ];

    protected $casts = [
        'amount' => 'integer',
        'remaining' => 'integer',
        'expires_at' => 'datetime',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            if (! self::usesLegacyLedgerMirrorColumn()) {
                return;
            }

            if (! $transaction->id) {
                $transaction->id = (string) static::newUniqueId();
            }

            if (! $transaction->getAttribute('credit_ledger_entry_id')) {
                $transaction->setAttribute('credit_ledger_entry_id', $transaction->id);
            }
        });
    }

    public function ledgerEntry()
    {
        return $this->belongsTo(CreditLedgerEntry::class, 'id');
    }

    public function wallet()
    {
        return $this->belongsTo(CreditWallet::class, 'credit_wallet_id');
    }

    private static function usesLegacyLedgerMirrorColumn(): bool
    {
        if (self::$usesLegacyLedgerMirrorColumn !== null) {
            return self::$usesLegacyLedgerMirrorColumn;
        }

        return self::$usesLegacyLedgerMirrorColumn = Schema::hasColumn('credit_wallet_transactions', 'credit_ledger_entry_id');
    }
}
