<?php

namespace App\Http\Controllers\App;

use App\Enums\SupportedLanguage;
use App\Enums\WordPressPostType;
use App\Exceptions\InsufficientCreditsException;
use App\Http\Controllers\Controller;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentSeries;
use App\Models\ContentSeriesGenerationRun;
use App\Models\ContentSeriesGenerationRunArticle;
use App\Models\Draft;
use App\Support\ContentIntentCatalog;
use App\Support\EditorialTaxonomyService;
use App\Services\Content\ContentSeriesArticleSyncService;
use App\Services\Content\SeriesArticleGenerationService;
use App\Services\Content\SeriesCloningService;
use App\Services\Content\SeriesPublishingService;
use App\Services\Content\SeriesStructureService;
use App\Services\Content\SeriesStrategyService;
use App\Services\Content\SeriesTranslationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AppContentSeriesController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', ContentSeries::class);

        $organizationId = $this->organizationId($request);
        $filter = $this->resolveFilter((string) $request->query('filter', 'all'));

        $query = ContentSeries::query()
            ->with([
                'site',
                'creator',
                'seriesArticles' => fn ($builder) => $builder
                    ->where('is_pillar', true)
                    ->with('content:id,title'),
            ])
            ->withCount('contents')
            ->where('organization_id', $organizationId)
            ->orderByDesc('created_at');

        $this->applyStatusFilter($query, $filter);

        $series = $query
            ->paginate(20)
            ->withQueryString();

        return view('app.content.series.index', [
            'series' => $series,
            'filter' => $filter,
            'filters' => ['all', 'draft', 'published', 'scheduled', 'archived'],
        ]);
    }

    public function create(Request $request, EditorialTaxonomyService $taxonomyService): View
    {
        $this->authorize('create', ContentSeries::class);

        $organizationId = $this->organizationId($request);
        $taxonomyService->ensureDefaults($organizationId);

        $sites = ClientSite::query()
            ->forOrganization($organizationId)
            ->where('is_active', true)
            ->where('status', '!=', 'disabled')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'site_url', 'base_url']);

        $prefill = $this->prefillFromSourceBrief($request, $organizationId);

        return view('app.content.series.create', [
            'sites' => $sites,
            'contentIntentOptions' => ContentIntentCatalog::options(),
            'contentTypeOptions' => WordPressPostType::seriesOptions(),
            'prefill' => $prefill,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ContentSeries::class);

        $organizationId = $this->organizationId($request);
        $siteIds = ClientSite::query()->forOrganization($organizationId)->pluck('id')->all();
        $intentInput = $request->input('intents', $request->input('intent_keys', []));

        $data = $request->validate([
            'site_id' => ['required', 'uuid', Rule::in($siteIds ?: ['__none__'])],
            'name' => ['required', 'string', 'max:255'],
            'main_topic' => ['required', 'string', 'max:255'],
            'primary_keyword' => ['required', 'string', 'max:255'],
            'supporting_keywords' => ['nullable', 'string', 'max:5000'],
            'intents' => ['nullable', 'array'],
            'intents.*' => ['string', Rule::in(ContentIntentCatalog::allowedKeys())],
            'audience' => ['nullable', 'string', 'max:255'],
            'tone' => ['nullable', 'string', 'max:255'],
            'funnel_stage' => ['nullable', 'string', 'max:64'],
            'articles_count' => ['nullable', 'integer', 'min:1', 'max:20'],
            'content_type' => ['nullable', 'string', Rule::in(WordPressPostType::values())],
        ]);

        $series = ContentSeries::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => $organizationId,
            'site_id' => (string) $data['site_id'],
            'name' => trim((string) $data['name']),
            'main_topic' => trim((string) $data['main_topic']),
            'primary_keyword' => trim((string) $data['primary_keyword']),
            'supporting_keywords' => $this->parseSupportingKeywords((string) ($data['supporting_keywords'] ?? '')),
            'intent_keys' => ContentIntentCatalog::normalizeKeys((array) $intentInput),
            'audience' => $this->nullableString($data['audience'] ?? null),
            'tone' => $this->nullableString($data['tone'] ?? null),
            'funnel_stage' => $this->nullableString($data['funnel_stage'] ?? null),
            'articles_count' => (int) ($data['articles_count'] ?? 5),
            'content_type' => $this->nullableString($data['content_type'] ?? null) ?? WordPressPostType::POST->value,
            'status' => ContentSeries::STATUS_DRAFT,
            'is_locked' => false,
            'created_by' => $request->user()->id,
        ]);

        app(ContentSeriesArticleSyncService::class)->sync($series);

        return redirect()
            ->route('app.content.series.show', $series)
            ->with('status', 'Series created. Continue with strategy generation.');
    }

    public function show(
        Request $request,
        ContentSeries $series,
        ContentSeriesArticleSyncService $seriesArticleSyncService
    ): View
    {
        $this->authorize('view', $series);
        $this->assertSeriesInOrganization($request, $series);

        $seriesArticleSyncService->sync($series);

        $series->load([
            'site',
            'creator',
            'seriesArticles.content.currentVersion',
            'seriesArticles.content.localizedVariants.currentVersion',
            'contents' => fn ($query) => $query->orderBy('created_at'),
            'contents.currentVersion',
            'contents.localizedVariants.currentVersion',
        ]);

        $displayData = $this->buildSeriesDisplayData($series);
        $strategyArticles = $displayData['strategy_articles'];
        $displayRun = $displayData['generation_run'];
        $articleRows = $displayData['article_rows'];

        $generatedCount = (int) $articleRows->where('status', ContentSeriesGenerationRunArticle::STATUS_DRAFT)->count();

        $progress = [
            'planned' => max($strategyArticles->count(), (int) $series->articles_count, $articleRows->count()),
            'generated' => $generatedCount,
            'published' => (int) $articleRows->sum(fn (array $row): int => (int) data_get($row, 'locale_summary.published', 0)),
            'translated' => (int) $articleRows->sum(fn (array $row): int => (int) data_get($row, 'locale_summary.translated', 0)),
            'unpublished' => (int) $articleRows->sum(fn (array $row): int => (int) data_get($row, 'locale_summary.unpublished', 0)),
            'locales' => (int) $articleRows->sum(fn (array $row): int => (int) data_get($row, 'locale_summary.total', 0)),
        ];

        return view('app.content.series.show', [
            'series' => $series,
            'strategyArticles' => $strategyArticles,
            'pillarArticle' => $series->getPillarArticle(),
            'articleRows' => $articleRows,
            'generationRun' => $displayRun,
            'isGenerationRunning' => in_array((string) ($displayRun?->status ?? ''), [
                ContentSeriesGenerationRun::STATUS_PENDING,
                ContentSeriesGenerationRun::STATUS_RUNNING,
            ], true),
            'progress' => $progress,
            'isReadOnly' => $series->isLocked() || $series->isArchived(),
            'translationLanguages' => SupportedLanguage::cases(),
        ]);
    }

    public function structure(
        Request $request,
        ContentSeries $series,
        ContentSeriesArticleSyncService $seriesArticleSyncService,
        SeriesStructureService $seriesStructureService
    ): View {
        $this->authorize('view', $series);
        $this->assertSeriesInOrganization($request, $series);

        $seriesArticleSyncService->sync($series);

        $series->load([
            'site',
            'creator',
            'seriesArticles.content',
            'contents' => fn ($query) => $query->orderBy('created_at'),
        ]);

        $displayData = $this->buildSeriesDisplayData($series);
        $suggestedPillarArticleNumber = $seriesStructureService->suggestPillarArticleNumber($series);
        $articleRows = $displayData['article_rows']->map(function (array $row) use ($suggestedPillarArticleNumber): array {
            $row['is_suggested_pillar'] = ! $row['is_pillar'] && (int) $row['article_number'] === (int) $suggestedPillarArticleNumber;

            return $row;
        });

        return view('app.content.series.structure', [
            'series' => $series,
            'articleRows' => $articleRows,
            'pillarArticle' => $series->getPillarArticle(),
            'suggestedPillarArticleNumber' => $suggestedPillarArticleNumber,
            'generationRun' => $displayData['generation_run'],
            'isReadOnly' => $series->isLocked() || $series->isArchived(),
        ]);
    }

    public function generateStrategy(
        Request $request,
        ContentSeries $series,
        SeriesStrategyService $strategyService,
        SeriesStructureService $seriesStructureService
    ): RedirectResponse {
        $this->authorize('update', $series);
        $this->assertSeriesInOrganization($request, $series);

        try {
            $strategyService->generateStrategy($series);
            $seriesStructureService->applySuggestedPillar($series->fresh());
        } catch (\Throwable $exception) {
            return back()->withErrors([
                'series_strategy' => 'Strategy generation failed: ' . $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('app.content.series.structure', $series)
            ->with('status', 'Strategy generated. Review the structure and confirm the pillar article.');
    }

    public function generateArticles(
        Request $request,
        ContentSeries $series,
        SeriesArticleGenerationService $generationService
    ): RedirectResponse {
        $this->authorize('update', $series);
        $this->assertSeriesInOrganization($request, $series);

        $data = $request->validate([
            'article_numbers' => ['sometimes', 'array'],
            'article_numbers.*' => ['integer', 'min:1', 'max:100'],
        ]);

        try {
            $result = $generationService->dispatchGeneration(
                $series,
                (int) $request->user()->id,
                (array) ($data['article_numbers'] ?? [])
            );
        } catch (InsufficientCreditsException $exception) {
            return back()->withErrors([
                'series_generation' => $exception->getMessage(),
            ]);
        } catch (ValidationException $exception) {
            return back()->withErrors([
                'series_generation' => $this->formatValidationMessages($exception->errors()),
            ]);
        } catch (\Throwable $exception) {
            return back()->withErrors([
                'series_generation' => 'Article generation failed: ' . $exception->getMessage(),
            ]);
        }

        if ((bool) ($result['already_running'] ?? false)) {
            return back()->with('status', 'Generation is already running. Progress updates automatically on this page.');
        }

        $queued = (int) ($result['queued'] ?? 0);
        $alreadyGenerated = (int) ($result['already_generated'] ?? 0);
        $creditsUsed = (int) ($result['credits_used'] ?? 0);

        if ($queued > 0) {
            return back()->with('status', sprintf(
                'Queued generation for %d article(s). %d already generated. %d credits deducted.',
                $queued,
                $alreadyGenerated,
                $creditsUsed
            ));
        }

        return back()->with('status', 'All selected articles are already generated.');
    }

    public function publish(
        Request $request,
        ContentSeries $series,
        SeriesPublishingService $publishingService
    ): RedirectResponse {
        $this->authorize('publish', $series);
        $this->assertSeriesInOrganization($request, $series);

        try {
            $result = $publishingService->publish($series);
        } catch (\Throwable $exception) {
            return back()->withErrors([
                'series_publish' => 'Series publishing failed: ' . $exception->getMessage(),
            ]);
        }

        return back()->with('status', sprintf(
            'Publishing started. Queued: %d, published immediately: %d, failed: %d.',
            (int) $result['queued'],
            (int) $result['published'],
            (int) $result['failed']
        ));
    }

    public function translate(
        Request $request,
        ContentSeries $series,
        SeriesTranslationService $seriesTranslationService
    ): RedirectResponse {
        $this->authorize('view', $series);
        $this->assertSeriesInOrganization($request, $series);

        $data = $request->validate([
            'target_locale' => ['required', 'string', Rule::in(SupportedLanguage::values())],
            'article_numbers' => ['nullable', 'array'],
            'article_numbers.*' => ['integer', 'min:1', 'max:100'],
        ]);

        $summary = $seriesTranslationService->queueSeries(
            $series,
            (string) $data['target_locale'],
            (string) $request->user()->id,
            (array) ($data['article_numbers'] ?? []),
        );

        if ((int) $summary['total'] === 0) {
            return back()->withErrors([
                'series_translation' => 'No source articles are available to translate for this series.',
            ]);
        }

        if ((int) $summary['queued'] === 0 && (int) $summary['failed'] > 0) {
            Log::warning('content.series.translation.batch_failed', [
                'series_id' => (string) $series->id,
                'target_locale' => (string) $data['target_locale'],
                'user_id' => $request->user()?->id,
                'errors' => $summary['errors'],
            ]);

            return back()->withErrors([
                'series_translation' => implode(' | ', array_slice($summary['errors'], 0, 3)),
            ]);
        }

        $language = $summary['target_language'];
        $message = sprintf(
            'Queued %d/%d article translation(s) to %s.',
            (int) $summary['queued'],
            (int) $summary['total'],
            $language instanceof SupportedLanguage ? $language->englishLabel() : strtoupper((string) $data['target_locale'])
        );

        if ((int) $summary['skipped'] > 0) {
            $message .= sprintf(' %d already running.', (int) $summary['skipped']);
        }

        if ((int) $summary['failed'] > 0) {
            $message .= sprintf(' %d could not be queued.', (int) $summary['failed']);
        }

        return back()->with('status', $message);
    }

    public function duplicate(
        Request $request,
        ContentSeries $series,
        SeriesCloningService $cloningService
    ): RedirectResponse {
        $this->authorize('duplicate', $series);
        $this->assertSeriesInOrganization($request, $series);

        $cloned = $cloningService->cloneAsDraft($series, (int) $request->user()->id);

        return redirect()
            ->route('app.content.series.show', $cloned)
            ->with('status', 'New draft series created based on the selected series.');
    }

    public function setPillar(
        Request $request,
        ContentSeries $series,
        ContentSeriesArticleSyncService $seriesArticleSyncService
    ): RedirectResponse {
        $this->authorize('update', $series);
        $this->assertSeriesInOrganization($request, $series);

        $data = $request->validate([
            'article_number' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $seriesArticleSyncService->setPillar($series, (int) $data['article_number']);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('status', 'Pillar article updated.');
    }

    public function clearPillar(
        Request $request,
        ContentSeries $series,
        ContentSeriesArticleSyncService $seriesArticleSyncService
    ): RedirectResponse {
        $this->authorize('update', $series);
        $this->assertSeriesInOrganization($request, $series);

        $seriesArticleSyncService->clearPillar($series);

        return back()->with('status', 'Pillar article removed.');
    }

    public function archive(Request $request, ContentSeries $series): RedirectResponse
    {
        $this->authorize('archive', $series);
        $this->assertSeriesInOrganization($request, $series);

        $series->update([
            'status' => ContentSeries::STATUS_ARCHIVED,
            'is_locked' => true,
        ]);

        return redirect()
            ->route('app.content.series.index')
            ->with('status', 'Series archived.');
    }

    public function destroy(Request $request, ContentSeries $series): RedirectResponse
    {
        $this->authorize('delete', $series);
        $this->assertSeriesInOrganization($request, $series);

        $series->delete();

        return redirect()
            ->route('app.content.series.index')
            ->with('status', 'Draft series deleted.');
    }

    private function assertSeriesInOrganization(Request $request, ContentSeries $series): void
    {
        $organizationId = $this->organizationId($request);

        if ((int) $series->organization_id !== $organizationId) {
            abort(404);
        }
    }

    private function organizationId(Request $request): int
    {
        $organizationId = (int) $request->user()->organization_id;
        if ($organizationId < 1) {
            abort(403, 'No organization context available.');
        }

        return $organizationId;
    }

    private function resolveFilter(string $requested): string
    {
        $requested = strtolower(trim($requested));
        $allowed = ['all', 'draft', 'published', 'scheduled', 'archived'];

        return in_array($requested, $allowed, true) ? $requested : 'all';
    }

    private function applyStatusFilter(\Illuminate\Database\Eloquent\Builder $query, string $filter): void
    {
        $legacyStrategy = ['strategy_ready', ContentSeries::STATUS_STRATEGY_GENERATED];
        $legacyReady = ['generated', ContentSeries::STATUS_READY];
        $legacyScheduled = ['publishing', ContentSeries::STATUS_SCHEDULED];

        if ($filter === 'all') {
            $query->where('status', '!=', ContentSeries::STATUS_ARCHIVED);
            return;
        }

        if ($filter === 'draft') {
            $query->where(function ($inner) use ($legacyStrategy): void {
                $inner->where('status', ContentSeries::STATUS_DRAFT)
                    ->orWhereIn('status', $legacyStrategy);
            });
            return;
        }

        if ($filter === 'published') {
            $query->where('status', ContentSeries::STATUS_PUBLISHED);
            return;
        }

        if ($filter === 'scheduled') {
            $query->whereIn('status', $legacyScheduled);
            return;
        }

        if ($filter === 'archived') {
            $query->where('status', ContentSeries::STATUS_ARCHIVED);
            return;
        }

        // Fallback to common active states.
        $query->where(function ($inner) use ($legacyStrategy, $legacyReady, $legacyScheduled): void {
            $inner->whereIn('status', [
                ContentSeries::STATUS_DRAFT,
                ContentSeries::STATUS_GENERATING,
                ContentSeries::STATUS_PUBLISHED,
            ])->orWhereIn('status', $legacyStrategy)
                ->orWhereIn('status', $legacyReady)
                ->orWhereIn('status', $legacyScheduled);
        });
    }

    /**
     * @return array<int,string>
     */
    private function parseSupportingKeywords(string $value): array
    {
        return collect(preg_split('/[\r\n,]+/', $value) ?: [])
            ->map(fn ($row) => trim((string) $row))
            ->filter()
            ->unique()
            ->values()
            ->take(50)
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function formatValidationMessages(array $errors): string
    {
        $messages = collect($errors)
            ->flatten()
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->values();

        if ($messages->isEmpty()) {
            return 'Article generation failed due to invalid brief data.';
        }

        return 'Article generation failed: ' . $messages->take(3)->implode(' | ');
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
     * @return array{
     *     strategy_articles:\Illuminate\Support\Collection<int,array<string,mixed>>,
     *     generation_run:?ContentSeriesGenerationRun,
     *     article_rows:\Illuminate\Support\Collection<int,array<string,mixed>>
     * }
     */
    private function buildSeriesDisplayData(ContentSeries $series): array
    {
        $strategyArticles = collect((array) data_get($series->strategy_json, 'articles', []))
            ->filter(fn ($value) => is_array($value))
            ->values();

        $strategyByNumber = $strategyArticles
            ->mapWithKeys(fn (array $article, int $index): array => [
                (int) data_get($article, 'article_number', $index + 1) => $article,
            ]);
        $seriesArticlesByNumber = $series->seriesArticles->keyBy('article_number');

        $contentsByArticleNumber = $series->contents
            ->mapWithKeys(function (Content $content) use ($series): array {
                $articleNumber = $this->parseArticleNumberFromExternalKey((string) ($content->external_key ?? ''), (string) $series->id);
                if ($articleNumber < 1) {
                    return [];
                }

                return [$articleNumber => $content];
            });

        $latestDraftByContentId = Draft::query()
            ->whereIn('content_id', $series->contents->pluck('id')->all())
            ->orderByDesc('created_at')
            ->get()
            ->unique('content_id')
            ->keyBy('content_id');

        $activeRun = ContentSeriesGenerationRun::query()
            ->where('series_id', (string) $series->id)
            ->whereIn('status', [
                ContentSeriesGenerationRun::STATUS_PENDING,
                ContentSeriesGenerationRun::STATUS_RUNNING,
            ])
            ->latest('created_at')
            ->first();

        $latestRun = ContentSeriesGenerationRun::query()
            ->where('series_id', (string) $series->id)
            ->latest('created_at')
            ->first();

        $displayRun = $activeRun ?: $latestRun;
        if ($displayRun) {
            $displayRun->load([
                'articles' => fn ($query) => $query->orderBy('article_number'),
            ]);
        }

        $runArticlesByNumber = collect($displayRun?->articles ?? [])->keyBy('article_number');

        $articleNumbers = collect(range(1, max((int) $series->articles_count, $strategyByNumber->count())))
            ->merge($strategyByNumber->keys())
            ->merge($contentsByArticleNumber->keys())
            ->merge($runArticlesByNumber->keys())
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->unique()
            ->sort()
            ->values();

        $articleRows = $articleNumbers->map(function (int $articleNumber) use (
            $strategyByNumber,
            $contentsByArticleNumber,
            $runArticlesByNumber,
            $latestDraftByContentId,
            $seriesArticlesByNumber
        ): array {
            $strategyArticle = (array) ($strategyByNumber->get($articleNumber) ?? []);
            $seriesArticle = $seriesArticlesByNumber->get($articleNumber);
            /** @var Content|null $content */
            $content = $contentsByArticleNumber->get($articleNumber);
            /** @var ContentSeriesGenerationRunArticle|null $runArticle */
            $runArticle = $runArticlesByNumber->get($articleNumber);
            $draft = $content ? $latestDraftByContentId->get((string) $content->id) : null;

            $isGenerated = $content && $draft && in_array((string) $draft->status, [
                'generated',
                'ready_to_deliver',
                'delivered',
                'published',
            ], true) && in_array((string) $content->status, [
                'draft',
                'review',
                'approved',
                'published',
            ], true);

            $status = (string) ($runArticle?->status ?? '');
            if ($isGenerated && ! in_array($status, [
                ContentSeriesGenerationRunArticle::STATUS_PENDING,
                ContentSeriesGenerationRunArticle::STATUS_GENERATING,
                ContentSeriesGenerationRunArticle::STATUS_FAILED,
            ], true)) {
                $status = ContentSeriesGenerationRunArticle::STATUS_DRAFT;
            } elseif ($status === '') {
                $status = $isGenerated ? 'draft' : ($content ? 'brief' : 'pending');
            }

            $errorMessage = trim((string) ($runArticle?->error_message ?? ''));
            if ($errorMessage === '' && $draft && (string) $draft->status === 'failed') {
                $errorMessage = trim((string) ($draft->last_error ?? ''));
            }

            $isPillar = (bool) ($seriesArticle?->is_pillar ?? data_get($strategyArticle, 'is_pillar', false));
            $locales = $content
                ? $this->buildLocaleRowsForSeriesContent($content)
                : collect();
            $localeSummary = [
                'total' => $locales->count(),
                'translated' => $locales->filter(fn (array $localeRow): bool => ! (bool) $localeRow['is_source'])->count(),
                'published' => $locales->filter(fn (array $localeRow): bool => (string) $localeRow['publish_status'] === 'published')->count(),
                'unpublished' => $locales->filter(fn (array $localeRow): bool => (string) $localeRow['publish_status'] !== 'published')->count(),
            ];

            return [
                'article_number' => $articleNumber,
                'title' => trim((string) ($runArticle?->title ?? data_get($strategyArticle, 'title', ''))) ?: (string) ($content?->title ?? ('Series article ' . $articleNumber)),
                'primary_keyword' => trim((string) ($seriesArticle?->primary_keyword ?? data_get($strategyArticle, 'primary_keyword', ''))),
                'status' => $status,
                'publish_status' => (string) ($content?->publish_status ?? 'draft'),
                'links_to' => array_values((array) ($runArticle?->internal_links_to ?? data_get($strategyArticle, 'internal_links_to', []))),
                'is_pillar' => $isPillar,
                'role_label' => $isPillar ? 'Pillar' : 'Supporting',
                'series_article' => $seriesArticle,
                'published_at' => (($content?->publish_status ?? '') === 'published') ? $content?->updated_at : null,
                'locales' => $locales,
                'locale_summary' => $localeSummary,
                'content' => $content,
                'can_retry' => $status === ContentSeriesGenerationRunArticle::STATUS_FAILED,
                'error_message' => $errorMessage,
            ];
        })->values();

        return [
            'strategy_articles' => $strategyArticles,
            'generation_run' => $displayRun,
            'article_rows' => $articleRows,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int,array<string,mixed>>
     */
    private function buildLocaleRowsForSeriesContent(Content $content): \Illuminate\Support\Collection
    {
        $content->loadMissing('familyRoot', 'translationSourceContent', 'localizedVariants');

        return $content->normalizedLocalizationFamily()
            ->map(function (Content $variant) use ($content): array {
                $locale = SupportedLanguage::fromStringOrDefault($variant->localeCode());
                $publishStatus = (string) ($variant->publish_status ?? 'draft');

                return [
                    'content' => $variant,
                    'content_id' => (string) $variant->id,
                    'locale' => $locale->value,
                    'label' => strtoupper($locale->value),
                    'language_label' => $locale->englishLabel(),
                    'is_source' => (string) $variant->id === (string) $content->localizationSource()->id,
                    'publish_status' => $publishStatus,
                    'status_label' => $publishStatus === 'published' ? 'published' : 'draft',
                    'published_at' => $publishStatus === 'published' ? $variant->updated_at : null,
                ];
            })
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function prefillFromSourceBrief(Request $request, int $organizationId): array
    {
        $briefId = trim((string) $request->query('source_brief', ''));
        if ($briefId === '') {
            return [];
        }

        $brief = Brief::query()
            ->where('id', $briefId)
            ->whereHas('clientSite.workspace', fn ($query) => $query->where('organization_id', $organizationId))
            ->first();

        if (! $brief) {
            return [];
        }

        $chain = (array) data_get($brief->client_refs, 'source_briefing.chain_proposal', []);
        $supportingKeywords = collect((array) ($chain['supporting_subtopics'] ?? []))
            ->map(fn (array $row): string => trim((string) data_get($row, 'title', '')))
            ->filter()
            ->implode(PHP_EOL);

        return [
            'site_id' => (string) $brief->client_site_id,
            'name' => (string) ($chain['pillar_topic'] ?? $brief->title),
            'main_topic' => (string) ($chain['pillar_topic'] ?? $brief->title),
            'primary_keyword' => (string) ($brief->primary_keyword ?? ''),
            'supporting_keywords' => $supportingKeywords,
            'audience' => (string) ($brief->target_audience ?? ''),
            'tone' => (string) ($brief->tone_of_voice ?? ''),
            'funnel_stage' => (string) ($brief->funnel_stage ?? ''),
            'articles_count' => max(3, count((array) ($chain['supporting_subtopics'] ?? []))),
            'content_type' => WordPressPostType::POST->value,
        ];
    }
}
