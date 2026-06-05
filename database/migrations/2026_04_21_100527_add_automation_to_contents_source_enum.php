<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add 'automation' to the contents.source enum
        // This enables explicit tracking of automation-generated content
        //
        // MySQL: Expand the ENUM to include 'automation'
        // SQLite: No-op (doesn't enforce ENUM constraints; application layer validates via ContentSource enum)
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE contents MODIFY COLUMN source ENUM('wp', 'manual', 'api', 'automation') NOT NULL DEFAULT 'api'");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            // First, update any 'automation' values to 'api' to prevent data loss
            DB::table('contents')
                ->where('source', 'automation')
                ->update(['source' => 'api']);

            // Revert to original enum values
            DB::statement("ALTER TABLE contents MODIFY COLUMN source ENUM('wp', 'manual', 'api') NOT NULL DEFAULT 'api'");
        }
    }
};
