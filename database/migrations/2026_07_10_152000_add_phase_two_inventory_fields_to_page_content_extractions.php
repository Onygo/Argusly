<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->normalizeUrlColumnTypes();

        Schema::table('page_content_extractions', function (Blueprint $table): void {
            if (! Schema::hasColumn('page_content_extractions', 'open_graph_image_url')) {
                $table->text('open_graph_image_url')->nullable()->after('quality_score');
            }

            if (! Schema::hasColumn('page_content_extractions', 'schema_types_json')) {
                $table->json('schema_types_json')->nullable()->after('open_graph_image_url');
            }

            if (! Schema::hasColumn('page_content_extractions', 'meta_robots')) {
                $table->string('meta_robots', 500)->nullable()->after('schema_types_json');
            }

            if (! Schema::hasColumn('page_content_extractions', 'indexability_status')) {
                $table->string('indexability_status', 40)->nullable()->after('meta_robots');
            }

            if (! Schema::hasColumn('page_content_extractions', 'canonical_url')) {
                $table->text('canonical_url')->nullable()->after('indexability_status');
            }

            if (! Schema::hasColumn('page_content_extractions', 'content_fingerprint')) {
                $table->char('content_fingerprint', 64)->nullable()->after('canonical_url');
            }

            if (! Schema::hasColumn('page_content_extractions', 'external_modified_at')) {
                $table->timestamp('external_modified_at')->nullable()->after('content_fingerprint');
            }
        });

        $this->normalizeUrlColumnTypes();

        $this->addIndexIfMissing('page_content_extractions', ['indexability_status'], 'page_content_extractions_indexability_status_index');
        $this->addIndexIfMissing('page_content_extractions', ['content_fingerprint'], 'page_content_extractions_content_fingerprint_index');
        $this->addIndexIfMissing('page_content_extractions', ['external_modified_at'], 'page_content_extractions_external_modified_at_index');
    }

    public function down(): void
    {
        foreach ([
            'page_content_extractions_indexability_status_index',
            'page_content_extractions_content_fingerprint_index',
            'page_content_extractions_external_modified_at_index',
        ] as $index) {
            $this->dropIndexIfExists('page_content_extractions', $index);
        }

        Schema::table('page_content_extractions', function (Blueprint $table): void {
            foreach ([
                'open_graph_image_url',
                'schema_types_json',
                'meta_robots',
                'indexability_status',
                'canonical_url',
                'content_fingerprint',
                'external_modified_at',
            ] as $column) {
                if (Schema::hasColumn('page_content_extractions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function normalizeUrlColumnTypes(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        foreach (['open_graph_image_url', 'canonical_url'] as $column) {
            if (! Schema::hasColumn('page_content_extractions', $column)) {
                continue;
            }

            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE `page_content_extractions` MODIFY `{$column}` TEXT NULL");

                continue;
            }

            if ($driver === 'pgsql') {
                DB::statement("ALTER TABLE page_content_extractions ALTER COLUMN {$column} TYPE TEXT");
            }
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function addIndexIfMissing(string $table, array $columns, string $name): void
    {
        if (Schema::hasIndex($table, $columns)) {
            return;
        }

        if (! $this->hasAvailableIndexSlot($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($columns, $name): void {
            $table->index($columns, $name);
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (! $this->hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name): void {
            $table->dropIndex($name);
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => (string) ($index['name'] ?? '') === $name);
    }

    private function hasAvailableIndexSlot(string $table): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return true;
        }

        return count(Schema::getIndexes($table)) < 64;
    }
};
