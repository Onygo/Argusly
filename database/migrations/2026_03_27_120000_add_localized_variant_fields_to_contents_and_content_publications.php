<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->uuid('translation_source_content_id')->nullable()->after('language');
            $table->uuid('translation_source_version_id')->nullable()->after('translation_source_content_id');
            $table->string('translation_source_locale', 10)->nullable()->after('translation_source_version_id');
            $table->boolean('is_source_locale')->default(true)->after('translation_source_locale');
            $table->timestamp('translation_generated_at')->nullable()->after('is_source_locale');
            $table->timestamp('translation_source_updated_at')->nullable()->after('translation_generated_at');

            $table->index('translation_source_content_id', 'contents_translation_source_content_idx');
            $table->index(['language', 'publish_status', 'status'], 'contents_language_publish_status_idx');

            $table->foreign('translation_source_content_id', 'contents_translation_source_content_fk')
                ->references('id')
                ->on('contents')
                ->nullOnDelete();

            $table->foreign('translation_source_version_id', 'contents_translation_source_version_fk')
                ->references('id')
                ->on('content_versions')
                ->nullOnDelete();
        });

        Schema::table('content_publications', function (Blueprint $table): void {
            $table->string('locale', 10)->nullable()->after('client_site_id');
            $table->index(['content_id', 'locale'], 'content_publications_content_locale_idx');
        });

        $this->backfillLocalizedContentFields();
        $this->backfillPublicationLocales();
    }

    public function down(): void
    {
        Schema::table('content_publications', function (Blueprint $table): void {
            $table->dropIndex('content_publications_content_locale_idx');
            $table->dropColumn('locale');
        });

        Schema::table('contents', function (Blueprint $table): void {
            $table->dropForeign('contents_translation_source_content_fk');
            $table->dropForeign('contents_translation_source_version_fk');
            $table->dropIndex('contents_translation_source_content_idx');
            $table->dropIndex('contents_language_publish_status_idx');
            $table->dropColumn([
                'translation_source_content_id',
                'translation_source_version_id',
                'translation_source_locale',
                'is_source_locale',
                'translation_generated_at',
                'translation_source_updated_at',
            ]);
        });
    }

    private function backfillLocalizedContentFields(): void
    {
        if (! Schema::hasTable('contents')) {
            return;
        }

        DB::table('contents')
            ->select(['id', 'language', 'type', 'current_version_id'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                $contentIds = collect($rows)->pluck('id')->filter()->values()->all();
                $versionIds = collect($rows)->pluck('current_version_id')->filter()->values()->all();

                $versionMeta = DB::table('content_versions')
                    ->whereIn('id', $versionIds)
                    ->pluck('meta', 'id');

                $drafts = DB::table('drafts')
                    ->whereIn('content_id', $contentIds)
                    ->orderByDesc('created_at')
                    ->get(['content_id', 'language', 'created_at']);

                $draftByContent = $drafts
                    ->unique('content_id')
                    ->keyBy('content_id');

                $briefs = DB::table('briefs')
                    ->whereIn('content_id', $contentIds)
                    ->orderByDesc('created_at')
                    ->get(['content_id', 'language', 'created_at']);

                $briefByContent = $briefs
                    ->unique('content_id')
                    ->keyBy('content_id');

                $translationLinks = DB::table('drafts as translated')
                    ->join('drafts as source', 'translated.source_draft_id', '=', 'source.id')
                    ->join('contents as source_content', 'source.content_id', '=', 'source_content.id')
                    ->whereIn('translated.content_id', $contentIds)
                    ->whereNotNull('translated.content_id')
                    ->whereNotNull('source.content_id')
                    ->orderByDesc('translated.created_at')
                    ->get([
                        'translated.content_id as target_content_id',
                        'source.content_id as source_content_id',
                        'source_content.current_version_id as source_version_id',
                        'source_content.language as source_language',
                        'source_content.updated_at as source_updated_at',
                        'translated.created_at as translated_created_at',
                    ])
                    ->unique('target_content_id')
                    ->keyBy('target_content_id');

                foreach ($rows as $row) {
                    $inferredLocale = $this->inferLocale(
                        currentLanguage: $row->language,
                        type: $row->type,
                        versionMeta: $versionMeta->get($row->current_version_id),
                        draftLanguage: data_get($draftByContent->get($row->id), 'language'),
                        briefLanguage: data_get($briefByContent->get($row->id), 'language'),
                    );

                    $translationLink = $translationLinks->get($row->id);
                    $updates = [
                        'language' => $inferredLocale,
                        'translation_source_locale' => $inferredLocale,
                        'is_source_locale' => true,
                        'translation_source_content_id' => null,
                        'translation_source_version_id' => null,
                        'translation_generated_at' => null,
                        'translation_source_updated_at' => null,
                    ];

                    if ($translationLink && (string) $translationLink->source_content_id !== (string) $row->id) {
                        $sourceLocale = $this->normalizeLocale($translationLink->source_language, 'nl');

                        $updates = array_merge($updates, [
                            'translation_source_content_id' => (string) $translationLink->source_content_id,
                            'translation_source_version_id' => $translationLink->source_version_id ? (string) $translationLink->source_version_id : null,
                            'translation_source_locale' => $sourceLocale,
                            'is_source_locale' => false,
                            'translation_generated_at' => $translationLink->translated_created_at,
                            'translation_source_updated_at' => $translationLink->source_updated_at,
                        ]);
                    }

                    DB::table('contents')
                        ->where('id', $row->id)
                        ->update($updates);
                }
            }, 'id', 'id');
    }

    private function backfillPublicationLocales(): void
    {
        if (! Schema::hasTable('content_publications')) {
            return;
        }

        DB::table('content_publications')
            ->select(['id', 'content_id'])
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                $contentMap = DB::table('contents')
                    ->whereIn('id', collect($rows)->pluck('content_id')->filter()->values()->all())
                    ->pluck('language', 'id');

                foreach ($rows as $row) {
                    DB::table('content_publications')
                        ->where('id', $row->id)
                        ->update([
                            'locale' => $this->normalizeLocale($contentMap->get($row->content_id), 'nl'),
                        ]);
                }
            }, 'id', 'id');
    }

    private function inferLocale(
        mixed $currentLanguage,
        mixed $type,
        mixed $versionMeta,
        mixed $draftLanguage,
        mixed $briefLanguage,
    ): string {
        $versionPayload = is_array($versionMeta)
            ? $versionMeta
            : (json_decode((string) $versionMeta, true) ?: []);

        $fallback = trim((string) $type) === 'article' ? 'nl' : 'en';

        foreach ([
            data_get($versionPayload, 'locale'),
            data_get($versionPayload, 'language'),
            data_get($versionPayload, 'lang'),
            $draftLanguage,
            $briefLanguage,
            $currentLanguage,
        ] as $candidate) {
            $resolved = $this->normalizeLocale($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return $fallback;
    }

    private function normalizeLocale(mixed $value, ?string $fallback = null): ?string
    {
        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['en', 'nl', 'de', 'fr', 'es'], true)) {
            return $normalized;
        }

        return $fallback;
    }
};
