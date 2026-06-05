<?php

use App\Models\CreditPackPurchase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table): void {
            if (! Schema::hasColumn('drafts', 'workspace_credit_wallet_id')) {
                $table->uuid('workspace_credit_wallet_id')->nullable()->after('credit_wallet_id');
                $table->index(['workspace_credit_wallet_id'], 'drafts_workspace_credit_wallet_idx');
            }

            if (! Schema::hasColumn('drafts', 'workspace_credit_transaction_id')) {
                $table->uuid('workspace_credit_transaction_id')->nullable()->after('credit_ledger_entry_id');
                $table->index(['workspace_credit_transaction_id'], 'drafts_workspace_credit_transaction_idx');
            }
        });

        Schema::table('content_images', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_images', 'workspace_credit_wallet_id')) {
                $table->uuid('workspace_credit_wallet_id')->nullable()->after('credit_wallet_id');
                $table->index(['workspace_credit_wallet_id'], 'content_images_workspace_credit_wallet_idx');
            }

            if (! Schema::hasColumn('content_images', 'workspace_credit_transaction_id')) {
                $table->uuid('workspace_credit_transaction_id')->nullable()->after('credit_ledger_entry_id');
                $table->index(['workspace_credit_transaction_id'], 'content_images_workspace_credit_transaction_idx');
            }
        });

        Schema::table('credit_reservations', function (Blueprint $table): void {
            foreach ([
                'reservation_workspace_transaction_id',
                'capture_workspace_transaction_id',
                'release_workspace_transaction_id',
            ] as $column) {
                if (! Schema::hasColumn('credit_reservations', $column)) {
                    $table->uuid($column)->nullable()->after('release_ledger_entry_id');
                    $table->index([$column], 'credit_reservations_' . $column . '_idx');
                }
            }
        });

        Schema::table('content_credit_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_credit_logs', 'workspace_credit_transaction_id')) {
                $table->uuid('workspace_credit_transaction_id')->nullable()->after('credit_ledger_entry_id');
                $table->index(['workspace_credit_transaction_id'], 'content_credit_logs_workspace_credit_transaction_idx');
            }
        });

        Schema::table('credit_pack_purchases', function (Blueprint $table): void {
            if (! Schema::hasColumn('credit_pack_purchases', 'workspace_credit_transaction_id')) {
                $table->uuid('workspace_credit_transaction_id')->nullable()->after('credit_ledger_entry_id');
                $table->index(['workspace_credit_transaction_id'], 'credit_pack_purchases_workspace_credit_transaction_idx');
            }
        });

        Schema::table('content_series_generation_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_series_generation_runs', 'workspace_credit_transaction_id')) {
                $table->uuid('workspace_credit_transaction_id')->nullable()->after('credit_ledger_entry_id');
                $table->index(['workspace_credit_transaction_id'], 'content_series_generation_runs_workspace_credit_transaction_idx');
            }
        });

        $this->backfillWorkspaceWalletReferences();
        $this->backfillReservationTransactions();
        $this->backfillPurchaseTransactions();
    }

    public function down(): void
    {
        Schema::table('content_series_generation_runs', function (Blueprint $table): void {
            if (Schema::hasColumn('content_series_generation_runs', 'workspace_credit_transaction_id')) {
                $table->dropIndex('content_series_generation_runs_workspace_credit_transaction_idx');
                $table->dropColumn('workspace_credit_transaction_id');
            }
        });

        Schema::table('credit_pack_purchases', function (Blueprint $table): void {
            if (Schema::hasColumn('credit_pack_purchases', 'workspace_credit_transaction_id')) {
                $table->dropIndex('credit_pack_purchases_workspace_credit_transaction_idx');
                $table->dropColumn('workspace_credit_transaction_id');
            }
        });

        Schema::table('content_credit_logs', function (Blueprint $table): void {
            if (Schema::hasColumn('content_credit_logs', 'workspace_credit_transaction_id')) {
                $table->dropIndex('content_credit_logs_workspace_credit_transaction_idx');
                $table->dropColumn('workspace_credit_transaction_id');
            }
        });

        Schema::table('credit_reservations', function (Blueprint $table): void {
            foreach ([
                'reservation_workspace_transaction_id',
                'capture_workspace_transaction_id',
                'release_workspace_transaction_id',
            ] as $column) {
                if (Schema::hasColumn('credit_reservations', $column)) {
                    $table->dropIndex('credit_reservations_' . $column . '_idx');
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('content_images', function (Blueprint $table): void {
            if (Schema::hasColumn('content_images', 'workspace_credit_transaction_id')) {
                $table->dropIndex('content_images_workspace_credit_transaction_idx');
                $table->dropColumn('workspace_credit_transaction_id');
            }

            if (Schema::hasColumn('content_images', 'workspace_credit_wallet_id')) {
                $table->dropIndex('content_images_workspace_credit_wallet_idx');
                $table->dropColumn('workspace_credit_wallet_id');
            }
        });

        Schema::table('drafts', function (Blueprint $table): void {
            if (Schema::hasColumn('drafts', 'workspace_credit_transaction_id')) {
                $table->dropIndex('drafts_workspace_credit_transaction_idx');
                $table->dropColumn('workspace_credit_transaction_id');
            }

            if (Schema::hasColumn('drafts', 'workspace_credit_wallet_id')) {
                $table->dropIndex('drafts_workspace_credit_wallet_idx');
                $table->dropColumn('workspace_credit_wallet_id');
            }
        });
    }

    private function backfillWorkspaceWalletReferences(): void
    {
        DB::table('drafts')
            ->whereNull('workspace_credit_wallet_id')
            ->whereNotNull('client_site_id')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $workspaceId = DB::table('client_sites')->where('id', $row->client_site_id)->value('workspace_id');
                    if (! $workspaceId) {
                        continue;
                    }

                    $workspaceWalletId = DB::table('workspace_credit_wallets')->where('workspace_id', $workspaceId)->value('id');
                    if ($workspaceWalletId) {
                        DB::table('drafts')->where('id', $row->id)->update(['workspace_credit_wallet_id' => $workspaceWalletId]);
                    }
                }
            });

        DB::table('content_images')
            ->whereNull('workspace_credit_wallet_id')
            ->whereNotNull('content_id')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $clientSiteId = DB::table('contents')->where('id', $row->content_id)->value('client_site_id');
                    if (! $clientSiteId) {
                        continue;
                    }

                    $workspaceId = DB::table('client_sites')->where('id', $clientSiteId)->value('workspace_id');
                    $workspaceWalletId = $workspaceId
                        ? DB::table('workspace_credit_wallets')->where('workspace_id', $workspaceId)->value('id')
                        : null;

                    if ($workspaceWalletId) {
                        DB::table('content_images')->where('id', $row->id)->update(['workspace_credit_wallet_id' => $workspaceWalletId]);
                    }
                }
            });
    }

    private function backfillReservationTransactions(): void
    {
        DB::table('credit_reservations')
            ->where(function ($query): void {
                $query
                    ->whereNull('reservation_workspace_transaction_id')
                    ->orWhereNull('capture_workspace_transaction_id')
                    ->orWhereNull('release_workspace_transaction_id');
            })
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $updates = [];

                    $reserveId = DB::table('workspace_credit_transactions')
                        ->where('credit_reservation_id', $row->id)
                        ->where('type', 'reserve')
                        ->value('id');
                    if ($reserveId) {
                        $updates['reservation_workspace_transaction_id'] = $reserveId;
                    }

                    $captureId = DB::table('workspace_credit_transactions')
                        ->where('credit_reservation_id', $row->id)
                        ->where('type', 'commit')
                        ->value('id');
                    if ($captureId) {
                        $updates['capture_workspace_transaction_id'] = $captureId;
                    }

                    $releaseId = DB::table('workspace_credit_transactions')
                        ->where('credit_reservation_id', $row->id)
                        ->where('type', 'release')
                        ->value('id');
                    if ($releaseId) {
                        $updates['release_workspace_transaction_id'] = $releaseId;
                    }

                    if ($updates !== []) {
                        DB::table('credit_reservations')->where('id', $row->id)->update($updates);
                    }
                }
            });
    }

    private function backfillPurchaseTransactions(): void
    {
        DB::table('credit_pack_purchases')
            ->whereNull('workspace_credit_transaction_id')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $transactionId = DB::table('workspace_credit_transactions')
                        ->where('reference_type', CreditPackPurchase::class)
                        ->where('reference_id', $row->id)
                        ->orderByDesc('created_at')
                        ->value('id');

                    if ($transactionId) {
                        DB::table('credit_pack_purchases')
                            ->where('id', $row->id)
                            ->update(['workspace_credit_transaction_id' => $transactionId]);
                    }
                }
            });
    }
};
