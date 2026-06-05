<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->uuid('family_id')->nullable()->after('language');
            $table->index('family_id', 'contents_family_id_idx');
        });

        $this->backfillFamilies();

        Schema::table('contents', function (Blueprint $table): void {
            $table->unique(['family_id', 'language'], 'contents_family_locale_unique');
            $table->foreign('family_id', 'contents_family_id_fk')
                ->references('id')
                ->on('contents');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropForeign('contents_family_id_fk');
            $table->dropUnique('contents_family_locale_unique');
            $table->dropIndex('contents_family_id_idx');
            $table->dropColumn('family_id');
        });
    }

    private function backfillFamilies(): void
    {
        $rows = DB::table('contents')
            ->select([
                'id',
                'language',
                'family_id',
                'translation_source_content_id',
                'translation_source_version_id',
                'translation_source_locale',
                'is_source_locale',
                'status',
                'current_version_id',
                'locale_repair_meta',
                'created_at',
                'updated_at',
            ])
            ->orderBy('created_at')
            ->get()
            ->keyBy('id');

        if ($rows->isEmpty()) {
            return;
        }

        $rootById = [];

        foreach ($rows as $row) {
            $rootById[$row->id] = $this->resolveRootId($row->id, $rows);
        }

        $updates = [];

        foreach (collect($rootById)->groupBy(fn (string $rootId): string => $rootId) as $rootId => $memberIds) {
            $familyRows = collect($memberIds)
                ->map(fn (string $contentId) => $rows->get($contentId))
                ->filter()
                ->values();

            $root = $familyRows->firstWhere('id', $rootId);
            if (! $root) {
                continue;
            }

            $rootLocale = $this->normalizeLocale($root->language, 'en');
            $canonicalIds = collect([$rootId]);

            foreach ($familyRows->groupBy(fn ($row): string => $this->normalizeLocale($row->language, 'en')) as $locale => $localeRows) {
                if ($locale === $rootLocale) {
                    continue;
                }

                $canonical = $localeRows
                    ->sortBy(fn ($row): array => $this->canonicalVariantPriority($row, $root))
                    ->first();

                if ($canonical) {
                    $canonicalIds->push((string) $canonical->id);
                }
            }

            $canonicalIds = $canonicalIds->unique()->values();

            foreach ($familyRows as $row) {
                $contentId = (string) $row->id;

                if ($contentId === $rootId) {
                    $updates[$contentId] = [
                        'family_id' => $rootId,
                        'translation_source_content_id' => null,
                        'translation_source_version_id' => null,
                        'translation_source_locale' => null,
                        'is_source_locale' => true,
                    ];

                    continue;
                }

                if ($canonicalIds->contains($contentId)) {
                    $updates[$contentId] = [
                        'family_id' => $rootId,
                        'translation_source_content_id' => $rootId,
                        'translation_source_version_id' => $root->current_version_id ? (string) $root->current_version_id : null,
                        'translation_source_locale' => $rootLocale,
                        'is_source_locale' => false,
                    ];

                    continue;
                }

                $updates[$contentId] = [
                    'family_id' => $contentId,
                    'translation_source_content_id' => null,
                    'translation_source_version_id' => null,
                    'translation_source_locale' => null,
                    'is_source_locale' => true,
                    'status' => 'archived',
                    'locale_repair_meta' => $this->appendRepairMeta($row->locale_repair_meta, [
                        'repaired_at' => now()->toIso8601String(),
                        'repair_type' => 'family_integrity_migration_duplicate_archived',
                        'canonical_family_id' => $rootId,
                        'canonical_locale' => $this->normalizeLocale($row->language, 'en'),
                    ]),
                ];
            }
        }

        foreach ($updates as $contentId => $attributes) {
            DB::table('contents')
                ->where('id', $contentId)
                ->update($attributes);
        }
    }

    /**
     * @param  Collection<string,object>  $rows
     */
    private function resolveRootId(string $startId, Collection $rows): string
    {
        $visited = [];
        $currentId = $startId;

        while ($currentId !== '' && ! isset($visited[$currentId]) && $rows->has($currentId)) {
            $visited[$currentId] = true;
            $current = $rows->get($currentId);
            $nextId = trim((string) ($current->translation_source_content_id ?? ''));

            if ($nextId === '' || ! $rows->has($nextId)) {
                return $currentId;
            }

            $currentId = $nextId;
        }

        $candidates = collect(array_keys($visited))
            ->map(fn (string $id) => $rows->get($id))
            ->filter()
            ->sortBy(fn ($row): array => $this->rootPriority($row));

        return (string) ($candidates->first()->id ?? $startId);
    }

    /**
     * @return array<int,int|string>
     */
    private function rootPriority(object $row): array
    {
        return [
            $row->translation_source_content_id === null ? 0 : 1,
            (bool) $row->is_source_locale ? 0 : 1,
            (string) ($row->status ?? '') === 'archived' ? 1 : 0,
            strtotime((string) ($row->created_at ?? 'now')) ?: 0,
            (string) $row->id,
        ];
    }

    /**
     * @return array<int,int|string>
     */
    private function canonicalVariantPriority(object $row, object $root): array
    {
        return [
            (string) ($row->status ?? '') === 'archived' ? 1 : 0,
            (string) ($row->translation_source_content_id ?? '') === (string) $root->id ? 0 : 1,
            (bool) ($row->is_source_locale ?? false) ? 1 : 0,
            $row->current_version_id ? 0 : 1,
            -1 * (strtotime((string) ($row->updated_at ?? 'now')) ?: 0),
            strtotime((string) ($row->created_at ?? 'now')) ?: 0,
            (string) $row->id,
        ];
    }

    private function normalizeLocale(mixed $value, string $fallback): string
    {
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['en', 'nl', 'de', 'fr', 'es'], true)
            ? $normalized
            : $fallback;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function appendRepairMeta(mixed $value, array $entry): string
    {
        $meta = is_array($value)
            ? $value
            : (json_decode((string) $value, true) ?: []);

        if (! is_array($meta)) {
            $meta = [];
        }

        $entries = data_get($meta, 'family_integrity_repairs', []);
        if (! is_array($entries)) {
            $entries = [];
        }

        $entries[] = $entry;
        $meta['family_integrity_repairs'] = $entries;

        return json_encode($meta, JSON_THROW_ON_ERROR);
    }
};
