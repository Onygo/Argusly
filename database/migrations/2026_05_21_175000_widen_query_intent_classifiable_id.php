<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('query_intent_classifications') || ! Schema::hasColumn('query_intent_classifications', 'classifiable_id')) {
            return;
        }

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement('ALTER TABLE query_intent_classifications MODIFY classifiable_id CHAR(36) NULL'),
            'pgsql' => DB::statement('ALTER TABLE query_intent_classifications ALTER COLUMN classifiable_id TYPE uuid USING classifiable_id::uuid'),
            default => null,
        };
    }

    public function down(): void
    {
        if (! Schema::hasTable('query_intent_classifications') || ! Schema::hasColumn('query_intent_classifications', 'classifiable_id')) {
            return;
        }

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement('ALTER TABLE query_intent_classifications MODIFY classifiable_id BIGINT UNSIGNED NULL'),
            'pgsql' => DB::statement('ALTER TABLE query_intent_classifications ALTER COLUMN classifiable_id TYPE bigint USING classifiable_id::bigint'),
            default => null,
        };
    }
};
