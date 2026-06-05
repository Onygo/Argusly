<?php

namespace App\Services\Content;

use App\Models\Brief;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\Draft;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContentFamilyIntegrityRepairService
{
    /**
     * @return array{
     *   families:int,
     *   affected_families:int,
     *   archived_duplicates:int,
     *   reattached_drafts:int,
     *   reattached_publications:int,
     *   reports:array<int,array<string,mixed>>
     * }
     */
    public function repair(bool $dryRun = false): array
    {
        $contents = Content::query()
            ->with(['brief', 'drafts', 'publications', 'currentVersion'])
            ->orderBy('created_at')
            ->get();

        $reports = [];
        $archivedDuplicates = 0;
        $reattachedDrafts = 0;
        $reattachedPublications = 0;

        foreach ($this->buildClusters($contents) as $cluster) {
            $plan = $this->planClusterRepair($cluster);

            if (! $plan['needs_changes']) {
                continue;
            }

            $reports[] = $plan;
            $archivedDuplicates += count($plan['archived_duplicate_ids']);
            $reattachedDrafts += count($plan['draft_updates']);
            $reattachedPublications += count($plan['publication_moves']);

            if ($dryRun) {
                continue;
            }

            DB::transaction(fn () => $this->applyPlan($plan));
        }

        return [
            'families' => count($this->buildClusters($contents)),
            'affected_families' => count($reports),
            'archived_duplicates' => $archivedDuplicates,
            'reattached_drafts' => $reattachedDrafts,
            'reattached_publications' => $reattachedPublications,
            'reports' => $reports,
        ];
    }

    /**
     * @param  Collection<int,Content>  $contents
     * @return array<int,Collection<int,Content>>
     */
    private function buildClusters(Collection $contents): array
    {
        $byId = $contents->keyBy(fn (Content $content): string => (string) $content->id);
        $adjacency = [];

        foreach ($contents as $content) {
            $id = (string) $content->id;
            $adjacency[$id] ??= [];

            foreach (array_filter([
                (string) ($content->translation_source_content_id ?? ''),
                (string) ($content->family_id ?? ''),
            ]) as $linkedId) {
                if ($linkedId === '' || ! $byId->has($linkedId) || $linkedId === $id) {
                    continue;
                }

                $adjacency[$id][$linkedId] = true;
                $adjacency[$linkedId][$id] = true;
            }
        }

        $visited = [];
        $clusters = [];

        foreach ($contents as $content) {
            $startId = (string) $content->id;
            if (isset($visited[$startId])) {
                continue;
            }

            $stack = [$startId];
            $component = [];

            while ($stack !== []) {
                $id = array_pop($stack);
                if (isset($visited[$id])) {
                    continue;
                }

                $visited[$id] = true;
                $component[] = $id;

                foreach (array_keys($adjacency[$id] ?? []) as $neighborId) {
                    if (! isset($visited[$neighborId])) {
                        $stack[] = $neighborId;
                    }
                }
            }

            $clusters[] = collect($component)
                ->map(fn (string $id): Content => $byId->get($id))
                ->filter(fn (?Content $content): bool => $content instanceof Content)
                ->sortBy('created_at')
                ->values();
        }

        return $clusters;
    }

    /**
     * @param  Collection<int,Content>  $cluster
     * @return array<string,mixed>
     */
    private function planClusterRepair(Collection $cluster): array
    {
        $root = $cluster
            ->sortBy(fn (Content $content): array => $this->rootPriority($content))
            ->first();

        if (! $root instanceof Content) {
            return [
                'needs_changes' => false,
            ];
        }

        $rootLocale = $root->localeCode();
        $canonicalByLocale = $cluster
            ->groupBy(fn (Content $content): string => $content->localeCode())
            ->map(function (Collection $variants, string $locale) use ($root): Content {
                if ($locale === $root->localeCode()) {
                    return $root;
                }

                return $variants
                    ->sortBy(fn (Content $content): array => $this->canonicalVariantPriority($content, $root))
                    ->first() ?? $variants->firstOrFail();
            });

        $canonicalIds = $canonicalByLocale
            ->map(fn (Content $content): string => (string) $content->id)
            ->values()
            ->all();

        $duplicates = $cluster
            ->groupBy(fn (Content $content): string => $content->localeCode())
            ->map(function (Collection $variants, string $locale) use ($canonicalByLocale): array {
                /** @var Content $canonical */
                $canonical = $canonicalByLocale->get($locale);

                return [
                    'locale' => $locale,
                    'canonical_id' => (string) $canonical->id,
                    'duplicate_ids' => $variants
                        ->reject(fn (Content $content): bool => (string) $content->id === (string) $canonical->id)
                        ->map(fn (Content $content): string => (string) $content->id)
                        ->values()
                        ->all(),
                ];
            })
            ->filter(fn (array $entry): bool => $entry['duplicate_ids'] !== [])
            ->values()
            ->all();

        $mirroredLinks = $this->mirroredLinks($cluster);
        $contentUpdates = [];
        $draftUpdates = [];
        $briefUpdates = [];
        $publicationMoves = [];
        $archivedDuplicateIds = [];

        foreach ($cluster as $content) {
            $contentId = (string) $content->id;

            if ($contentId === (string) $root->id) {
                $updates = $this->diffContentAttributes($content, [
                    'family_id' => (string) $root->id,
                    'translation_source_content_id' => null,
                    'translation_source_version_id' => null,
                    'translation_source_locale' => null,
                    'is_source_locale' => true,
                ]);

                if ($updates !== []) {
                    $contentUpdates[$contentId] = $updates;
                }

                continue;
            }

            /** @var Content $canonical */
            $canonical = $canonicalByLocale->get($content->localeCode()) ?? $root;

            if ($contentId === (string) $canonical->id) {
                $updates = $this->diffContentAttributes($content, [
                    'family_id' => (string) $root->id,
                    'translation_source_content_id' => (string) $root->id,
                    'translation_source_version_id' => $root->current_version_id ? (string) $root->current_version_id : null,
                    'translation_source_locale' => $rootLocale,
                    'is_source_locale' => false,
                ]);

                if ($updates !== []) {
                    $contentUpdates[$contentId] = $updates;
                }

                continue;
            }

            $archivedDuplicateIds[] = $contentId;
            $repairMeta = $this->appendRepairMeta($content, [
                'repaired_at' => now()->toIso8601String(),
                'repair_type' => 'family_integrity_duplicate_archived',
                'canonical_family_id' => (string) $root->id,
                'canonical_content_id' => (string) $canonical->id,
                'canonical_locale' => $canonical->localeCode(),
            ]);

            $updates = $this->diffContentAttributes($content, [
                'family_id' => $contentId,
                'translation_source_content_id' => null,
                'translation_source_version_id' => null,
                'translation_source_locale' => null,
                'is_source_locale' => true,
                'status' => 'archived',
                'locale_repair_meta' => $repairMeta,
            ]);

            if ($updates !== []) {
                $contentUpdates[$contentId] = $updates;
            }

            foreach ($content->drafts as $draft) {
                $draftUpdates[(string) $draft->id] = [
                    'content_id' => (string) $canonical->id,
                ];

                if ($draft->brief_id && $content->brief instanceof Brief) {
                    $briefUpdates[(string) $content->brief->id] = [
                        'content_id' => (string) $canonical->id,
                    ];
                }
            }

            $canonicalPublicationKeys = $canonical->publications
                ->mapWithKeys(fn (ContentPublication $publication): array => [$this->publicationSignature($publication) => true]);

            foreach ($content->publications as $publication) {
                $signature = $this->publicationSignature($publication);

                if ($canonicalPublicationKeys->has($signature)) {
                    continue;
                }

                $publicationMoves[(string) $publication->id] = [
                    'content_id' => (string) $canonical->id,
                ];
            }
        }

        return [
            'needs_changes' => $contentUpdates !== [] || $draftUpdates !== [] || $briefUpdates !== [] || $publicationMoves !== [] || $duplicates !== [] || $mirroredLinks !== [],
            'family_root_id' => (string) $root->id,
            'source_locale' => $rootLocale,
            'locales' => $canonicalByLocale->keys()->values()->all(),
            'duplicates' => $duplicates,
            'mirrored_links' => $mirroredLinks,
            'content_updates' => $contentUpdates,
            'draft_updates' => $draftUpdates,
            'brief_updates' => $briefUpdates,
            'publication_moves' => $publicationMoves,
            'archived_duplicate_ids' => $archivedDuplicateIds,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function applyPlan(array $plan): void
    {
        foreach ($plan['content_updates'] as $contentId => $attributes) {
            $content = Content::query()->find($contentId);
            if (! $content instanceof Content) {
                continue;
            }

            $content->forceFill($attributes)->saveQuietly();
        }

        foreach ($plan['brief_updates'] as $briefId => $attributes) {
            Brief::query()->whereKey($briefId)->update($attributes);
        }

        foreach ($plan['draft_updates'] as $draftId => $attributes) {
            Draft::query()->whereKey($draftId)->update($attributes);
        }

        foreach ($plan['publication_moves'] as $publicationId => $attributes) {
            ContentPublication::query()->whereKey($publicationId)->update($attributes);
        }
    }

    /**
     * @return array<int,string>
     */
    private function mirroredLinks(Collection $cluster): array
    {
        $byId = $cluster->keyBy(fn (Content $content): string => (string) $content->id);
        $pairs = [];

        foreach ($cluster as $content) {
            $targetId = trim((string) $content->translation_source_content_id);
            if ($targetId === '' || ! $byId->has($targetId)) {
                continue;
            }

            $target = $byId->get($targetId);
            if (! $target instanceof Content) {
                continue;
            }

            if ((string) $target->translation_source_content_id !== (string) $content->id) {
                continue;
            }

            $pair = collect([(string) $content->id, (string) $target->id])->sort()->implode('<->');
            $pairs[$pair] = $pair;
        }

        return array_values($pairs);
    }

    /**
     * @return array<int,int|string>
     */
    private function rootPriority(Content $content): array
    {
        return [
            $content->translation_source_content_id === null ? 0 : 1,
            (bool) $content->is_source_locale ? 0 : 1,
            (string) $content->status === 'archived' ? 1 : 0,
            (int) ($content->created_at?->timestamp ?? 0),
            (string) $content->id,
        ];
    }

    /**
     * @return array<int,int|string>
     */
    private function canonicalVariantPriority(Content $content, Content $root): array
    {
        return [
            (string) $content->status === 'archived' ? 1 : 0,
            (string) $content->translation_source_content_id === (string) $root->id ? 0 : 1,
            (bool) $content->is_source_locale ? 1 : 0,
            $content->isPublishedForTranslation() ? 0 : 1,
            $content->isDeliveredForTranslation() ? 0 : 1,
            $content->current_version_id ? 0 : 1,
            $content->drafts->isNotEmpty() ? 0 : 1,
            $content->publications->isNotEmpty() ? 0 : 1,
            -1 * (int) ($content->updated_at?->timestamp ?? 0),
            (int) ($content->created_at?->timestamp ?? 0),
            (string) $content->id,
        ];
    }

    /**
     * @param  array<string,mixed>  $target
     * @return array<string,mixed>
     */
    private function diffContentAttributes(Content $content, array $target): array
    {
        $updates = [];

        foreach ($target as $key => $value) {
            $current = $content->getAttribute($key);

            if ($current instanceof Collection) {
                $current = $current->all();
            }

            if ($current === $value) {
                continue;
            }

            if ($current === null && $value === null) {
                continue;
            }

            $updates[$key] = $value;
        }

        return $updates;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<int|string,mixed>
     */
    private function appendRepairMeta(Content $content, array $entry): array
    {
        $meta = is_array($content->locale_repair_meta) ? $content->locale_repair_meta : [];
        $existingEntries = data_get($meta, 'family_integrity_repairs', []);

        if (! is_array($existingEntries)) {
            $existingEntries = [];
        }

        $existingEntries[] = $entry;
        $meta['family_integrity_repairs'] = $existingEntries;

        return $meta;
    }

    private function publicationSignature(ContentPublication $publication): string
    {
        $locale = $publication->locale;
        if ($locale instanceof \BackedEnum) {
            $locale = $locale->value;
        }

        return implode('|', [
            (string) ($publication->destination_id ?? ''),
            (string) ($publication->client_site_id ?? ''),
            (string) ($publication->provider ?? ''),
            (string) ($locale ?? ''),
        ]);
    }
}
