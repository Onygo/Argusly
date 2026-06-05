<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_credit_wallets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id')->unique();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->integer('balance_cached')->default(0);
            $table->integer('reserved_cached')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'workspace_id'], 'wcw_org_workspace_idx');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
        });

        Schema::create('workspace_credit_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_credit_wallet_id');
            $table->uuid('workspace_id');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->uuid('client_site_id')->nullable();
            $table->uuid('site_credit_allocation_id')->nullable();
            $table->uuid('credit_reservation_id')->nullable();
            $table->string('type', 48);
            $table->string('source', 32)->nullable();
            $table->integer('amount');
            $table->integer('remaining')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->string('reference_type', 120)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->timestamps();

            $table->index(['workspace_credit_wallet_id', 'created_at'], 'wct_wallet_created_idx');
            $table->index(['workspace_id', 'type', 'created_at'], 'wct_workspace_type_created_idx');
            $table->index(['client_site_id', 'created_at'], 'wct_site_created_idx');
            $table->index(['source', 'remaining'], 'wct_source_remaining_idx');
            $table->unique(['idempotency_key'], 'wct_idempotency_unique');

            $table->foreign('workspace_credit_wallet_id')->references('id')->on('workspace_credit_wallets')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
        });

        Schema::create('site_credit_allocations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('client_site_id')->unique();
            $table->integer('allocated_credits')->default(0);
            $table->integer('reserved_cached')->default(0);
            $table->integer('used_cached')->default(0);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'client_site_id'], 'sca_workspace_site_idx');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('site_credit_allocation_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('client_site_id');
            $table->uuid('from_client_site_id')->nullable();
            $table->uuid('to_client_site_id')->nullable();
            $table->string('action', 48);
            $table->integer('amount');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at'], 'scal_workspace_created_idx');
            $table->index(['client_site_id', 'created_at'], 'scal_site_created_idx');
            $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $table->foreign('client_site_id')->references('id')->on('client_sites')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('credit_reservations', function (Blueprint $table): void {
            if (! Schema::hasColumn('credit_reservations', 'workspace_credit_wallet_id')) {
                $table->uuid('workspace_credit_wallet_id')->nullable()->after('workspace_id');
            }
            if (! Schema::hasColumn('credit_reservations', 'site_credit_allocation_id')) {
                $table->uuid('site_credit_allocation_id')->nullable()->after('client_site_id');
            }

            $table->index(['workspace_credit_wallet_id', 'status'], 'credit_reservations_workspace_wallet_status_idx');
            $table->index(['site_credit_allocation_id', 'status'], 'credit_reservations_allocation_status_idx');
        });

        $this->backfillWorkspaceWalletsAndAllocations();
        $this->backfillWorkspaceTransactions();
        $this->backfillReservationLinks();
    }

    public function down(): void
    {
        Schema::table('credit_reservations', function (Blueprint $table): void {
            foreach ([
                'credit_reservations_workspace_wallet_status_idx',
                'credit_reservations_allocation_status_idx',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (\Throwable) {
                }
            }

            foreach (['workspace_credit_wallet_id', 'site_credit_allocation_id'] as $column) {
                if (Schema::hasColumn('credit_reservations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('site_credit_allocation_logs');
        Schema::dropIfExists('site_credit_allocations');
        Schema::dropIfExists('workspace_credit_transactions');
        Schema::dropIfExists('workspace_credit_wallets');
    }

    private function backfillWorkspaceWalletsAndAllocations(): void
    {
        DB::table('workspaces')->orderBy('id')->chunkById(200, function ($workspaces): void {
            foreach ($workspaces as $workspace) {
                $siteIds = DB::table('client_sites')
                    ->where('workspace_id', $workspace->id)
                    ->pluck('id');

                $balance = (int) DB::table('credit_wallets')
                    ->whereIn('client_site_id', $siteIds)
                    ->sum('balance_cached');

                $reserved = (int) DB::table('credit_wallets')
                    ->whereIn('client_site_id', $siteIds)
                    ->sum('reserved_cached');

                DB::table('workspace_credit_wallets')->insert([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => $workspace->id,
                    'organization_id' => $workspace->organization_id,
                    'balance_cached' => $balance,
                    'reserved_cached' => $reserved,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('client_sites')
                    ->where('workspace_id', $workspace->id)
                    ->orderBy('id')
                    ->chunkById(200, function ($sites) use ($workspace): void {
                        foreach ($sites as $site) {
                            $wallet = DB::table('credit_wallets')
                                ->where('client_site_id', $site->id)
                                ->first();

                            $used = (int) DB::table('credit_ledger_entries')
                                ->where('client_site_id', $site->id)
                                ->where('type', 'usage')
                                ->sum(DB::raw('ABS(amount)'));

                            DB::table('site_credit_allocations')->insert([
                                'id' => (string) Str::uuid(),
                                'workspace_id' => $workspace->id,
                                'client_site_id' => $site->id,
                                'allocated_credits' => (int) ($wallet->balance_cached ?? 0),
                                'reserved_cached' => (int) ($wallet->reserved_cached ?? 0),
                                'used_cached' => $used,
                                'metadata' => json_encode([
                                    'backfilled_from_credit_wallet_id' => $wallet->id ?? null,
                                    'legacy_model' => true,
                                ]),
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }, 'id');
            }
        }, 'id');
    }

    private function backfillWorkspaceTransactions(): void
    {
        DB::table('credit_ledger_entries')
            ->orderBy('id')
            ->chunkById(200, function ($entries): void {
                foreach ($entries as $entry) {
                    $workspaceId = DB::table('credit_wallets')
                        ->where('id', $entry->credit_wallet_id)
                        ->value('workspace_id');

                    if (! $workspaceId) {
                        continue;
                    }

                    $workspaceWalletId = DB::table('workspace_credit_wallets')
                        ->where('workspace_id', $workspaceId)
                        ->value('id');

                    $allocationId = $entry->client_site_id
                        ? DB::table('site_credit_allocations')->where('client_site_id', $entry->client_site_id)->value('id')
                        : null;

                    $metadata = is_array($entry->meta) ? $entry->meta : json_decode((string) $entry->meta, true);

                    DB::table('workspace_credit_transactions')->insert([
                        'id' => (string) Str::uuid(),
                        'workspace_credit_wallet_id' => $workspaceWalletId,
                        'workspace_id' => $workspaceId,
                        'organization_id' => $entry->organization_id,
                        'client_site_id' => $entry->client_site_id,
                        'site_credit_allocation_id' => $allocationId,
                        'credit_reservation_id' => null,
                        'type' => (string) $entry->type,
                        'source' => (string) ($entry->source ?: null),
                        'amount' => (int) $entry->amount,
                        'remaining' => 0,
                        'expires_at' => $entry->expires_at,
                        'reference_type' => $entry->source_type,
                        'reference_id' => $entry->source_id,
                        'metadata' => json_encode(array_merge(is_array($metadata) ? $metadata : [], [
                            'backfilled_from_credit_ledger_entry_id' => $entry->id,
                        ])),
                        'idempotency_key' => $entry->idempotency_key ? 'workspace-backfill:' . $entry->idempotency_key : 'workspace-backfill:' . $entry->id,
                        'created_at' => $entry->created_at,
                        'updated_at' => $entry->updated_at,
                    ]);
                }
            }, 'id');
    }

    private function backfillReservationLinks(): void
    {
        DB::table('credit_reservations')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $workspaceCreditWalletId = $row->workspace_id
                    ? DB::table('workspace_credit_wallets')->where('workspace_id', $row->workspace_id)->value('id')
                    : null;
                $siteCreditAllocationId = $row->client_site_id
                    ? DB::table('site_credit_allocations')->where('client_site_id', $row->client_site_id)->value('id')
                    : null;

                DB::table('credit_reservations')
                    ->where('id', $row->id)
                    ->update([
                        'workspace_credit_wallet_id' => $workspaceCreditWalletId,
                        'site_credit_allocation_id' => $siteCreditAllocationId,
                    ]);
            }
        }, 'id');
    }
};
