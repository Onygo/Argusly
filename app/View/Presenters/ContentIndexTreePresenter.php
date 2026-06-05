<?php

namespace App\View\Presenters;

use App\Enums\SupportedLanguage;
use App\Enums\ContentOriginType;
use App\Enums\ContentSource;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\ContentTranslation;
use App\Models\ContentSeries;
use Illuminate\Support\Collection;

class ContentIndexTreePresenter
{
    /**
     * @param  Collection<int,Content>  $contents
     * @param  Collection<int,Content>  $matchingContents
     * @param  array<string,mixed>  $filters
     * @param  array<string,array<string,mixed>>  $contentInsights
     * @return Collection<int,array<string,mixed>>
     */
    public static function present(
        Collection $contents,
        Collection $matchingContents,
        array $filters = [],
        array $contentInsights = [],
    ): Collection {
        $matchingIds = $matchingContents
            ->values()
            ->mapWithKeys(fn (Content $content, int $index): array => [(string) $content->id => $index]);

        $hasActiveFilters = self::hasActiveFilters($filters);
        $hasVariantScopedFilters = self::hasVariantScopedFilters($filters);

        $articleNodes = $contents
            ->groupBy(fn (Content $content): string => self::articleRootId($content))
            ->map(fn (Collection $variants): array => self::buildArticleNode(
                $variants,
                $matchingIds,
                $filters,
                $contentInsights,
                $hasActiveFilters,
                $hasVariantScopedFilters,
            ))
            ->sortBy([
                ['group_sort', 'asc'],
                ['match_sort', 'asc'],
                ['article_sort', 'asc'],
                ['title', 'asc'],
            ])
            ->values();

        return $articleNodes
            ->groupBy('group_key')
            ->map(fn (Collection $articles): array => self::buildGroupNode($articles, $filters, $hasActiveFilters))
            ->filter(fn (array $group): bool => ! $hasActiveFilters || $group['is_visible'])
            ->sortBy([
                ['group_sort', 'asc'],
                ['match_sort', 'asc'],
                ['updated_timestamp', 'desc'],
                ['title', 'asc'],
            ])
            ->values();
    }

    private static function hasActiveFilters(array $filters): bool
    {
        foreach ([
            'inbox',
            'q',
            'status',
            'site',
            'author',
            'publish_status',
            'locale',
            'publication_state',
            'translation_state',
            'workflow_state',
            'locale_scope',
            'preset',
            'structure',
            'role',
            'origin',
            'series',
            'automation',
            'created_from',
            'created_to',
            'published_from',
            'published_to',
        ] as $key) {
            if (filled($filters[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private static function hasVariantScopedFilters(array $filters): bool
    {
        foreach ([
            'inbox',
            'status',
            'site',
            'author',
            'publish_status',
            'locale',
            'publication_state',
            'translation_state',
            'workflow_state',
            'locale_scope',
            'preset',
            'origin',
            'automation',
            'created_from',
            'created_to',
            'published_from',
            'published_to',
        ] as $key) {
            if (filled($filters[$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private static function articleRootId(Content $content): string
    {
        return $content->localizationRootId();
    }

    /**
     * @param  Collection<int,bool>  $matchingIds
     * @param  array<string,mixed>  $filters
     * @param  array<string,array<string,mixed>>  $contentInsights
     * @return array<string,mixed>
     */
    private static function buildArticleNode(
        Collection $variants,
        Collection $matchingIds,
        array $filters,
        array $contentInsights,
        bool $hasActiveFilters,
        bool $hasVariantScopedFilters,
    ): array {
        $rootId = self::articleRootId($variants->first());
        /** @var Content|null $root */
        $root = $variants->first(fn (Content $content): bool => (string) $content->id === $rootId);
        $normalizedVariants = $variants
            ->groupBy(fn (Content $content): string => $content->localeCode())
            ->map(fn (Collection $localeVariants): Content => self::selectCanonicalLocaleVariant($localeVariants, $root))
            ->sortBy(function (Content $content) use ($root): array {
                return [
                    self::isSourceVariant($content, $root) ? 0 : 1,
                    $content->seriesArticle?->article_number ?? PHP_INT_MAX,
                    $content->localeCode(),
                    -1 * (int) ($content->updated_at?->timestamp ?? 0),
                ];
            })
            ->values();

        /** @var Content $source */
        $source = $normalizedVariants->first(fn (Content $content): bool => self::isSourceVariant($content, $root)) ?? $normalizedVariants->first();
        $canonical = $source;
        $enabledLocales = self::expectedLocalesFor($canonical);
        $expectedLocales = max(1, count($enabledLocales));

        $variantNodes = $normalizedVariants
            ->map(function (Content $content) use ($matchingIds, $source, $contentInsights): array {
                $presenter = ContentStatusPresenter::for($content);
                $matched = $matchingIds->has((string) $content->id);
                $sourceLocale = $content->isTranslationVariant() && $source instanceof Content
                    ? SupportedLanguage::fromStringOrDefault($source->localeCode())
                    : null;
                $performanceInsight = $contentInsights[(string) $content->id] ?? [];

                return [
                    'key' => 'variant:'.(string) $content->id,
                    'content' => $content,
                    'presenter' => $presenter,
                    'matched' => $matched,
                    'is_source' => self::isSourceVariant($content, $source),
                    'locale' => strtoupper($content->localeCode()),
                    'locale_name' => SupportedLanguage::fromStringOrDefault($content->localeCode())->englishLabel(),
                    'updated_at' => $content->updated_at,
                    'last_sync_at' => $presenter->getPublication()?->last_delivered_at,
                    'site_label' => (string) ($content->clientSite?->name ?? 'No site'),
                    'destination_label' => $presenter->destinationLabel(),
                    'source_locale' => $sourceLocale?->value && $sourceLocale->value !== $content->localeCode()
                        ? strtoupper($sourceLocale->value)
                        : null,
                    'performance' => self::performanceSummary($performanceInsight),
                ];
            })
            ->values();

        $matchedVariants = $variantNodes->filter(fn (array $variant): bool => (bool) $variant['matched'])->values();
        $matchSort = $normalizedVariants
            ->map(fn (Content $content): int => (int) ($matchingIds->get((string) $content->id, PHP_INT_MAX)))
            ->min() ?? PHP_INT_MAX;

        $visibleVariants = match (true) {
            ! $hasActiveFilters => $variantNodes,
            $hasVariantScopedFilters => $matchedVariants,
            $matchedVariants->isNotEmpty() => $variantNodes,
            default => collect(),
        };

        $availableLocales = $variantNodes
            ->pluck('content')
            ->filter(fn (?Content $content): bool => $content instanceof Content)
            ->map(fn (Content $content): string => $content->localeCode())
            ->unique()
            ->count();

        $publishedVariants = $variantNodes->filter(function (array $variant): bool {
            /** @var ContentStatusPresenter $presenter */
            $presenter = $variant['presenter'];

            return $presenter->deliveryStatus()->isSuccess()
                || (string) ($variant['content']->publish_status ?? '') === 'published';
        })->count();

        $failedDeliveries = $variantNodes->filter(function (array $variant): bool {
            /** @var ContentStatusPresenter $presenter */
            $presenter = $variant['presenter'];

            return $presenter->needsAttention() || $presenter->hasDeliveryError();
        })->count();

        $scheduledVariants = $variantNodes->filter(fn (array $variant): bool => (string) ($variant['content']->publish_status ?? '') === 'scheduled')->count();
        $publishingVariants = $variantNodes->filter(fn (array $variant): bool => (string) ($variant['content']->publish_status ?? '') === 'publishing')->count();
        $hasUnpublishedDraft = $variantNodes->contains(function (array $variant): bool {
            /** @var Content $variantContent */
            $variantContent = $variant['content'];

            if ((int) ($variantContent->pending_drafts_count ?? 0) < 1) {
                return false;
            }

            if ((string) ($variantContent->publish_status ?? '') !== 'published' && (string) ($variantContent->status ?? '') !== 'published') {
                return false;
            }

            return true;
        });

        $missingTranslations = max(0, $expectedLocales - $availableLocales);

        $visibleCanonical = $visibleVariants->first()['content'] ?? $canonical;
        $performanceInsight = $contentInsights[(string) ($canonical?->id ?? '')]
            ?? $contentInsights[(string) ($visibleCanonical?->id ?? '')]
            ?? [];
        $sourceContent = $source;
        $translationRequests = $sourceContent->relationLoaded('translationRequests')
            ? $sourceContent->translationRequests->keyBy(fn (ContentTranslation $translation): string => (string) $translation->target_locale)
            : collect();

        $translationTargets = collect($enabledLocales)
            ->reject(fn (SupportedLanguage $language): bool => $language->value === $canonical->localeCode())
            ->map(function (SupportedLanguage $language) use ($variantNodes, $canonical, $translationRequests): array {
                $existing = $variantNodes->first(fn (array $variant): bool => $variant['content']->localeCode() === $language->value);
                $translationRequest = $translationRequests->get($language->value);
                $state = match (true) {
                    ! $translationRequest instanceof ContentTranslation => 'ready',
                    $translationRequest->isInsufficientCreditsFailure() => ContentTranslation::STATUS_INSUFFICIENT_CREDITS,
                    $translationRequest->isStaleFailure() => 'stale_recovered',
                    default => (string) $translationRequest->displayStatus(),
                };
                $hasExistingVariant = $existing !== null || filled($translationRequest?->target_content_id);

                return [
                    'locale' => $language->value,
                    'label' => strtoupper($language->value),
                    'verb' => match (true) {
                        $state === ContentTranslation::STATUS_INSUFFICIENT_CREDITS => 'Retry after adding credits',
                        in_array($state, [ContentTranslation::STATUS_FAILED, 'stale', 'stale_recovered'], true) => 'Retry translation',
                        $hasExistingVariant => 'Refresh translation',
                        default => 'Translate',
                    },
                    'content' => $canonical,
                    'state' => $state,
                    'state_label' => match ($state) {
                        'ready' => 'Ready for translation',
                        ContentTranslation::STATUS_QUEUED => 'Queued',
                        ContentTranslation::STATUS_PROCESSING => 'Translating',
                        ContentTranslation::STATUS_COMPLETED => 'Translated',
                        ContentTranslation::STATUS_FAILED => 'Failed',
                        ContentTranslation::STATUS_INSUFFICIENT_CREDITS => 'Not enough credits',
                        'stale_recovered' => 'Stale recovered',
                        default => null,
                    },
                    'error_message' => $translationRequest?->displayErrorMessage(),
                    'is_disabled' => in_array($state, [
                        ContentTranslation::STATUS_QUEUED,
                        ContentTranslation::STATUS_PROCESSING,
                    ], true),
                ];
            })
            ->values()
            ->all();

        $translationFailureCount = collect($translationTargets)->filter(fn (array $target): bool => in_array((string) ($target['state'] ?? ''), [
            ContentTranslation::STATUS_FAILED,
            ContentTranslation::STATUS_INSUFFICIENT_CREDITS,
            'stale_recovered',
        ], true))->count();
        $articleStatus = self::articleStatusLabel(
            $availableLocales,
            $expectedLocales,
            $publishedVariants,
            $failedDeliveries,
            $scheduledVariants,
            $publishingVariants,
        );
        $statusDetails = self::articleStatusDetails(
            $missingTranslations,
            $translationFailureCount,
            $failedDeliveries,
            $hasUnpublishedDraft,
        );

        $seriesArticle = $canonical?->seriesArticle;
        $role = $seriesArticle?->is_pillar ? 'pillar' : ($canonical?->series_id ? 'supporting' : 'standard');
        $roleLabel = match ($role) {
            'pillar' => 'Pillar',
            'supporting' => 'Supporting',
            default => 'Standard',
        };

        return [
            'key' => 'article:'.self::articleRootId($canonical),
            'group_key' => $canonical?->series_id ? 'series:'.(string) $canonical->series_id : 'standalone:'.self::articleRootId($canonical),
            'group_sort' => $canonical?->series_id ? 0 : 1,
            'article_sort' => (int) ($seriesArticle?->article_number ?? 0),
            'match_sort' => $matchSort,
            'title' => (string) ($canonical?->title ?? 'Untitled content'),
            'canonical_content' => $canonical,
            'source_content' => $source,
            'role' => $role,
            'role_label' => $roleLabel,
            'series' => $canonical?->series,
            'series_article' => $seriesArticle,
            'source_locale' => strtoupper($source?->localeCode() ?? 'EN'),
            'site_label' => (string) ($canonical?->clientSite?->name ?? 'No site'),
            'destination_label' => (string) ($canonical?->contentDestination?->typeLabel() ?? $canonical?->clientSite?->name ?? 'No destination'),
            'updated_at' => $normalizedVariants->max('updated_at'),
            'updated_timestamp' => (int) ($normalizedVariants->max('updated_at')?->timestamp ?? 0),
            'all_variants' => $variantNodes->all(),
            'visible_variants' => $visibleVariants->all(),
            'is_visible' => ! $hasActiveFilters || $visibleVariants->isNotEmpty(),
            'default_open' => false,
            'translation_targets' => $translationTargets,
            'summary' => [
                'expected_locales' => $expectedLocales,
                'available_locales' => $availableLocales,
                'published_variants' => $publishedVariants,
                'missing_translations' => $missingTranslations,
                'failed_deliveries' => $failedDeliveries,
                'visible_variant_count' => $visibleVariants->count(),
                'status_label' => $articleStatus['label'],
                'status_color' => $articleStatus['color'],
                'status_tooltip' => $statusDetails['tooltip'],
                'status_reasons' => $statusDetails['reasons'],
                'translation_progress_text' => sprintf('%d of %d locales', $availableLocales, $expectedLocales),
                'publication_progress_text' => sprintf('published in %d of %d locales', $publishedVariants, $availableLocales ?: $expectedLocales),
                'completion_percent' => $expectedLocales > 0 ? (int) round(($availableLocales / $expectedLocales) * 100) : 0,
                'published_percent' => $expectedLocales > 0 ? (int) round(($publishedVariants / $expectedLocales) * 100) : 0,
            ],
            'performance' => self::performanceSummary($performanceInsight),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $articles
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private static function buildGroupNode(Collection $articles, array $filters, bool $hasActiveFilters): array
    {
        $first = $articles->first();
        $series = $first['series'] ?? null;
        $visibleArticles = $articles->filter(fn (array $article): bool => (bool) $article['is_visible'])->values();
        $matchSort = (int) ($visibleArticles->min('match_sort') ?? $articles->min('match_sort') ?? PHP_INT_MAX);

        $articleCount = $articles->count();
        $pillarCount = $articles->filter(fn (array $article): bool => (string) $article['role'] === 'pillar')->count();
        $expectedLocales = (int) $articles->sum(fn (array $article): int => (int) data_get($article, 'summary.expected_locales', 0));
        $availableLocales = (int) $articles->sum(fn (array $article): int => (int) data_get($article, 'summary.available_locales', 0));
        $publishedVariants = (int) $articles->sum(fn (array $article): int => (int) data_get($article, 'summary.published_variants', 0));
        $missingTranslations = (int) $articles->sum(fn (array $article): int => (int) data_get($article, 'summary.missing_translations', 0));
        $failedDeliveries = (int) $articles->sum(fn (array $article): int => (int) data_get($article, 'summary.failed_deliveries', 0));
        $groupStatus = self::groupStatusLabel(
            $expectedLocales,
            $availableLocales,
            $publishedVariants,
            $failedDeliveries,
        );

        $title = $series instanceof ContentSeries
            ? (string) $series->name
            : (string) ($first['title'] ?? 'Standalone content');

        return [
            'key' => (string) ($first['group_key'] ?? 'group:unknown'),
            'kind' => $series instanceof ContentSeries ? 'chain' : 'standalone',
            'label' => $series instanceof ContentSeries ? 'Chain' : 'Standalone',
            'title' => $title,
            'series' => $series,
            'articles' => $visibleArticles->all(),
            'is_visible' => ! $hasActiveFilters || $visibleArticles->isNotEmpty(),
            'default_open' => false,
            'group_sort' => $series instanceof ContentSeries ? 0 : 1,
            'match_sort' => $matchSort,
            'updated_timestamp' => (int) $articles->max('updated_timestamp'),
            'summary' => [
                'article_count' => $articleCount,
                'visible_article_count' => $visibleArticles->count(),
                'pillar_count' => $pillarCount,
                'expected_locales' => $expectedLocales,
                'available_locales' => $availableLocales,
                'published_variants' => $publishedVariants,
                'missing_translations' => $missingTranslations,
                'failed_deliveries' => $failedDeliveries,
                'status_label' => $groupStatus['label'],
                'status_color' => $groupStatus['color'],
                'completion_percent' => $expectedLocales > 0 ? (int) round(($availableLocales / $expectedLocales) * 100) : 0,
                'published_percent' => $expectedLocales > 0 ? (int) round(($publishedVariants / $expectedLocales) * 100) : 0,
            ],
        ];
    }

    private static function isSourceVariant(Content $content, ?Content $root = null): bool
    {
        return ! $content->isTranslationVariant()
            || (bool) $content->is_source_locale
            || ($root instanceof Content && (string) $content->id === (string) $root->id);
    }

    /**
     * @return Collection<int,SupportedLanguage>
     */
    private static function expectedLocalesFor(?Content $content): Collection
    {
        if (! $content instanceof Content) {
            return collect([SupportedLanguage::EN]);
        }

        $workspaceLocales = collect($content->workspace?->getEnabledLanguagesAsEnums() ?? [])
            ->map(fn (SupportedLanguage $language): string => $language->value)
            ->values();

        $automation = $content->relationLoaded('automation') ? $content->automation : null;
        if ($automation instanceof ContentAutomation) {
            $sourceLocale = SupportedLanguage::normalizeLocale(
                $automation->locale instanceof SupportedLanguage
                    ? $automation->locale->value
                    : (string) $automation->locale
            ) ?: $content->localeCode();

            $automationLocales = collect($automation->autoTranslateGeneratedContent() ? (array) $automation->locales : [])
                ->prepend($sourceLocale)
                ->map(fn (mixed $locale): ?string => SupportedLanguage::normalizeLocale((string) $locale))
                ->filter()
                ->unique()
                ->values();

            if ($workspaceLocales->isNotEmpty()) {
                $automationLocales = $automationLocales
                    ->filter(fn (string $locale): bool => $workspaceLocales->contains($locale))
                    ->values();
            }

            if ($automationLocales->isNotEmpty()) {
                return $automationLocales
                    ->map(fn (string $locale): SupportedLanguage => SupportedLanguage::fromStringOrDefault($locale))
                    ->values();
            }
        }

        $originType = $content->origin_type instanceof ContentOriginType
            ? $content->origin_type
            : ContentOriginType::tryFrom((string) $content->origin_type);
        $source = $content->source instanceof ContentSource
            ? $content->source
            : ContentSource::tryFrom((string) $content->source);

        if (($originType?->isFromAutomation() ?? false) || $source === ContentSource::AUTOMATION) {
            return collect([SupportedLanguage::fromStringOrDefault($content->localeCode())]);
        }

        if ($workspaceLocales->isNotEmpty()) {
            return $workspaceLocales
                ->map(fn (string $locale): SupportedLanguage => SupportedLanguage::fromStringOrDefault($locale))
                ->values();
        }

        return collect([SupportedLanguage::fromStringOrDefault($content->localeCode())]);
    }

    private static function selectCanonicalLocaleVariant(Collection $variants, ?Content $root = null): Content
    {
        return $variants
            ->sortBy(fn (Content $content): array => self::canonicalLocaleVariantPriority($content, $root))
            ->first() ?? $variants->firstOrFail();
    }

    /**
     * @return array<int,int|string>
     */
    private static function canonicalLocaleVariantPriority(Content $content, ?Content $root = null): array
    {
        return [
            ($root instanceof Content && (string) $content->id === (string) $root->id) ? 0 : 1,
            (bool) $content->is_source_locale ? 0 : 1,
            (string) $content->status === 'archived' ? 1 : 0,
            $content->isPublishedForTranslation() ? 0 : 1,
            $content->isDeliveredForTranslation() ? 0 : 1,
            -1 * (int) ($content->updated_at?->timestamp ?? 0),
            (int) ($content->created_at?->timestamp ?? 0),
            (string) $content->id,
        ];
    }

    /**
     * @param  array<string,mixed>  $insight
     * @return array<string,mixed>
     */
    private static function performanceSummary(array $insight): array
    {
        $statusCode = (string) data_get($insight, 'status_code', 'waiting_for_data');
        $statusMessage = (string) data_get($insight, 'status_message', 'Waiting for tracking data.');
        $hasScores = is_numeric(data_get($insight, 'roi_score'))
            || is_numeric(data_get($insight, 'ai_visibility_score'))
            || is_numeric(data_get($insight, 'ai_seo_score'));

        $message = match (true) {
            $hasScores => trim(collect([
                is_numeric(data_get($insight, 'roi_score')) ? 'ROI '.number_format((float) data_get($insight, 'roi_score'), 1) : null,
                is_numeric(data_get($insight, 'ai_visibility_score')) ? 'AI Visibility '.number_format((float) data_get($insight, 'ai_visibility_score'), 1) : null,
                is_numeric(data_get($insight, 'ai_seo_score')) ? 'AI SEO '.number_format((float) data_get($insight, 'ai_seo_score'), 1) : (
                    data_get($insight, 'ai_seo_score_stale') === true ? 'AI SEO pending recalculation' : null
                ),
            ])->filter()->implode(' • ')),
            $statusCode === 'not_published' => 'Publish to start tracking.',
            in_array($statusCode, ['tracking_not_configured', 'tracking_disabled', 'tracking_pending_verification'], true) => 'Tracking not ready.',
            default => $statusMessage !== '' ? $statusMessage : 'Not enough data yet.',
        };

        return [
            'message' => $message !== '' ? $message : 'Not enough data yet.',
            'has_scores' => $hasScores,
        ];
    }

    /**
     * @return array{label:string,color:string}
     */
    private static function articleStatusLabel(
        int $availableLocales,
        int $expectedLocales,
        int $publishedVariants,
        int $failedDeliveries,
        int $scheduledVariants,
        int $publishingVariants,
    ): array {
        return match (true) {
            $failedDeliveries > 0 => ['label' => 'Failed', 'color' => 'red'],
            $publishedVariants >= $expectedLocales && $expectedLocales > 0 => ['label' => 'Fully published', 'color' => 'green'],
            $publishedVariants > 0 => ['label' => 'Partially published', 'color' => 'sky'],
            $publishingVariants > 0 => ['label' => 'Publishing', 'color' => 'amber'],
            $scheduledVariants > 0 => ['label' => 'Scheduled', 'color' => 'amber'],
            $availableLocales <= 1 && $expectedLocales > 1 => ['label' => 'Draft', 'color' => 'gray'],
            $availableLocales < $expectedLocales => ['label' => 'Partially translated', 'color' => 'amber'],
            default => ['label' => 'Draft', 'color' => 'gray'],
        };
    }

    /**
     * @return array{reasons:array<int,string>,tooltip:?string}
     */
    private static function articleStatusDetails(
        int $missingTranslations,
        int $translationFailureCount,
        int $failedDeliveries,
        bool $hasUnpublishedDraft,
    ): array {
        $reasons = collect([
            $missingTranslations > 0 ? 'Missing locale' : null,
            $translationFailureCount > 0 ? 'Failed translation' : null,
            $failedDeliveries > 0 ? 'Failed publish target' : null,
            $hasUnpublishedDraft ? 'Unpublished draft exists' : null,
        ])->filter()->values()->all();

        return [
            'reasons' => $reasons,
            'tooltip' => $reasons !== [] ? implode(' • ', $reasons) : null,
        ];
    }

    /**
     * @return array{label:string,color:string}
     */
    private static function groupStatusLabel(
        int $expectedLocales,
        int $availableLocales,
        int $publishedVariants,
        int $failedDeliveries,
    ): array {
        return match (true) {
            $failedDeliveries > 0 => ['label' => 'Needs attention', 'color' => 'amber'],
            $publishedVariants >= $expectedLocales && $expectedLocales > 0 && $availableLocales >= $expectedLocales => ['label' => 'Complete', 'color' => 'green'],
            $publishedVariants > 0 => ['label' => 'Partially published', 'color' => 'sky'],
            $availableLocales < $expectedLocales => ['label' => 'In progress', 'color' => 'amber'],
            default => ['label' => 'In progress', 'color' => 'slate'],
        };
    }
}
