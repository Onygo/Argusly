<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $this->dropLegacyForeignKeys();
        $this->remapLegacyWalletReferencesToAllocations();
        $this->promoteCreditWalletTransactionsToCanonicalLegacyEntryIds();
        $this->addCompatibilityForeignKeys();

        Schema::dropIfExists('credit_ledger_entries');
        Schema::dropIfExists('credit_wallets');
    }

    public function down(): void
    {
        // Destructive migration. Legacy tables are intentionally not recreated here.
    }

    private function remapLegacyWalletReferencesToAllocations(): void
    {
        DB::table('credit_wallet_transactions')
            ->orderBy('created_at')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $allocationId = $row->client_site_id
                        ? DB::table('site_credit_allocations')->where('client_site_id', $row->client_site_id)->value('id')
                        : null;

                    if ($allocationId) {
                        DB::table('credit_wallet_transactions')
                            ->where('id', $row->id)
                            ->update(['credit_wallet_id' => $allocationId]);
                    }
                }
            }, 'id');

        DB::table('drafts')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                if (! $row->client_site_id) {
                    continue;
                }

                $allocationId = DB::table('site_credit_allocations')
                    ->where('client_site_id', $row->client_site_id)
                    ->value('id');

                if ($allocationId) {
                    DB::table('drafts')
                        ->where('id', $row->id)
                        ->update(['credit_wallet_id' => $allocationId]);
                }
            }
        }, 'id');

        DB::table('content_images')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $clientSiteId = DB::table('contents')->where('id', $row->content_id)->value('client_site_id');
                if (! $clientSiteId) {
                    continue;
                }

                $allocationId = DB::table('site_credit_allocations')
                    ->where('client_site_id', $clientSiteId)
                    ->value('id');

                if ($allocationId) {
                    DB::table('content_images')
                        ->where('id', $row->id)
                        ->update(['credit_wallet_id' => $allocationId]);
                }
            }
        }, 'id');

        DB::table('credit_reservations')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $allocationId = $row->site_credit_allocation_id;

                if (! $allocationId && $row->client_site_id) {
                    $allocationId = DB::table('site_credit_allocations')
                        ->where('client_site_id', $row->client_site_id)
                        ->value('id');
                }

                if ($allocationId) {
                    DB::table('credit_reservations')
                        ->where('id', $row->id)
                        ->update(['credit_wallet_id' => $allocationId]);
                }
            }
        }, 'id');
    }

    private function promoteCreditWalletTransactionsToCanonicalLegacyEntryIds(): void
    {
        DB::statement('UPDATE credit_wallet_transactions SET id = credit_ledger_entry_id');
    }

    private function dropLegacyForeignKeys(): void
    {
        $this->dropForeignKeysForColumns('drafts', ['credit_wallet_id']);
        $this->dropForeignKeysForColumns('content_credit_logs', ['credit_ledger_entry_id']);
        $this->dropForeignKeysForColumns('credit_reservations', [
            'credit_wallet_id',
            'reservation_ledger_entry_id',
            'capture_ledger_entry_id',
            'release_ledger_entry_id',
        ]);
        $this->dropForeignKeysForColumns('credit_wallet_transactions', [
            'credit_ledger_entry_id',
            'credit_wallet_id',
        ]);
    }

    private function addCompatibilityForeignKeys(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('credit_wallet_transactions', function (Blueprint $table): void {
            if (Schema::hasColumn('credit_wallet_transactions', 'credit_ledger_entry_id')) {
                try {
                    $table->dropUnique(['credit_ledger_entry_id']);
                } catch (\Throwable) {
                }
                $table->dropColumn('credit_ledger_entry_id');
            }
        });
    }

    private function dropForeignKeysForColumns(string $table, array $columns): void
    {
        // SQLite test databases do not expose MySQL-style information_schema metadata.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $database = DB::getDatabaseName();

        foreach ($columns as $column) {
            $constraints = DB::table('information_schema.KEY_COLUMN_USAGE')
                ->where('TABLE_SCHEMA', $database)
                ->where('TABLE_NAME', $table)
                ->where('COLUMN_NAME', $column)
                ->whereNotNull('REFERENCED_TABLE_NAME')
                ->pluck('CONSTRAINT_NAME');

            foreach ($constraints as $constraint) {
                DB::statement(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY `%s`', $table, $constraint));
            }
        }
    }
};
