<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('contents', 'dedupe_fingerprint')) {
                $table->string('dedupe_fingerprint', 64)->nullable()->after('external_key');
            }

            if (! Schema::hasColumn('contents', 'duplicate_checked_at')) {
                $table->timestamp('duplicate_checked_at')->nullable()->after('dedupe_fingerprint');
            }

            if (! Schema::hasColumn('contents', 'duplicate_of_content_id')) {
                $table->uuid('duplicate_of_content_id')->nullable()->after('duplicate_checked_at');
            }
        });

        $this->addIndexIfMissing('contents', 'contents_dedupe_fingerprint_idx', 'CREATE INDEX contents_dedupe_fingerprint_idx ON contents (dedupe_fingerprint)');
        $this->addIndexIfMissing('contents', 'contents_workspace_dedupe_unique', 'CREATE UNIQUE INDEX contents_workspace_dedupe_unique ON contents (workspace_id, dedupe_fingerprint)');

        if (Schema::getConnection()->getDriverName() !== 'sqlite' && ! $this->foreignKeyExists('contents', 'contents_duplicate_of_content_id_fk')) {
            Schema::table('contents', function (Blueprint $table): void {
                $table->foreign('duplicate_of_content_id', 'contents_duplicate_of_content_id_fk')
                    ->references('id')
                    ->on('contents')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->foreignKeyExists('contents', 'contents_duplicate_of_content_id_fk')) {
            Schema::table('contents', function (Blueprint $table): void {
                $table->dropForeign('contents_duplicate_of_content_id_fk');
            });
        }

        $this->dropIndexIfExists('contents', 'contents_workspace_dedupe_unique');
        $this->dropIndexIfExists('contents', 'contents_dedupe_fingerprint_idx');

        Schema::table('contents', function (Blueprint $table): void {
            if (Schema::hasColumn('contents', 'duplicate_of_content_id')) {
                $table->dropColumn('duplicate_of_content_id');
            }

            if (Schema::hasColumn('contents', 'duplicate_checked_at')) {
                $table->dropColumn('duplicate_checked_at');
            }

            if (Schema::hasColumn('contents', 'dedupe_fingerprint')) {
                $table->dropColumn('dedupe_fingerprint');
            }
        });
    }

    private function addIndexIfMissing(string $table, string $index, string $sql): void
    {
        if ($this->indexExists($table, $index)) {
            return;
        }

        DB::statement($sql);
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($index): void {
            $table->dropIndex($index);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list('{$table}')");

            foreach ($indexes as $row) {
                if ((string) ($row->name ?? '') === $index) {
                    return true;
                }
            }

            return false;
        }

        $database = $connection->getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    private function foreignKeyExists(string $table, string $name): bool
    {
        $connection = Schema::getConnection();

        if ($connection->getDriverName() === 'sqlite') {
            return false;
        }

        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', $connection->getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $name)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }
};
