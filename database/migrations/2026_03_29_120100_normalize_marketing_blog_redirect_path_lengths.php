<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite' || ! Schema::hasTable('marketing_blog_redirects')) {
            return;
        }

        if ($this->hasIndex('marketing_blog_redirects', 'marketing_blog_redirects_source_path_unique')) {
            DB::statement('ALTER TABLE `marketing_blog_redirects` DROP INDEX `marketing_blog_redirects_source_path_unique`');
        }

        DB::statement('ALTER TABLE `marketing_blog_redirects` MODIFY `source_path` VARCHAR(512) COLLATE utf8mb4_unicode_ci NOT NULL');
        DB::statement('ALTER TABLE `marketing_blog_redirects` MODIFY `target_path` VARCHAR(512) COLLATE utf8mb4_unicode_ci NOT NULL');

        if (! $this->hasIndex('marketing_blog_redirects', 'marketing_blog_redirects_source_path_unique')) {
            DB::statement('ALTER TABLE `marketing_blog_redirects` ADD UNIQUE `marketing_blog_redirects_source_path_unique` (`source_path`)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite' || ! Schema::hasTable('marketing_blog_redirects')) {
            return;
        }

        if ($this->hasIndex('marketing_blog_redirects', 'marketing_blog_redirects_source_path_unique')) {
            DB::statement('ALTER TABLE `marketing_blog_redirects` DROP INDEX `marketing_blog_redirects_source_path_unique`');
        }

        DB::statement('ALTER TABLE `marketing_blog_redirects` MODIFY `source_path` VARCHAR(1024) COLLATE utf8mb4_unicode_ci NOT NULL');
        DB::statement('ALTER TABLE `marketing_blog_redirects` MODIFY `target_path` VARCHAR(1024) COLLATE utf8mb4_unicode_ci NOT NULL');
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $indexes = DB::select(sprintf('PRAGMA index_list(%s)', DB::getPdo()->quote($table)));

            return collect($indexes)->contains(function ($index) use ($indexName): bool {
                return (string) ($index->name ?? $index->Name ?? '') === $indexName;
            });
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }
};
