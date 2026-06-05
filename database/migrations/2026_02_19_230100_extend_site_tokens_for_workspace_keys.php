<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('site_tokens', 'workspace_id')) {
                $table->uuid('workspace_id')->nullable()->after('client_site_id');
            }

            if (! Schema::hasColumn('site_tokens', 'name')) {
                $table->string('name', 120)->nullable()->after('workspace_id');
            }

            if (! Schema::hasColumn('site_tokens', 'key_prefix')) {
                $table->string('key_prefix', 24)->nullable()->after('token_hash');
            }

            if (! Schema::hasColumn('site_tokens', 'abilities')) {
                $table->json('abilities')->nullable()->after('scopes');
            }

            if (! Schema::hasColumn('site_tokens', 'revoked_at')) {
                $table->timestamp('revoked_at')->nullable()->after('revoked');
            }

            if (! Schema::hasColumn('site_tokens', 'last_ip')) {
                $table->string('last_ip', 64)->nullable()->after('last_used_at');
            }
        });

        DB::table('site_tokens')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $workspaceId = null;

                if (! empty($row->client_site_id)) {
                    $workspaceId = DB::table('client_sites')->where('id', $row->client_site_id)->value('workspace_id');
                }

                DB::table('site_tokens')
                    ->where('id', $row->id)
                    ->update([
                        'workspace_id' => $workspaceId,
                        'name' => $row->name ?? 'WordPress plugin key',
                        'abilities' => $row->abilities ?? $row->scopes,
                        'revoked_at' => (bool) ($row->revoked ?? false) ? ($row->updated_at ?? now()) : null,
                    ]);
            }
        }, 'id');

        Schema::table('site_tokens', function (Blueprint $table) {
            if (! $this->indexExists('site_tokens', 'site_tokens_workspace_idx')) {
                $table->index(['workspace_id'], 'site_tokens_workspace_idx');
            }

            if (! $this->indexExists('site_tokens', 'site_tokens_workspace_revoked_idx')) {
                $table->index(['workspace_id', 'revoked'], 'site_tokens_workspace_revoked_idx');
            }

            if (! $this->foreignKeyExists('site_tokens', 'site_tokens_workspace_id_foreign')) {
                $table->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('site_tokens', function (Blueprint $table) {
            if ($this->foreignKeyExists('site_tokens', 'site_tokens_workspace_id_foreign')) {
                $table->dropForeign(['workspace_id']);
            }

            foreach (['site_tokens_workspace_idx', 'site_tokens_workspace_revoked_idx'] as $index) {
                if ($this->indexExists('site_tokens', $index)) {
                    $table->dropIndex($index);
                }
            }

            foreach (['workspace_id', 'name', 'key_prefix', 'abilities', 'revoked_at', 'last_ip'] as $column) {
                if (Schema::hasColumn('site_tokens', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
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
