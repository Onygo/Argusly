<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_sites', function (Blueprint $table) {
            if (! Schema::hasColumn('client_sites', 'base_url')) {
                $table->string('base_url')->nullable()->after('site_url');
            }

            if (! Schema::hasColumn('client_sites', 'status')) {
                $table->string('status', 20)->default('pending')->after('is_active');
            }

            if (! Schema::hasColumn('client_sites', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('status');
            }

            if (! Schema::hasColumn('client_sites', 'last_healthcheck_at')) {
                $table->timestamp('last_healthcheck_at')->nullable()->after('last_seen_at');
            }

            if (! Schema::hasColumn('client_sites', 'last_error')) {
                $table->text('last_error')->nullable()->after('last_healthcheck_at');
            }

            if (! Schema::hasColumn('client_sites', 'wp_version')) {
                $table->string('wp_version', 40)->nullable()->after('last_error');
            }

            if (! Schema::hasColumn('client_sites', 'plugin_version')) {
                $table->string('plugin_version', 40)->nullable()->after('wp_version');
            }

            if (! Schema::hasColumn('client_sites', 'capabilities')) {
                $table->json('capabilities')->nullable()->after('plugin_version');
            }

            if (! Schema::hasColumn('client_sites', 'created_by_user_id')) {
                $table->unsignedBigInteger('created_by_user_id')->nullable()->after('workspace_id');
            }

            if (! Schema::hasColumn('client_sites', 'disabled_at')) {
                $table->timestamp('disabled_at')->nullable()->after('created_by_user_id');
            }

            if (! Schema::hasColumn('client_sites', 'deleted_at')) {
                $table->softDeletes();
            }
        });

        DB::table('client_sites')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                $base = $this->normalizeBaseUrl((string) ($row->site_url ?? ''));
                $status = (bool) ($row->is_active ?? true) ? 'pending' : 'disabled';

                DB::table('client_sites')
                    ->where('id', $row->id)
                    ->update([
                        'base_url' => $base,
                        'status' => $status,
                        'disabled_at' => $status === 'disabled' ? ($row->updated_at ?? now()) : null,
                    ]);
            }
        }, 'id');

        Schema::table('client_sites', function (Blueprint $table) {
            if (! $this->indexExists('client_sites', 'client_sites_workspace_base_unique')) {
                $table->unique(['workspace_id', 'base_url'], 'client_sites_workspace_base_unique');
            }

            if (! $this->indexExists('client_sites', 'client_sites_workspace_status_idx')) {
                $table->index(['workspace_id', 'status'], 'client_sites_workspace_status_idx');
            }

            if (! $this->indexExists('client_sites', 'client_sites_last_seen_idx')) {
                $table->index(['last_seen_at'], 'client_sites_last_seen_idx');
            }

            if (! $this->foreignKeyExists('client_sites', 'client_sites_created_by_user_id_foreign')) {
                $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_sites', function (Blueprint $table) {
            if ($this->foreignKeyExists('client_sites', 'client_sites_created_by_user_id_foreign')) {
                $table->dropForeign(['created_by_user_id']);
            }

            foreach (['client_sites_workspace_base_unique', 'client_sites_workspace_status_idx', 'client_sites_last_seen_idx'] as $index) {
                if ($this->indexExists('client_sites', $index)) {
                    if ($index === 'client_sites_workspace_base_unique') {
                        $table->dropUnique($index);
                    } else {
                        $table->dropIndex($index);
                    }
                }
            }

            foreach (['base_url', 'status', 'last_seen_at', 'last_healthcheck_at', 'last_error', 'wp_version', 'plugin_version', 'capabilities', 'created_by_user_id', 'disabled_at', 'deleted_at'] as $column) {
                if (Schema::hasColumn('client_sites', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (! str_contains($url, '://')) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return rtrim(strtolower($url), '/');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (! in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }

        $host = strtolower((string) $parts['host']);
        $path = isset($parts['path']) ? '/' . trim((string) $parts['path'], '/') : '';

        return rtrim($scheme . '://' . $host . $path, '/');
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
