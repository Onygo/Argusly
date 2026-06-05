<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agentic_marketing_objectives')
            || ! Schema::hasColumn('agentic_marketing_objectives', 'goal')) {
            return;
        }

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => DB::statement('ALTER TABLE `agentic_marketing_objectives` MODIFY `goal` TEXT NOT NULL'),
            'pgsql' => DB::statement('ALTER TABLE agentic_marketing_objectives ALTER COLUMN goal TYPE TEXT'),
            default => null,
        };
    }

    public function down(): void
    {
        // Keep this intentionally non-destructive so existing longer goals are not truncated.
    }
};
