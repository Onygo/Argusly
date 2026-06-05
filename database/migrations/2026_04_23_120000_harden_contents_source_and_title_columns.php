<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contents')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE `contents` MODIFY `source` VARCHAR(100) NOT NULL DEFAULT 'api'");
            DB::statement("ALTER TABLE `contents` MODIFY `title` VARCHAR(255) NOT NULL");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE contents ALTER COLUMN source TYPE VARCHAR(100)");
            DB::statement("ALTER TABLE contents ALTER COLUMN source SET DEFAULT 'api'");
            DB::statement("ALTER TABLE contents ALTER COLUMN title TYPE VARCHAR(255)");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('contents')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::table('contents')
                ->whereNotIn('source', ['wp', 'manual', 'api', 'automation'])
                ->update(['source' => 'api']);

            DB::statement("ALTER TABLE `contents` MODIFY `source` ENUM('wp', 'manual', 'api', 'automation') NOT NULL DEFAULT 'api'");
            DB::statement("ALTER TABLE `contents` MODIFY `title` VARCHAR(255) NOT NULL");

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE contents ALTER COLUMN source TYPE VARCHAR(100)");
            DB::statement("ALTER TABLE contents ALTER COLUMN source SET DEFAULT 'api'");
            DB::statement("ALTER TABLE contents ALTER COLUMN title TYPE VARCHAR(255)");
        }
    }
};
