<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contents')) {
            return;
        }

        Schema::table('contents', function (Blueprint $table): void {
            $this->indexIfMissing($table, 'contents', ['language', 'status', 'publish_status'], 'cnt_lang_stat_pub');
            $this->indexIfMissing($table, 'contents', ['publish_status', 'first_published_at'], 'cnt_pub_first');
            $this->indexIfMissing($table, 'contents', ['language', 'publish_url_key'], 'cnt_lang_slug');
            $this->indexIfMissing($table, 'contents', ['family_id', 'updated_at'], 'cnt_family_upd');
            $this->indexIfMissing($table, 'contents', ['updated_at'], 'cnt_upd');

            if (Schema::hasColumn('contents', 'deleted_at')) {
                $this->indexIfMissing($table, 'contents', ['deleted_at'], 'cnt_deleted');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('contents')) {
            return;
        }

        Schema::table('contents', function (Blueprint $table): void {
            foreach (['cnt_lang_stat_pub', 'cnt_pub_first', 'cnt_lang_slug', 'cnt_family_upd', 'cnt_upd', 'cnt_deleted'] as $index) {
                if ($this->hasIndex('contents', $index)) {
                    $table->dropIndex($index);
                }
            }
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function indexIfMissing(Blueprint $table, string $tableName, array $columns, string $name): void
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                return;
            }
        }

        if (! $this->hasIndex($tableName, $name)) {
            $table->index($columns, $name);
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");

            foreach ($rows as $row) {
                if ((string) ($row->name ?? '') === $index) {
                    return true;
                }
            }

            return false;
        }

        $database = (string) $connection->getDatabaseName();
        $rows = DB::select(
            'SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1',
            [$database, $table, $index]
        );

        return $rows !== [];
    }
};
