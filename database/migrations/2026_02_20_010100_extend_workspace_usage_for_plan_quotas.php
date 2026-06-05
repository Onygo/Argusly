<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workspace_usage')) {
            return;
        }

        Schema::table('workspace_usage', function (Blueprint $table) {
            if (! Schema::hasColumn('workspace_usage', 'site_id')) {
                $table->uuid('site_id')->nullable()->after('workspace_id');
            }

            if (! Schema::hasColumn('workspace_usage', 'period_ym')) {
                $table->string('period_ym', 6)->nullable()->after('year_month');
            }

            if (! Schema::hasColumn('workspace_usage', 'articles_generated')) {
                $table->unsignedInteger('articles_generated')->default(0)->after('drafts_count');
            }

            if (! Schema::hasColumn('workspace_usage', 'llm_queries_run')) {
                $table->unsignedInteger('llm_queries_run')->default(0)->after('articles_generated');
            }

            if (! Schema::hasColumn('workspace_usage', 'audit_pages_crawled')) {
                $table->unsignedInteger('audit_pages_crawled')->default(0)->after('llm_queries_run');
            }
        });

        DB::table('workspace_usage')->orderBy('id')->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                $periodYm = null;
                if (! empty($row->year_month) && is_string($row->year_month)) {
                    $periodYm = str_replace('-', '', $row->year_month);
                }

                DB::table('workspace_usage')
                    ->where('id', $row->id)
                    ->update([
                        'period_ym' => $periodYm,
                    ]);
            }
        });

        Schema::table('workspace_usage', function (Blueprint $table) {
            if ($this->foreignKeyExists('workspace_usage', 'workspace_usage_workspace_id_foreign')) {
                $table->dropForeign('workspace_usage_workspace_id_foreign');
            }

            if ($this->indexExists('workspace_usage', 'workspace_usage_workspace_id_year_month_unique')) {
                $table->dropUnique('workspace_usage_workspace_id_year_month_unique');
            }

            if (! $this->indexExists('workspace_usage', 'workspace_usage_workspace_site_period_idx')) {
                $table->index(['workspace_id', 'site_id', 'period_ym'], 'workspace_usage_workspace_site_period_idx');
            }

            if (! $this->indexExists('workspace_usage', 'workspace_usage_period_ym_idx')) {
                $table->index(['period_ym'], 'workspace_usage_period_ym_idx');
            }

            if (! $this->indexExists('workspace_usage', 'workspace_usage_site_id_idx')) {
                $table->index(['site_id'], 'workspace_usage_site_id_idx');
            }

            if (! $this->foreignKeyExists('workspace_usage', 'workspace_usage_site_fk_idx')) {
                $table->foreign('site_id', 'workspace_usage_site_fk_idx')
                    ->references('id')
                    ->on('client_sites')
                    ->nullOnDelete();
            }

            if (! $this->foreignKeyExists('workspace_usage', 'workspace_usage_workspace_id_foreign')) {
                $table->foreign('workspace_id', 'workspace_usage_workspace_id_foreign')
                    ->references('id')
                    ->on('workspaces')
                    ->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('workspace_usage')) {
            return;
        }

        Schema::table('workspace_usage', function (Blueprint $table) {
            if ($this->foreignKeyExists('workspace_usage', 'workspace_usage_workspace_id_foreign')) {
                $table->dropForeign('workspace_usage_workspace_id_foreign');
            }

            if ($this->foreignKeyExists('workspace_usage', 'workspace_usage_site_fk_idx')) {
                $table->dropForeign('workspace_usage_site_fk_idx');
            }

            foreach (['workspace_usage_workspace_site_period_idx', 'workspace_usage_period_ym_idx', 'workspace_usage_site_id_idx'] as $index) {
                if ($this->indexExists('workspace_usage', $index)) {
                    $table->dropIndex($index);
                }
            }

            if (! $this->indexExists('workspace_usage', 'workspace_usage_workspace_id_year_month_unique')) {
                $table->unique(['workspace_id', 'year_month']);
            }

            if (! $this->foreignKeyExists('workspace_usage', 'workspace_usage_workspace_id_foreign')) {
                $table->foreign('workspace_id', 'workspace_usage_workspace_id_foreign')
                    ->references('id')
                    ->on('workspaces')
                    ->cascadeOnDelete();
            }

            foreach (['site_id', 'period_ym', 'articles_generated', 'llm_queries_run', 'audit_pages_crawled'] as $column) {
                if (Schema::hasColumn('workspace_usage', $column)) {
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

        if ($driver === 'sqlite') {
            // SQLite foreign keys are not always named/persisted in a queryable way per constraint name.
            return false;
        }

        return false;
    }
};
