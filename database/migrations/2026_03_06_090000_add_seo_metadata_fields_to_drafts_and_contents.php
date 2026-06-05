<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drafts', function (Blueprint $table): void {
            if (! Schema::hasColumn('drafts', 'seo_title')) {
                $table->string('seo_title')->nullable()->after('title');
            }
            if (! Schema::hasColumn('drafts', 'seo_meta_description')) {
                $table->text('seo_meta_description')->nullable()->after('seo_title');
            }
            if (! Schema::hasColumn('drafts', 'seo_h1')) {
                $table->string('seo_h1')->nullable()->after('seo_meta_description');
            }
            if (! Schema::hasColumn('drafts', 'seo_canonical')) {
                $table->string('seo_canonical', 2048)->nullable()->after('seo_h1');
            }
            if (! Schema::hasColumn('drafts', 'seo_og_title')) {
                $table->string('seo_og_title')->nullable()->after('seo_canonical');
            }
            if (! Schema::hasColumn('drafts', 'seo_og_description')) {
                $table->text('seo_og_description')->nullable()->after('seo_og_title');
            }
            if (! Schema::hasColumn('drafts', 'seo_og_image')) {
                $table->string('seo_og_image', 2048)->nullable()->after('seo_og_description');
            }
            if (! Schema::hasColumn('drafts', 'seo_twitter_title')) {
                $table->string('seo_twitter_title')->nullable()->after('seo_og_image');
            }
            if (! Schema::hasColumn('drafts', 'seo_twitter_description')) {
                $table->text('seo_twitter_description')->nullable()->after('seo_twitter_title');
            }
        });

        Schema::table('contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('contents', 'seo_title')) {
                $table->string('seo_title')->nullable()->after('title');
            }
            if (! Schema::hasColumn('contents', 'seo_meta_description')) {
                $table->text('seo_meta_description')->nullable()->after('seo_title');
            }
            if (! Schema::hasColumn('contents', 'seo_h1')) {
                $table->string('seo_h1')->nullable()->after('seo_meta_description');
            }
            if (! Schema::hasColumn('contents', 'seo_canonical')) {
                $table->string('seo_canonical', 2048)->nullable()->after('seo_h1');
            }
            if (! Schema::hasColumn('contents', 'seo_og_title')) {
                $table->string('seo_og_title')->nullable()->after('seo_canonical');
            }
            if (! Schema::hasColumn('contents', 'seo_og_description')) {
                $table->text('seo_og_description')->nullable()->after('seo_og_title');
            }
            if (! Schema::hasColumn('contents', 'seo_og_image')) {
                $table->string('seo_og_image', 2048)->nullable()->after('seo_og_description');
            }
            if (! Schema::hasColumn('contents', 'seo_twitter_title')) {
                $table->string('seo_twitter_title')->nullable()->after('seo_og_image');
            }
            if (! Schema::hasColumn('contents', 'seo_twitter_description')) {
                $table->text('seo_twitter_description')->nullable()->after('seo_twitter_title');
            }
        });

        Schema::table('client_sites', function (Blueprint $table): void {
            if (! Schema::hasColumn('client_sites', 'seo_provider')) {
                $table->string('seo_provider', 32)->default('none')->after('connector_platform');
                $table->index('seo_provider', 'client_sites_seo_provider_idx');
            }
            if (! Schema::hasColumn('client_sites', 'supports_meta_title')) {
                $table->boolean('supports_meta_title')->default(false)->after('seo_provider');
            }
            if (! Schema::hasColumn('client_sites', 'supports_meta_description')) {
                $table->boolean('supports_meta_description')->default(false)->after('supports_meta_title');
            }
            if (! Schema::hasColumn('client_sites', 'supports_canonical')) {
                $table->boolean('supports_canonical')->default(false)->after('supports_meta_description');
            }
            if (! Schema::hasColumn('client_sites', 'supports_og_tags')) {
                $table->boolean('supports_og_tags')->default(false)->after('supports_canonical');
            }
        });

        $this->backfillDraftSeoFields();
        $this->backfillContentSeoFields();
    }

    public function down(): void
    {
        Schema::table('client_sites', function (Blueprint $table): void {
            if (Schema::hasColumn('client_sites', 'supports_og_tags')) {
                $table->dropColumn('supports_og_tags');
            }
            if (Schema::hasColumn('client_sites', 'supports_canonical')) {
                $table->dropColumn('supports_canonical');
            }
            if (Schema::hasColumn('client_sites', 'supports_meta_description')) {
                $table->dropColumn('supports_meta_description');
            }
            if (Schema::hasColumn('client_sites', 'supports_meta_title')) {
                $table->dropColumn('supports_meta_title');
            }
            if (Schema::hasColumn('client_sites', 'seo_provider')) {
                if ($this->indexExists('client_sites', 'client_sites_seo_provider_idx')) {
                    $table->dropIndex('client_sites_seo_provider_idx');
                }
                $table->dropColumn('seo_provider');
            }
        });

        Schema::table('contents', function (Blueprint $table): void {
            foreach ([
                'seo_title',
                'seo_meta_description',
                'seo_h1',
                'seo_canonical',
                'seo_og_title',
                'seo_og_description',
                'seo_og_image',
                'seo_twitter_title',
                'seo_twitter_description',
            ] as $column) {
                if (Schema::hasColumn('contents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('drafts', function (Blueprint $table): void {
            foreach ([
                'seo_title',
                'seo_meta_description',
                'seo_h1',
                'seo_canonical',
                'seo_og_title',
                'seo_og_description',
                'seo_og_image',
                'seo_twitter_title',
                'seo_twitter_description',
            ] as $column) {
                if (Schema::hasColumn('drafts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function backfillDraftSeoFields(): void
    {
        DB::table('drafts')
            ->select(['id', 'title', 'meta', 'seo_title', 'seo_meta_description', 'seo_h1', 'seo_canonical', 'seo_og_title', 'seo_og_description', 'seo_og_image', 'seo_twitter_title', 'seo_twitter_description'])
            ->orderBy('id')
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    $meta = $this->decodeJsonObject($row->meta ?? null);

                    $updates = [
                        'seo_title' => $row->seo_title ?: ($row->title ?: null),
                        'seo_meta_description' => $row->seo_meta_description ?: $this->firstNonEmpty([
                            $meta['meta_description'] ?? null,
                            data_get($meta, 'meta.description'),
                            $meta['description'] ?? null,
                        ]),
                        'seo_h1' => $row->seo_h1 ?: $this->firstNonEmpty([
                            $meta['h1'] ?? null,
                            $row->title ?: null,
                        ]),
                        'seo_canonical' => $row->seo_canonical ?: $this->firstNonEmpty([
                            $meta['canonical_url'] ?? null,
                            $meta['canonical'] ?? null,
                        ]),
                        'seo_og_title' => $row->seo_og_title ?: $this->firstNonEmpty([
                            $meta['og_title'] ?? null,
                            data_get($meta, 'og.title'),
                        ]),
                        'seo_og_description' => $row->seo_og_description ?: $this->firstNonEmpty([
                            $meta['og_description'] ?? null,
                            data_get($meta, 'og.description'),
                        ]),
                        'seo_og_image' => $row->seo_og_image ?: $this->firstNonEmpty([
                            $meta['og_image'] ?? null,
                            data_get($meta, 'og.image'),
                            $meta['og_image_url'] ?? null,
                        ]),
                        'seo_twitter_title' => $row->seo_twitter_title ?: $this->firstNonEmpty([
                            $meta['twitter_title'] ?? null,
                            data_get($meta, 'twitter.title'),
                        ]),
                        'seo_twitter_description' => $row->seo_twitter_description ?: $this->firstNonEmpty([
                            $meta['twitter_description'] ?? null,
                            data_get($meta, 'twitter.description'),
                        ]),
                    ];

                    DB::table('drafts')->where('id', $row->id)->update($updates);
                }
            });
    }

    private function backfillContentSeoFields(): void
    {
        $seoRowsByContentId = DB::table('content_seo')
            ->select(['content_id', 'meta_title', 'meta_description'])
            ->get()
            ->keyBy('content_id');

        DB::table('contents')
            ->select([
                'id',
                'title',
                'seo_title',
                'seo_meta_description',
                'seo_h1',
                'seo_canonical',
                'seo_og_title',
                'seo_og_description',
                'seo_og_image',
                'seo_twitter_title',
                'seo_twitter_description',
            ])
            ->orderBy('id')
            ->chunk(200, function ($rows) use ($seoRowsByContentId): void {
                foreach ($rows as $row) {
                    $seo = $seoRowsByContentId->get($row->id);
                    $updates = [
                        'seo_title' => $row->seo_title ?: ($seo?->meta_title ?: ($row->title ?: null)),
                        'seo_meta_description' => $row->seo_meta_description ?: ($seo?->meta_description ?: null),
                        'seo_h1' => $row->seo_h1 ?: ($row->title ?: null),
                        'seo_canonical' => $row->seo_canonical ?: null,
                        'seo_og_title' => $row->seo_og_title ?: null,
                        'seo_og_description' => $row->seo_og_description ?: null,
                        'seo_og_image' => $row->seo_og_image ?: null,
                        'seo_twitter_title' => $row->seo_twitter_title ?: null,
                        'seo_twitter_description' => $row->seo_twitter_description ?: null,
                    ];

                    DB::table('contents')->where('id', $row->id)->update($updates);
                }
            });
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int,mixed> $values
     */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $candidate = trim((string) $value);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
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
};
