<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreditLedgerEntry extends Model
{
    use HasUuids;

    protected $table = 'credit_wallet_transactions';

    protected static ?bool $usesLegacyLedgerMirrorColumn = null;

    protected $fillable = [
        'credit_wallet_id',
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
        'client_site_id',
        'organization_id',
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
        static::creating(function (self $entry): void {
            if (! self::usesLegacyLedgerMirrorColumn()) {
                return;
            }

            if (! $entry->id) {
                $entry->id = (string) static::newUniqueId();
            }

            if (! $entry->getAttribute('credit_ledger_entry_id')) {
                $entry->setAttribute('credit_ledger_entry_id', $entry->id);
            }

            self::ensureLegacyMirror($entry);
        });
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CreditWallet::class, 'credit_wallet_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(CreditWalletTransaction::class, 'id');
    }

    private static function usesLegacyLedgerMirrorColumn(): bool
    {
        if (self::$usesLegacyLedgerMirrorColumn !== null) {
            return self::$usesLegacyLedgerMirrorColumn;
        }

        return self::$usesLegacyLedgerMirrorColumn = Schema::hasColumn('credit_wallet_transactions', 'credit_ledger_entry_id');
    }

    private static function ensureLegacyMirror(self $entry): void
    {
        if (! Schema::hasTable('credit_ledger_entries') || ! Schema::hasTable('credit_wallets')) {
            return;
        }

        $legacyWalletId = (string) $entry->credit_wallet_id;
        $clientSiteId = (string) ($entry->client_site_id ?? '');

        if ($legacyWalletId !== '' && $clientSiteId !== '') {
            DB::table('credit_wallets')->updateOrInsert(
                ['id' => $legacyWalletId],
                [
                    'client_site_id' => $clientSiteId,
                    'balance_cached' => 0,
                    'reserved_cached' => 0,
                    'created_at' => $entry->created_at ?? now(),
                    'updated_at' => $entry->updated_at ?? now(),
                ]
            );
        }

        DB::table('credit_ledger_entries')->updateOrInsert(
            ['id' => (string) $entry->getAttribute('credit_ledger_entry_id')],
            [
                'credit_wallet_id' => $legacyWalletId !== '' ? $legacyWalletId : null,
                'type' => (string) $entry->type,
                'amount' => (int) $entry->amount,
                'expires_at' => $entry->expires_at,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'brief_id' => $entry->brief_id,
                'client_site_id' => $entry->client_site_id,
                'user_id' => $entry->user_id,
                'meta' => json_encode($entry->meta ?? []),
                'created_at' => $entry->created_at ?? now(),
                'updated_at' => $entry->updated_at ?? now(),
                'organization_id' => $entry->organization_id,
                'source' => $entry->source,
                'remaining' => $entry->remaining,
                'period_start' => $entry->period_start,
                'period_end' => $entry->period_end,
                'purchase_payment_id' => $entry->purchase_payment_id,
                'consumed_from_entry_id' => $entry->consumed_from_entry_id,
                'idempotency_key' => $entry->idempotency_key,
            ]
        );
    }
}
