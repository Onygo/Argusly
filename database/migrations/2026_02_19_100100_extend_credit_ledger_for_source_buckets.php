<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('credit_ledger_entries', 'organization_id')) {
                $table->unsignedBigInteger('organization_id')->nullable()->after('client_site_id');
            }

            if (! Schema::hasColumn('credit_ledger_entries', 'source')) {
                $table->string('source', 32)->nullable()->after('type');
            }

            if (! Schema::hasColumn('credit_ledger_entries', 'remaining')) {
                $table->integer('remaining')->default(0)->after('amount');
            }

            if (! Schema::hasColumn('credit_ledger_entries', 'period_start')) {
                $table->timestamp('period_start')->nullable()->after('expires_at');
            }

            if (! Schema::hasColumn('credit_ledger_entries', 'period_end')) {
                $table->timestamp('period_end')->nullable()->after('period_start');
            }

            if (! Schema::hasColumn('credit_ledger_entries', 'purchase_payment_id')) {
                $table->uuid('purchase_payment_id')->nullable()->after('source_id');
            }

            if (! Schema::hasColumn('credit_ledger_entries', 'consumed_from_entry_id')) {
                $table->uuid('consumed_from_entry_id')->nullable()->after('purchase_payment_id');
            }
        });

        if (! $this->indexExists('credit_ledger_entries', 'cle_org_created_idx')) {
            Schema::table('credit_ledger_entries', function (Blueprint $table) {
                $table->index(['organization_id', 'created_at'], 'cle_org_created_idx');
            });
        }

        if (! $this->indexExists('credit_ledger_entries', 'cle_source_exp_created_idx')) {
            Schema::table('credit_ledger_entries', function (Blueprint $table) {
                $table->index(['source', 'expires_at', 'created_at'], 'cle_source_exp_created_idx');
            });
        }

        if (! $this->indexExists('credit_ledger_entries', 'cle_source_remaining_idx')) {
            Schema::table('credit_ledger_entries', function (Blueprint $table) {
                $table->index(['source', 'remaining'], 'cle_source_remaining_idx');
            });
        }

        if (! $this->indexExists('credit_ledger_entries', 'cle_purchase_payment_idx')) {
            Schema::table('credit_ledger_entries', function (Blueprint $table) {
                $table->index(['purchase_payment_id'], 'cle_purchase_payment_idx');
            });
        }

        if (! $this->indexExists('credit_ledger_entries', 'cle_consumed_from_idx')) {
            Schema::table('credit_ledger_entries', function (Blueprint $table) {
                $table->index(['consumed_from_entry_id'], 'cle_consumed_from_idx');
            });
        }

        if (! $this->foreignKeyExists('credit_ledger_entries', 'credit_ledger_entries_organization_id_foreign')) {
            Schema::table('credit_ledger_entries', function (Blueprint $table) {
                $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            });
        }

        $this->backfillData();
    }

    public function down(): void
    {
        if ($this->foreignKeyExists('credit_ledger_entries', 'credit_ledger_entries_organization_id_foreign')) {
            Schema::table('credit_ledger_entries', function (Blueprint $table) {
                $table->dropForeign(['organization_id']);
            });
        }

        foreach ([
            'cle_org_created_idx',
            'cle_source_exp_created_idx',
            'cle_source_remaining_idx',
            'cle_purchase_payment_idx',
            'cle_consumed_from_idx',
        ] as $indexName) {
            if ($this->indexExists('credit_ledger_entries', $indexName)) {
                Schema::table('credit_ledger_entries', function (Blueprint $table) use ($indexName) {
                    $table->dropIndex($indexName);
                });
            }
        }

        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            foreach (['organization_id', 'source', 'remaining', 'period_start', 'period_end', 'purchase_payment_id', 'consumed_from_entry_id'] as $column) {
                if (Schema::hasColumn('credit_ledger_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function backfillData(): void
    {
        DB::table('credit_ledger_entries')->whereNull('organization_id')->orderBy('id')->chunkById(200, function ($entries): void {
            foreach ($entries as $entry) {
                $organizationId = DB::table('client_sites as cs')
                    ->join('workspaces as w', 'w.id', '=', 'cs.workspace_id')
                    ->where('cs.id', $entry->client_site_id)
                    ->value('w.organization_id');

                DB::table('credit_ledger_entries')
                    ->where('id', $entry->id)
                    ->update(['organization_id' => $organizationId]);
            }
        }, 'id');

        DB::table('credit_ledger_entries')->orderBy('created_at')->chunkById(200, function ($entries): void {
            foreach ($entries as $entry) {
                $meta = is_array($entry->meta) ? $entry->meta : json_decode((string) $entry->meta, true);
                $source = $entry->source;
                $remaining = (int) $entry->remaining;
                $periodStart = $entry->period_start;
                $periodEnd = $entry->period_end;
                $purchasePaymentId = $entry->purchase_payment_id;

                if (! $source) {
                    if ($entry->type === 'allowance') {
                        $source = 'included_plan';
                    } elseif (in_array($entry->type, ['pack_purchase', 'refund', 'adjustment'], true)) {
                        $source = 'addon_pack';
                    } else {
                        $source = 'usage';
                    }
                }

                if ($remaining === 0 && (int) $entry->amount > 0 && in_array($source, ['included_plan', 'addon_pack'], true)) {
                    $remaining = (int) $entry->amount;
                }

                if (! $periodStart && $entry->type === 'allowance') {
                    $periodStart = data_get($meta, 'period_start');
                }

                if (! $periodEnd && $entry->type === 'allowance') {
                    $periodEnd = data_get($meta, 'period_end');
                }

                if (! $purchasePaymentId) {
                    $purchasePaymentId = data_get($meta, 'payment_intent_id');
                }

                $periodStart = $this->normalizeTimestamp($periodStart);
                $periodEnd = $this->normalizeTimestamp($periodEnd);

                DB::table('credit_ledger_entries')
                    ->where('id', $entry->id)
                    ->update([
                        'source' => $source,
                        'remaining' => $remaining,
                        'period_start' => $periodStart,
                        'period_end' => $periodEnd,
                        'purchase_payment_id' => $purchasePaymentId,
                    ]);
            }
        }, 'id');
    }

    private function normalizeTimestamp($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d H:i:s');
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $database = DB::getDatabaseName();
            $row = DB::selectOne(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
                [$database, $table, $indexName]
            );

            return $row !== null;
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if (($row->name ?? null) === $indexName) {
                    return true;
                }
            }
        }

        return false;
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $database = DB::getDatabaseName();
            $row = DB::selectOne(
                'SELECT 1 FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = ? AND constraint_name = ? AND constraint_type = ? LIMIT 1',
                [$database, $table, $constraintName, 'FOREIGN KEY']
            );

            return $row !== null;
        }

        return false;
    }
};
