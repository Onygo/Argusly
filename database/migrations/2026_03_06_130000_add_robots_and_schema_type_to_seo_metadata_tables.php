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
            if (! Schema::hasColumn('drafts', 'robots_index')) {
                $table->boolean('robots_index')->nullable()->after('seo_twitter_description');
            }
            if (! Schema::hasColumn('drafts', 'robots_follow')) {
                $table->boolean('robots_follow')->nullable()->after('robots_index');
            }
            if (! Schema::hasColumn('drafts', 'schema_type')) {
                $table->string('schema_type', 120)->nullable()->after('robots_follow');
            }
        });

        Schema::table('contents', function (Blueprint $table): void {
            if (! Schema::hasColumn('contents', 'robots_index')) {
                $table->boolean('robots_index')->nullable()->after('seo_twitter_description');
            }
            if (! Schema::hasColumn('contents', 'robots_follow')) {
                $table->boolean('robots_follow')->nullable()->after('robots_index');
            }
            if (! Schema::hasColumn('contents', 'schema_type')) {
                $table->string('schema_type', 120)->nullable()->after('robots_follow');
            }
        });

        Schema::table('content_seo', function (Blueprint $table): void {
            if (! Schema::hasColumn('content_seo', 'robots_index')) {
                $table->boolean('robots_index')->nullable()->after('primary_keyword');
            }
            if (! Schema::hasColumn('content_seo', 'robots_follow')) {
                $table->boolean('robots_follow')->nullable()->after('robots_index');
            }
            if (! Schema::hasColumn('content_seo', 'schema_type')) {
                $table->string('schema_type', 120)->nullable()->after('robots_follow');
            }
        });

        $this->backfillDraftSeoRobotsAndSchema();
    }

    public function down(): void
    {
        Schema::table('content_seo', function (Blueprint $table): void {
            if (Schema::hasColumn('content_seo', 'schema_type')) {
                $table->dropColumn('schema_type');
            }
            if (Schema::hasColumn('content_seo', 'robots_follow')) {
                $table->dropColumn('robots_follow');
            }
            if (Schema::hasColumn('content_seo', 'robots_index')) {
                $table->dropColumn('robots_index');
            }
        });

        Schema::table('contents', function (Blueprint $table): void {
            if (Schema::hasColumn('contents', 'schema_type')) {
                $table->dropColumn('schema_type');
            }
            if (Schema::hasColumn('contents', 'robots_follow')) {
                $table->dropColumn('robots_follow');
            }
            if (Schema::hasColumn('contents', 'robots_index')) {
                $table->dropColumn('robots_index');
            }
        });

        Schema::table('drafts', function (Blueprint $table): void {
            if (Schema::hasColumn('drafts', 'schema_type')) {
                $table->dropColumn('schema_type');
            }
            if (Schema::hasColumn('drafts', 'robots_follow')) {
                $table->dropColumn('robots_follow');
            }
            if (Schema::hasColumn('drafts', 'robots_index')) {
                $table->dropColumn('robots_index');
            }
        });
    }

    private function backfillDraftSeoRobotsAndSchema(): void
    {
        DB::table('drafts')
            ->select(['id', 'meta', 'robots_index', 'robots_follow', 'schema_type'])
            ->orderBy('id')
            ->chunk(200, function ($rows): void {
                foreach ($rows as $row) {
                    $meta = $this->decodeJsonObject($row->meta ?? null);

                    $robotsIndex = $row->robots_index;
                    if ($robotsIndex === null) {
                        $robotsIndex = $this->firstBoolean([
                            $meta['robots_index'] ?? null,
                            data_get($meta, 'seo.robots_index'),
                            data_get($meta, 'robots.index'),
                            $this->robotsDirectiveValue(data_get($meta, 'robots'), 'index'),
                            $this->robotsDirectiveValue(data_get($meta, 'robots_meta'), 'index'),
                        ]);
                    }

                    $robotsFollow = $row->robots_follow;
                    if ($robotsFollow === null) {
                        $robotsFollow = $this->firstBoolean([
                            $meta['robots_follow'] ?? null,
                            data_get($meta, 'seo.robots_follow'),
                            data_get($meta, 'robots.follow'),
                            $this->robotsDirectiveValue(data_get($meta, 'robots'), 'follow'),
                            $this->robotsDirectiveValue(data_get($meta, 'robots_meta'), 'follow'),
                        ]);
                    }

                    $schemaType = $row->schema_type;
                    if (trim((string) $schemaType) === '') {
                        $schemaType = $this->firstNonEmpty([
                            $meta['schema_type'] ?? null,
                            data_get($meta, 'seo.schema_type'),
                            data_get($meta, 'schema.type'),
                            data_get($meta, 'schema'),
                        ]);
                    }

                    $updates = [];
                    if ($row->robots_index === null && $robotsIndex !== null) {
                        $updates['robots_index'] = $robotsIndex;
                    }
                    if ($row->robots_follow === null && $robotsFollow !== null) {
                        $updates['robots_follow'] = $robotsFollow;
                    }
                    if (trim((string) ($row->schema_type ?? '')) === '' && $schemaType !== null) {
                        $updates['schema_type'] = $schemaType;
                    }

                    if ($updates !== []) {
                        DB::table('drafts')->where('id', $row->id)->update($updates);
                    }
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

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function robotsDirectiveValue(mixed $value, string $directive): ?bool
    {
        $tokens = [];
        if (is_string($value)) {
            $tokens = preg_split('/[\s,;]+/', mb_strtolower(trim($value))) ?: [];
        } elseif (is_array($value)) {
            $tokens = collect($value)
                ->map(fn ($token) => mb_strtolower(trim((string) $token)))
                ->filter()
                ->values()
                ->all();
        }

        if ($tokens === []) {
            return null;
        }

        if ($directive === 'index') {
            if (in_array('noindex', $tokens, true)) {
                return false;
            }
            if (in_array('index', $tokens, true)) {
                return true;
            }

            return null;
        }

        if (in_array('nofollow', $tokens, true)) {
            return false;
        }
        if (in_array('follow', $tokens, true)) {
            return true;
        }

        return null;
    }

    /**
     * @param array<int,mixed> $values
     */
    private function firstBoolean(array $values): ?bool
    {
        foreach ($values as $value) {
            $candidate = $this->normalizeBoolean($value);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function normalizeBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return ((int) $value) === 1 ? true : (((int) $value) === 0 ? false : null);
        }

        $token = mb_strtolower(trim((string) $value));
        if ($token === '') {
            return null;
        }

        if (in_array($token, ['1', 'true', 'yes', 'on', 'index', 'follow'], true)) {
            return true;
        }

        if (in_array($token, ['0', 'false', 'no', 'off', 'noindex', 'nofollow'], true)) {
            return false;
        }

        return null;
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
};
