<?php

namespace App\Services\Content;

use App\Enums\ContentOriginType;
use App\Enums\WordPressPostType;
use App\Exceptions\InsufficientCreditsException;
use App\Jobs\GenerateInternalLinksJob;
use App\Jobs\GenerateSeriesRunArticleJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentSeries;
use App\Models\ContentSeriesArticle;
use App\Models\ContentSeriesGenerationRun;
use App\Models\ContentSeriesGenerationRunArticle;
use App\Models\CreditAction;
use App\Models\CreditLedgerEntry;
use App\Models\SiteCreditAllocation;
use App\Models\CreditWallet;
use App\Models\Draft;
use App\Services\Credits\SiteCreditAllocationService;
use App\Services\Credits\WorkspaceCreditLedgerService;
use App\Services\DraftGenerationService;
use App\Services\Content\InternalLinkPlacementService;
use App\Support\ContentPersistencePayloadNormalizer;
use App\Support\SeoMetadata;
use App\Support\TitleSanitizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SeriesArticleGenerationService
{
    public function __construct(
        private readonly DraftGenerationService $draftGenerationService,
        private readonly ContentLifecycleService $contentLifecycleService,
        private readonly ContentDeduplicationService $contentDeduplicationService,
        private readonly SeriesBriefPayloadFactory $seriesBriefPayloadFactory,
        private readonly ContentSeriesArticleSyncService $seriesArticleSyncService,
        private readonly InternalLinkPlacementService $internalLinkPlacement,
    ) {
    }

    /**
     * @param array<int,mixed> $requestedArticleNumbers
     * @return array{
     *   queued:int,
     *   already_generated:int,
     *   credits_used:int,
     *   total_strategy_articles:int,
     *   run_id:string|null,
     *   already_running:bool
     * }
     */
    public function dispatchGeneration(ContentSeries $series, int $actorUserId, array $requestedArticleNumbers = []): array
    {
        $dispatchArticleIds = [];

        $result = DB::transaction(function () use ($series, $actorUserId, $requestedArticleNumbers, &$dispatchArticleIds): array {
            /** @var ContentSeries $lockedSeries */
            $lockedSeries = ContentSeries::query()
                ->lockForUpdate()
                ->findOrFail($series->id);

            $lockedSeries->loadMissing('site.workspace');
            $this->seriesArticleSyncService->sync($lockedSeries);
            $this->validateSeriesGenerationAllowed($lockedSeries);

            $site = $lockedSeries->site;
            if (! $site) {
                throw new RuntimeException('Series has no connected site.');
            }

            $strategyArticles = $this->strategyArticles($lockedSeries);
            $totalStrategyArticles = $strategyArticles->count();

            if ($totalStrategyArticles < 1) {
                throw new RuntimeException('Generate strategy before generating articles.');
            }

            $requestedNumbers = $this->normalizeRequestedArticleNumbers($requestedArticleNumbers, $totalStrategyArticles);

            $activeRun = ContentSeriesGenerationRun::query()
                ->where('series_id', (string) $lockedSeries->id)
                ->whereIn('status', [
                    ContentSeriesGenerationRun::STATUS_PENDING,
                    ContentSeriesGenerationRun::STATUS_RUNNING,
                ])
                ->latest('created_at')
                ->first();

            if ($activeRun) {
                $openArticleIds = ContentSeriesGenerationRunArticle::query()
                    ->where('run_id', (string) $activeRun->id)
                    ->whereIn('status', [
                        ContentSeriesGenerationRunArticle::STATUS_PENDING,
                        ContentSeriesGenerationRunArticle::STATUS_GENERATING,
                        ContentSeriesGenerationRunArticle::STATUS_BRIEF,
                    ])
                    ->pluck('id')
                    ->map(fn ($value): string => (string) $value)
                    ->all();

                if ($openArticleIds !== []) {
                    $dispatchArticleIds = array_values(array_unique(array_merge($dispatchArticleIds, $openArticleIds)));

                    return [
                        'queued' => count($openArticleIds),
                        'already_generated' => 0,
                        'credits_used' => 0,
                        'total_strategy_articles' => $totalStrategyArticles,
                        'run_id' => (string) $activeRun->id,
                        'already_running' => true,
                    ];
                }
            }

            $strategyByNumber = $strategyArticles
                ->values()
                ->mapWithKeys(fn (array $article, int $index) => [$index + 1 => $article])
                ->all();

            $existingByNumber = $this->existingArticlesByNumber($lockedSeries);
            $plannedUrlMap = $this->resolvePlannedUrlMap($lockedSeries, $site, $strategyByNumber, $existingByNumber);

            $targetArticleNumbers = [];
            $alreadyGenerated = 0;

            foreach ($strategyByNumber as $articleNumber => $article) {
                if ($requestedNumbers !== [] && ! in_array($articleNumber, $requestedNumbers, true)) {
                    continue;
                }

                $existing = $existingByNumber[$articleNumber] ?? null;
                if (is_array($existing) && $this->isArticleAlreadyGenerated($existing['content'] ?? null, $existing['draft'] ?? null)) {
                    $alreadyGenerated++;
                    continue;
                }

                $targetArticleNumbers[] = $articleNumber;
            }

            if ($targetArticleNumbers === []) {
                return [
                    'queued' => 0,
                    'already_generated' => $alreadyGenerated,
                    'credits_used' => 0,
                    'total_strategy_articles' => $totalStrategyArticles,
                    'run_id' => null,
                    'already_running' => false,
                ];
            }

            $pricing = $this->resolveArticlePricing();
            $creditsRequired = (int) $pricing['cost'] * count($targetArticleNumbers);

            $run = ContentSeriesGenerationRun::query()->create([
                'id' => (string) Str::uuid(),
                'series_id' => (string) $lockedSeries->id,
                'organization_id' => (int) $lockedSeries->organization_id,
                'requested_by' => $actorUserId,
                'total_articles' => count($targetArticleNumbers),
                'completed_articles' => 0,
                'failed_articles' => 0,
                'status' => ContentSeriesGenerationRun::STATUS_PENDING,
                'meta' => [
                    'pricing' => [
                        'action_id' => (string) ($pricing['action_id'] ?? ''),
                        'cost' => (int) $pricing['cost'],
                    ],
                    'requested_article_numbers' => array_values($targetArticleNumbers),
                    'planned_url_map' => $plannedUrlMap,
                ],
            ]);

            $creditEntry = $this->deductCreditsBeforeGeneration(
                series: $lockedSeries,
                site: $site,
                requiredCredits: $creditsRequired,
                actorUserId: $actorUserId,
                idempotencyKey: 'content_series:' . $lockedSeries->id . ':generation_run:' . $run->id,
                articlesCount: count($targetArticleNumbers),
                runId: (string) $run->id,
            );

            $runMeta = is_array($run->meta) ? $run->meta : [];
            $runMeta['credits'] = [
                'required' => $creditsRequired,
                'entry_id' => (string) $creditEntry->id,
                'charged_at' => now()->toIso8601String(),
            ];

            $run->update([
                'credit_ledger_entry_id' => (string) $creditEntry->id,
                'meta' => $runMeta,
            ]);

            foreach ($targetArticleNumbers as $articleNumber) {
                $article = $strategyByNumber[$articleNumber] ?? [];
                $title = TitleSanitizer::normalize(
                    data_get($article, 'title', '') ?: ('Series article ' . $articleNumber),
                    fallback: 'Series article ' . $articleNumber
                );
                $existing = $existingByNumber[$articleNumber] ?? null;

                $runArticle = ContentSeriesGenerationRunArticle::query()->create([
                    'id' => (string) Str::uuid(),
                    'run_id' => (string) $run->id,
                    'series_id' => (string) $lockedSeries->id,
                    'article_number' => $articleNumber,
                    'title' => $title,
                    'status' => ContentSeriesGenerationRunArticle::STATUS_PENDING,
                    'content_id' => $existing['content']->id ?? null,
                    'brief_id' => $existing['brief']->id ?? null,
                    'draft_id' => $existing['draft']->id ?? null,
                    'slug' => $this->extractSlugFromPlannedUrl((string) ($plannedUrlMap[$articleNumber] ?? '')),
                    'planned_url' => (string) ($plannedUrlMap[$articleNumber] ?? ''),
                    'internal_links_to' => $this->normalizeInternalLinksTo(
                        raw: (array) data_get($article, 'internal_links_to', []),
                        articleNumber: $articleNumber,
                        totalArticles: $totalStrategyArticles,
                    ),
                    'error_message' => null,
                    'attempts' => 0,
                ]);

                $dispatchArticleIds[] = (string) $runArticle->id;
            }

            $existingPublishPlan = is_array($lockedSeries->publish_plan_json) ? $lockedSeries->publish_plan_json : [];
            $existingPublishPlan['generation'] = [
                'run_id' => (string) $run->id,
                'status' => ContentSeriesGenerationRun::STATUS_PENDING,
                'total_articles' => count($targetArticleNumbers),
                'completed_articles' => 0,
                'failed_articles' => 0,
                'started_at' => null,
                'finished_at' => null,
                'last_error' => null,
            ];
            $existingPublishPlan['credits'] = $runMeta['credits'];

            $lockedSeries->update([
                'status' => ContentSeries::STATUS_GENERATING,
                'publish_plan_json' => $existingPublishPlan,
            ]);

            return [
                'queued' => count($dispatchArticleIds),
                'already_generated' => $alreadyGenerated,
                'credits_used' => $creditsRequired,
                'total_strategy_articles' => $totalStrategyArticles,
                'run_id' => (string) $run->id,
                'already_running' => false,
            ];
        });

        foreach ($dispatchArticleIds as $runArticleId) {
            GenerateSeriesRunArticleJob::dispatch($runArticleId)->onQueue('generation');
        }

        return $result;
    }

    public function generateRunArticle(ContentSeriesGenerationRunArticle $runArticle, int $attempt, int $maxAttempts): void
    {
        $runArticle->loadMissing('run.series.site.workspace');

        $run = $runArticle->run;
        $series = $run?->series;
        $site = $series?->site;

        if (! $run || ! $series || ! $site) {
            throw new RuntimeException('Generation run context is missing.');
        }

        if ((string) $runArticle->status === ContentSeriesGenerationRunArticle::STATUS_DRAFT) {
            $this->syncRunProgress($run);
            return;
        }

        $run->update([
            'status' => ContentSeriesGenerationRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?: now(),
            'finished_at' => null,
        ]);

        $runArticle->update([
            'status' => ContentSeriesGenerationRunArticle::STATUS_GENERATING,
            'error_message' => null,
            'attempts' => max((int) $runArticle->attempts, $attempt),
            'started_at' => $runArticle->started_at ?: now(),
            'finished_at' => null,
        ]);

        $strategyByNumber = $this->strategyArticles($series)
            ->values()
            ->mapWithKeys(fn (array $article, int $index) => [$index + 1 => $article]);

        $article = (array) ($strategyByNumber->get((int) $runArticle->article_number) ?? []);
        if ($article === []) {
            throw new RuntimeException(sprintf('Strategy article %d is missing.', (int) $runArticle->article_number));
        }

        $articleNumber = (int) $runArticle->article_number;
        $titleResult = TitleSanitizer::normalizeWithMetadata(
            data_get($article, 'title', '') ?: ('Series article ' . $articleNumber),
            fallback: 'Series article ' . $articleNumber
        );
        $title = $titleResult['title'];
        if ($titleResult['was_shortened']) {
            Log::notice('content_series.title_shortened', [
                'series_id' => (string) $series->id,
                'run_id' => (string) $run->id,
                'run_article_id' => (string) $runArticle->id,
                'article_number' => $articleNumber,
                'original_length' => $titleResult['original_length'],
                'persisted_length' => $titleResult['persisted_length'],
                'max_length' => $titleResult['max_length'],
            ]);
        }
        $primaryKeyword = trim((string) data_get($article, 'primary_keyword', '')) ?: trim((string) $series->primary_keyword);
        $secondaryKeywords = collect((array) data_get($article, 'secondary_keywords', []))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();

        $internalLinksTo = $this->normalizeInternalLinksTo(
            raw: (array) data_get($article, 'internal_links_to', []),
            articleNumber: $articleNumber,
            totalArticles: max(1, $strategyByNumber->count()),
        );

        $runMeta = is_array($run->meta) ? $run->meta : [];
        $plannedUrlMap = (array) ($runMeta['planned_url_map'] ?? []);
        if ($plannedUrlMap === []) {
            $existingByNumber = $this->existingArticlesByNumber($series);
            $plannedUrlMap = $this->resolvePlannedUrlMap($series, $site, $strategyByNumber->all(), $existingByNumber);
            $runMeta['planned_url_map'] = $plannedUrlMap;
            $run->update(['meta' => $runMeta]);
        }

        $plannedUrl = trim((string) ($runArticle->planned_url ?: ($plannedUrlMap[$articleNumber] ?? '')));
        if ($plannedUrl === '') {
            $fallbackUsedSlugs = [];
            $fallbackSlug = $this->makeUniqueSlug($title, $articleNumber, $fallbackUsedSlugs);
            $plannedUrl = $this->plannedUrl($site, $fallbackSlug, $series->wordPressPostType());
        }

        $slug = trim((string) ($runArticle->slug ?: $this->extractSlugFromPlannedUrl($plannedUrl)));
        if ($slug === '') {
            $slugUsedSlugs = [];
            $slug = $this->makeUniqueSlug($title, $articleNumber, $slugUsedSlugs);
        }

        $pricing = (array) ($runMeta['pricing'] ?? []);
        $creditActionId = (string) ($pricing['action_id'] ?? '');
        $creditCost = max(1, (int) ($pricing['cost'] ?? $this->resolveArticlePricing()['cost']));

        $content = null;
        $brief = null;
        $draft = null;
        $briefPayload = [];
        $generationStartedAt = microtime(true);

        try {
            $externalKey = $this->articleExternalKey($series, $articleNumber);

            if ($runArticle->content_id) {
                $content = Content::query()->find((string) $runArticle->content_id);
            }

            if (! $content) {
                $content = Content::query()
                    ->where('series_id', (string) $series->id)
                    ->where('external_key', $externalKey)
                    ->first();
            }

            if (! $content) {
                $seriesArticle = ContentSeriesArticle::query()
                    ->where('series_id', (string) $series->id)
                    ->where('article_number', $articleNumber)
                    ->first();

                if ($seriesArticle?->content_id) {
                    $content = Content::query()->find((string) $seriesArticle->content_id);
                }
            }

            if ($content && trim((string) ($content->external_key ?? '')) === '') {
                $content->forceFill([
                    'external_key' => $externalKey,
                    'series_id' => (string) $series->id,
                    'is_source_locale' => true,
                ])->save();
            }

            if (! $content) {
                $requestedLocale = (string) data_get($briefPayload, 'brief.language', $site->workspace?->defaultContentLanguageCode() ?? 'en');
                $contentPayload = [
                    'id' => (string) Str::uuid(),
                    'workspace_id' => (string) $site->workspace_id,
                    'client_site_id' => (string) $site->id,
                    'series_id' => (string) $series->id,
                    'title' => $title,
                    'language' => $requestedLocale,
                    'translation_source_locale' => null,
                    'is_source_locale' => true,
                    'primary_keyword' => $primaryKeyword,
                    'intent_keys' => (array) data_get($briefPayload, 'brief.intent.keys', []),
                    'type' => 'article',
                    'status' => 'brief',
                    'source' => 'manual',
                    'origin_type' => ContentOriginType::SERIES_GENERATED->value,
                    'external_key' => $externalKey,
                    'delivery_status' => 'pending',
                    'publish_status' => 'draft',
                    'generation_mode' => 'balanced',
                    'created_by' => (int) ($run->requested_by ?? 0) ?: null,
                    'updated_by' => (int) ($run->requested_by ?? 0) ?: null,
                ];

                $content = $this->contentDeduplicationService->createOrReuse($contentPayload, [
                    'workspace_id' => (string) $site->workspace_id,
                    'client_site_id' => (string) $site->id,
                    'series_id' => (string) $series->id,
                    'article_number' => (string) $articleNumber,
                    'language' => $requestedLocale,
                    'type' => 'article',
                    'external_key' => $externalKey,
                ]);
            }

            if ($runArticle->brief_id) {
                $brief = Brief::query()->find((string) $runArticle->brief_id);
            }

            if (! $brief) {
                $brief = Brief::query()
                    ->where('content_id', (string) $content->id)
                    ->latest('created_at')
                    ->first();
            }

            if (! $brief) {
                $briefPayload = $this->seriesBriefPayloadFactory->build(
                    series: $series,
                    site: $site,
                    article: $article,
                    articleNumber: $articleNumber,
                    title: $title,
                    primaryKeyword: $primaryKeyword,
                    secondaryKeywords: $secondaryKeywords,
                    slug: $slug,
                    plannedUrl: $plannedUrl,
                    internalLinksTo: $internalLinksTo,
                );

                $this->validateGeneratedBriefPayload($briefPayload, (int) $series->organization_id);

                $brief = Brief::query()->create(
                    $this->briefAttributesFromPayload(
                        payload: $briefPayload,
                        site: $site,
                        series: $series,
                        content: $content,
                        articleNumber: $articleNumber,
                        createdByUserId: (int) ($run->requested_by ?? 0) ?: null,
                        slug: $slug,
                        plannedUrl: $plannedUrl,
                        internalLinksTo: $internalLinksTo,
                    )
                );
            } else {
                $briefPayload = $this->seriesBriefPayloadFactory->build(
                    series: $series,
                    site: $site,
                    article: $article,
                    articleNumber: $articleNumber,
                    title: $title,
                    primaryKeyword: $primaryKeyword,
                    secondaryKeywords: $secondaryKeywords,
                    slug: $slug,
                    plannedUrl: $plannedUrl,
                    internalLinksTo: $internalLinksTo,
                );

                $this->validateGeneratedBriefPayload($briefPayload, (int) $series->organization_id);

                $brief->update($this->briefAttributesFromPayload(
                    payload: $briefPayload,
                    site: $site,
                    series: $series,
                    content: $content,
                    articleNumber: $articleNumber,
                    createdByUserId: (int) ($run->requested_by ?? 0) ?: null,
                    slug: $slug,
                    plannedUrl: $plannedUrl,
                    internalLinksTo: $internalLinksTo,
                ));
            }

            if ($runArticle->draft_id) {
                $draft = Draft::query()->find((string) $runArticle->draft_id);
            }

            if (! $draft) {
                $draft = Draft::query()
                    ->where('content_id', (string) $content->id)
                    ->latest('created_at')
                    ->first();
            }

            if ($this->isArticleAlreadyGenerated($content, $draft)) {
                $runArticle->update([
                    'status' => ContentSeriesGenerationRunArticle::STATUS_DRAFT,
                    'content_id' => (string) $content->id,
                    'brief_id' => (string) $brief->id,
                    'draft_id' => $draft?->id,
                    'planned_url' => $plannedUrl,
                    'slug' => $slug,
                    'internal_links_to' => $internalLinksTo,
                    'error_message' => null,
                    'finished_at' => now(),
                ]);

                $this->syncRunProgress($run->fresh());

                return;
            }

            $runArticle->update([
                'status' => ContentSeriesGenerationRunArticle::STATUS_BRIEF,
                'content_id' => (string) $content->id,
                'brief_id' => (string) $brief->id,
                'planned_url' => $plannedUrl,
                'slug' => $slug,
                'internal_links_to' => $internalLinksTo,
                'error_message' => null,
            ]);

            if (! $draft) {
                $draft = Draft::query()->create([
                    'id' => (string) Str::uuid(),
                    'brief_id' => (string) $brief->id,
                    'content_id' => (string) $content->id,
                    'client_site_id' => (string) $site->id,
                    'status' => 'processing',
                    'delivery_status' => 'pending',
                    'title' => $title,
                    'output_type' => (string) data_get($briefPayload, 'brief.output_type', 'kb_article'),
                    'language' => (string) data_get($briefPayload, 'brief.language', $site->workspace?->defaultContentLanguageCode() ?? 'en'),
                    'content_html' => '',
                    'meta' => [
                        'language' => (string) data_get($briefPayload, 'brief.language', $site->workspace?->defaultContentLanguageCode() ?? 'en'),
                        'tone' => (string) data_get($briefPayload, 'brief.tone_of_voice', (string) ($series->tone ?? '')),
                        'audience' => (string) data_get($briefPayload, 'brief.target_audience', (string) ($series->audience ?? '')),
                        'preferred_length' => (string) data_get($briefPayload, 'brief.preferred_length', 'medium'),
                        'intent_keys' => (array) data_get($briefPayload, 'brief.intent.keys', []),
                        'audience_tags' => (array) data_get($briefPayload, 'brief.audience_keys', []),
                        'primary_keyword' => $primaryKeyword,
                        'secondary_keywords' => $secondaryKeywords,
                        'series_context' => [
                            'series_id' => (string) $series->id,
                            'article_number' => $articleNumber,
                            'slug' => $slug,
                            'planned_url' => $plannedUrl,
                        ],
                    ],
                    'links' => $this->seriesLinkHints($internalLinksTo, $plannedUrlMap, $strategyByNumber),
                    'credit_action_id' => $creditActionId !== '' ? $creditActionId : null,
                    'credit_cost' => $creditCost,
                    'credit_wallet_id' => (string) ($run->creditLedgerEntry?->credit_wallet_id ?? null) ?: null,
                    'credit_status' => 'committed',
                    'credit_ledger_entry_id' => (string) ($run->credit_ledger_entry_id ?: null) ?: null,
                ]);
            } else {
                $existingMeta = is_array($draft->meta) ? $draft->meta : [];
                $seriesContext = array_replace((array) ($existingMeta['series_context'] ?? []), [
                    'series_id' => (string) $series->id,
                    'article_number' => $articleNumber,
                    'slug' => $slug,
                    'planned_url' => $plannedUrl,
                ]);

                $existingMeta = array_replace_recursive($existingMeta, [
                    'primary_keyword' => $primaryKeyword,
                    'secondary_keywords' => $secondaryKeywords,
                    'series_context' => $seriesContext,
                ]);

                $draft->update([
                    'brief_id' => (string) $brief->id,
                    'status' => 'processing',
                    'delivery_status' => 'pending',
                    'delivery_last_error' => null,
                    'title' => $title,
                    'last_error' => null,
                    'meta' => $existingMeta,
                    'links' => $this->seriesLinkHints($internalLinksTo, $plannedUrlMap, $strategyByNumber),
                    'credit_action_id' => $draft->credit_action_id ?: ($creditActionId !== '' ? $creditActionId : null),
                    'credit_cost' => (int) ($draft->credit_cost ?: $creditCost),
                    'credit_wallet_id' => $draft->credit_wallet_id ?: ((string) ($run->creditLedgerEntry?->credit_wallet_id ?? '') ?: null),
                    'credit_status' => 'committed',
                    'credit_ledger_entry_id' => $draft->credit_ledger_entry_id ?: ($run->credit_ledger_entry_id ?: null),
                ]);
            }

            $runArticle->update([
                'status' => ContentSeriesGenerationRunArticle::STATUS_GENERATING,
                'draft_id' => (string) $draft->id,
            ]);

            Log::info('Series article generation started.', [
                'series_id' => (string) $series->id,
                'run_id' => (string) $run->id,
                'run_article_id' => (string) $runArticle->id,
                'article_number' => $articleNumber,
                'provider' => (string) config('llm.default_provider', 'openai'),
                'model' => (string) config('llm.providers.openai.default_model', ''),
            ]);

            $result = $this->draftGenerationService->generateWithRepair($draft, 2);

            $existingMeta = is_array($draft->meta) ? $draft->meta : [];
            $resultMeta = (array) ($result['meta'] ?? []);
            $mergedMeta = array_replace_recursive($existingMeta, $resultMeta);
            $seoFields = SeoMetadata::merge(
                [
                    'seo_title' => $result['title'] ?? $title,
                    'seo_meta_description' => data_get($result, 'meta.description'),
                    'seo_canonical' => $plannedUrl,
                    'robots_index' => data_get($result, 'meta.robots_index'),
                    'robots_follow' => data_get($result, 'meta.robots_follow'),
                    'schema_type' => data_get($result, 'meta.schema_type'),
                ],
                $mergedMeta,
            );
            if (trim((string) ($seoFields['seo_h1'] ?? '')) === '') {
                $seoFields['seo_h1'] = $seoFields['seo_title'] ?: ($result['title'] ?? $title);
            }

            $mergedMeta = array_replace_recursive($mergedMeta, array_filter([
                'meta_description' => $seoFields['seo_meta_description'],
                'canonical_url' => $seoFields['seo_canonical'],
                'robots_index' => $seoFields['robots_index'],
                'robots_follow' => $seoFields['robots_follow'],
                'schema_type' => $seoFields['schema_type'],
            ], static fn ($value) => is_bool($value) || trim((string) $value) !== ''));

            $provider = (string) data_get($result, 'provider', config('llm.default_provider', 'openai'));
            $model = (string) data_get($result, 'model', '');

            $mergedMeta['generation'] = array_filter([
                'provider' => $provider,
                'model' => $model,
                'tokens' => (int) data_get($result, 'usage.total_tokens', 0),
                'input_tokens' => (int) data_get($result, 'usage.input_tokens', 0),
                'output_tokens' => (int) data_get($result, 'usage.output_tokens', 0),
                'request_id' => (string) data_get($result, 'request_id', ''),
                'credits' => $creditCost,
                'generated_at' => now()->toIso8601String(),
                'trigger' => 'content_series',
                'series_id' => (string) $series->id,
                'run_id' => (string) $run->id,
                'article_number' => $articleNumber,
            ], fn ($value) => $value !== null);

            $placementResult = $this->internalLinkPlacement->placeIntoHtml(
                (string) ($result['content_html'] ?? ''),
                $this->seriesLinkHints($internalLinksTo, $plannedUrlMap, $strategyByNumber)
            );
            $resolvedHtml = (string) ($placementResult['updated_html'] ?? '');

            $generatedTitleResult = TitleSanitizer::normalizeWithMetadata(
                $result['title'] ?? $title,
                fallback: $title
            );
            $generatedTitle = $generatedTitleResult['title'];
            if ($generatedTitleResult['was_shortened']) {
                Log::notice('content_series.generated_title_shortened', [
                    'series_id' => (string) $series->id,
                    'run_id' => (string) $run->id,
                    'run_article_id' => (string) $runArticle->id,
                    'article_number' => $articleNumber,
                    'content_id' => (string) $content->id,
                    'draft_id' => (string) $draft->id,
                    'original_length' => $generatedTitleResult['original_length'],
                    'persisted_length' => $generatedTitleResult['persisted_length'],
                    'max_length' => $generatedTitleResult['max_length'],
                ]);
            }

            $draft->update([
                'status' => 'ready_to_deliver',
                'title' => $generatedTitle,
                'seo_title' => $seoFields['seo_title'] ?: $generatedTitle,
                'seo_meta_description' => $seoFields['seo_meta_description'],
                'seo_h1' => $seoFields['seo_h1'],
                'seo_canonical' => $seoFields['seo_canonical'],
                'seo_og_title' => $seoFields['seo_og_title'],
                'seo_og_description' => $seoFields['seo_og_description'],
                'seo_og_image' => $seoFields['seo_og_image'],
                'seo_twitter_title' => $seoFields['seo_twitter_title'],
                'seo_twitter_description' => $seoFields['seo_twitter_description'],
                'robots_index' => $seoFields['robots_index'],
                'robots_follow' => $seoFields['robots_follow'],
                'schema_type' => $seoFields['schema_type'],
                'content_html' => $resolvedHtml,
                'meta' => $mergedMeta,
                'links' => $result['links'] ?? $draft->links,
                'last_error' => null,
                'delivery_status' => 'pending',
                'delivery_last_error' => null,
            ]);

            $this->contentLifecycleService->ensureRevisionFromDraft(
                $draft->fresh(),
                (int) ($run->requested_by ?? 0) ?: null,
            );
            GenerateInternalLinksJob::dispatch((string) $content->id)
                ->onQueue('generation')
                ->afterCommit();

            $content->update([
                'status' => 'draft',
                'title' => $generatedTitle,
                'primary_keyword' => $primaryKeyword,
                'intent_keys' => (array) data_get($briefPayload, 'brief.intent.keys', []),
                'published_url' => null,
                'publish_status' => 'draft',
                'publish_error' => null,
                'updated_by' => (int) ($run->requested_by ?? 0) ?: null,
            ]);
            $this->seriesArticleSyncService->syncContent($content->fresh());

            $durationMs = (int) round((microtime(true) - $generationStartedAt) * 1000);

            $runArticleMeta = is_array($runArticle->meta) ? $runArticle->meta : [];
            $runArticleMeta['generation'] = [
                'provider' => $provider,
                'model' => $model,
                'duration_ms' => $durationMs,
                'attempt' => $attempt,
                'completed_at' => now()->toIso8601String(),
            ];

            $runArticle->update([
                'status' => ContentSeriesGenerationRunArticle::STATUS_DRAFT,
                'content_id' => (string) $content->id,
                'brief_id' => (string) $brief->id,
                'draft_id' => (string) $draft->id,
                'planned_url' => $plannedUrl,
                'slug' => $slug,
                'internal_links_to' => $internalLinksTo,
                'error_message' => null,
                'meta' => $runArticleMeta,
                'finished_at' => now(),
            ]);

            Log::info('Series article generation completed.', [
                'series_id' => (string) $series->id,
                'run_id' => (string) $run->id,
                'run_article_id' => (string) $runArticle->id,
                'article_number' => $articleNumber,
                'provider' => $provider,
                'model' => $model,
                'duration_ms' => $durationMs,
            ]);

            $this->syncRunProgress($run->fresh());
        } catch (Throwable $exception) {
            $errorMessage = $exception instanceof ValidationException
                ? $this->formatValidationMessageSummary($exception->errors())
                : $exception->getMessage();

            if ($briefPayload !== []) {
                $logContext = [
                    'series_id' => (string) $series->id,
                    'run_id' => (string) $run->id,
                    'run_article_id' => (string) $runArticle->id,
                    'article_number' => $articleNumber,
                    'validation_errors' => $exception instanceof ValidationException ? $exception->errors() : [],
                    'error' => $errorMessage,
                ];

                if (app()->environment(['local', 'testing'])) {
                    $logContext['payload'] = $briefPayload;
                }

                Log::error('Series brief generation failed.', $logContext);
            }

            if ($draft) {
                $draft->update([
                    'status' => 'failed',
                    'last_error' => mb_substr($errorMessage, 0, 5000),
                ]);
            }

            $this->markRunArticleFailed(
                $runArticle,
                mb_substr($errorMessage, 0, 5000),
                $attempt >= $maxAttempts
            );

            throw $exception;
        }
    }

    public function markRunArticleFailed(ContentSeriesGenerationRunArticle $runArticle, string $message, bool $permanent): void
    {
        $runArticle->loadMissing('run');

        $runArticle->update([
            'status' => $permanent
                ? ContentSeriesGenerationRunArticle::STATUS_FAILED
                : ContentSeriesGenerationRunArticle::STATUS_PENDING,
            'error_message' => mb_substr($message, 0, 5000),
            'finished_at' => $permanent ? now() : null,
        ]);

        $run = $runArticle->run;
        if (! $run) {
            return;
        }

        $run->update([
            'last_error' => mb_substr($message, 0, 5000),
            'status' => ContentSeriesGenerationRun::STATUS_RUNNING,
            'finished_at' => null,
        ]);

        $this->syncRunProgress($run->fresh());
    }

    public function syncRunProgress(ContentSeriesGenerationRun $run): ContentSeriesGenerationRun
    {
        return DB::transaction(function () use ($run): ContentSeriesGenerationRun {
            /** @var ContentSeriesGenerationRun $lockedRun */
            $lockedRun = ContentSeriesGenerationRun::query()
                ->with('series')
                ->lockForUpdate()
                ->findOrFail($run->id);

            $counts = ContentSeriesGenerationRunArticle::query()
                ->where('run_id', (string) $lockedRun->id)
                ->selectRaw('COUNT(*) as total_count')
                ->selectRaw("SUM(CASE WHEN status = '" . ContentSeriesGenerationRunArticle::STATUS_DRAFT . "' THEN 1 ELSE 0 END) as completed_count")
                ->selectRaw("SUM(CASE WHEN status = '" . ContentSeriesGenerationRunArticle::STATUS_FAILED . "' THEN 1 ELSE 0 END) as failed_count")
                ->selectRaw("SUM(CASE WHEN status IN ('" . ContentSeriesGenerationRunArticle::STATUS_PENDING . "','" . ContentSeriesGenerationRunArticle::STATUS_GENERATING . "','" . ContentSeriesGenerationRunArticle::STATUS_BRIEF . "') THEN 1 ELSE 0 END) as open_count")
                ->first();

            $total = (int) ($counts->total_count ?? 0);
            $completed = (int) ($counts->completed_count ?? 0);
            $failed = (int) ($counts->failed_count ?? 0);
            $open = (int) ($counts->open_count ?? 0);

            $status = (string) $lockedRun->status;

            if ($total === 0) {
                $status = ContentSeriesGenerationRun::STATUS_COMPLETED;
            } elseif ($open > 0) {
                $status = $lockedRun->started_at
                    ? ContentSeriesGenerationRun::STATUS_RUNNING
                    : ContentSeriesGenerationRun::STATUS_PENDING;
            } elseif ($completed === $total && $failed === 0) {
                $status = ContentSeriesGenerationRun::STATUS_COMPLETED;
            } elseif ($failed > 0) {
                $status = ContentSeriesGenerationRun::STATUS_FAILED;
            }

            $startedAt = $lockedRun->started_at;
            if (! $startedAt && ($completed > 0 || $failed > 0 || $open > 0)) {
                $startedAt = now();
            }

            $finishedAt = null;
            if (in_array($status, [ContentSeriesGenerationRun::STATUS_COMPLETED, ContentSeriesGenerationRun::STATUS_FAILED], true)) {
                $finishedAt = now();
            }

            $lockedRun->update([
                'total_articles' => $total,
                'completed_articles' => $completed,
                'failed_articles' => $failed,
                'status' => $status,
                'started_at' => $startedAt,
                'finished_at' => $finishedAt,
            ]);

            $series = $lockedRun->series;
            if ($series && ! $series->isLocked() && ! $series->isArchived()) {
                $nextSeriesStatus = match ($status) {
                    ContentSeriesGenerationRun::STATUS_COMPLETED => ContentSeries::STATUS_READY,
                    ContentSeriesGenerationRun::STATUS_FAILED => ContentSeries::STATUS_STRATEGY_GENERATED,
                    default => ContentSeries::STATUS_GENERATING,
                };

                $articles = ContentSeriesGenerationRunArticle::query()
                    ->where('run_id', (string) $lockedRun->id)
                    ->orderBy('article_number')
                    ->get();

                $existingPublishPlan = is_array($series->publish_plan_json) ? $series->publish_plan_json : [];
                $runMeta = is_array($lockedRun->meta) ? $lockedRun->meta : [];

                $existingPublishPlan['generation'] = [
                    'run_id' => (string) $lockedRun->id,
                    'status' => $status,
                    'total_articles' => $total,
                    'completed_articles' => $completed,
                    'failed_articles' => $failed,
                    'started_at' => optional($lockedRun->started_at)->toIso8601String(),
                    'finished_at' => optional($lockedRun->finished_at)->toIso8601String(),
                    'last_error' => $lockedRun->last_error,
                ];

                if (isset($runMeta['credits']) && is_array($runMeta['credits'])) {
                    $existingPublishPlan['credits'] = $runMeta['credits'];
                }

                $existingPublishPlan['articles'] = $articles
                    ->map(fn (ContentSeriesGenerationRunArticle $article): array => [
                        'article_number' => (int) $article->article_number,
                        'content_id' => (string) ($article->content_id ?? ''),
                        'draft_id' => (string) ($article->draft_id ?? ''),
                        'slug' => (string) ($article->slug ?? ''),
                        'planned_url' => (string) ($article->planned_url ?? ''),
                        'internal_links_to' => array_values((array) ($article->internal_links_to ?? [])),
                        'status' => (string) $article->status,
                        'error' => (string) ($article->error_message ?? ''),
                    ])
                    ->values()
                    ->all();

                if ($status === ContentSeriesGenerationRun::STATUS_COMPLETED) {
                    $existingPublishPlan['generated_at'] = now()->toIso8601String();
                }

                $series->update([
                    'status' => $nextSeriesStatus,
                    'publish_plan_json' => $existingPublishPlan,
                ]);
            }

            return $lockedRun->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validateGeneratedBriefPayload(array $payload, int $organizationId): void
    {
        $validator = Validator::make($payload, [
            'client.type' => ['required', 'string'],
            'client.site_url' => ['required', 'string'],
            'brief.title' => ['required', 'string'],
            'brief.language' => ['required', 'string', 'max:10'],
            'brief.intent.keys' => ['required', 'array', 'min:1'],
            'brief.intent.keys.*' => ['string', 'max:50'],
            'brief.audience_keys' => ['required', 'array', 'min:1'],
            'brief.audience_keys.*' => ['string', 'max:50'],
            'brief.output_type' => ['required', 'string'],
            'brief.content_type' => ['nullable', 'in:blog,landing,linkedin,email,other'],
            'brief.preferred_length' => ['nullable', 'in:short,medium,long,pillar'],
        ]);

        $errors = [];

        if ($validator->fails()) {
            $errors = $validator->errors()->toArray();
        }

        $intentKeys = collect((array) data_get($payload, 'brief.intent.keys', []))
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();

        $audienceKeys = collect((array) data_get($payload, 'brief.audience_keys', []))
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();

        $allowedAudienceKeys = array_keys(app(\App\Support\EditorialTaxonomyService::class)
            ->activeItemMapByTenantAndType($organizationId, \App\Models\TaxonomyItem::TYPE_AUDIENCE));

        $invalidAudience = collect($audienceKeys)
            ->first(fn (string $key): bool => ! in_array($key, $allowedAudienceKeys, true));

        if ($invalidAudience) {
            $errors['brief.audience_keys'][] = "Unknown audience key: {$invalidAudience}";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, int>  $internalLinksTo
     * @return array<string, mixed>
     */
    private function briefAttributesFromPayload(
        array $payload,
        ClientSite $site,
        ContentSeries $series,
        Content $content,
        int $articleNumber,
        ?int $createdByUserId,
        string $slug,
        string $plannedUrl,
        array $internalLinksTo,
    ): array {
        $intentKeys = (array) data_get($payload, 'brief.intent.keys', []);
        $audienceKeys = (array) data_get($payload, 'brief.audience_keys', []);
        $existingRefs = is_array($content->brief?->client_refs ?? null) ? $content->brief->client_refs : [];

        return ContentPersistencePayloadNormalizer::normalizeBrief([
            'client_site_id' => (string) $site->id,
            'created_by_user_id' => $createdByUserId,
            'content_id' => (string) $content->id,
            'status' => 'queued',
            'progress' => 0,
            'source' => 'client_ui',
            'title' => (string) data_get($payload, 'brief.title', ''),
            'language' => (string) data_get($payload, 'brief.language', $site->workspace?->defaultContentLanguageCode() ?? 'en'),
            'content_type' => (string) data_get($payload, 'brief.content_type', 'blog'),
            'output_type' => (string) data_get($payload, 'brief.output_type', 'kb_article'),
            'intent' => $intentKeys[0] ?? null,
            'primary_keyword' => (string) data_get($payload, 'brief.primary_keyword', '') ?: null,
            'secondary_keywords' => array_values((array) data_get($payload, 'brief.secondary_keywords', [])),
            'audience' => implode(',', $audienceKeys),
            'target_audience' => (string) data_get($payload, 'brief.target_audience', '') ?: ((string) ($series->audience ?? '') ?: null),
            'funnel_stage' => (string) data_get($payload, 'brief.funnel_stage', '') ?: null,
            'tone_of_voice' => (string) data_get($payload, 'brief.tone_of_voice', '') ?: null,
            'notes' => (string) data_get($payload, 'brief.notes', '') ?: null,
            'client_refs' => array_replace_recursive($existingRefs, [
                'client_type' => 'content_series',
                'site_url' => (string) data_get($payload, 'client.site_url', ''),
                'series_id' => (string) $series->id,
                'article_number' => $articleNumber,
                'slug' => $slug,
                'planned_url' => $plannedUrl,
                'internal_links_to' => $internalLinksTo,
                'taxonomy' => [
                    'intent_keys' => $intentKeys,
                    'audience_keys' => $audienceKeys,
                ],
                'preferred_length' => (string) data_get($payload, 'brief.preferred_length', 'medium'),
                'request_payload' => $payload,
            ]),
        ]);
    }

    private function validateSeriesGenerationAllowed(ContentSeries $series): void
    {
        if ($series->isLocked() || in_array($series->normalizedStatus(), [ContentSeries::STATUS_PUBLISHED, ContentSeries::STATUS_ARCHIVED], true)) {
            throw new RuntimeException('Published or archived series are read-only.');
        }
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function formatValidationMessageSummary(array $errors): string
    {
        $messages = collect($errors)
            ->flatten()
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->values();

        if ($messages->isEmpty()) {
            return 'Generated brief payload is invalid.';
        }

        return $messages->take(3)->implode(' | ');
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function strategyArticles(ContentSeries $series): Collection
    {
        $jsonArticles = collect((array) data_get($series->strategy_json, 'articles', []))
            ->filter(fn ($value) => is_array($value))
            ->values();

        $series->loadMissing('seriesArticles');

        if ($jsonArticles->isNotEmpty()) {
            $rowsByNumber = $series->seriesArticles->keyBy('article_number');
            $rows = $jsonArticles
                ->map(function (array $jsonArticle, int $index) use ($rowsByNumber): array {
                    $articleNumber = (int) data_get($jsonArticle, 'article_number', $index + 1);
                    /** @var ContentSeriesArticle|null $row */
                    $row = $rowsByNumber->get($articleNumber);

                    return [
                        'article_number' => $articleNumber,
                        'title' => (string) ($row?->title ?: data_get($jsonArticle, 'title', '')),
                        'primary_keyword' => (string) ($row?->primary_keyword ?: data_get($jsonArticle, 'primary_keyword', '')),
                        'output_type' => (string) data_get($jsonArticle, 'output_type', ''),
                        'content_type' => (string) data_get($jsonArticle, 'content_type', ''),
                        'target_audience' => (string) data_get($jsonArticle, 'target_audience', ''),
                        'audience_keys' => array_values((array) data_get($jsonArticle, 'audience_keys', [])),
                        'secondary_keywords' => $row
                            ? array_values((array) ($row->secondary_keywords ?? []))
                            : array_values((array) data_get($jsonArticle, 'secondary_keywords', [])),
                        'internal_links_to' => $row
                            ? array_values((array) ($row->internal_links_to ?? []))
                            : array_values((array) data_get($jsonArticle, 'internal_links_to', [])),
                        'is_pillar' => $row
                            ? (bool) $row->is_pillar
                            : (bool) data_get($jsonArticle, 'is_pillar', false),
                        'planned_url' => $row?->planned_url,
                    ];
                })
                ->values();

            $pillar = $rows->first(fn (array $row): bool => (bool) ($row['is_pillar'] ?? false));

            return $rows
                ->map(function (array $row) use ($rows, $pillar): array {
                    $row['pillar_article_number'] = (int) ($pillar['article_number'] ?? 0);
                    $row['pillar_title'] = $pillar['title'] ?? null;
                    $row['pillar_primary_keyword'] = $pillar['primary_keyword'] ?? null;
                    $row['supporting_titles'] = $rows
                        ->reject(fn (array $candidate): bool => (int) $candidate['article_number'] === (int) $row['article_number'])
                        ->map(fn (array $candidate): string => (string) $candidate['title'])
                        ->filter()
                        ->values()
                        ->all();

                    return $row;
                })
                ->values();
        }

        if ($series->seriesArticles->contains(function (ContentSeriesArticle $article): bool {
            return trim((string) ($article->title ?? '')) !== ''
                || trim((string) ($article->primary_keyword ?? '')) !== ''
                || ! empty((array) ($article->internal_links_to ?? []))
                || $article->content_id !== null;
        })) {
            return $series->seriesArticles
                ->sortBy('article_number')
                ->map(fn (ContentSeriesArticle $article): array => [
                    'article_number' => (int) $article->article_number,
                    'title' => (string) ($article->title ?? ''),
                    'primary_keyword' => (string) ($article->primary_keyword ?? ''),
                    'secondary_keywords' => array_values((array) ($article->secondary_keywords ?? [])),
                    'internal_links_to' => array_values((array) ($article->internal_links_to ?? [])),
                    'is_pillar' => (bool) $article->is_pillar,
                    'planned_url' => $article->planned_url,
                ])
                ->values();
        }

        return $jsonArticles;
    }

    /**
     * @param array<int,mixed> $requested
     * @return array<int,int>
     */
    private function normalizeRequestedArticleNumbers(array $requested, int $max): array
    {
        if ($requested === []) {
            return [];
        }

        return collect($requested)
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0 && $value <= $max)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,array{content:Content,draft:Draft|null,brief:Brief|null}>
     */
    private function existingArticlesByNumber(ContentSeries $series): array
    {
        $rows = [];

        $contents = Content::query()
            ->where('series_id', (string) $series->id)
            ->orderBy('created_at')
            ->get();

        foreach ($contents as $content) {
            $articleNumber = $this->parseArticleNumberFromExternalKey((string) ($content->external_key ?? ''), (string) $series->id);
            if ($articleNumber < 1) {
                continue;
            }

            $draft = Draft::query()
                ->where('content_id', (string) $content->id)
                ->latest('created_at')
                ->first();

            $brief = Brief::query()
                ->where('content_id', (string) $content->id)
                ->latest('created_at')
                ->first();

            $rows[$articleNumber] = [
                'content' => $content,
                'draft' => $draft,
                'brief' => $brief,
            ];
        }

        $series->loadMissing('seriesArticles.content');

        foreach ($series->seriesArticles as $seriesArticle) {
            $articleNumber = (int) $seriesArticle->article_number;
            if ($articleNumber < 1 || isset($rows[$articleNumber]) || ! $seriesArticle->content_id) {
                continue;
            }

            $content = $seriesArticle->content instanceof Content
                ? $seriesArticle->content
                : Content::query()->find((string) $seriesArticle->content_id);

            if (! $content instanceof Content) {
                continue;
            }

            $draft = Draft::query()
                ->where('content_id', (string) $content->id)
                ->latest('created_at')
                ->first();

            $brief = Brief::query()
                ->where('content_id', (string) $content->id)
                ->latest('created_at')
                ->first();

            $rows[$articleNumber] = [
                'content' => $content,
                'draft' => $draft,
                'brief' => $brief,
            ];
        }

        return $rows;
    }

    private function isArticleAlreadyGenerated(?Content $content, ?Draft $draft): bool
    {
        if (! $content || ! $draft) {
            return false;
        }

        if (! in_array((string) $draft->status, ['generated', 'ready_to_deliver', 'delivered', 'published'], true)) {
            return false;
        }

        return in_array((string) $content->status, ['draft', 'review', 'approved', 'published'], true);
    }

    private function articleExternalKey(ContentSeries $series, int $articleNumber): string
    {
        return 'series-' . $series->id . '-article-' . $articleNumber;
    }

    private function parseArticleNumberFromExternalKey(string $externalKey, string $seriesId): int
    {
        $pattern = '/^series-' . preg_quote($seriesId, '/') . '-article-(\d+)$/';
        if (! preg_match($pattern, $externalKey, $matches)) {
            return 0;
        }

        return isset($matches[1]) ? max(0, (int) $matches[1]) : 0;
    }

    /**
     * @param array<int,array<string,mixed>> $strategyByNumber
     * @param array<int,array{content:Content,draft:Draft|null,brief:Brief|null}> $existingByNumber
     * @return array<int,string>
     */
    private function resolvePlannedUrlMap(ContentSeries $series, ClientSite $site, array $strategyByNumber, array $existingByNumber): array
    {
        $map = [];
        $usedSlugs = [];

        $existingPlanMap = collect((array) data_get($series->publish_plan_json, 'articles', []))
            ->filter(fn ($row) => is_array($row) && (int) data_get($row, 'article_number', 0) > 0)
            ->mapWithKeys(fn (array $row): array => [(int) data_get($row, 'article_number') => (string) data_get($row, 'planned_url', '')])
            ->all();

        foreach ($strategyByNumber as $articleNumber => $article) {
            $plannedUrl = trim((string) ($existingPlanMap[$articleNumber] ?? ''));

            if ($plannedUrl === '' && isset($existingByNumber[$articleNumber])) {
                /** @var Draft|null $existingDraft */
                $existingDraft = $existingByNumber[$articleNumber]['draft'] ?? null;
                $plannedUrl = trim((string) (
                    $existingDraft?->seo_canonical
                    ?: data_get($existingDraft?->meta, 'series_context.planned_url', '')
                ));
            }

            if ($plannedUrl === '') {
                $slug = $this->makeUniqueSlug(
                    title: trim((string) data_get($article, 'title', '')) ?: ('Series article ' . $articleNumber),
                    articleNumber: $articleNumber,
                    usedSlugs: $usedSlugs,
                );
                $plannedUrl = $this->plannedUrl($site, $slug, $series->wordPressPostType());
            } else {
                $slug = $this->extractSlugFromPlannedUrl($plannedUrl);
                if ($slug && ! in_array($slug, $usedSlugs, true)) {
                    $usedSlugs[] = $slug;
                }
            }

            $map[(int) $articleNumber] = $plannedUrl;
        }

        return $map;
    }

    private function extractSlugFromPlannedUrl(string $plannedUrl): ?string
    {
        $path = parse_url($plannedUrl, PHP_URL_PATH);
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        if ($segments === []) {
            return null;
        }

        $slug = (string) end($segments);

        return trim($slug) !== '' ? $slug : null;
    }

    /**
     * @return array{action_id:string|null,cost:int}
     */
    private function resolveArticlePricing(): array
    {
        $action = CreditAction::query()
            ->where('key', 'content.article')
            ->where('is_active', true)
            ->first();

        if (! $action) {
            $action = CreditAction::query()
                ->where('category', 'content')
                ->where('is_active', true)
                ->orderBy('credits_cost')
                ->first();
        }

        return [
            'action_id' => $action?->id,
            'cost' => max(1, (int) ($action?->credits_cost ?? config('argusly.ai.drafts.credit_cost', 4))),
        ];
    }

    private function deductCreditsBeforeGeneration(
        ContentSeries $series,
        ClientSite $site,
        int $requiredCredits,
        int $actorUserId,
        string $idempotencyKey,
        int $articlesCount,
        string $runId
    ): CreditLedgerEntry {
        if ($requiredCredits <= 0) {
            throw new RuntimeException('Invalid credit estimate for series generation.');
        }

        return DB::transaction(function () use ($series, $site, $requiredCredits, $actorUserId, $idempotencyKey, $articlesCount, $runId): CreditLedgerEntry {
            $existing = CreditLedgerEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }

            $wallet = CreditWallet::query()
                ->where('client_site_id', (string) $site->id)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                $wallet = CreditWallet::query()->create([
                    'id' => (string) Str::uuid(),
                    'client_site_id' => (string) $site->id,
                    'workspace_id' => (string) $site->workspace_id,
                    'balance_cached' => 0,
                    'reserved_cached' => 0,
                ]);
                $wallet->refresh();
            }

            $consumable = app(SiteCreditAllocationService::class)
                ->consumableCreditsForSite((string) $site->id);

            $available = $consumable - (int) $wallet->reserved_cached;
            if ($available < $requiredCredits) {
                throw new InsufficientCreditsException($requiredCredits, max(0, $available));
            }

            $allocations = $this->consumeFromBuckets($wallet, $requiredCredits);

            $entry = CreditLedgerEntry::query()->create([
                'id' => (string) Str::uuid(),
                'credit_wallet_id' => (string) $wallet->id,
                'type' => 'series_generation',
                'source' => 'usage',
                'amount' => -$requiredCredits,
                'remaining' => 0,
                'source_type' => ContentSeries::class,
                'source_id' => (string) $series->id,
                'client_site_id' => (string) $site->id,
                'organization_id' => (int) $series->organization_id,
                'user_id' => $actorUserId,
                'meta' => [
                    'event' => 'series_generation',
                    'series_id' => (string) $series->id,
                    'run_id' => $runId,
                    'articles_count' => $articlesCount,
                    'allocations' => $allocations,
                    'consumption_policy' => 'included_first_then_addon',
                ],
                'idempotency_key' => $idempotencyKey,
            ]);

            if ($wallet->getTable() !== 'site_credit_allocations') {
                $wallet->balance_cached = (int) $wallet->balance_cached - $requiredCredits;
                $wallet->save();
            }

            app(SiteCreditAllocationService::class)->syncLegacyUsage((string) $site->id, $requiredCredits);
            $workspaceUsage = app(WorkspaceCreditLedgerService::class)->commitUsage(
                workspaceId: (string) $site->workspace_id,
                amount: $requiredCredits,
                clientSiteId: (string) $site->id,
                allocationId: SiteCreditAllocation::query()->where('client_site_id', $site->id)->value('id'),
                metadata: [
                    'feature' => 'series_generation',
                    'series_id' => (string) $series->id,
                    'run_id' => $runId,
                    'articles_count' => $articlesCount,
                ],
                referenceType: ContentSeries::class,
                referenceId: (string) $series->id,
                idempotencyKey: 'workspace:' . $idempotencyKey
            );

            ContentSeriesGenerationRun::query()
                ->whereKey($runId)
                ->update(['workspace_credit_transaction_id' => $workspaceUsage->id]);

            return $entry;
        });
    }

    /**
     * @return array<int,array{bucket_entry_id:string,source:string,amount:int}>
     */
    private function consumeFromBuckets(CreditWallet $wallet, int $amount): array
    {
        return app(SiteCreditAllocationService::class)
            ->consumeAllocatedCredits((string) $wallet->client_site_id, $amount);
    }

    /**
     * @param array<int,mixed> $raw
     * @return array<int,int>
     */
    private function normalizeInternalLinksTo(array $raw, int $articleNumber, int $totalArticles): array
    {
        return collect($raw)
            ->map(function ($value): int {
                if (is_numeric($value)) {
                    return (int) $value;
                }

                preg_match('/\d+/', (string) $value, $matches);

                return isset($matches[0]) ? (int) $matches[0] : 0;
            })
            ->filter(fn (int $value) => $value > 0)
            ->filter(fn (int $value) => $value <= $totalArticles)
            ->filter(fn (int $value) => $value !== $articleNumber)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int,string> $usedSlugs
     */
    private function makeUniqueSlug(string $title, int $articleNumber, array &$usedSlugs): string
    {
        $base = Str::slug($title);
        if ($base === '') {
            $base = 'series-article-' . $articleNumber;
        }

        $slug = $base;
        $counter = 2;
        while (in_array($slug, $usedSlugs, true)) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        $usedSlugs[] = $slug;

        return $slug;
    }

    private function plannedUrl(ClientSite $site, string $slug, WordPressPostType $postType = WordPressPostType::POST): string
    {
        $base = rtrim((string) ($site->base_url ?: $site->site_url), '/');

        return $postType->buildPlannedUrl($base, $slug);
    }

    /**
     * @param array<int,int> $internalLinksTo
     * @param array<int,string> $plannedUrlMap
     * @param Collection<int,array<string,mixed>> $strategyByNumber
     * @return array<int,array<string,string|null>>
     */
    private function seriesLinkHints(array $internalLinksTo, array $plannedUrlMap, Collection $strategyByNumber): array
    {
        return collect($internalLinksTo)
            ->map(function (int $target) use ($plannedUrlMap, $strategyByNumber): ?array {
                $url = trim((string) ($plannedUrlMap[$target] ?? ''));
                $targetArticle = (array) ($strategyByNumber->get($target) ?? []);
                $anchor = trim((string) (data_get($targetArticle, 'primary_keyword') ?: data_get($targetArticle, 'title', '')));
                $title = trim((string) data_get($targetArticle, 'title', $anchor));

                if ($url === '' || $title === '') {
                    return null;
                }

                return [
                    'target_content_id' => '',
                    'target_url' => $url,
                    'anchor_text' => $anchor !== '' ? $anchor : $title,
                    'title' => $title,
                    'reason' => 'series_article_hint',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
