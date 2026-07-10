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

        Schema::table('contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('contents', 'inventory_source_type')) {
                $table->string('inventory_source_type', 80)->nullable()->after('canonical_url_key');
            }

            if (! Schema::hasColumn('contents', 'management_type')) {
                $table->string('management_type', 80)->nullable()->after('inventory_source_type');
            }

            if (! Schema::hasColumn('contents', 'discovery_method')) {
                $table->string('discovery_method', 80)->nullable()->after('management_type');
            }

            if (! Schema::hasColumn('contents', 'original_url')) {
                $table->text('original_url')->nullable()->after('discovery_method');
            }

            if (! Schema::hasColumn('contents', 'normalized_url')) {
                $table->text('normalized_url')->nullable()->after('original_url');
            }

            if (! Schema::hasColumn('contents', 'canonical_url')) {
                $table->text('canonical_url')->nullable()->after('normalized_url');
            }

            if (! Schema::hasColumn('contents', 'url_hash')) {
                $table->char('url_hash', 64)->nullable()->after('canonical_url');
            }

            if (! Schema::hasColumn('contents', 'content_fingerprint')) {
                $table->char('content_fingerprint', 64)->nullable()->after('url_hash');
            }

            if (! Schema::hasColumn('contents', 'http_status')) {
                $table->unsignedSmallInteger('http_status')->nullable()->after('content_fingerprint');
            }

            if (! Schema::hasColumn('contents', 'first_seen_at')) {
                $table->timestamp('first_seen_at')->nullable()->after('http_status');
            }

            if (! Schema::hasColumn('contents', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('first_seen_at');
            }

            if (! Schema::hasColumn('contents', 'last_fetched_at')) {
                $table->timestamp('last_fetched_at')->nullable()->after('last_seen_at');
            }

            if (! Schema::hasColumn('contents', 'external_modified_at')) {
                $table->timestamp('external_modified_at')->nullable()->after('last_fetched_at');
            }

            if (! Schema::hasColumn('contents', 'external_changed_at')) {
                $table->timestamp('external_changed_at')->nullable()->after('external_modified_at');
            }

            if (! Schema::hasColumn('contents', 'review_status')) {
                $table->string('review_status', 80)->nullable()->after('external_changed_at');
            }

            if (! Schema::hasColumn('contents', 'campaign_eligible')) {
                $table->boolean('campaign_eligible')->default(false)->after('review_status');
            }

            if (! Schema::hasColumn('contents', 'inventory_metadata')) {
                $table->json('inventory_metadata')->nullable()->after('campaign_eligible');
            }
        });

        $this->normalizeUrlColumnTypes();

        $this->addIndexIfMissing('contents', ['workspace_id', 'inventory_source_type'], 'contents_inventory_source_idx');
        $this->addIndexIfMissing('contents', ['workspace_id', 'management_type'], 'contents_management_type_idx');
        $this->addIndexIfMissing('contents', ['workspace_id', 'review_status'], 'contents_inventory_review_idx');
        $this->addIndexIfMissing('contents', ['workspace_id', 'campaign_eligible'], 'contents_campaign_eligibility_idx');
        $this->addIndexIfMissing('contents', ['workspace_id', 'url_hash'], 'contents_workspace_url_hash_idx');
        $this->addIndexIfMissing('contents', ['inventory_source_type'], 'contents_inventory_source_type_index');
        $this->addIndexIfMissing('contents', ['management_type'], 'contents_management_type_index');
        $this->addIndexIfMissing('contents', ['discovery_method'], 'contents_discovery_method_index');
        $this->addIndexIfMissing('contents', ['url_hash'], 'contents_url_hash_index');
        $this->addIndexIfMissing('contents', ['content_fingerprint'], 'contents_content_fingerprint_index');
        $this->addIndexIfMissing('contents', ['http_status'], 'contents_http_status_index');
        $this->addIndexIfMissing('contents', ['first_seen_at'], 'contents_first_seen_at_index');
        $this->addIndexIfMissing('contents', ['last_seen_at'], 'contents_last_seen_at_index');
        $this->addIndexIfMissing('contents', ['last_fetched_at'], 'contents_last_fetched_at_index');
        $this->addIndexIfMissing('contents', ['external_modified_at'], 'contents_external_modified_at_index');
        $this->addIndexIfMissing('contents', ['external_changed_at'], 'contents_external_changed_at_index');
        $this->addIndexIfMissing('contents', ['review_status'], 'contents_review_status_index');
        $this->addIndexIfMissing('contents', ['campaign_eligible'], 'contents_campaign_eligible_index');
    }

    public function down(): void
    {
        foreach ([
            'contents_inventory_source_idx',
            'contents_management_type_idx',
            'contents_inventory_review_idx',
            'contents_campaign_eligibility_idx',
            'contents_workspace_url_hash_idx',
            'contents_inventory_source_type_index',
            'contents_management_type_index',
            'contents_discovery_method_index',
            'contents_url_hash_index',
            'contents_content_fingerprint_index',
            'contents_http_status_index',
            'contents_first_seen_at_index',
            'contents_last_seen_at_index',
            'contents_last_fetched_at_index',
            'contents_external_modified_at_index',
            'contents_external_changed_at_index',
            'contents_review_status_index',
            'contents_campaign_eligible_index',
        ] as $index) {
            $this->dropIndexIfExists('contents', $index);
        }

        Schema::table('contents', function (Blueprint $table): void {
            foreach ([
                'inventory_source_type',
                'management_type',
                'discovery_method',
                'original_url',
                'normalized_url',
                'canonical_url',
                'url_hash',
                'content_fingerprint',
                'http_status',
                'first_seen_at',
                'last_seen_at',
                'last_fetched_at',
                'external_modified_at',
                'external_changed_at',
                'review_status',
                'campaign_eligible',
                'inventory_metadata',
            ] as $column) {
                if (Schema::hasColumn('contents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function normalizeUrlColumnTypes(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        foreach (['original_url', 'normalized_url', 'canonical_url'] as $column) {
            if (! Schema::hasColumn('contents', $column)) {
                continue;
            }

            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE `contents` MODIFY `{$column}` TEXT NULL");

                continue;
            }

            if ($driver === 'pgsql') {
                DB::statement("ALTER TABLE contents ALTER COLUMN {$column} TYPE TEXT");
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
