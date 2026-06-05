<?php

namespace App\Services\Content;

use App\Enums\ContentDestinationType;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Services\Publication\ContentPublicationStateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContentStateRepairService
{
    public function __construct(
        private readonly ContentPublicationStateService $publicationState,
    ) {}

    /**
     * @param  array{workspace?:?string,site?:?string,content?:?string}  $filters
     * @return array<string,mixed>
     */
    public function repair(array $filters = [], bool $dryRun = false): array
    {
        $contents = $this->scopedContentsQuery($filters)
            ->with([
                'clientSite',
                'contentDestination',
                'translationSourceContent',
                'publications.destination',
            ])
            ->orderBy('created_at')
            ->get();

        $contentIds = $contents->pluck('id')->map(fn ($id): string => (string) $id)->all();

        $familyPlan = $this->planFamilyRepairs($contents, $filters);
        $publicationLocalePlan = $this->planPublicationLocaleRepairs($contentIds, $filters);
        $publicationStatePlan = $this->planPublicationStateRepairs($contents);
        $shadowPlan = $this->planLegacyShadowRepairs($contents);

        if (! $dryRun) {
            DB::transaction(function () use ($familyPlan, $publicationLocalePlan, $publicationStatePlan, $shadowPlan): void {
                $this->applyContentUpdates($familyPlan['content_updates'] ?? []);
                $this->applyPublicationLocaleUpdates($publicationLocalePlan['updates'] ?? []);
                $this->applyPublicationUpdates($publicationStatePlan['updates'] ?? []);
                $this->applyContentUpdates($shadowPlan['content_updates'] ?? []);
            });
        }

        return [
            'dry_run' => $dryRun,
            'filters' => [
                'workspace' => $filters['workspace'] ?? null,
                'site' => $filters['site'] ?? null,
                'content' => $filters['content'] ?? null,
            ],
            'scope' => [
                'contents_scanned' => $contents->count(),
                'publications_scanned' => (int) ($publicationLocalePlan['scanned_rows'] ?? 0),
            ],
            'family' => $familyPlan,
            'publication_locales' => $publicationLocalePlan,
            'publication_states' => $publicationStatePlan,
            'legacy_shadows' => $shadowPlan,
            'reports' => $this->buildInconsistencyReport($contents, $filters, $contentIds, $familyPlan),
        ];
    }

    /**
     * @param  Collection<int,Content>  $contents
     * @param  array{workspace?:?string,site?:?string,content?:?string}  $filters
     * @return array<string,mixed>
     */
    private function planFamilyRepairs(Collection $contents, array $filters): array
    {
        $scannedRows = 0;
        $skippedRows = 0;
        $unrepairableRows = [];
        $contentUpdates = [];
        $repairs = [];
        $plannedIds = [];

        $candidates = $contents
            ->filter(fn (Content $content): bool => $content->translation_source_content_id !== null && $content->family_id === null)
            ->values();

        foreach ($candidates as $content) {
            $scannedRows++;

            $source = $this->resolveFamilySource($content);
            if (! $source instanceof Content) {
                $unrepairableRows[] = [
                    'content_id' => (string) $content->id,
                    'translation_source_content_id' => (string) ($content->translation_source_content_id ?? ''),
                    'reason' => 'missing_source_content',
                ];

                continue;
            }

            $familyId = trim((string) ($source->family_id ?: $source->id));
            if ($familyId === '') {
                $unrepairableRows[] = [
                    'content_id' => (string) $content->id,
                    'translation_source_content_id' => (string) ($content->translation_source_content_id ?? ''),
                    'reason' => 'source_family_unresolved',
                ];

                continue;
            }

            $relatedFamilyRows = $this->relatedFamilyRows($source, $filters);

            foreach ($relatedFamilyRows as $related) {
                $relatedId = (string) $related->id;

                if (isset($plannedIds[$relatedId])) {
                    $skippedRows++;
                    continue;
                }

                if ($related->translation_source_content_id !== null && $related->family_id === null) {
                    $contentUpdates[$relatedId] = [
                        'family_id' => $familyId,
                    ];

                    $repairs[] = [
                        'content_id' => $relatedId,
                        'locale' => $related->localeCode(),
                        'source_id' => (string) $source->id,
                        'family_id' => $familyId,
                    ];

                    $plannedIds[$relatedId] = true;
                }
            }

            if ($source->family_id === null && ! isset($plannedIds[(string) $source->id])) {
                $contentUpdates[(string) $source->id] = [
                    'family_id' => (string) $source->id,
                ];

                $repairs[] = [
                    'content_id' => (string) $source->id,
                    'locale' => $source->localeCode(),
                    'source_id' => (string) $source->id,
                    'family_id' => (string) $source->id,
                ];

                $plannedIds[(string) $source->id] = true;
            }
        }

        return [
            'scanned_rows' => $scannedRows,
            'repaired_rows' => count($contentUpdates),
            'skipped_rows' => $skippedRows,
            'unrepairable_rows' => count($unrepairableRows),
            'content_updates' => $contentUpdates,
            'repairs' => $repairs,
            'failures' => $unrepairableRows,
        ];
    }

    /**
     * @param  array<int,string>  $contentIds
     * @param  array{workspace?:?string,site?:?string,content?:?string}  $filters
     * @return array<string,mixed>
     */
    private function planPublicationLocaleRepairs(array $contentIds, array $filters): array
    {
        $query = ContentPublication::query()
            ->with(['content' => fn ($builder) => $builder->select('id', 'language', 'workspace_id', 'client_site_id')]);

        if ($contentIds !== []) {
            $query->whereIn('content_id', $contentIds);
        } else {
            $query->whereRaw('1 = 0');
        }

        if (filled($filters['site'] ?? null)) {
            $query->where('client_site_id', (string) $filters['site']);
        }

        $publications = $query
            ->orderBy('created_at')
            ->get();

        $updates = [];
        $repairs = [];

        foreach ($publications as $publication) {
            $content = $publication->content;
            if (! $content instanceof Content) {
                continue;
            }

            $currentLocale = trim((string) ($publication->locale?->value ?? $publication->getRawOriginal('locale') ?? ''));
            $targetLocale = $content->localeCode();

            if ($currentLocale === $targetLocale) {
                continue;
            }

            $updates[(string) $publication->id] = [
                'locale' => $targetLocale,
            ];

            $repairs[] = [
                'publication_id' => (string) $publication->id,
                'content_id' => (string) $content->id,
                'from' => $currentLocale,
                'to' => $targetLocale,
            ];
        }

        return [
            'scanned_rows' => $publications->count(),
            'mismatched_rows' => count($updates),
            'repaired_rows' => count($updates),
            'updates' => $updates,
            'repairs' => $repairs,
        ];
    }

    /**
     * @param  Collection<int,Content>  $contents
     * @return array<string,mixed>
     */
    private function planLegacyShadowRepairs(Collection $contents): array
    {
        $contentUpdates = [];
        $repairs = [];

        foreach ($contents as $content) {
            $publication = $this->publicationState->resolveCanonicalPublication($content);
            if (! $publication instanceof ContentPublication) {
                continue;
            }

            $target = $this->publicationState->legacyShadowAttributes($content, $publication);
            $changes = $this->contentAttributeDiff($content, $target);

            if ($changes === []) {
                continue;
            }

            $contentUpdates[(string) $content->id] = $changes;
            $repairs[] = [
                'content_id' => (string) $content->id,
                'publication_id' => (string) $publication->id,
                'changes' => $changes,
            ];
        }

        return [
            'scanned_rows' => $contents->count(),
            'repaired_rows' => count($contentUpdates),
            'content_updates' => $contentUpdates,
            'repairs' => $repairs,
        ];
    }

    /**
     * @param  Collection<int,Content>  $contents
     * @return array<string,mixed>
     */
    private function planPublicationStateRepairs(Collection $contents): array
    {
        $updates = [];
        $repairs = [];
        $scannedRows = 0;

        foreach ($contents as $content) {
            foreach ($content->publications as $publication) {
                $scannedRows++;

                $expectedProvider = $this->expectedProviderForPublication($publication, $content);
                if ($expectedProvider === null || (string) ($publication->provider ?? '') === $expectedProvider) {
                    continue;
                }

                $updates[(string) $publication->id] = [
                    'provider' => $expectedProvider,
                ];

                $repairs[] = [
                    'publication_id' => (string) $publication->id,
                    'content_id' => (string) $content->id,
                    'from_provider' => (string) ($publication->provider ?? ''),
                    'to_provider' => $expectedProvider,
                ];

                // Keep the in-memory collection aligned so shadow repair planning uses canonical provider metadata.
                $publication->setAttribute('provider', $expectedProvider);
            }
        }

        return [
            'scanned_rows' => $scannedRows,
            'repaired_rows' => count($updates),
            'updates' => $updates,
            'repairs' => $repairs,
        ];
    }

    /**
     * @param  Collection<int,Content>  $contents
     * @param  array{workspace?:?string,site?:?string,content?:?string}  $filters
     * @param  array<int,string>  $contentIds
     * @param  array<string,mixed>  $familyPlan
     * @return array<string,mixed>
     */
    private function buildInconsistencyReport(
        Collection $contents,
        array $filters,
        array $contentIds,
        array $familyPlan,
    ): array {
        $familyUpdates = collect($familyPlan['content_updates'] ?? []);

        $orphanedTranslations = $contents
            ->filter(function (Content $content) use ($contents, $familyUpdates): bool {
                if ($content->translation_source_content_id === null) {
                    return false;
                }

                $source = $this->resolveFamilySource($content);
                if (! $source instanceof Content) {
                    return true;
                }

                $effectiveFamilyId = (string) ($familyUpdates->get((string) $content->id)['family_id'] ?? $content->family_id ?? '');
                $sourceFamilyId = (string) ($familyUpdates->get((string) $source->id)['family_id'] ?? $source->family_id ?? $source->id);

                return $effectiveFamilyId === '' || $effectiveFamilyId !== $sourceFamilyId;
            })
            ->map(fn (Content $content): array => [
                'content_id' => (string) $content->id,
                'source_id' => (string) ($content->translation_source_content_id ?? ''),
                'family_id' => (string) ($content->family_id ?? ''),
                'locale' => $content->localeCode(),
            ])
            ->values()
            ->all();

        $orphanedPublications = $this->orphanedPublications($filters, $contentIds);

        $conflictingPublicationRows = $contents
            ->filter(function (Content $content): bool {
                $signatures = $content->publications
                    ->map(fn (ContentPublication $publication): string => implode('|', [
                        (string) ($publication->provider ?? ''),
                        (string) ($publication->destination_id ?? ''),
                        (string) ($publication->delivery_status ?? ''),
                        (string) ($publication->remote_status ?? ''),
                        (string) ($publication->locale?->value ?? $publication->getRawOriginal('locale') ?? ''),
                    ]))
                    ->unique();

                return $signatures->count() > 1;
            })
            ->map(fn (Content $content): array => [
                'content_id' => (string) $content->id,
                'publication_ids' => $content->publications->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
                'states' => $content->publications
                    ->map(fn (ContentPublication $publication): array => [
                        'publication_id' => (string) $publication->id,
                        'provider' => (string) ($publication->provider ?? ''),
                        'destination_id' => (string) ($publication->destination_id ?? ''),
                        'delivery_status' => (string) ($publication->delivery_status ?? ''),
                        'remote_status' => (string) ($publication->remote_status ?? ''),
                        'locale' => (string) ($publication->locale?->value ?? $publication->getRawOriginal('locale') ?? ''),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        $invalidFamilies = $contents
            ->groupBy(fn (Content $content): string => (string) ($familyUpdates->get((string) $content->id)['family_id'] ?? $content->family_id ?? $content->id))
            ->map(function (Collection $family, string $familyId): ?array {
                $duplicateLocales = $family
                    ->groupBy(fn (Content $content): string => $content->localeCode())
                    ->filter(fn (Collection $variants): bool => $variants->count() > 1)
                    ->map(fn (Collection $variants, string $locale): array => [
                        'locale' => $locale,
                        'content_ids' => $variants->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
                    ])
                    ->values()
                    ->all();

                $sourceRows = $family->filter(fn (Content $content): bool => (bool) $content->is_source_locale);

                if ($duplicateLocales === [] && $sourceRows->count() === 1) {
                    return null;
                }

                return [
                    'family_id' => $familyId,
                    'source_count' => $sourceRows->count(),
                    'locales' => $family->pluck('language.value')->filter()->values()->all(),
                    'duplicate_locales' => $duplicateLocales,
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'orphaned_translations' => $orphanedTranslations,
            'orphaned_publications' => $orphanedPublications,
            'conflicting_publications' => $conflictingPublicationRows,
            'invalid_families' => $invalidFamilies,
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $updates
     */
    private function applyContentUpdates(array $updates): void
    {
        foreach ($updates as $contentId => $attributes) {
            Content::query()
                ->whereKey($contentId)
                ->update($attributes);
        }
    }

    /**
     * @param  array<string,array<string,mixed>>  $updates
     */
    private function applyPublicationLocaleUpdates(array $updates): void
    {
        foreach ($updates as $publicationId => $attributes) {
            ContentPublication::query()
                ->whereKey($publicationId)
                ->update($attributes);
        }
    }

    /**
     * @param  array<string,array<string,mixed>>  $updates
     */
    private function applyPublicationUpdates(array $updates): void
    {
        foreach ($updates as $publicationId => $attributes) {
            ContentPublication::query()
                ->whereKey($publicationId)
                ->update($attributes);
        }
    }

    private function resolveFamilySource(Content $content): ?Content
    {
        $current = $content;
        $visited = [];

        while ($current instanceof Content) {
            $currentId = (string) $current->id;

            if ($currentId === '' || isset($visited[$currentId])) {
                break;
            }

            $visited[$currentId] = true;

            if ($current->translation_source_content_id === null) {
                return $current;
            }

            $nextId = (string) $current->translation_source_content_id;
            $next = Content::query()
                ->select(['id', 'family_id', 'translation_source_content_id', 'language', 'client_site_id', 'workspace_id'])
                ->find($nextId);

            if (! $next instanceof Content) {
                return null;
            }

            $current = $next;
        }

        return null;
    }

    /**
     * @param  array{workspace?:?string,site?:?string,content?:?string}  $filters
     * @return Collection<int,Content>
     */
    private function relatedFamilyRows(Content $source, array $filters): Collection
    {
        $query = Content::query()
            ->select(['id', 'family_id', 'translation_source_content_id', 'language', 'workspace_id', 'client_site_id'])
            ->where(function (Builder $builder) use ($source): void {
                $builder->whereKey((string) $source->id)
                    ->orWhere('translation_source_content_id', (string) $source->id);

                if ($source->family_id !== null) {
                    $builder->orWhere('family_id', (string) $source->family_id);
                }
            });

        if (filled($filters['workspace'] ?? null)) {
            $query->where('workspace_id', (string) $filters['workspace']);
        }

        if (filled($filters['site'] ?? null)) {
            $query->where('client_site_id', (string) $filters['site']);
        }

        return $query->get();
    }

    /**
     * @param  array<string,mixed>  $target
     * @return array<string,mixed>
     */
    private function contentAttributeDiff(Content $content, array $target): array
    {
        $changes = [];

        foreach ($target as $attribute => $value) {
            $current = $this->normalizeComparableValue($content->{$attribute});
            $targetValue = $this->normalizeComparableValue($value);

            if ((string) ($current ?? '') === (string) ($targetValue ?? '')) {
                continue;
            }

            $changes[$attribute] = $value;
        }

        return $changes;
    }

    private function normalizeComparableValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }

    /**
     * @param  array{workspace?:?string,site?:?string,content?:?string}  $filters
     * @param  array<int,string>  $contentIds
     * @return array<int,array<string,mixed>>
     */
    private function orphanedPublications(array $filters, array $contentIds): array
    {
        $query = DB::table('content_publications')
            ->leftJoin('contents', 'contents.id', '=', 'content_publications.content_id')
            ->whereNull('contents.id')
            ->select([
                'content_publications.id',
                'content_publications.content_id',
                'content_publications.client_site_id',
                'content_publications.destination_id',
                'content_publications.provider',
                'content_publications.locale',
            ]);

        if (filled($filters['site'] ?? null)) {
            $query->where('content_publications.client_site_id', (string) $filters['site']);
        }

        if (filled($filters['content'] ?? null) && $contentIds !== []) {
            $query->whereIn('content_publications.content_id', $contentIds);
        }

        return collect($query->get())
            ->map(fn ($row): array => [
                'publication_id' => (string) $row->id,
                'content_id' => (string) $row->content_id,
                'client_site_id' => (string) ($row->client_site_id ?? ''),
                'destination_id' => (string) ($row->destination_id ?? ''),
                'provider' => (string) ($row->provider ?? ''),
                'locale' => (string) ($row->locale ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{workspace?:?string,site?:?string,content?:?string}  $filters
     */
    private function scopedContentsQuery(array $filters): Builder
    {
        $query = Content::query();

        if (filled($filters['workspace'] ?? null)) {
            $query->where('workspace_id', (string) $filters['workspace']);
        }

        if (filled($filters['site'] ?? null)) {
            $query->where('client_site_id', (string) $filters['site']);
        }

        if (! filled($filters['content'] ?? null)) {
            return $query;
        }

        $content = Content::query()->find((string) $filters['content']);
        if (! $content instanceof Content) {
            return $query->whereRaw('1 = 0');
        }

        $rootIds = array_values(array_unique(array_filter([
            (string) $content->id,
            (string) ($content->translation_source_content_id ?? ''),
            (string) ($content->family_id ?? ''),
        ])));

        return $query->where(function (Builder $builder) use ($content, $rootIds): void {
            $builder->whereKey((string) $content->id);

            if ($rootIds !== []) {
                $builder->orWhereIn('id', $rootIds)
                    ->orWhereIn('translation_source_content_id', $rootIds)
                    ->orWhereIn('family_id', $rootIds);
            }
        });
    }

    private function expectedProviderForPublication(ContentPublication $publication, Content $content): ?string
    {
        $destinationType = $publication->destination?->resolvedType()
            ?? $content->contentDestination?->resolvedType()
            ?? ContentDestinationType::fromNormalized($content->clientSite?->type);

        return match ($destinationType) {
            ContentDestinationType::WORDPRESS => ContentPublication::PROVIDER_WORDPRESS,
            ContentDestinationType::LARAVEL => ContentPublication::PROVIDER_LARAVEL,
            ContentDestinationType::API => ContentPublication::PROVIDER_API,
            default => null,
        };
    }
}
