<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agentic_marketing_execution_assets') || ! Schema::hasColumn('agentic_marketing_execution_assets', 'assetable_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        $columnType = Schema::getColumnType('agentic_marketing_execution_assets', 'assetable_id');

        if (! str_contains(strtolower($columnType), 'bigint')) {
            return;
        }

        if ($driver === 'mysql') {
            $this->dropIndexIfExists('am_execution_assets_assetable_idx');
            DB::statement('ALTER TABLE agentic_marketing_execution_assets MODIFY assetable_id CHAR(36) NULL');
            DB::statement('CREATE INDEX am_execution_assets_assetable_idx ON agentic_marketing_execution_assets (assetable_type, assetable_id)');
        }
    }

    public function down(): void
    {
        // Intentionally not narrowing UUID-capable morph IDs back to integers.
    }

    private function dropIndexIfExists(string $indexName): void
    {
        $database = DB::getDatabaseName();
        $exists = DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', 'agentic_marketing_execution_assets')
            ->where('index_name', $indexName)
            ->exists();

        if ($exists) {
            DB::statement('DROP INDEX '.$indexName.' ON agentic_marketing_execution_assets');
        }
    }
};
