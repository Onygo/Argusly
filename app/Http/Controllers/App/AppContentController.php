<?php

namespace App\Http\Controllers\App;

use App\Actions\Agents\RunAgentForContent;
use App\Actions\Agents\RunInternalLinkingForContent;
use App\Actions\Agents\RunLocalizationForContent;
use App\Actions\Content\ApplyInternalLinkSuggestion;
use App\Actions\Content\CreateRefreshDraft;
use App\Agents\ContentRefresh\ContentRefreshAgent;
use App\Agents\InternalLinking\InternalLinkingAgent;
use App\Agents\Localization\LocalizationAgent;
use App\Enums\ContentDestinationType;
use App\Enums\ContentOriginType;
use App\Enums\SupportedLanguage;
use App\Enums\WordPressPostType;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\TranslateContentRequest;
use App\Jobs\DeliverDraftJob;
use App\Jobs\GenerateStructuredAnswersJob;
use App\Jobs\RegenerateContentDraftJob;
use App\Models\BrandVoice;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\AgentRun;
use App\Models\ContentImage;
use App\Models\ContentImprovementRun;
use App\Models\ContentPublication;
use App\Models\ContentDestination;
use App\Models\MarketingBlogRedirect;
use App\Models\ContentPublishTarget;
use App\Models\ContentAutomation;
use App\Models\ContentSeries;
use App\Models\ContentVersion;
use App\Models\CreditAction;
use App\Models\Draft;
use App\Models\Event;
use App\Models\Persona;
use App\Models\SiteToken;
use App\Models\StructuredAnswerBlock;
use App\Models\TeamMember;
use App\Services\Ai\ImageGenerationService;
use App\Services\Aeo\AeoScoreService;
use App\Services\Analytics\ContentPerformanceInsightService;
use App\Services\BrandContext\BrandGenerationDefaultsService;
use App\Services\Brief\BriefDefaultBuilder;
use App\Services\Content\ContentLifecycleService;
use App\Services\Content\AnswerBlockInjectorService;
use App\Services\Content\AnswerBlockSchemaService;
use App\Services\Content\ContentImprovementService;
use App\Services\Content\TranslationDebugService;
use App\Services\Content\ContentDeletionService;
use App\Services\Content\ContentLocalizationService;
use App\Services\Content\LocalePublishingSyncService;
use App\Services\Content\LocaleMismatchService;
use App\Services\Content\ContentTranslationCoordinator;
use App\Services\ContentImages\UploadedContentImageAssetService;
use App\Services\ContentVisuals\VisualPlanService;
use App\Services\CreditWalletService;
use App\Services\DraftDelivery\PushContentFeaturedImageToWordPress;
use App\Services\DraftDelivery\PushContentOgImageToWordPress;
use App\Services\Entitlements\FeatureGate;
use App\Exceptions\InsufficientCreditsException;
use App\Services\DraftGenerationService;
use App\Services\Entitlements\WorkspaceEntitlementsService;
use App\Services\ImagePresetService;
use App\Services\PlanQuotaService;
use App\Services\Integrations\LaravelConnectorDestinationResolver;
use App\Services\Integrations\LaravelConnectorPublishingService;
use App\Services\Publication\ContentPublicationService;
use App\Services\Publication\WordPressPublicationDestinationResolver;
use App\Services\Markdown\MarkdownArtifactService;
use App\Services\Markdown\MarkdownRenderer;
use App\Services\Seo\ContentIndexationHealthService;
use App\Services\Seo\CanonicalUrlService;
use App\Services\StockImages\UnsplashImageService;
use App\Support\Database\RequestQueryProfiler;
use App\Support\ContentIntentCatalog;
use App\Support\SeoMetadata;
use App\Services\Performance\PerformanceCacheService;
use App\View\Presenters\ContentIndexTreePresenter;
use App\View\Presenters\ContentStatusPresenter;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class AppContentController extends Controller
{
    public function index(
        Request $request,
        ContentPerformanceInsightService $contentPerformanceInsightService,
        PerformanceCacheService $performanceCache
    ): View
    {
        $this->authorize('viewAny', Content::class);
        $profiler = RequestQueryProfiler::startIfEnabled($request, 'content.index');

        $organizationId = (int) $request->user()->organization_id;
        $inbox = trim((string) $request->query('inbox', ''));
        $status = trim((string) $request->query('status', ''));
        $siteId = trim((string) $request->query('site', ''));
        $authorId = trim((string) $request->query('author', ''));
        $q = trim((string) $request->query('q', ''));
        $publishStatus = trim((string) $request->query('publish_status', ''));
        $locale = trim((string) $request->query('locale', ''));
        $resolvedLocale = $locale !== '' ? SupportedLanguage::fromStringOrDefault($locale)->value : '';
        $publicationState = trim((string) $request->query('publication_state', ''));
        $translationState = trim((string) $request->query('translation_state', ''));
        $workflowState = trim((string) $request->query('workflow_state', ''));
        $localeScope = trim((string) $request->query('locale_scope', ''));
        $preset = trim((string) $request->query('preset', ''));
        $sort = trim((string) $request->query('sort', 'newest_created'));
        $origin = trim((string) $request->query('origin', ''));
        $seriesId = trim((string) $request->query('series', ''));
        $automationId = trim((string) $request->query('automation', ''));
        $createdFrom = trim((string) $request->query('created_from', ''));
        $createdTo = trim((string) $request->query('created_to', ''));
        $publishedFrom = trim((string) $request->query('published_from', ''));
        $publishedTo = trim((string) $request->query('published_to', ''));
        $showDeleted = $request->boolean('show_deleted');
        $withFilterCounts = $request->boolean('with_counts', false);

        $filterState = $this->mergeContentIndexPresetFilters([
            'inbox' => $inbox,
            'status' => $status,
            'site' => $siteId,
            'author' => $authorId,
            'publish_status' => $publishStatus,
            'locale' => $resolvedLocale,
            'publication_state' => $publicationState,
            'translation_state' => $translationState,
            'workflow_state' => $workflowState,
            'locale_scope' => $localeScope,
            'preset' => $preset,
            'q' => $q,
            'sort' => $sort,
            'origin' => $origin,
            'series' => $seriesId,
            'automation' => $automationId,
            'created_from' => $createdFrom,
            'created_to' => $createdTo,
            'published_from' => $publishedFrom,
            'published_to' => $publishedTo,
            'show_deleted' => $showDeleted,
        ]);

        $matchingQuery = $this->applyContentIndexFilters(
            $this->contentIndexBaseQuery($organizationId, $showDeleted)->select($this->contentIndexSelectColumns()),
            $filterState
        );

        $matchingContents = $this->applyContentIndexSort($matchingQuery, $sort)
            ->simplePaginate(20)
            ->withQueryString();

        $pageRootIds = collect($matchingContents->items())
            ->map(fn (Content $content): string => $content->localizationRootId())
            ->filter()
            ->unique()
            ->values();

        $contents = $pageRootIds->isEmpty()
            ? collect()
            : Content::query()
                ->when($showDeleted, fn ($query) => $query->withTrashed())
                ->select($this->contentIndexSelectColumns())
                ->withCount([
                    'drafts as pending_drafts_count' => fn (Builder $query) => $query
                        ->whereNotIn('status', ['delivered', 'published', 'cancelled']),
                ])
                ->with([
                    'workspace:id,organization_id,name,display_name,default_content_language,enabled_content_languages',
                    'clientSite:id,name,type,workspace_id',
                    'clientSite.workspace:id,organization_id',
                    'contentDestination:id,workspace_id,name,type,status',
                    'publications:id,content_id,destination_id,client_site_id,locale,provider,remote_id,remote_url,remote_status,delivery_status,last_delivered_at,last_error_at,last_error_code,last_error_message,created_at',
                    'translationRequests:id,content_id,target_locale,target_content_id,status,failure_reason,error_message,processing_failed_at,processing_job_uuid,updated_at,created_at',
                    'series:id,name',
                    'seriesArticle:id,series_id,content_id,article_number,is_pillar',
                    'automation:id,workspace_id,name,locale,locales,include_translation',
                ])
                ->where(function ($query) use ($organizationId): void {
                    $query->whereHas('workspace', fn ($workspaceQuery) => $workspaceQuery->where('organization_id', $organizationId))
                        ->orWhereHas('clientSite.workspace', fn ($workspaceQuery) => $workspaceQuery->where('organization_id', $organizationId));
                })
                ->whereInLocalizationRoots($pageRootIds->all())
                ->tap(fn (Builder $query) => $this->applyContentIndexSort($query, $sort))
                ->get();

        $contentInsights = $contentPerformanceInsightService->forContents($contents);
        $contentTree = ContentIndexTreePresenter::present(
            $contents,
            collect($matchingContents->items()),
            [
                'inbox' => $inbox,
                'status' => $status,
                'site' => $siteId,
                'author' => $authorId,
                'publish_status' => $publishStatus,
                'locale' => $resolvedLocale,
                'publication_state' => (string) ($filterState['publication_state'] ?? ''),
                'translation_state' => (string) ($filterState['translation_state'] ?? ''),
                'workflow_state' => (string) ($filterState['workflow_state'] ?? ''),
                'locale_scope' => (string) ($filterState['locale_scope'] ?? ''),
                'preset' => (string) ($filterState['preset'] ?? ''),
                'q' => $q,
                'sort' => $sort,
                'origin' => $origin,
                'series' => $seriesId,
                'automation' => $automationId,
                'created_from' => $createdFrom,
                'created_to' => $createdTo,
                'published_from' => $publishedFrom,
                'published_to' => $publishedTo,
                'show_deleted' => $showDeleted,
            ],
            $contentInsights
        );

        $sites = $performanceCache->rememberOrganization(
            'content-index-sites',
            $organizationId,
            [],
            now()->addMinutes(10),
            fn () => \App\Models\ClientSite::query()
                ->whereHas('workspace', fn ($query) => $query->where('organization_id', $organizationId))
                ->orderBy('name')
                ->get(['id', 'name'])
        );

        $authors = $performanceCache->rememberOrganization(
            'content-index-authors',
            $organizationId,
            [],
            now()->addMinutes(10),
            fn () => \App\Models\User::query()
                ->where('organization_id', $organizationId)
                ->orderBy('name')
                ->get(['id', 'name'])
        );

        $contentSeriesList = $performanceCache->rememberOrganization(
            'content-index-series',
            $organizationId,
            [],
            now()->addMinutes(10),
            fn () => ContentSeries::query()
                ->where('organization_id', $organizationId)
                ->orderBy('name')
                ->get(['id', 'name'])
        );

        $contentAutomations = $performanceCache->rememberOrganization(
            'content-index-automations',
            $organizationId,
            [],
            now()->addMinutes(10),
            fn () => ContentAutomation::query()
                ->where('organization_id', $organizationId)
                ->orderBy('name')
                ->get(['id', 'name'])
        );

        $filterCounts = $withFilterCounts
            ? $performanceCache->rememberOrganization(
                'content-index-filter-counts',
                $organizationId,
                ['show_deleted' => $showDeleted, 'filters' => $filterState],
                now()->addSeconds(120),
                fn (): array => $this->contentIndexFilterCounts($organizationId, $showDeleted, $filterState)
            )
            : [];

        $profiler?->logSummary([
            'page_items' => $matchingContents->count(),
            'family_contents' => $contents->count(),
            'family_roots' => $pageRootIds->count(),
        ]);

        return view('app.content.index', [
            'contents' => $matchingContents,
            'contentTree' => $contentTree,
            'sites' => $sites,
            'authors' => $authors,
            'createContentFormOpen' => $request->boolean('create'),
            'newContentDefaults' => [
                'site_id' => trim((string) $request->query('site_id', '')),
                'scheduled_publish_at' => $this->parseScheduledPublishAt($request->query('scheduled_publish_at'))?->format('Y-m-d\\TH:i'),
            ],
            'filters' => [
                'inbox' => $inbox,
                'status' => $status,
                'site' => $siteId,
                'author' => $authorId,
                'publish_status' => $publishStatus,
                'locale' => $resolvedLocale,
                'publication_state' => (string) ($filterState['publication_state'] ?? ''),
                'translation_state' => (string) ($filterState['translation_state'] ?? ''),
                'workflow_state' => (string) ($filterState['workflow_state'] ?? ''),
                'locale_scope' => (string) ($filterState['locale_scope'] ?? ''),
                'preset' => (string) ($filterState['preset'] ?? ''),
                'q' => $q,
                'sort' => $sort,
                'origin' => $origin,
                'series' => $seriesId,
                'automation' => $automationId,
                'created_from' => $createdFrom,
                'created_to' => $createdTo,
                'published_from' => $publishedFrom,
                'published_to' => $publishedTo,
                'show_deleted' => $showDeleted,
                'with_counts' => $withFilterCounts,
            ],
            'contentInsights' => $contentInsights,
            'localeOptions' => SupportedLanguage::cases(),
            'contentSeriesList' => $contentSeriesList,
            'contentAutomations' => $contentAutomations,
            'filterCounts' => $filterCounts,
            'sortOptions' => [
                'newest_created' => 'Newest created',
                'oldest_created' => 'Oldest created',
                'newest_published' => 'Recently published',
                'oldest_published' => 'Oldest published',
                'last_updated' => 'Last updated',
                'title_asc' => 'Title A-Z',
            ],
            'originOptions' => ContentOriginType::cases(),
            'publicationStateOptions' => $this->publicationStateOptions(),
            'translationStateOptions' => $this->translationStateOptions(),
            'workflowStateOptions' => $this->workflowStateOptions(),
            'localeScopeOptions' => $this->localeScopeOptions(),
            'quickFilterPresets' => $this->quickFilterPresets(),
        ]);
    }

    private function contentIndexBaseQuery(int $organizationId, bool $showDeleted): Builder
    {
        return Content::query()
            ->when($showDeleted, fn (Builder $query) => $query->withTrashed())
            ->where(function (Builder $query) use ($organizationId): void {
                $query->whereHas('workspace', fn (Builder $workspaceQuery) => $workspaceQuery->where('organization_id', $organizationId))
                    ->orWhereHas('clientSite.workspace', fn (Builder $workspaceQuery) => $workspaceQuery->where('organization_id', $organizationId));
            });
    }

    /**
     * @return list<string>
     */
    private function contentIndexSelectColumns(): array
    {
        return [
            'id',
            'workspace_id',
            'client_site_id',
            'content_destination_id',
            'series_id',
            'automation_id',
            'translation_source_content_id',
            'family_id',
            'title',
            'language',
            'is_source_locale',
            'status',
            'publish_status',
            'delivery_status',
            'publish_error',
            'wp_post_id',
            'origin_type',
            'primary_keyword',
            'published_url',
            'publish_url_key',
            'canonical_url_key',
            'scheduled_publish_at',
            'first_published_at',
            'created_by',
            'updated_at',
            'created_at',
            'deleted_at',
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function applyContentIndexFilters(Builder $query, array $filters): Builder
    {
        $status = trim((string) ($filters['status'] ?? ''));
        $inbox = trim((string) ($filters['inbox'] ?? ''));
        $siteId = trim((string) ($filters['site'] ?? ''));
        $authorId = trim((string) ($filters['author'] ?? ''));
        $publishStatus = trim((string) ($filters['publish_status'] ?? ''));
        $locale = trim((string) ($filters['locale'] ?? ''));
        $publicationState = trim((string) ($filters['publication_state'] ?? ''));
        $translationState = trim((string) ($filters['translation_state'] ?? ''));
        $workflowState = trim((string) ($filters['workflow_state'] ?? ''));
        $localeScope = trim((string) ($filters['locale_scope'] ?? ''));
        $q = trim((string) ($filters['q'] ?? ''));
        $origin = trim((string) ($filters['origin'] ?? ''));
        $seriesId = trim((string) ($filters['series'] ?? ''));
        $automationId = trim((string) ($filters['automation'] ?? ''));
        $createdFrom = trim((string) ($filters['created_from'] ?? ''));
        $createdTo = trim((string) ($filters['created_to'] ?? ''));
        $publishedFrom = trim((string) ($filters['published_from'] ?? ''));
        $publishedTo = trim((string) ($filters['published_to'] ?? ''));

        $query
            ->when($status === '', fn (Builder $builder) => $builder->where('status', '!=', 'archived'))
            ->when($inbox !== '', fn (Builder $builder) => $this->applyInboxFilter($builder, $inbox))
            ->when($status !== '', fn (Builder $builder) => $this->applyContentStatusFilter($builder, $status))
            ->when($siteId !== '', fn (Builder $builder) => $builder->where('client_site_id', $siteId))
            ->when($authorId !== '', fn (Builder $builder) => $builder->where('created_by', $authorId))
            ->when($publishStatus !== '', fn (Builder $builder) => $builder->where('publish_status', $publishStatus))
            ->when($locale !== '', function (Builder $builder) use ($locale): void {
                $builder->where(function (Builder $nested) use ($locale): void {
                    $nested->where('language', $locale)
                        ->orWhereHas('localizedVariants', fn (Builder $variantQuery) => $variantQuery->where('language', $locale))
                        ->orWhereHas('translationSourceContent', function (Builder $sourceQuery) use ($locale): void {
                            $sourceQuery->where('language', $locale)
                                ->orWhereHas('localizedVariants', fn (Builder $variantQuery) => $variantQuery->where('language', $locale));
                        });
                });
            })
            ->when($q !== '', function (Builder $builder) use ($q): void {
                $builder->where(function (Builder $nested) use ($q): void {
                    $nested->where('title', 'like', '%'.$q.'%')
                        ->orWhere('primary_keyword', 'like', '%'.$q.'%');
                });
            })
            ->when($origin !== '', fn (Builder $builder) => $builder->where('origin_type', $origin))
            ->when($seriesId !== '', fn (Builder $builder) => $builder->where('series_id', $seriesId))
            ->when($automationId !== '', fn (Builder $builder) => $builder->where('automation_id', $automationId))
            ->when($createdFrom !== '', fn (Builder $builder) => $builder->whereDate('created_at', '>=', $createdFrom))
            ->when($createdTo !== '', fn (Builder $builder) => $builder->whereDate('created_at', '<=', $createdTo))
            ->when($publishedFrom !== '', fn (Builder $builder) => $builder->whereDate('first_published_at', '>=', $publishedFrom))
            ->when($publishedTo !== '', fn (Builder $builder) => $builder->whereDate('first_published_at', '<=', $publishedTo));

        $this->applyPublicationStateFilter($query, $publicationState);
        $this->applyTranslationStateFilter($query, $translationState);
        $this->applyWorkflowStateFilter($query, $workflowState);
        $this->applyLocaleScopeFilter($query, $localeScope);

        return $query;
    }

    private function applyContentStatusFilter(Builder $query, string $status): Builder
    {
        if ($status === 'draft') {
            return $query->draftState();
        }

        return $query->where('status', $status);
    }

    private function applyContentIndexSort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'oldest_created' => $query->orderBy('created_at'),
            'newest_published' => $query->orderByDesc('first_published_at'),
            'oldest_published' => $query->orderBy('first_published_at'),
            'last_updated' => $query->orderByDesc('updated_at'),
            'title_asc' => $query->orderBy('title'),
            default => $query->orderByDesc('created_at'),
        };
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    private function mergeContentIndexPresetFilters(array $filters): array
    {
        $preset = trim((string) ($filters['preset'] ?? ''));

        return match ($preset) {
            'partially_published' => array_merge($filters, ['publication_state' => $filters['publication_state'] ?: 'partially_published']),
            'needs_translation' => array_merge($filters, ['translation_state' => $filters['translation_state'] ?: 'missing_translations']),
            'needs_refresh' => array_merge($filters, ['workflow_state' => $filters['workflow_state'] ?: 'refresh_recommended']),
            'failed_items' => array_merge($filters, ['publication_state' => $filters['publication_state'] ?: 'failed']),
            default => $filters,
        };
    }

    private function applyPublicationStateFilter(Builder $query, string $publicationState): void
    {
        match ($publicationState) {
            'draft' => $query->draftState(),
            'scheduled' => $query->scheduledState(),
            'publishing' => $query->publishingState(),
            'partially_published' => $query->partiallyPublished(),
            'fully_published' => $query->fullyPublished(),
            'failed' => $query->failedPublication(),
            'archived' => $query->archivedState(),
            default => null,
        };
    }

    private function applyTranslationStateFilter(Builder $query, string $translationState): void
    {
        match ($translationState) {
            'missing_translations' => $query->needsTranslation(),
            'partially_translated' => $query->partiallyTranslated(),
            'fully_translated' => $query->fullyTranslated(),
            'translation_failed' => $query->translationFailed(),
            'translation_processing' => $query->translationProcessing(),
            default => null,
        };
    }

    private function applyWorkflowStateFilter(Builder $query, string $workflowState): void
    {
        match ($workflowState) {
            'needs_review' => $query->needsReview(),
            'ai_improvements_pending' => $query->aiImprovementsPending(),
            'ai_improvements_generated' => $query->aiImprovementsGenerated(),
            'refresh_recommended' => $query->refreshRecommended(),
            'stale_content' => $query->staleContent(),
            'recently_updated' => $query->recentlyUpdated(),
            default => null,
        };
    }

    private function applyLocaleScopeFilter(Builder $query, string $localeScope): void
    {
        match ($localeScope) {
            'en_only' => $query->localeOnly(SupportedLanguage::EN->value),
            'nl_only' => $query->localeOnly(SupportedLanguage::NL->value),
            'missing_nl' => $query->missingLocale(SupportedLanguage::NL->value),
            'missing_en' => $query->missingLocale(SupportedLanguage::EN->value),
            'multi_locale_only' => $query->multiLocaleOnly(),
            default => null,
        };
    }

    private function countDistinctLocalizationRoots(Builder $query): int
    {
        $countQuery = clone $query;
        $table = $countQuery->getModel()->getTable();

        return (int) $countQuery
            ->reorder()
            ->selectRaw('COUNT(DISTINCT ' . Content::localizationRootExpression($table) . ') as aggregate')
            ->value('aggregate');
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,array<string,int>>
     */
    private function contentIndexFilterCounts(int $organizationId, bool $showDeleted, array $filters): array
    {
        $baseFilters = $filters;
        unset($baseFilters['publication_state'], $baseFilters['translation_state'], $baseFilters['workflow_state'], $baseFilters['locale_scope'], $baseFilters['preset']);

        $publicationBase = $this->applyContentIndexFilters($this->contentIndexBaseQuery($organizationId, $showDeleted), array_merge($baseFilters, [
            'publication_state' => '',
            'translation_state' => '',
            'workflow_state' => '',
            'locale_scope' => '',
            'preset' => '',
        ]));

        $translationBase = $this->applyContentIndexFilters($this->contentIndexBaseQuery($organizationId, $showDeleted), array_merge($baseFilters, [
            'publication_state' => (string) ($filters['publication_state'] ?? ''),
            'translation_state' => '',
            'workflow_state' => (string) ($filters['workflow_state'] ?? ''),
            'locale_scope' => (string) ($filters['locale_scope'] ?? ''),
            'preset' => '',
        ]));

        $workflowBase = $this->applyContentIndexFilters($this->contentIndexBaseQuery($organizationId, $showDeleted), array_merge($baseFilters, [
            'publication_state' => (string) ($filters['publication_state'] ?? ''),
            'translation_state' => (string) ($filters['translation_state'] ?? ''),
            'workflow_state' => '',
            'locale_scope' => (string) ($filters['locale_scope'] ?? ''),
            'preset' => '',
        ]));

        $localeBase = $this->applyContentIndexFilters($this->contentIndexBaseQuery($organizationId, $showDeleted), array_merge($baseFilters, [
            'publication_state' => (string) ($filters['publication_state'] ?? ''),
            'translation_state' => (string) ($filters['translation_state'] ?? ''),
            'workflow_state' => (string) ($filters['workflow_state'] ?? ''),
            'locale_scope' => '',
            'preset' => '',
        ]));

        $publicationCounts = [];
        foreach (array_keys($this->publicationStateOptions()) as $state) {
            $query = clone $publicationBase;
            $this->applyPublicationStateFilter($query, $state);
            $publicationCounts[$state] = $this->countDistinctLocalizationRoots($query);
        }

        $translationCounts = [];
        foreach (array_keys($this->translationStateOptions()) as $state) {
            $query = clone $translationBase;
            $this->applyTranslationStateFilter($query, $state);
            $translationCounts[$state] = $this->countDistinctLocalizationRoots($query);
        }

        $workflowCounts = [];
        foreach (array_keys($this->workflowStateOptions()) as $state) {
            $query = clone $workflowBase;
            $this->applyWorkflowStateFilter($query, $state);
            $workflowCounts[$state] = $this->countDistinctLocalizationRoots($query);
        }

        $localeCounts = [];
        foreach (array_keys($this->localeScopeOptions()) as $state) {
            $query = clone $localeBase;
            $this->applyLocaleScopeFilter($query, $state);
            $localeCounts[$state] = $this->countDistinctLocalizationRoots($query);
        }

        $presetCounts = [];
        foreach ($this->quickFilterPresets() as $presetKey => $presetConfig) {
            $query = $this->applyContentIndexFilters(
                $this->contentIndexBaseQuery($organizationId, $showDeleted),
                $this->mergeContentIndexPresetFilters(array_merge($filters, [
                    'preset' => $presetKey,
                ]))
            );
            $presetCounts[$presetKey] = $this->countDistinctLocalizationRoots($query);
        }

        return [
            'publication' => $publicationCounts,
            'translation' => $translationCounts,
            'workflow' => $workflowCounts,
            'locale_scope' => $localeCounts,
            'presets' => $presetCounts,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function publicationStateOptions(): array
    {
        return [
            'draft' => 'Draft',
            'scheduled' => 'Scheduled',
            'publishing' => 'Publishing',
            'partially_published' => 'Partially published',
            'fully_published' => 'Fully published',
            'failed' => 'Failed',
            'archived' => 'Archived',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function translationStateOptions(): array
    {
        return [
            'missing_translations' => 'Missing translations',
            'partially_translated' => 'Partially translated',
            'fully_translated' => 'Fully translated',
            'translation_failed' => 'Translation failed',
            'translation_processing' => 'Translation processing',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function workflowStateOptions(): array
    {
        return [
            'needs_review' => 'Needs review',
            'ai_improvements_pending' => 'AI improvements pending',
            'ai_improvements_generated' => 'AI improvements generated',
            'refresh_recommended' => 'Refresh recommended',
            'stale_content' => 'Stale content',
            'recently_updated' => 'Recently updated',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function localeScopeOptions(): array
    {
        return [
            'en_only' => 'EN only',
            'nl_only' => 'NL only',
            'missing_nl' => 'Missing NL',
            'missing_en' => 'Missing EN',
            'multi_locale_only' => 'Multi locale only',
        ];
    }

    /**
     * @return array<string,array{label:string}>
     */
    private function quickFilterPresets(): array
    {
        return [
            'partially_published' => ['label' => 'Partially published'],
            'needs_translation' => ['label' => 'Needs translation'],
            'needs_refresh' => ['label' => 'Needs refresh'],
            'failed_items' => ['label' => 'Failed items'],
        ];
    }

    public function store(
        Request $request,
        WorkspaceEntitlementsService $entitlements,
        BrandGenerationDefaultsService $brandGenerationDefaults,
    ): RedirectResponse
    {
        $this->authorize('create', Content::class);

        $organizationId = (int) $request->user()->organization_id;

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'primary_keyword' => ['nullable', 'string', 'max:255'],
            'site_id' => ['nullable', 'uuid'],
            'scheduled_publish_at' => ['nullable', 'date'],
        ]);

        $siteQuery = ClientSite::query()
            ->whereHas('workspace', fn ($query) => $query->where('organization_id', $organizationId))
            ->where('is_active', true)
            ->where('status', '!=', 'disabled')
            ->orderBy('name');

        $site = filled($data['site_id'] ?? null)
            ? (clone $siteQuery)->whereKey((string) $data['site_id'])->first()
            : (clone $siteQuery)->first();

        if (! $site) {
            return back()->withInput()->withErrors([
                'site_id' => 'Select an active site before creating content.',
            ]);
        }

        $site->loadMissing('workspace.organization.personas', 'workspace.organization.teamMembers', 'workspace.brandVoices');
        $generationDefaults = $brandGenerationDefaults->forWorkspace($site->workspace);
        $defaultLocale = $site->workspace?->defaultContentLanguageCode() ?? SupportedLanguage::EN->value;

        try {
            $entitlements->consumeBriefQuota($site->workspace);
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['site_id' => $exception->getMessage()]);
        }

        $scheduledAt = $this->parseScheduledPublishAt($data['scheduled_publish_at'] ?? null);

        $content = Content::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $site->workspace_id,
            'client_site_id' => (string) $site->id,
            'title' => (string) $data['title'],
            'language' => $defaultLocale,
            'translation_source_locale' => null,
            'is_source_locale' => true,
            'primary_keyword' => (string) ($data['primary_keyword'] ?? ''),
            'type' => 'article',
            'status' => 'brief',
            'source' => 'manual',
            'origin_type' => ContentOriginType::MANUAL->value,
            'external_key' => (string) Str::uuid(),
            'scheduled_publish_at' => $scheduledAt,
            'publish_status' => $scheduledAt ? 'scheduled' : 'draft',
            'publish_error' => null,
            'generation_mode' => 'balanced',
            'brand_voice_id' => $generationDefaults['brand_voice_id'],
            'buyer_persona_id' => $generationDefaults['buyer_persona_id'],
            'team_member_id' => $generationDefaults['team_member_id'],
            'preferred_length' => 'medium',
            'created_by' => (int) $request->user()->id,
            'updated_by' => (int) $request->user()->id,
        ]);

        // Build default brief structure for draft generation
        $briefBuilder = app(BriefDefaultBuilder::class);
        $title = (string) $data['title'];
        $keyword = (string) ($data['primary_keyword'] ?? '') ?: $title;
        $briefDefaults = $briefBuilder->build($title, $keyword, $generationDefaults['audience_persona']);

        $brief = Brief::query()->create([
            'client_site_id' => (string) $site->id,
            'created_by_user_id' => (int) $request->user()->id,
            'content_id' => (string) $content->id,
            'status' => 'draft',
            'source' => 'client_ui',
            'title' => $title,
            'language' => $defaultLocale,
            'content_type' => 'blog',
            'output_type' => 'kb_article',
            'primary_keyword' => $keyword !== $title ? $keyword : null,
            'intent' => $briefDefaults['intent']['type'],
            'audience' => $briefDefaults['audience']['persona'],
            'funnel_stage' => $briefDefaults['search_context']['stage'],
            'search_intent' => 'informational',
            'progress' => 0,
            'client_refs' => [
                'client_type' => 'client_ui',
                'site_url' => (string) ($site->site_url ?? ''),
                'taxonomy' => [
                    'intent_keys' => $briefDefaults['intent']['keys'],
                    'audience_keys' => [],
                ],
                'brand_voice_id' => $generationDefaults['brand_voice_id'],
                'buyer_persona_id' => $generationDefaults['buyer_persona_id'],
                'team_member_id' => $generationDefaults['team_member_id'],
                'preferred_length' => 'medium',
                'brief_defaults_applied' => true,
            ],
            'wp_site_id' => (string) $site->id,
        ]);

        return redirect()
            ->route('app.content.workspace.show', $brief)
            ->with('status', 'New content created. Continue in the content workspace.');
    }

    public function calendar(Request $request): View
    {
        $this->authorize('viewAny', Content::class);

        $organizationId = (int) $request->user()->organization_id;
        $selectedSiteId = trim((string) $request->query('site', ''));
        $mode = (string) $request->query('mode', 'month');
        if (! in_array($mode, ['day', 'week', 'month'], true)) {
            $mode = 'month';
        }
        $showWeekNumbers = $this->resolveCalendarShowWeekNumbers($request);

        $anchor = $this->resolveCalendarAnchor($request->query('date'));
        $selectedDateInput = $mode === 'day'
            ? ($request->query('selected_date') ?: $request->query('date'))
            : $request->query('selected_date');

        if ($mode === 'day') {
            $rangeStart = $anchor->copy()->startOfDay();
            $rangeEnd = $anchor->copy()->endOfDay();
            $selectedDate = $this->resolveCalendarSelectedDate($selectedDateInput, $rangeStart, $rangeEnd) ?? $anchor->copy()->startOfDay();
        } elseif ($mode === 'week') {
            $rangeStart = $anchor->copy()->startOfWeek(Carbon::MONDAY);
            $rangeEnd = $anchor->copy()->endOfWeek(Carbon::SUNDAY);
            $selectedDate = $this->resolveCalendarSelectedDate($selectedDateInput, $rangeStart, $rangeEnd);
        } else {
            $rangeStart = $anchor->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
            $rangeEnd = $anchor->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
            $selectedDate = $this->resolveCalendarSelectedDate($selectedDateInput, $rangeStart, $rangeEnd);
        }
        $canCreate = $request->user()->can('create', Content::class);

        $sites = ClientSite::query()
            ->whereHas('workspace', fn ($query) => $query->where('organization_id', $organizationId))
            ->where('is_active', true)
            ->where('status', '!=', 'disabled')
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        $successfulPublicationStatuses = $this->calendarSuccessfulPublicationStatuses();
        $successfulDraftStatuses = $this->calendarSuccessfulDraftStatuses();
        $supportsExplicitPublishedAt = $this->calendarSupportsExplicitPublishedAt();

        $items = Content::query()
            ->with([
                'brief:id,content_id',
                'clientSite:id,name,type,workspace_id',
                'publications' => fn ($query) => $query
                    ->orderByRaw("CASE delivery_status WHEN 'delivered' THEN 1 WHEN 'partial_success' THEN 2 ELSE 3 END")
                    ->orderBy('last_delivered_at')
                    ->orderBy('created_at'),
            ])
            // Earliest successful delivery is the canonical published date for calendar history.
            ->withMin([
                'publications as first_successful_publication_at' => fn ($query) => $query
                    ->whereIn('delivery_status', $successfulPublicationStatuses)
                    ->whereNotNull('last_delivered_at'),
            ], 'last_delivered_at')
            ->withMin([
                'drafts as first_delivered_draft_at' => fn ($query) => $query
                    ->whereIn('delivery_status', $successfulDraftStatuses)
                    ->whereNotNull('delivered_at'),
            ], 'delivered_at')
            ->where(function ($query) use ($organizationId): void {
                $query->whereHas('workspace', fn ($workspaceQuery) => $workspaceQuery->where('organization_id', $organizationId))
                    ->orWhereHas('clientSite.workspace', fn ($workspaceQuery) => $workspaceQuery->where('organization_id', $organizationId));
            })
            ->when($selectedSiteId !== '', fn ($query) => $query->where('client_site_id', $selectedSiteId))
            ->where(function (Builder $query) use ($rangeStart, $rangeEnd, $successfulPublicationStatuses, $successfulDraftStatuses, $supportsExplicitPublishedAt): void {
                $query->whereBetween('scheduled_publish_at', [$rangeStart, $rangeEnd])
                    ->orWhereHas('publications', fn (Builder $publicationQuery) => $publicationQuery
                        ->whereIn('delivery_status', $successfulPublicationStatuses)
                        ->whereBetween('last_delivered_at', [$rangeStart, $rangeEnd]))
                    ->orWhereHas('drafts', fn (Builder $draftQuery) => $draftQuery
                        ->whereIn('delivery_status', $successfulDraftStatuses)
                        ->whereBetween('delivered_at', [$rangeStart, $rangeEnd]));

                if ($supportsExplicitPublishedAt) {
                    $query->orWhereBetween('published_at', [$rangeStart, $rangeEnd]);
                }
            })
            ->get();

        $itemsByDate = $items
            ->map(fn (Content $content) => $this->presentCalendarItem($request, $content))
            ->filter(fn (?array $item) => $item !== null)
            ->filter(fn (array $item) => $this->calendarItemFallsInRange($item, $rangeStart, $rangeEnd))
            ->sortBy(fn (array $item) => sprintf('%010d-%s', $item['calendar_timestamp'], Str::lower((string) $item['title'])))
            ->groupBy('date_key');

        $dates = collect(CarbonPeriod::create($rangeStart, '1 day', $rangeEnd))
            ->map(fn (Carbon $date) => $date->copy());
        $today = now()->startOfDay();

        $days = $dates->map(function (Carbon $date) use ($request, $anchor, $itemsByDate, $selectedDate, $selectedSiteId, $mode, $canCreate, $today, $showWeekNumbers) {
            $key = $date->format('Y-m-d');
            $items = collect($itemsByDate->get($key, collect()))->values();
            $isPast = $date->lt($today);

            return [
                'key' => $key,
                'label' => $date->format('D d M'),
                'full_label' => $date->format('l, j F Y'),
                'day_number' => $date->format('j'),
                'weekday' => $date->format('D'),
                'month' => $date->format('M'),
                'is_today' => $date->isToday(),
                'is_past' => $isPast,
                'is_future' => $date->gt($today),
                'is_in_anchor_month' => $date->isSameMonth($anchor),
                'is_selected' => $selectedDate?->isSameDay($date) ?? false,
                'items' => $items->all(),
                'preview_items' => $items->take(2)->values()->all(),
                'overflow_item_count' => max(0, $items->count() - 2),
                'item_count' => $items->count(),
                'open_url' => $this->calendarRoute($anchor, $mode, $selectedSiteId, $key, $showWeekNumbers),
                'create_url' => $canCreate && ! $isPast ? $this->calendarCreateUrl($date, $selectedSiteId) : null,
                'create_label' => $items->isNotEmpty() ? 'Add content' : 'Create content',
            ];
        })->values();

        $weekRows = $days->chunk(7)->map(function (\Illuminate\Support\Collection $week, int $index): array {
            $firstDay = Carbon::parse((string) data_get($week->first(), 'key'));

            return [
                'index' => $index,
                'week_number' => $firstDay->isoWeek(),
                'days' => $week->values()->all(),
            ];
        })->values();

        $selectedDay = $selectedDate
            ? collect($days)->firstWhere('key', $selectedDate->format('Y-m-d'))
            : null;

        // Series for sidebar dropdown
        $series = ContentSeries::query()
            ->where('organization_id', $organizationId)
            ->whereNotIn('status', [ContentSeries::STATUS_ARCHIVED])
            ->orderBy('name')
            ->get(['id', 'name']);

        // Calculate stats
        $stats = $this->calculateCalendarStats($days, $today);

        return view('app.content.calendar', [
            'mode' => $mode,
            'anchor' => $anchor,
            'rangeStart' => $rangeStart,
            'rangeEnd' => $rangeEnd,
            'days' => $days,
            'weekRows' => $weekRows,
            'selectedDay' => $selectedDay,
            'selectedDate' => $selectedDate,
            'selectedSiteId' => $selectedSiteId,
            'showWeekNumbers' => $showWeekNumbers,
            'sites' => $sites,
            'canCreateContent' => $canCreate,
            'closeSelectedDayUrl' => $this->calendarRoute($anchor, $mode, $selectedSiteId, null, $showWeekNumbers),
            // New data for sidebar
            'series' => $series,
            'contentTypes' => [
                'article' => 'Artikel',
                'page' => 'Pagina',
                'post' => 'Blog post',
            ],
            'intentOptions' => ContentIntentCatalog::options(),
            'stats' => $stats,
        ]);
    }

    private function resolveCalendarShowWeekNumbers(Request $request): bool
    {
        $default = (bool) config('argusly.calendar.show_week_numbers', false);

        if (! $request->has('week_numbers')) {
            return $default;
        }

        return $request->boolean('week_numbers');
    }

    public function show(
        Request $request,
        string $content,
        CreditWalletService $creditWalletService,
        ContentPerformanceInsightService $contentPerformanceInsightService,
        WordPressPublicationDestinationResolver $wordPressDestinationResolver,
        LaravelConnectorDestinationResolver $laravelDestinationResolver,
        ImagePresetService $imagePresetService,
        FeatureGate $featureGate,
        ContentLocalizationService $contentLocalizationService,
        ContentTranslationCoordinator $contentTranslationCoordinator,
        TranslationDebugService $translationDebugService,
        ?\App\Services\Content\LocaleMismatchService $localeMismatchService = null,
    ): View|RedirectResponse
    {
        $content = $this->resolveContentFromIdentifier($content);
        $requestedTab = (string) $request->query('tab', 'overview');

        $content->load([
            'workspace.brandVoices',
            'workspace.writerProfiles',
            'workspace.organization.brandVoices',
            'workspace.organization.personas' => fn ($query) => $query->where('status', Persona::STATUS_APPROVED),
            'workspace.organization.teamMembers' => fn ($query) => $query->where('is_active', true),
            'clientSite',
            'clientSite.analyticsSite',
            'versions' => fn ($query) => $query->latest(),
            'currentVersion',
            'briefVersion',
            'draftVersion',
            'brief.drafts' => fn ($query) => $query->latest('created_at')->limit(1),
            'drafts' => fn ($query) => $query->latest(),
            'images',
            'featuredImage',
            'ogImage',
            'chainGuidance',
            'series',
            'seriesArticle',
            'answerBlocks',
        ]);

        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('view', $content);

        if ($requestedTab === 'brief') {
            $workspaceBrief = $content->brief()->first();
            if ($workspaceBrief) {
                return redirect()->route('app.content.workspace.brief', $workspaceBrief);
            }
        }

        $activity = Event::query()
            ->where('client_site_id', $content->client_site_id)
            ->where(function ($query) use ($content): void {
                $query->where('type', 'like', 'content.%')
                    ->orWhere('data->content_id', $content->id);
            })
            ->latest('occurred_at')
            ->limit(50)
            ->get();

        $legacyBrief = $content->brief;
        $legacyBriefLatestDraft = $legacyBrief?->drafts->first();
        $legacyDraft = $content->drafts->first();
        $latestDraft = $content->drafts->first();
        $generationDraft = Draft::query()
            ->where('content_id', $content->id)
            ->latest('created_at')
            ->first();
        $creditSummary = null;
        if ($generationDraft?->client_site_id) {
            $creditSummary = $creditWalletService->getSummary((string) $generationDraft->client_site_id);
        }
        $organization = $content->workspace?->organization;

        // Use eager loaded relationships and sort in memory to avoid N+1 queries
        $orgVoices = ($organization?->brandVoices ?? collect())
            ->sortByDesc('is_default')
            ->sortBy('name');

        $workspaceVoices = ($content->workspace?->brandVoices ?? collect())
            ->sortByDesc('is_default')
            ->sortBy('name');

        $brandVoices = $orgVoices
            ->merge($workspaceVoices)
            ->unique('id')
            ->values();
        $writerProfiles = ($content->workspace?->writerProfiles ?? collect())
            ->where('status', \App\Models\WriterProfile::STATUS_ACTIVE)
            ->sortBy('name')
            ->values();

        // Team members are eager loaded with is_active filter, just sort in memory
        $teamMembers = ($organization?->teamMembers ?? collect())
            ->sortBy('name')
            ->values();
        $buyerPersonas = ($organization?->personas ?? collect())
            ->sortBy('name')
            ->sortBy('type')
            ->values();
        $wordpressSites = collect();
        if ($organization) {
            $wordpressSites = $organization->clientSites()
                ->where('client_sites.type', ClientSite::TYPE_WORDPRESS)
                ->where('client_sites.is_active', true)
                ->where('client_sites.status', '!=', 'disabled')
                ->orderBy('client_sites.name')
                ->get(['client_sites.id', 'client_sites.name', 'client_sites.base_url', 'client_sites.site_url']);
        }

        $featuredImageHistory = ContentImage::query()
            ->where('content_id', $content->id)
            ->where('type', ImageGenerationService::FEATURED_TYPE)
            ->latest('created_at')
            ->limit(12)
            ->get();

        $ogImageHistory = ContentImage::query()
            ->where('content_id', $content->id)
            ->where('type', ImageGenerationService::OG_TYPE)
            ->latest('created_at')
            ->limit(12)
            ->get();

        $contentImageAssets = ContentImage::query()
            ->where('content_id', $content->id)
            ->whereIn('type', [
                ImageGenerationService::FEATURED_TYPE,
                ImageGenerationService::OG_TYPE,
                'social',
            ])
            ->latest('created_at')
            ->limit(24)
            ->get();
        $linkedContentImageAssets = $this->linkedLocaleImageAssets($content);

        $contentInsight = $contentPerformanceInsightService->forContent($content);
        $contentNetworkEntitlement = $featureGate->value($content->workspace, 'content_network_analysis_enabled', false);
        $contentChainEnabled = (bool) config('features.content_network_analysis', false)
            && ! in_array(strtolower(trim((string) $contentNetworkEntitlement)), ['', '0', 'false', 'off', 'no'], true);
        $chainSuggestions = collect();

        if ($contentChainEnabled) {
            $chainSuggestions = $content->outboundChainSuggestions()
                ->with(['targetContent:id,title,published_url', 'generatedContent:id,title', 'contentCluster:id,name'])
                ->orderByDesc('score')
                ->orderBy('title')
                ->get();
        }

        $destination = $content->contentDestination;
        $siteDestinationType = ContentDestinationType::fromNormalized($content->clientSite?->type);

        if (! $destination && $siteDestinationType === ContentDestinationType::WORDPRESS) {
            $destination = $wordPressDestinationResolver->resolveForContent($content, $latestDraft);
        }

        if (! $destination && $siteDestinationType === ContentDestinationType::LARAVEL) {
            $destination = $laravelDestinationResolver->resolveForContent($content);
        }

        $isWordPressSite = $destination?->isWordPressDestination()
            ?? false;

        if (! $isWordPressSite) {
            $isWordPressSite = $siteDestinationType === ContentDestinationType::WORDPRESS;
        }

        $laravelDestination = $destination?->isLaravelConnector()
            ? $destination
            : (! $destination ? $laravelDestinationResolver->resolveForContent($content) : null);
        $laravelPublication = null;
        $laravelPublishTarget = null;
        $latestLaravelSyncAttempt = null;
        $recentLaravelSyncAttempts = collect();
        $localizedContentSource = $contentLocalizationService->source($content);
        $localizedContentSource->loadMissing('translationRequests');
        $localizedContentStatuses = collect($contentLocalizationService->statusMatrix($content));
        $localizedTranslationTargets = $contentTranslationCoordinator->targetLocales($content);
        $translationDebugger = $translationDebugService->contentDebugger($localizedContentSource);
        $contentImprovementService = app(ContentImprovementService::class);
        $contentImprovementDashboard = $contentImprovementService->dashboard($content);
        $contentImprovementOptions = $this->resolveContentImprovementOptions($content, $contentImprovementDashboard, $contentImprovementService);
        // Get published locales from status matrix for filtering invalid cross-locale redirects
        $publishedLocales = $localizedContentStatuses
            ->filter(fn ($status) => $status['is_published'] ?? false)
            ->pluck('locale')
            ->all();

        $legacyLocaleRedirects = MarketingBlogRedirect::query()
            ->active()
            ->where('target_content_id', (string) ($localizedContentSource?->id ?? $content->id))
            ->where('redirect_kind', 'legacy_locale_mismatch')
            ->orderBy('source_path')
            ->get()
            ->filter(function (MarketingBlogRedirect $redirect) use ($publishedLocales): bool {
                // Keep same-locale redirects (slug changes) - always valid
                if ($redirect->source_locale === $redirect->target_locale) {
                    return true;
                }
                // Hide cross-locale redirects if source_locale has a published variant
                return ! in_array($redirect->source_locale, $publishedLocales, true);
            });

        $showLegacyLocaleRedirects = $legacyLocaleRedirects->isNotEmpty()
            && (string) ($localizedContentSource?->id ?? $content->id) === (string) $content->id;
        $refreshRecommendationsRun = $this->resolveSelectedRefreshRecommendationsRun($request, $content);
        $internalLinkingRun = $this->resolveSelectedInternalLinkingRun($request, $content);
        $localizationRun = $this->resolveSelectedLocalizationRun($request, $content);
        $hasLocalization = $content->localizationRecommendations()->exists();
        $hasRefresh = $content->refreshRecommendations()->exists();
        $hasLinks = $content->internalLinkSuggestions()->exists();
        $hasInsights = $hasLocalization || $hasRefresh || $hasLinks;
        $hasLocalizationResults = $this->hasLocalizationFindings($localizationRun);
        $hasRefreshResults = $this->hasRefreshFindings($refreshRecommendationsRun);
        $hasLinksResults = $this->hasInternalLinkFindings($internalLinkingRun);
        $hasAnyInsightResults = $hasLocalizationResults || $hasRefreshResults || $hasLinksResults;
        $localizationStatusLabel = $this->resolveInsightStatusLabel($hasLocalization, $hasLocalizationResults);
        $refreshStatusLabel = $this->resolveInsightStatusLabel($hasRefresh, $hasRefreshResults);
        $linksStatusLabel = $this->resolveInsightStatusLabel($hasLinks, $hasLinksResults);
        $localizationStatusClass = $this->resolveInsightStatusClass($localizationStatusLabel);
        $refreshStatusClass = $this->resolveInsightStatusClass($refreshStatusLabel);
        $linksStatusClass = $this->resolveInsightStatusClass($linksStatusLabel);
        $contentHealthScore = $this->calculateContentHealthScore(
            $hasLocalization,
            $hasLocalizationResults,
            $hasRefresh,
            $hasRefreshResults,
            $hasLinks,
            $hasLinksResults,
        );
        $localizationSummary = $hasLocalizationResults
            ? $this->resolveInsightSummary($localizationRun, 'Locale consistency checks found items that may need review.')
            : null;
        $refreshSummary = $hasRefreshResults
            ? $this->resolveInsightSummary($refreshRecommendationsRun, 'Freshness signals suggest this content may benefit from an update.')
            : null;
        $linksSummary = $hasLinksResults
            ? $this->resolveInsightSummary($internalLinkingRun, 'Relevant same-site internal linking opportunities were found.')
            : null;
        $selectedInsight = $this->resolveSelectedContentInsight(
            $request,
            $hasLocalizationResults,
            $hasRefreshResults,
            $hasLinksResults,
        );

        // Load organization-scoped image presets for the Images tab dropdown
        $imagePresets = $organization
            ? $imagePresetService->getPresetOptions((int) $organization->id)
            : [];
        $stockImageQuery = trim((string) $request->query('stock_image_query', ''));
        $stockImageResults = [];
        $stockImageSearchError = null;
        $unsplashImageService = app(UnsplashImageService::class);

        if ($requestedTab === 'images' && $stockImageQuery !== '') {
            try {
                $stockImageResults = $unsplashImageService->search($stockImageQuery);
            } catch (Throwable $exception) {
                $stockImageSearchError = $exception->getMessage();
            }
        }

        // Locale mismatch detection
        $localeMismatchAnalysis = $localeMismatchService?->analyze($content);
        $indexationDiagnostics = app(ContentIndexationHealthService::class)->evaluate($content);

        if ($laravelDestination) {
            $laravelPublication = ContentPublication::query()
                ->where('content_id', (string) $content->id)
                ->where('destination_id', (string) $laravelDestination->id)
                ->where('provider', ContentPublication::PROVIDER_LARAVEL)
                ->latest('updated_at')
                ->first();

            $laravelPublishTarget = ContentPublishTarget::query()
                ->where('content_id', (string) $content->id)
                ->where('content_destination_id', (string) $laravelDestination->id)
                ->where('target_type', 'laravel_connector')
                ->latest('created_at')
                ->first();

            $latestLaravelSyncAttempt = $laravelPublishTarget?->syncAttempts()
                ->latest('created_at')
                ->first();

            $recentLaravelSyncAttempts = $laravelPublishTarget?->syncAttempts()
                ->latest('created_at')
                ->limit(5)
                ->get() ?? collect();
        }

        $availablePublishingDestinations = ContentDestination::query()
            ->where('workspace_id', (string) $content->workspace_id)
            ->where('status', 'active')
            ->whereIn('type', [
                ContentDestinationType::WORDPRESS->value,
                ContentDestinationType::LARAVEL->value,
            ])
            ->orderBy('name')
            ->get(['id', 'workspace_id', 'name', 'type', 'status', 'config']);
        $currentPublishingSite = $content->clientSite;
        $usesImplicitPublishingSite = ! $content->contentDestination
            && in_array(ContentDestinationType::fromNormalized($currentPublishingSite?->type)?->value, [
                ContentDestinationType::WORDPRESS->value,
                ContentDestinationType::LARAVEL->value,
            ], true);

        return view('app.content.show', [
            'content' => $content,
            'activeTab' => $requestedTab,
            'activity' => $activity,
            'legacyBrief' => $legacyBrief,
            'legacyBriefLatestDraft' => $legacyBriefLatestDraft,
            'legacyDraft' => $legacyDraft,
            'generationDraft' => $generationDraft,
            'creditSummary' => $creditSummary,
            'brandVoices' => $brandVoices,
            'writerProfiles' => $writerProfiles,
            'buyerPersonas' => $buyerPersonas,
            'teamMembers' => $teamMembers,
            'featuredImage' => $content->featuredImage,
            'featuredImageHistory' => $featuredImageHistory,
            'ogImage' => $content->ogImage,
            'ogImageHistory' => $ogImageHistory,
            'contentImageAssets' => $contentImageAssets,
            'linkedContentImageAssets' => $linkedContentImageAssets,
            'hasWpImagePushConnection' => $this->hasWpImagePushConnection($content, $latestDraft),
            'wordpressSites' => $wordpressSites,
            'contentInsight' => $contentInsight,
            'destination' => $destination,
            'availablePublishingDestinations' => $availablePublishingDestinations,
            'currentPublishingSite' => $currentPublishingSite,
            'usesImplicitPublishingSite' => $usesImplicitPublishingSite,
            'isWordPressSite' => $isWordPressSite,
            'laravelDestination' => $laravelDestination,
            'laravelPublication' => $laravelPublication,
            'laravelPublishTarget' => $laravelPublishTarget,
            'latestLaravelSyncAttempt' => $latestLaravelSyncAttempt,
            'recentLaravelSyncAttempts' => $recentLaravelSyncAttempts,
            'imagePresets' => $imagePresets,
            'stockImagesConfigured' => $unsplashImageService->isConfigured(),
            'stockImageQuery' => $stockImageQuery,
            'stockImageResults' => $stockImageResults,
            'stockImageSearchError' => $stockImageSearchError,
            'contentChainEnabled' => $contentChainEnabled,
            'chainGuidance' => $content->chainGuidance,
            'chainSuggestions' => $chainSuggestions,
            'localizedContentSource' => $localizedContentSource,
            'localizedContentStatuses' => $localizedContentStatuses,
            'localizedTranslationTargets' => $localizedTranslationTargets,
            'translationDebugger' => $translationDebugger,
            'contentImprovementDashboard' => $contentImprovementDashboard,
            'contentImprovementOptions' => $contentImprovementOptions,
            'legacyLocaleRedirects' => $legacyLocaleRedirects,
            'showLegacyLocaleRedirects' => $showLegacyLocaleRedirects,
            'refreshRecommendationsRun' => $refreshRecommendationsRun,
            'internalLinkingRun' => $internalLinkingRun,
            'localizationRun' => $localizationRun,
            'hasLocalization' => $hasLocalization,
            'hasRefresh' => $hasRefresh,
            'hasLinks' => $hasLinks,
            'hasInsights' => $hasInsights,
            'hasLocalizationResults' => $hasLocalizationResults,
            'hasRefreshResults' => $hasRefreshResults,
            'hasLinksResults' => $hasLinksResults,
            'hasAnyInsightResults' => $hasAnyInsightResults,
            'contentHealthScore' => $contentHealthScore,
            'localizationStatusLabel' => $localizationStatusLabel,
            'refreshStatusLabel' => $refreshStatusLabel,
            'linksStatusLabel' => $linksStatusLabel,
            'localizationStatusClass' => $localizationStatusClass,
            'refreshStatusClass' => $refreshStatusClass,
            'linksStatusClass' => $linksStatusClass,
            'localizationSummary' => $localizationSummary,
            'refreshSummary' => $refreshSummary,
            'linksSummary' => $linksSummary,
            'selectedInsight' => $selectedInsight,
            'localeMismatchAnalysis' => $localeMismatchAnalysis,
            'indexationDiagnostics' => $indexationDiagnostics,
        ]);
    }

    private function linkedLocaleImageAssets(Content $content)
    {
        $variantIds = $content->normalizedLocalizationFamily()
            ->reject(fn (Content $variant): bool => (string) $variant->id === (string) $content->id)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();

        if ($variantIds === []) {
            return collect();
        }

        return ContentImage::query()
            ->with(['content:id,title,language,publish_url_key,workspace_id,translation_source_content_id,family_id'])
            ->whereIn('content_id', $variantIds)
            ->whereIn('type', [
                ImageGenerationService::FEATURED_TYPE,
                ImageGenerationService::OG_TYPE,
                'social',
            ])
            ->where('status', 'ready')
            ->latest('created_at')
            ->limit(24)
            ->get();
    }

    public function runRefreshRecommendations(
        Request $request,
        string $content,
        RunAgentForContent $runAgentForContent,
    ): RedirectResponse {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('runAgent', $content);

        $run = $runAgentForContent->execute($content, $request->user());

        return redirect()
            ->route('app.content.show', [
                'content' => $content,
                'tab' => 'overview',
                'refresh_recommendations_run' => $run->id,
            ])
            ->with('status', 'Refresh recommendations updated.');
    }

    public function runLocalization(
        Request $request,
        string $content,
        RunLocalizationForContent $runLocalizationForContent,
    ): RedirectResponse {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('runAgent', $content);

        $run = $runLocalizationForContent->execute($content, $request->user());

        return redirect()
            ->route('app.content.show', [
                'content' => $content,
                'tab' => (string) $request->input('tab', 'overview'),
                'localization_run' => $run->id,
            ])
            ->with('status', 'Localization recommendations updated.');
    }

    public function createRefreshDraft(
        Request $request,
        string $content,
        CreateRefreshDraft $createRefreshDraft,
    ): RedirectResponse {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('generateDraft', $content);

        $data = $request->validate([
            'agent_run_id' => ['required', 'uuid'],
        ]);

        $run = AgentRun::query()
            ->whereKey((string) $data['agent_run_id'])
            ->where('agent_key', ContentRefreshAgent::KEY)
            ->where('content_id', (string) $content->id)
            ->firstOrFail();

        try {
            $draft = $createRefreshDraft->execute($content, $run, $request->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['refresh_recommendations' => $exception->getMessage()]);
        }

        return redirect()
            ->route('app.drafts.show', ['draft' => $draft, 'tab' => 'draft'])
            ->with('status', 'Refresh draft created.');
    }

    public function runInternalLinking(
        Request $request,
        string $content,
        RunInternalLinkingForContent $runInternalLinkingForContent,
    ): RedirectResponse {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('runAgent', $content);

        $run = $runInternalLinkingForContent->execute($content, $request->user());

        return redirect()
            ->route('app.content.show', [
                'content' => $content,
                'tab' => (string) $request->input('tab', 'overview'),
                'internal_linking_run' => $run->id,
            ])
            ->with('status', 'Suggested internal links updated.');
    }

    public function applyInternalLinkSuggestion(
        Request $request,
        string $content,
        ApplyInternalLinkSuggestion $applyInternalLinkSuggestion,
    ): RedirectResponse {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $data = $request->validate([
            'agent_run_id' => ['required', 'uuid'],
            'suggestion_index' => ['required', 'integer', 'min:0'],
            'tab' => ['nullable', 'string'],
        ]);

        try {
            $applyInternalLinkSuggestion->toContent(
                $content,
                (string) $data['agent_run_id'],
                (int) $data['suggestion_index'],
                $request->user(),
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['internal_linking' => $exception->getMessage()]);
        }

        return redirect()
            ->route('app.content.show', [
                'content' => $content,
                'tab' => (string) ($data['tab'] ?? 'overview'),
                'internal_linking_run' => (string) $data['agent_run_id'],
            ])
            ->with('status', 'Internal link suggestion applied through a refresh revision.');
    }

    public function translate(
        TranslateContentRequest $request,
        Content $content,
        ContentTranslationCoordinator $contentTranslationCoordinator,
        TranslationDebugService $translationDebugService,
    ): RedirectResponse {
        $this->assertContentInUserOrganization($request, $content);

        try {
            $queued = $contentTranslationCoordinator->queue(
                $content,
                $request->targetLocale(),
                (string) $request->user()->id,
            );
        } catch (Throwable $exception) {
            Log::error('content.translation.start_failed', [
                'content_id' => (string) $content->id,
                'target_locale' => $request->input('target_locale'),
                'user_id' => $request->user()?->id,
                'workspace_id' => $content->workspace_id,
                'exception' => $exception->getMessage(),
                'trace' => substr($exception->getTraceAsString(), 0, 2000),
            ]);
            $translationDebugService->logFailure('Translation start failed in controller.', [
                'trace_id' => (string) Str::uuid(),
                'source_content_id' => (string) $content->id,
                'content_id' => (string) $content->id,
                'locale' => $request->targetLocale(),
                'queue_name' => config('translation.queue.name', 'default'),
                'user_id' => $request->user()?->id,
                'organization_id' => $content->workspace?->organization_id,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'stack_trace' => $exception->getTraceAsString(),
            ]);

            return back()->withErrors(['translation' => $exception->getMessage()]);
        }

        return back()->with('status', $queued['mode'] === 'refresh'
            ? sprintf('Refresh translation queued for %s.', $queued['target_language']->englishLabel())
            : sprintf('Translation queued for %s.', $queued['target_language']->englishLabel()));
    }

    public function fixLocale(
        Request $request,
        Content $content,
        LocaleMismatchService $localeMismatchService,
    ): RedirectResponse {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $data = $request->validate([
            'target_locale' => ['required', 'string', 'in:en,nl,de,fr,es,it,pt,pl,cs,sk,hu,ro,bg,el,da,sv,no,fi'],
        ]);

        $targetLocale = SupportedLanguage::tryFrom($data['target_locale']);

        if ($targetLocale === null) {
            return back()->withErrors(['locale_fix' => 'Invalid target locale.']);
        }

        $result = $localeMismatchService->fixLocale($content, $targetLocale);

        if (! $result['success']) {
            return back()->withErrors(['locale_fix' => $result['message']]);
        }

        return back()->with('status', $result['message']);
    }

    public function fixLocaleAndSetAsSource(
        Request $request,
        Content $content,
        LocaleMismatchService $localeMismatchService,
    ): RedirectResponse {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $data = $request->validate([
            'target_locale' => ['required', 'string', 'in:en,nl,de,fr,es,it,pt,pl,cs,sk,hu,ro,bg,el,da,sv,no,fi'],
        ]);

        $targetLocale = SupportedLanguage::tryFrom($data['target_locale']);

        if ($targetLocale === null) {
            return back()->withErrors(['locale_fix' => 'Invalid target locale.']);
        }

        $result = $localeMismatchService->fixLocaleAndSetAsSource($content, $targetLocale);

        if (! $result['success']) {
            return back()->withErrors(['locale_fix' => $result['message']]);
        }

        return back()->with('status', $result['message']);
    }

    public function convertToNlAndRegenerateEn(
        Request $request,
        Content $content,
        LocaleMismatchService $localeMismatchService,
        ContentTranslationCoordinator $contentTranslationCoordinator,
    ): RedirectResponse {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $result = $localeMismatchService->fixLocaleAndSetAsSource($content, SupportedLanguage::NL);

        if (! $result['success']) {
            return back()->withErrors(['locale_fix' => $result['message']]);
        }

        try {
            $queued = $contentTranslationCoordinator->queue(
                $content->fresh() ?? $content,
                SupportedLanguage::EN->value,
                (string) $request->user()->id,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'locale_fix' => $exception->getMessage(),
            ])->with('status', 'Locale fixed to NL, but EN regeneration could not be queued.');
        }

        $message = $queued['mode'] === 'refresh'
            ? 'Locale fixed to NL and English refresh queued.'
            : 'Locale fixed to NL and English regeneration queued.';

        return back()->with('status', $message);
    }

    public function markdownPreview(
        Request $request,
        string $content,
        MarkdownArtifactService $artifacts,
        MarkdownRenderer $renderer,
        AnswerBlockInjectorService $answerBlockInjector,
        AnswerBlockSchemaService $answerBlockSchema
    ): View {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('view', $content);

        $content->loadMissing([
            'workspace',
            'clientSite',
            'currentVersion',
            'currentRevision',
            'seo',
            'teamMember',
            'renderArtifacts',
        ]);

        $locale = trim((string) $request->query('locale')) ?: null;
        $artifact = $artifacts->findForContent($content, $locale);
        $preview = $renderer->render($content, $locale);
        $sourceHtml = trim((string) (
            $content->currentRevision?->content_html
            ?: $content->currentVersion?->body
            ?: ''
        ));
        $articleHtml = $answerBlockInjector->inject($sourceHtml, $content);

        return view('app.content.markdown-preview', [
            'content' => $content,
            'artifact' => $artifact,
            'preview' => $preview,
            'resolvedLocale' => $preview['locale'],
            'articleHtml' => $articleHtml,
            'faqSchema' => $answerBlockSchema->forContent($content),
        ]);
    }

    public function markdownDocument(
        Request $request,
        string $content,
        MarkdownRenderer $renderer
    ) {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('view', $content);

        $preview = $renderer->render($content->loadMissing([
            'workspace',
            'clientSite',
            'currentVersion',
            'currentRevision',
            'seo',
            'teamMember',
            'answerBlocks',
        ]));

        return response($preview['rendered_markdown'], 200, [
            'Content-Type' => 'text/markdown; charset=UTF-8',
        ]);
    }

    public function answersDocument(Request $request, string $content)
    {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('view', $content);

        return $this->answerBlocksJsonResponse($content->fresh(['answerBlocks']));
    }

    public function recalculateAeo(
        Request $request,
        string $content,
        AeoScoreService $aeoScoreService
    ): RedirectResponse {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $aeoScoreService->recalculate($content->loadMissing(['currentRevision', 'currentVersion', 'answerBlocks']));

        return back()->with('status', 'AEO score recalculated.');
    }

    public function queueImprovement(
        Request $request,
        string $content,
        ContentImprovementService $improvements,
    ): JsonResponse|RedirectResponse {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $validated = $request->validate([
            'type' => ['required', 'string'],
            'recommendation' => ['required', 'string', 'max:500'],
        ]);

        $run = $improvements->queue(
            $content,
            (string) $validated['type'],
            trim((string) $validated['recommendation']),
            $request->user(),
        );

        $toastMessage = $run->wasRecentlyCreated
            ? 'AI improvement queued.'
            : 'An improvement of this type is already queued or running.';

        if ($request->expectsJson()) {
            return $this->contentImprovementJsonResponse($content, $toastMessage, [
                'queued' => true,
                'run_id' => (string) $run->id,
            ]);
        }

        return back()->with('status', $toastMessage);
    }

    public function improvementStatus(
        Request $request,
        string $content,
        ContentImprovementService $improvements,
    ): JsonResponse {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('view', $content);

        return $this->contentImprovementJsonResponse($content);
    }

    public function acceptImprovement(
        Request $request,
        string $content,
        ContentImprovementRun $run,
        ContentImprovementService $improvements,
        AeoScoreService $aeoScoreService,
    ): JsonResponse|RedirectResponse {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);
        $this->assertImprovementRunBelongsToContent($run, $content);

        $improvements->accept($run, $request->user());
        $aeoScoreService->recalculate($content->fresh()->loadMissing(['currentRevision', 'currentVersion', 'answerBlocks']));

        if ($request->expectsJson()) {
            return $this->contentImprovementJsonResponse($content->fresh(), 'Generated improvement applied to draft.', [
                'applied' => true,
            ]);
        }

        return back()->with('status', 'Generated improvement applied to draft.');
    }

    public function rejectImprovement(
        Request $request,
        string $content,
        ContentImprovementRun $run,
        ContentImprovementService $improvements,
    ): JsonResponse|RedirectResponse {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);
        $this->assertImprovementRunBelongsToContent($run, $content);

        $improvements->reject($run, $request->user());

        if ($request->expectsJson()) {
            return $this->contentImprovementJsonResponse($content, 'Generated improvement rejected.', [
                'rejected' => true,
            ]);
        }

        return back()->with('status', 'Generated improvement rejected.');
    }

    public function generateAnswerBlocks(Request $request, string $content): RedirectResponse
    {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        if ($content->answerBlockGenerationIsActive()) {
            return back()->with('status', 'Structured answer generation is already queued or running.');
        }

        $content->forceFill([
            'answer_block_generation_status' => Content::ANSWER_BLOCK_STATUS_QUEUED,
            'answer_block_generation_persisted_count' => (int) $content->answerBlocks()->count(),
            'answer_block_generation_draft_revision_id' => $content->current_revision_id ?: null,
            'answer_block_generation_started_at' => null,
            'answer_block_generation_completed_at' => null,
            'answer_block_generation_failed_at' => null,
            'answer_block_generation_last_error' => null,
            'answer_block_generation_last_warning' => null,
            'answer_block_generation_meta' => null,
        ])->saveQuietly();

        GenerateStructuredAnswersJob::dispatch((string) $content->id)->onQueue('generation');

        return back()->with('status', 'Structured answer generation queued.');
    }

    public function storeAnswerBlock(Request $request, string $content): RedirectResponse
    {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string', 'min:3', 'max:1200'],
            'entities' => ['nullable', 'string', 'max:500'],
            'platforms' => ['nullable', 'string', 'max:500'],
        ]);

        $this->validateAnswerBlockPayload($content, $validated['question'], $validated['answer']);

        StructuredAnswerBlock::query()->create([
            'content_id' => (string) $content->id,
            'question' => trim((string) $validated['question']),
            'answer' => trim((string) $validated['answer']),
            'entities' => $this->parseDelimitedList($validated['entities'] ?? null),
            'platforms' => $this->parsePlatforms($validated['platforms'] ?? null),
            'order' => (int) ($content->answerBlocks()->max('order') ?? -1) + 1,
        ]);

        return back()->with('status', 'Answer block added.');
    }

    public function updateAnswerBlockSettings(Request $request, string $content): RedirectResponse
    {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $validated = $request->validate([
            'answer_block_render_mode' => ['nullable', 'in:' . implode(',', Content::answerBlockRenderModes())],
            'answer_block_max_visible' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $content->update([
            'answer_block_render_mode' => $validated['answer_block_render_mode'] ?? null,
            'answer_block_max_visible' => $validated['answer_block_max_visible'] ?? null,
        ]);

        return back()->with('status', 'Answer block visibility settings updated.');
    }

    public function updateAnswerBlock(Request $request, string $content, StructuredAnswerBlock $block): RedirectResponse
    {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);
        abort_unless((string) $block->content_id === (string) $content->id, 404);

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string', 'min:3', 'max:1200'],
            'entities' => ['nullable', 'string', 'max:500'],
            'platforms' => ['nullable', 'string', 'max:500'],
        ]);

        $this->validateAnswerBlockPayload($content, $validated['question'], $validated['answer'], (string) $block->id);

        $block->update([
            'question' => trim((string) $validated['question']),
            'answer' => trim((string) $validated['answer']),
            'entities' => $this->parseDelimitedList($validated['entities'] ?? null),
            'platforms' => $this->parsePlatforms($validated['platforms'] ?? null),
        ]);

        return back()->with('status', 'Answer block updated.');
    }

    public function moveAnswerBlock(Request $request, string $content, StructuredAnswerBlock $block): RedirectResponse
    {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);
        abort_unless((string) $block->content_id === (string) $content->id, 404);

        $validated = $request->validate([
            'direction' => ['required', 'in:up,down'],
        ]);

        $blocks = $content->answerBlocks()->orderBy('order')->get()->values();
        $index = $blocks->search(fn (StructuredAnswerBlock $candidate): bool => (string) $candidate->id === (string) $block->id);

        if ($index === false) {
            abort(404);
        }

        $swapIndex = $validated['direction'] === 'up' ? $index - 1 : $index + 1;
        if (! isset($blocks[$swapIndex])) {
            return back();
        }

        DB::transaction(function () use ($blocks, $index, $swapIndex): void {
            $current = $blocks[$index];
            $swap = $blocks[$swapIndex];
            $currentOrder = (int) $current->order;

            $current->update(['order' => (int) $swap->order]);
            $swap->update(['order' => $currentOrder]);
        });

        return back()->with('status', 'Answer block order updated.');
    }

    public function destroyAnswerBlock(Request $request, string $content, StructuredAnswerBlock $block): RedirectResponse
    {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);
        abort_unless((string) $block->content_id === (string) $content->id, 404);

        $block->delete();

        $content->answerBlocks()->orderBy('order')->get()->values()->each(
            fn (StructuredAnswerBlock $answerBlock, int $index) => $answerBlock->update(['order' => $index])
        );

        return back()->with('status', 'Answer block removed.');
    }

    public function generateFeaturedImage(
        Request $request,
        Content $content,
        ImageGenerationService $imageGenerationService,
        CreditWalletService $creditWalletService
    ): RedirectResponse {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('generateImage', $content);

        if (! $content->client_site_id) {
            return back()->withErrors(['image_generate' => 'Content is not linked to a site with a credit wallet.']);
        }

        $activeGeneration = ContentImage::query()
            ->where('content_id', $content->id)
            ->where('type', 'featured')
            ->whereIn('status', ['queued', 'generating'])
            ->exists();

        if ($activeGeneration) {
            return back()->withErrors(['image_generate' => 'Featured image generation is already running.']);
        }

        $cost = $imageGenerationService->resolveCreditCost();
        $available = $creditWalletService->getAvailableForClientSite((string) $content->client_site_id);
        if ($available < $cost) {
            return back()->withErrors([
                'image_generate' => sprintf('Insufficient credits. Required: %d, available: %d.', $cost, $available),
            ]);
        }

        $content->forceFill(['updated_by' => $request->user()->id])->save();
        $imageGenerationService->generateFeaturedImage($content);

        return back()->with('status', 'Featured image generation queued.');
    }

    public function generateInlineVisualImage(
        Request $request,
        Content $content,
        string $assetKey,
        ImageGenerationService $imageGenerationService,
        CreditWalletService $creditWalletService,
        VisualPlanService $visualPlans
    ): RedirectResponse {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('generateImage', $content);

        if (! $content->client_site_id) {
            return back()->withErrors(['image_generate' => 'Content is not linked to a site with a credit wallet.']);
        }

        $content->loadMissing('currentRevision');
        $plan = $visualPlans->fromMeta(is_array($content->currentRevision?->meta) ? $content->currentRevision->meta : []);
        $asset = collect($plan['assets'])->firstWhere('asset_key', $assetKey);

        if (! is_array($asset)) {
            return back()->withErrors(['image_generate' => 'Visual asset was not found in the content visual plan.']);
        }

        if (! in_array((string) ($asset['type'] ?? ''), ['image', 'diagram', 'conceptual_visual'], true)) {
            return back()->withErrors(['image_generate' => 'This visual type is rendered from structured data and does not need AI image generation.']);
        }

        $activeGeneration = ContentImage::query()
            ->where('content_id', $content->id)
            ->where('type', ImageGenerationService::INLINE_TYPE)
            ->where('metadata->asset_key', $assetKey)
            ->whereIn('status', ['queued', 'generating'])
            ->exists();

        if ($activeGeneration) {
            return back()->withErrors(['image_generate' => 'Inline visual generation is already running.']);
        }

        $cost = $imageGenerationService->resolveCreditCost();
        $available = $creditWalletService->getAvailableForClientSite((string) $content->client_site_id);
        if ($available < $cost) {
            return back()->withErrors([
                'image_generate' => sprintf('Insufficient credits. Required: %d, available: %d.', $cost, $available),
            ]);
        }

        $content->forceFill(['updated_by' => $request->user()->id])->save();
        $imageGenerationService->generateInlineVisualImage($content, $asset);

        return back()->with('status', 'Inline visual generation queued.');
    }

    public function pushFeaturedImageToWordPress(
        Request $request,
        Content $content,
        PushContentFeaturedImageToWordPress $pushService
    ): RedirectResponse {
        $this->assertContentInUserOrganization($request, $content);
        $this->assertImagePushSupportedForContent($content);
        $this->authorize('pushFeaturedImage', $content);

        $result = $pushService->push($content);
        if (! ($result['ok'] ?? false)) {
            return back()->withErrors([
                'image_push' => (string) ($result['error'] ?? 'Could not push featured image to WordPress.'),
            ]);
        }

        return back()->with('status', 'Featured image pushed to connected site.');
    }

    public function useUnsplashFeaturedImage(
        Request $request,
        Content $content,
        UnsplashImageService $unsplashImageService,
        UploadedContentImageAssetService $imageAssetService
    ): RedirectResponse {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $data = $request->validate([
            'photo' => ['required', 'array'],
            'photo.id' => ['required', 'string', 'max:120'],
            'photo.query' => ['nullable', 'string', 'max:200'],
            'photo.urls.regular' => ['required', 'url', 'max:2000'],
            'photo.urls.small' => ['nullable', 'url', 'max:2000'],
            'photo.links.html' => ['required', 'url', 'max:2000'],
            'photo.links.download_location' => ['required', 'url', 'max:2000'],
            'photo.user.name' => ['required', 'string', 'max:180'],
            'photo.user.links.html' => ['required', 'url', 'max:2000'],
            'photo.alt_description' => ['nullable', 'string', 'max:500'],
            'photo.description' => ['nullable', 'string', 'max:500'],
            'photo.width' => ['nullable', 'integer', 'min:0'],
            'photo.height' => ['nullable', 'integer', 'min:0'],
            'display_on_website' => ['nullable', 'boolean'],
            'display_as_featured_image' => ['nullable', 'boolean'],
            'use_as_meta_image' => ['nullable', 'boolean'],
            'use_as_social_image' => ['nullable', 'boolean'],
            'use_for_linkedin' => ['nullable', 'boolean'],
        ]);

        $usage = [
            'display_on_website' => (bool) ($data['display_on_website'] ?? false),
            'display_as_featured_image' => (bool) ($data['display_as_featured_image'] ?? false),
            'use_as_meta_image' => (bool) ($data['use_as_meta_image'] ?? false),
            'use_as_social_image' => (bool) ($data['use_as_social_image'] ?? false),
            'use_for_linkedin' => (bool) ($data['use_for_linkedin'] ?? false),
        ];

        if (! in_array(true, $usage, true)) {
            $usage['display_as_featured_image'] = true;
        }

        try {
            $content->forceFill(['updated_by' => $request->user()->id])->save();
            $image = $unsplashImageService->usePhoto(
                $content,
                (array) $data['photo'],
                (string) $request->user()->id
            );
            $imageAssetService->assignUsageForContent($content, $image, $usage);
        } catch (Throwable $exception) {
            return back()->withErrors(['stock_image' => $exception->getMessage()]);
        }

        return redirect()
            ->route('app.content.show', ['content' => $content, 'tab' => 'images'])
            ->with('status', 'Unsplash photo set as featured image with attribution.');
    }

    public function generateOgImage(
        Request $request,
        Content $content,
        ImageGenerationService $imageGenerationService,
        CreditWalletService $creditWalletService
    ): RedirectResponse {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('generateImage', $content);

        if (! $content->client_site_id) {
            return back()->withErrors(['image_generate' => 'Content is not linked to a site with a credit wallet.']);
        }

        $activeGeneration = ContentImage::query()
            ->where('content_id', $content->id)
            ->where('type', ImageGenerationService::OG_TYPE)
            ->whereIn('status', ['queued', 'generating'])
            ->exists();

        if ($activeGeneration) {
            return back()->withErrors(['image_generate' => 'OG image generation is already running.']);
        }

        $hasReadyFeatured = ContentImage::query()
            ->where('content_id', $content->id)
            ->where('type', ImageGenerationService::FEATURED_TYPE)
            ->where('status', 'ready')
            ->exists();

        if (! $hasReadyFeatured) {
            $cost = $imageGenerationService->resolveCreditCost();
            $available = $creditWalletService->getAvailableForClientSite((string) $content->client_site_id);
            if ($available < $cost) {
                return back()->withErrors([
                    'image_generate' => sprintf('Insufficient credits for OG background generation. Required: %d, available: %d.', $cost, $available),
                ]);
            }
        }

        $content->forceFill(['updated_by' => $request->user()->id])->save();
        $imageGenerationService->generateOgImage($content);

        return back()->with('status', 'OG image generation queued.');
    }

    public function updateImageGenerationPreferences(Request $request, Content $content): RedirectResponse
    {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $data = $request->validate([
            'image_prompt_instructions' => ['nullable', 'string', 'max:4000'],
        ]);

        $instructions = trim((string) ($data['image_prompt_instructions'] ?? ''));

        $content->update([
            'image_prompt_instructions' => $instructions !== '' ? $instructions : null,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Image generation instructions updated.');
    }

    public function pushOgImageToWordPress(
        Request $request,
        Content $content,
        PushContentOgImageToWordPress $pushService
    ): RedirectResponse {
        $this->assertContentInUserOrganization($request, $content);
        $this->assertImagePushSupportedForContent($content);
        $this->authorize('pushFeaturedImage', $content);

        $result = $pushService->push($content);
        if (! ($result['ok'] ?? false)) {
            return back()->withErrors([
                'image_push' => (string) ($result['error'] ?? 'Could not push OG image to WordPress.'),
            ]);
        }

        return back()->with('status', 'OG image pushed to connected site.');
    }

    public function restoreImageVersion(
        Request $request,
        Content $content,
        ContentImage $imageVersion
    ): RedirectResponse {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $data = $request->validate([
            'image_type' => ['required', 'in:featured,og'],
        ]);

        if ((string) $imageVersion->content_id !== (string) $content->id) {
            abort(403, 'Image version does not belong to this content.');
        }

        $requestedType = (string) ($data['image_type'] ?? '');
        if ($requestedType !== (string) $imageVersion->type) {
            return back()->withErrors([
                'image_restore' => 'Image restore type mismatch.',
            ]);
        }

        if ((string) $imageVersion->status !== 'ready') {
            return back()->withErrors(['image_restore' => 'Only ready image versions can be restored.']);
        }

        DB::transaction(function () use ($content, $imageVersion, $requestedType): void {
            ContentImage::query()
                ->where('content_id', $content->id)
                ->where('type', $requestedType)
                ->update(['is_active' => false]);

            $imageVersion->forceFill(['is_active' => true])->save();
        });

        Artisan::call('optimize:clear');

        return back()->with('status', ucfirst((string) $imageVersion->type).' image version restored.');
    }

    public function deleteImageVersion(
        Request $request,
        Content $content,
        ContentImage $imageVersion
    ): RedirectResponse {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        if ((string) $imageVersion->content_id !== (string) $content->id) {
            abort(403, 'Image version does not belong to this content.');
        }

        if ($imageVersion->is_active) {
            $replacement = ContentImage::query()
                ->where('content_id', $content->id)
                ->where('type', $imageVersion->type)
                ->where('id', '!=', $imageVersion->id)
                ->where('status', 'ready')
                ->latest('created_at')
                ->first();

            if (! $replacement) {
                return back()->withErrors([
                    'image_delete' => 'Cannot delete the active image version without another ready version to activate.',
                ]);
            }

            DB::transaction(function () use ($content, $imageVersion, $replacement): void {
                ContentImage::query()
                    ->where('content_id', $content->id)
                    ->where('type', $imageVersion->type)
                    ->update(['is_active' => false]);

                $replacement->forceFill(['is_active' => true])->save();
                $imageVersion->delete();
            });

            return back()->with('status', ucfirst((string) $imageVersion->type).' image version deleted.');
        }

        $imageVersion->delete();

        return back()->with('status', ucfirst((string) $imageVersion->type).' image version deleted.');
    }

    private function resolveContentFromIdentifier(string $identifier): Content
    {
        $content = Content::query()->find($identifier);
        if ($content) {
            return $content;
        }

        $briefContent = Brief::query()
            ->whereKey($identifier)
            ->with('content')
            ->first()?->content;
        if ($briefContent) {
            return $briefContent;
        }

        $draftContent = Draft::query()
            ->whereKey($identifier)
            ->with('content')
            ->first()?->content;
        if ($draftContent) {
            return $draftContent;
        }

        abort(404);
    }

    public function storeRevision(Request $request, Content $content): RedirectResponse
    {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $data = $request->validate([
            'body' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $revisionNumber = ContentVersion::query()
            ->where('content_id', $content->id)
            ->where('type', 'revision')
            ->count() + 1;
        $label = 'Revision '.$revisionNumber.' - '.now()->format('Y-m-d H:i');

        $meta = ['label' => $label];
        if (filled($data['note'] ?? null)) {
            $meta['note'] = trim((string) $data['note']);
        }

        $version = ContentVersion::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $content->id,
            'type' => 'revision',
            'parent_version_id' => $content->current_version_id,
            'body' => $data['body'],
            'meta' => $meta,
            'source' => 'pl',
            'created_by' => $request->user()->id,
        ]);

        $content->update([
            'current_version_id' => $version->id,
            'status' => 'draft',
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Revision created and set as current.');
    }

    public function restoreVersion(
        Request $request,
        Content $content,
        ContentVersion $version,
        ContentLifecycleService $service
    ): RedirectResponse {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('restoreRevision', $content);

        $service->restoreVersion($content, $version, $request->user()->id);

        return back()->with('status', 'Revision restored.');
    }

    public function destroy(Request $request, string $contentId, ContentDeletionService $deletions): RedirectResponse
    {
        $content = Content::withTrashed()->findOrFail($contentId);
        $this->authorize('delete', $content);

        $result = $deletions->deleteContent(
            $content,
            (string) $request->input('scope', 'single'),
            $request->user(),
            $request,
        );

        return back()->with('status', sprintf('%d content item(s) deleted.', (int) $result['count']));
    }

    public function restore(Request $request, string $contentId, ContentDeletionService $deletions): RedirectResponse
    {
        $content = Content::withTrashed()->findOrFail($contentId);
        $this->authorize('restore', $content);

        $deletions->restoreContent($content, $request->user(), $request);

        return back()->with('status', 'Content restored.');
    }

    public function republish(
        Request $request,
        string $content,
        LaravelConnectorDestinationResolver $laravelDestinationResolver,
        LaravelConnectorPublishingService $laravelPublishingService,
        ContentPublicationService $publicationService,
    ): RedirectResponse
    {
        $content = $this->resolveContentFromIdentifier($content);
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $siteType = ClientSite::normalizeType((string) ($content->clientSite?->type ?? ''));
        $laravelDestination = $laravelDestinationResolver->resolveForContent($content);
        $humanContentPublicationContext = [
            'human_content_override' => $request->boolean('human_content_override'),
            'user_id' => $request->user()?->id,
        ];

        if ($siteType === ClientSite::TYPE_LARAVEL) {
            $draft = Draft::query()
                ->where('content_id', $content->id)
                ->latest('created_at')
                ->first();

            if (! $draft) {
                return back()->with('status', 'No draft found to re-sync for this content.');
            }

            if ($laravelDestination) {
                $dispatch = $publicationService->dispatchLaravelPublication($content, $draft, [
                    'source' => 'app.content.republish',
                    'force' => true,
                    'allow_stale_reclaim' => true,
                ] + $humanContentPublicationContext);

                return back()->with('status', (bool) ($dispatch['queued'] ?? false)
                    ? 'Content queued for Laravel publication.'
                    : 'Laravel publication was already queued or processed.');
            }

            try {
                $laravelPublishingService->publish($content, $draft, 'manual_resync', 'app.content.republish', $humanContentPublicationContext);
            } catch (Throwable $exception) {
                Log::error('content.republish.local_laravel_failed', [
                    'content_id' => (string) $content->id,
                    'locale' => $content->localeCode(),
                    'site_id' => (string) ($content->client_site_id ?? ''),
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ]);

                return back()->withErrors([
                    'publish' => 'Laravel republish failed while syncing the publication record: '.$exception->getMessage(),
                ]);
            }

            return back()->with('status', 'Content marked as published locally for Laravel.');
        }

        $this->assertWordPressSiteForContent($content);

        try {
            app(WorkspaceEntitlementsService::class)->assertCanPushToWp($content->clientSite->workspace);
        } catch (Throwable $exception) {
            return back()->withErrors(['regenerate' => $exception->getMessage()]);
        }

        $draft = Draft::query()
            ->where('content_id', $content->id)
            ->latest('created_at')
            ->first();

        if (! $draft) {
            return back()->with('status', 'No draft found to republish for this content.');
        }

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $metaRefs = is_array($meta['client_refs'] ?? null) ? $meta['client_refs'] : [];
        $briefRefs = is_array($draft->brief?->client_refs) ? $draft->brief->client_refs : [];

        $mergedRefs = array_replace($briefRefs, $metaRefs);
        if (empty($mergedRefs['draft_webhook_url']) && ! empty($draft->clientSite?->draft_webhook_url)) {
            $mergedRefs['draft_webhook_url'] = $draft->clientSite->draft_webhook_url;
        }
        if (empty($mergedRefs['draft_webhook_secret']) && ! empty($draft->clientSite?->draft_webhook_secret)) {
            $mergedRefs['draft_webhook_secret'] = $draft->clientSite->draft_webhook_secret;
        }
        if (empty($mergedRefs['wp_post_id']) && ! empty($content->wp_post_id)) {
            $mergedRefs['wp_post_id'] = (string) $content->wp_post_id;
        }
        $meta['client_refs'] = $mergedRefs;

        $draft->update([
            'status' => 'ready_to_deliver',
            'delivery_status' => 'pending',
            'delivery_last_error' => null,
            'meta' => $meta,
        ]);

        // Force delivery for explicit user-initiated republish (bypass checksum skip)
        DeliverDraftJob::dispatch((string) $draft->id, forceDelivery: true)
            ->onQueue((string) config('argusly.webhooks.queue', 'deliveries'));

        return back()->with('status', 'Content queued for WordPress republish.');
    }

    public function verifyRemote(
        Request $request,
        Content $content,
        ContentPublicationService $publicationService,
    ): RedirectResponse {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        try {
            $destination = $publicationService->resolveDestinationForContent($content);
            $destinationType = ContentDestinationType::normalize($destination->rawTypeValue());
            $driver = $publicationService->resolveConnector($destination);

            $publication = $content->publications()
                ->where('provider', $driver->type())
                ->when($destination->id, fn ($query) => $query->where('destination_id', $destination->id))
                ->first();

            if (! $publication) {
                return back()->withErrors(['publish' => match ($destinationType) {
                    ContentDestinationType::WORDPRESS->value => 'No WordPress publication exists for this content yet.',
                    ContentDestinationType::LARAVEL->value => 'No Laravel publication exists for this content yet.',
                    ContentDestinationType::API->value => 'No API publication exists for this content yet.',
                    default => 'Unknown destination for this content.',
                }]);
            }

            $result = $publicationService->verify($publication);

            if ($result->doesExist()) {
                return back()->with('status', match ($destinationType) {
                    ContentDestinationType::WORDPRESS->value => 'Remote WordPress post verified. The post exists and is accessible.',
                    ContentDestinationType::LARAVEL->value => 'Laravel route verified. The page exists and is reachable on the destination site.',
                    ContentDestinationType::API->value => 'API destination verified.',
                    default => 'Destination verified.',
                });
            }

            return back()->with('status', match ($destinationType) {
                ContentDestinationType::WORDPRESS->value => 'Remote WordPress post not found. The post may have been deleted in WordPress. You can republish to recreate it.',
                ContentDestinationType::LARAVEL->value => 'Laravel route not found. Republish to Laravel to recreate the route.',
                ContentDestinationType::API->value => 'API destination could not verify the remote resource.',
                default => 'Destination verification could not confirm the remote resource.',
            });
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['publish' => $exception->getMessage()]);
        }
    }

    public function unpublishRemote(
        Request $request,
        Content $content,
        LaravelConnectorDestinationResolver $laravelDestinationResolver,
        LaravelConnectorPublishingService $laravelPublishingService,
    ): RedirectResponse {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $laravelDestination = $laravelDestinationResolver->resolveForContent($content);
        if (! $laravelDestination) {
            return back()->withErrors(['publish' => 'No Laravel connector destination is configured for this content.']);
        }

        try {
            $laravelPublishingService->queueRemoteDeletion($content, 'app.content.unpublish-remote');
        } catch (RuntimeException $exception) {
            return back()->withErrors(['publish' => $exception->getMessage()]);
        }

        return back()->with('status', 'Remote delete queued for the Laravel connector.');
    }

    public function regenerateDraft(
        Request $request,
        Content $content,
        DraftGenerationService $draftGenerationService,
        ContentLifecycleService $contentLifecycleService,
        CreditWalletService $creditWalletService,
        PlanQuotaService $planQuotaService
    ): RedirectResponse {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('generateDraft', $content);

        $runSync = $request->boolean('run_sync', false);

        $draft = Draft::query()
            ->where('content_id', $content->id)
            ->latest('created_at')
            ->first();

        if (! $draft) {
            return back()->withErrors(['regenerate' => 'No draft found for this content.']);
        }

        if (! $runSync) {
            RegenerateContentDraftJob::dispatch(
                draftId: (string) $draft->id,
                userId: (int) $request->user()->id,
                autoRepushToWp: $request->boolean('auto_repush_to_wp', false)
            )->onQueue('generation');

            return back()->with('status', 'Draft regeneration queued. Refresh in a few moments for status and revision update.');
        }

        $creditCost = $this->resolveCreditCostForRegeneration($draft);
        if ($creditCost <= 0) {
            return back()->withErrors([
                'regenerate' => 'Draft has no credit cost configured. Set a credit action or run drafts:backfill-credit-costs.',
            ]);
        }

        $availableCredits = $creditWalletService->getAvailableForClientSite((string) $draft->client_site_id);
        if ($availableCredits < $creditCost) {
            return back()->withErrors([
                'regenerate' => sprintf(
                    'INSUFFICIENT_CREDITS: Insufficient credits. Required: %d, available: %d. Buy extra credits to continue.',
                    $creditCost,
                    $availableCredits
                ),
            ]);
        }

        $userId = (string) $request->user()->id;
        $autoRepushToWp = $request->boolean('auto_repush_to_wp', false);
        if ($autoRepushToWp) {
            $this->assertWordPressSiteForContent($content);
        }

        try {
            $draft->status = 'processing';
            $draft->last_error = null;
            $draft->save();

            $creditWalletService->reserveForDraft($draft, $userId);
            $result = $draftGenerationService->generateWithRepair($draft, 2);

            $existingMeta = is_array($draft->meta) ? $draft->meta : [];
            $resultMeta = (array) ($result['meta'] ?? []);
            $mergedMeta = array_replace_recursive($existingMeta, $resultMeta);
            $seoFields = SeoMetadata::merge(
                [
                    'seo_title' => $result['title'] ?? $draft->title,
                    'seo_meta_description' => data_get($result, 'meta.description'),
                    'robots_index' => data_get($result, 'meta.robots_index'),
                    'robots_follow' => data_get($result, 'meta.robots_follow'),
                    'schema_type' => data_get($result, 'meta.schema_type'),
                ],
                $mergedMeta,
                [
                    'seo_title' => $draft->seo_title,
                    'seo_meta_description' => $draft->seo_meta_description,
                    'seo_h1' => $draft->seo_h1,
                    'seo_canonical' => $draft->seo_canonical,
                    'seo_og_title' => $draft->seo_og_title,
                    'seo_og_description' => $draft->seo_og_description,
                    'seo_og_image' => $draft->seo_og_image,
                    'seo_twitter_title' => $draft->seo_twitter_title,
                    'seo_twitter_description' => $draft->seo_twitter_description,
                    'robots_index' => $draft->robots_index,
                    'robots_follow' => $draft->robots_follow,
                    'schema_type' => $draft->schema_type,
                ],
            );
            if (trim((string) ($seoFields['seo_h1'] ?? '')) === '') {
                $seoFields['seo_h1'] = $seoFields['seo_title'] ?: ($result['title'] ?? $draft->title);
            }
            $mergedMeta = array_replace_recursive($mergedMeta, array_filter([
                'meta_description' => $seoFields['seo_meta_description'],
                'canonical_url' => $seoFields['seo_canonical'],
                'og_title' => $seoFields['seo_og_title'],
                'og_description' => $seoFields['seo_og_description'],
                'og_image' => $seoFields['seo_og_image'],
                'twitter_title' => $seoFields['seo_twitter_title'],
                'twitter_description' => $seoFields['seo_twitter_description'],
                'robots_index' => $seoFields['robots_index'],
                'robots_follow' => $seoFields['robots_follow'],
                'schema_type' => $seoFields['schema_type'],
            ], static fn ($value) => is_bool($value) || trim((string) $value) !== ''));
            $mergedMeta['generation'] = array_filter([
                'provider' => (string) data_get($result, 'provider', config('llm.default_provider', 'openai')),
                'model' => (string) data_get($result, 'model', ''),
                'tokens' => (int) data_get($result, 'usage.total_tokens', 0),
                'input_tokens' => (int) data_get($result, 'usage.input_tokens', 0),
                'output_tokens' => (int) data_get($result, 'usage.output_tokens', 0),
                'request_id' => (string) data_get($result, 'request_id', ''),
                'credits' => $creditCost,
                'generated_at' => now()->toIso8601String(),
                'trigger' => 'app_content_regenerate',
            ], fn ($value) => $value !== null);

            $draft->meta = $mergedMeta;
            $draft->save();

            $creditWalletService->commitUsageForDraft($draft, $userId);
            $planQuotaService->incrementUsage(
                workspace: $content->clientSite->workspace,
                site: $content->clientSite,
                metric: PlanQuotaService::METRIC_ARTICLES_GENERATED,
                amount: 1,
            );

            $draft->status = 'generated';
            $draft->title = $result['title'] ?? $draft->title;
            $draft->seo_title = $seoFields['seo_title'] ?: ($result['title'] ?? $draft->title);
            $draft->seo_meta_description = $seoFields['seo_meta_description'] ?: $draft->seo_meta_description;
            $draft->seo_h1 = $seoFields['seo_h1'] ?: $draft->seo_h1;
            $draft->seo_canonical = $seoFields['seo_canonical'] ?: $draft->seo_canonical;
            $draft->seo_og_title = $seoFields['seo_og_title'] ?: $draft->seo_og_title;
            $draft->seo_og_description = $seoFields['seo_og_description'] ?: $draft->seo_og_description;
            $draft->seo_og_image = $seoFields['seo_og_image'] ?: $draft->seo_og_image;
            $draft->seo_twitter_title = $seoFields['seo_twitter_title'] ?: $draft->seo_twitter_title;
            $draft->seo_twitter_description = $seoFields['seo_twitter_description'] ?: $draft->seo_twitter_description;
            $draft->robots_index = $seoFields['robots_index'] ?? $draft->robots_index;
            $draft->robots_follow = $seoFields['robots_follow'] ?? $draft->robots_follow;
            $draft->schema_type = $seoFields['schema_type'] ?: $draft->schema_type;
            $draft->content_html = $result['content_html'] ?? $draft->content_html;
            $draft->meta = $mergedMeta;
            $draft->links = $result['links'] ?? $draft->links;
            $draft->delivery_status = 'pending';
            $draft->delivery_last_error = null;
            $draft->last_error = null;
            $draft->delivered_at = now();
            $draft->save();

            $contentLifecycleService->ensureRevisionFromDraft($draft, $request->user()->id);

            if ($autoRepushToWp) {
                $draft->status = 'ready_to_deliver';
                $draft->delivery_status = 'pending';
                $draft->delivery_last_error = null;
                $draft->save();

                DeliverDraftJob::dispatch((string) $draft->id)->onQueue((string) config('argusly.webhooks.queue', 'deliveries'));
            }

            return back()->with('status', sprintf(
                $autoRepushToWp
                    ? 'Draft regenerated via AI, saved as a new revision, and queued for WP repush. %d credits used.'
                    : 'Draft regenerated via AI. %d credits used and saved as a new revision.',
                $creditCost
            ));
        } catch (Throwable $exception) {
            $draft->refresh();
            if ($draft->credit_status === 'reserved') {
                try {
                    $creditWalletService->releaseReservationForDraft($draft, $userId);
                } catch (Throwable) {
                    // Best effort release; preserve original exception for UI feedback.
                }
            }

            $draft->status = 'failed';
            $draft->last_error = mb_substr($exception->getMessage(), 0, 5000);
            $draft->save();

            $message = $exception instanceof InsufficientCreditsException
                ? sprintf(
                    'INSUFFICIENT_CREDITS: Insufficient credits. Required: %d, available: %d. Buy extra credits to continue.',
                    $exception->required,
                    $exception->available,
                )
                : 'Draft regeneration failed: '.$exception->getMessage();

            return back()->withErrors(['regenerate' => $message]);
        }
    }

    public function updateGenerationPreferences(Request $request, Content $content): RedirectResponse
    {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $data = $request->validate([
            'brand_voice_id' => ['nullable', 'uuid', 'exists:brand_voices,id'],
            'writer_profile_id' => ['nullable', 'uuid', 'exists:writer_profiles,id'],
            'buyer_persona_id' => ['nullable', 'integer', 'exists:personas,id'],
            'team_member_id' => ['nullable', 'integer', 'exists:team_members,id'],
            'preferred_length' => ['nullable', 'in:short,medium,long,pillar'],
        ]);

        // Global organization scopes automatically filter to user's organization
        if (! empty($data['brand_voice_id'])) {
            $voice = BrandVoice::query()->find($data['brand_voice_id']);
            if (! $voice) {
                return back()->withErrors(['brand_voice_id' => 'Selected brand voice is not available for this organization.']);
            }
        }

        if (! empty($data['writer_profile_id'])) {
            $writerProfile = \App\Models\WriterProfile::query()
                ->where('workspace_id', $content->workspace_id)
                ->where('status', \App\Models\WriterProfile::STATUS_ACTIVE)
                ->find($data['writer_profile_id']);

            if (! $writerProfile) {
                return back()->withErrors(['writer_profile_id' => 'Selected writer profile is not available for this workspace.']);
            }
        }

        $buyerPersona = null;
        if (! empty($data['buyer_persona_id'])) {
            $buyerPersona = Persona::query()
                ->where('status', Persona::STATUS_APPROVED)
                ->find($data['buyer_persona_id']);

            if (! $buyerPersona) {
                return back()->withErrors(['buyer_persona_id' => 'Selected buyer persona is not available for this organization.']);
            }
        }

        if (! empty($data['team_member_id'])) {
            $member = TeamMember::query()->where('is_active', true)->find($data['team_member_id']);
            if (! $member) {
                return back()->withErrors(['team_member_id' => 'Selected team member is not available for this organization.']);
            }
        }

        $content->update([
            'brand_voice_id' => $data['brand_voice_id'] ?? null,
            'buyer_persona_id' => $data['buyer_persona_id'] ?? null,
            'team_member_id' => $data['team_member_id'] ?? null,
            'writer_profile_id' => $data['writer_profile_id'] ?? null,
            'preferred_length' => $data['preferred_length'] ?? null,
            'updated_by' => $request->user()->id,
        ]);

        $brief = $content->brief()->first();
        if ($brief) {
            $clientRefs = is_array($brief->client_refs) ? $brief->client_refs : [];
            $clientRefs['brand_voice_id'] = $data['brand_voice_id'] ?? null;
            $clientRefs['buyer_persona_id'] = $data['buyer_persona_id'] ?? null;
            $clientRefs['team_member_id'] = $data['team_member_id'] ?? null;
            $clientRefs['writer_profile_id'] = $data['writer_profile_id'] ?? null;
            $clientRefs['preferred_length'] = $data['preferred_length'] ?? 'medium';

            $briefUpdates = [
                'client_refs' => $clientRefs,
            ];

            if ($buyerPersona) {
                $briefUpdates['audience'] = $this->personaAudienceLabel($buyerPersona);
            }

            $brief->update($briefUpdates);
        }

        $latestDraft = Draft::query()->where('content_id', $content->id)->latest('created_at')->first();
        if ($latestDraft) {
            $meta = is_array($latestDraft->meta) ? $latestDraft->meta : [];
            $meta['brand_voice_id'] = $data['brand_voice_id'] ?? null;
            $meta['buyer_persona_id'] = $data['buyer_persona_id'] ?? null;
            $meta['team_member_id'] = $data['team_member_id'] ?? null;
            $meta['writer_profile_id'] = $data['writer_profile_id'] ?? null;
            $meta['preferred_length'] = $data['preferred_length'] ?? 'medium';
            if ($buyerPersona) {
                $meta['audience'] = $this->personaAudienceLabel($buyerPersona);
            }
            $latestDraft->update(['meta' => $meta]);
        }

        return back()->with('status', 'Generation preferences updated.');
    }

    private function personaAudienceLabel(Persona $persona): string
    {
        $role = trim((string) data_get($persona->profile_data, 'role', ''));

        return $role !== ''
            ? trim((string) $persona->name . ' (' . $role . ')')
            : (string) $persona->name;
    }

    public function updatePublishingSyncSettings(Request $request, Content $content): RedirectResponse
    {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $data = $request->validate([
            'sync_with_source' => ['nullable', 'boolean'],
            'auto_publish' => ['nullable', 'boolean'],
        ]);

        $content->forceFill([
            'sync_with_source' => $content->isTranslationVariant()
                ? (bool) ($data['sync_with_source'] ?? false)
                : (bool) ($content->sync_with_source ?? true),
            'auto_publish' => (bool) ($data['auto_publish'] ?? false),
        ])->save();

        if ($content->isTranslationVariant() && (bool) $content->sync_with_source) {
            app(LocalePublishingSyncService::class)->syncReadyTranslation(
                $content->fresh(['translationSourceContent.clientSite', 'clientSite', 'contentDestination']) ?? $content
            );
        }

        if (! $content->isTranslationVariant()) {
            app(LocalePublishingSyncService::class)->syncSourceSchedule(
                $content->fresh(['clientSite', 'contentDestination', 'localizedVariants', 'translationSourceContent']) ?? $content,
                $content->scheduled_publish_at
            );
        }

        return back()->with('status', 'Linked locale publishing settings updated.');
    }

    public function updatePublishingDestination(Request $request, Content $content): RedirectResponse
    {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $data = $request->validate([
            'content_destination_id' => ['nullable', 'uuid'],
        ]);
        $destinationId = trim((string) ($data['content_destination_id'] ?? ''));
        $destination = null;

        if ($destinationId !== '') {
            $destination = ContentDestination::query()
                ->where('workspace_id', (string) $content->workspace_id)
                ->where('status', 'active')
                ->whereIn('type', [
                    ContentDestinationType::WORDPRESS->value,
                    ContentDestinationType::LARAVEL->value,
                ])
                ->find($destinationId);

            if (! $destination) {
                return back()->withErrors([
                    'publish_destination' => 'Selected publishing destination is not available for this workspace.',
                ]);
            }
        }

        $billingSiteId = trim((string) data_get($destination?->config, 'billing_client_site_id', ''));
        $billingSite = $billingSiteId !== ''
            ? ClientSite::query()
                ->where('workspace_id', (string) $content->workspace_id)
                ->where('id', $billingSiteId)
                ->first()
            : null;

        DB::transaction(function () use ($content, $destination, $billingSite): void {
            $contentUpdates = [
                'content_destination_id' => $destination?->id,
                'publish_error' => null,
                'updated_by' => request()->user()?->id,
            ];

            if ($billingSite) {
                $contentUpdates['client_site_id'] = (string) $billingSite->id;
            }

            $content->forceFill($contentUpdates)->save();

            $relatedUpdates = ['content_destination_id' => $destination?->id];
            if ($billingSite) {
                $relatedUpdates['client_site_id'] = (string) $billingSite->id;
            }

            Draft::query()
                ->where('content_id', (string) $content->id)
                ->update($relatedUpdates);

            Brief::query()
                ->where('content_id', (string) $content->id)
                ->update($relatedUpdates);
        });

        return back()->with('status', $destination
            ? 'Publishing destination updated.'
            : 'Publishing destination cleared.'
        );
    }

    public function schedule(Request $request, Content $content): \Illuminate\Http\JsonResponse|RedirectResponse
    {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $content->loadMissing('translationSourceContent');

        if (
            $content->isTranslationVariant()
            && (bool) ($content->sync_with_source ?? true)
            && (bool) ($content->translationSourceContent?->auto_publish ?? true)
        ) {
            return back()->withErrors([
                'publish' => 'Manual scheduling is disabled while this locale is synced to its source.',
            ]);
        }

        $data = $request->validate([
            'scheduled_publish_at' => ['nullable', 'date'],
        ]);

        $scheduledAt = $this->parseScheduledPublishAt($data['scheduled_publish_at'] ?? null);

        DB::transaction(function () use ($content, $scheduledAt): void {
            $content->forceFill([
                'scheduled_publish_at' => $scheduledAt,
                'publish_status' => $scheduledAt ? 'scheduled' : 'draft',
                'publish_error' => null,
            ])->save();
        });

        DB::afterCommit(function () use ($content, $scheduledAt): void {
            try {
                $this->dispatchDuePublication($content->fresh(['clientSite', 'contentDestination']) ?? $content, $scheduledAt);
            } catch (\Throwable $exception) {
                Log::error('content.schedule_dispatch_failed', [
                    'content_id' => (string) $content->id,
                    'scheduled_at' => $scheduledAt?->toIso8601String(),
                    'is_translation_variant' => $content->isTranslationVariant(),
                    'locale' => $content->localeCode(),
                    'error' => $exception->getMessage(),
                    'exception' => $exception::class,
                    'throwable' => $exception,
                ]);
            }

            try {
                app(LocalePublishingSyncService::class)->syncSourceSchedule(
                    $content->fresh(['clientSite', 'contentDestination', 'localizedVariants', 'translationSourceContent']) ?? $content,
                    $scheduledAt
                );
            } catch (\Throwable $exception) {
                Log::error('content.locale_sync.schedule_failed', [
                    'content_id' => (string) $content->id,
                    'scheduled_at' => $scheduledAt?->toIso8601String(),
                    'locale' => $content->localeCode(),
                    'error' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);
            }
        });

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'status' => 'ok',
                'content_id' => (string) $content->id,
                'scheduled_publish_at' => $scheduledAt?->toIso8601String(),
                'scheduled_date' => $scheduledAt?->format('Y-m-d'),
                'scheduled_time' => $scheduledAt?->format('H:i'),
            ]);
        }

        return back()->with('status', $scheduledAt ? 'Content scheduled for publish.' : 'Publish schedule cleared.');
    }

    public function bulkSchedule(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Content::class);

        $data = $request->validate([
            'content_ids' => ['required', 'array', 'min:1'],
            'content_ids.*' => ['required', 'uuid'],
            'scheduled_publish_at' => ['nullable', 'date'],
        ]);

        $organizationId = (int) $request->user()->organization_id;
        $scheduledAt = $this->parseScheduledPublishAt($data['scheduled_publish_at'] ?? null);

        $contents = Content::query()
            ->whereIn('id', (array) $data['content_ids'])
            ->where(function ($query) use ($organizationId): void {
                $query->whereHas('workspace', fn ($workspaceQuery) => $workspaceQuery->where('organization_id', $organizationId))
                    ->orWhereHas('clientSite.workspace', fn ($workspaceQuery) => $workspaceQuery->where('organization_id', $organizationId));
            })
            ->get();

        $updated = 0;
        foreach ($contents as $content) {
            if (! $request->user()->can('update', $content)) {
                continue;
            }

            DB::transaction(function () use ($content, $scheduledAt): void {
                $content->forceFill([
                    'scheduled_publish_at' => $scheduledAt,
                    'publish_status' => $scheduledAt ? 'scheduled' : 'draft',
                    'publish_error' => null,
                ])->save();
            });

            DB::afterCommit(function () use ($content, $scheduledAt): void {
                try {
                    $this->dispatchDuePublication($content->fresh(['clientSite', 'contentDestination']) ?? $content, $scheduledAt);
                } catch (\Throwable $exception) {
                    Log::error('content.bulk_schedule_dispatch_failed', [
                        'content_id' => (string) $content->id,
                        'scheduled_at' => $scheduledAt?->toIso8601String(),
                        'is_translation_variant' => $content->isTranslationVariant(),
                        'locale' => $content->localeCode(),
                        'error' => $exception->getMessage(),
                        'exception' => $exception::class,
                        'throwable' => $exception,
                    ]);
                }

                try {
                    app(LocalePublishingSyncService::class)->syncSourceSchedule(
                        $content->fresh(['clientSite', 'contentDestination', 'localizedVariants', 'translationSourceContent']) ?? $content,
                        $scheduledAt
                    );
                } catch (\Throwable $exception) {
                    Log::error('content.locale_sync.bulk_schedule_failed', [
                        'content_id' => (string) $content->id,
                        'scheduled_at' => $scheduledAt?->toIso8601String(),
                        'locale' => $content->localeCode(),
                        'error' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ]);
                }
            });

            $updated++;
        }

        return back()->with('status', $updated.' content item(s) scheduled.');
    }

    public function bulkSyncLaravel(
        Request $request,
        LaravelConnectorPublishingService $laravelPublishingService,
        LaravelConnectorDestinationResolver $laravelDestinationResolver,
        ContentPublicationService $publicationService,
    ): RedirectResponse {
        $this->authorize('viewAny', Content::class);

        $data = $request->validate([
            'content_ids' => ['required', 'array', 'min:1'],
            'content_ids.*' => ['required', 'uuid'],
        ]);

        $organizationId = (int) $request->user()->organization_id;

        $contents = Content::query()
            ->whereIn('id', (array) $data['content_ids'])
            ->where(function ($query) use ($organizationId): void {
                $query->whereHas('workspace', fn ($workspaceQuery) => $workspaceQuery->where('organization_id', $organizationId))
                    ->orWhereHas('clientSite.workspace', fn ($workspaceQuery) => $workspaceQuery->where('organization_id', $organizationId));
            })
            ->get();

        $queued = 0;
        $skipped = 0;

        foreach ($contents as $content) {
            if (! $request->user()->can('update', $content)) {
                $skipped++;

                continue;
            }

            try {
                $laravelDestination = $laravelDestinationResolver->resolveForContent($content);

                if ($laravelDestination) {
                    $publicationService->dispatchLaravelPublication($content, null, [
                        'source' => 'app.content.sync-bulk',
                        'force' => true,
                        'allow_stale_reclaim' => true,
                    ]);
                } else {
                    $laravelPublishingService->publish($content, null, 'bulk_resync', 'app.content.sync-bulk');
                }

                $queued++;
            } catch (RuntimeException) {
                $skipped++;
            }
        }

        return back()->with('status', sprintf(
            'Queued %d Laravel connector sync(s). Skipped %d item(s).',
            $queued,
            $skipped
        ));
    }

    public function publishNow(
        Request $request,
        Content $content,
        ContentLocalizationService $contentLocalizationService,
        LocalePublishingSyncService $localePublishingSyncService,
        LaravelConnectorPublishingService $laravelPublishingService,
        LaravelConnectorDestinationResolver $laravelDestinationResolver,
        ContentPublicationService $publicationService,
    ): \Illuminate\Http\JsonResponse|RedirectResponse
    {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $data = $request->validate([
            'locale' => ['nullable', 'string'],
            'human_content_override' => ['nullable', 'boolean'],
        ]);
        $requestedLocale = filled($data['locale'] ?? null)
            ? SupportedLanguage::fromStringOrDefault((string) $data['locale'])->value
            : null;
        $humanContentPublicationContext = [
            'human_content_override' => $request->boolean('human_content_override'),
            'user_id' => $request->user()?->id,
        ];

        $content->loadMissing('clientSite', 'contentDestination');
        $destinationType = $content->contentDestination?->resolvedType()
            ?? ContentDestinationType::fromNormalized($content->clientSite?->type);

        if ($destinationType === ContentDestinationType::WORDPRESS) {
            $publishContent = $requestedLocale !== null
                ? $content->localizedVariantFor($requestedLocale)
                : $content;

            if (! $publishContent instanceof Content) {
                return back()->withErrors([
                    'publish' => sprintf('No %s locale variant is available to publish.', strtoupper((string) $requestedLocale)),
                ]);
            }

            $this->assertContentInUserOrganization($request, $publishContent);

            $publishContent->forceFill([
                'scheduled_publish_at' => null,
                'publish_error' => null,
            ])->save();

            $dispatch = $publicationService->dispatchWordPressPublication($publishContent, null, [
                'source' => 'app.content.publish_now',
                'locale' => $publishContent->localeCode(),
            ] + $humanContentPublicationContext);

            if (! $publishContent->isTranslationVariant()) {
                $localePublishingSyncService->syncSourceImmediatePublish(
                    $publishContent->fresh(['clientSite', 'contentDestination', 'localizedVariants', 'translationSourceContent']) ?? $publishContent
                );
            }

            if (! (bool) ($dispatch['queued'] ?? false) && (string) ($dispatch['skip_reason'] ?? '') === 'content_preflight_blocked') {
                return back()->withErrors([
                    'publish' => (string) ($dispatch['skip_message'] ?? 'Publication blocked by content preflight checks.'),
                ]);
            }

            return back()->with('status', (bool) ($dispatch['queued'] ?? false)
                ? sprintf('%s publish job queued.', strtoupper($publishContent->localeCode()))
                : 'Publication was already queued or processed.');
        }

        if ($destinationType === ContentDestinationType::LARAVEL) {
            $destination = $laravelDestinationResolver->resolveForContent($content);

            if ($destination || $requestedLocale !== null) {
                try {
                    $result = $publicationService->publishVariantNow(
                        $content,
                        $requestedLocale ?? $content->localeCode(),
                        ['source' => 'app.content.publish-now'] + $humanContentPublicationContext
                    );
                } catch (RuntimeException $exception) {
                    if ($request->expectsJson() || $request->ajax()) {
                        return response()->json([
                            'status' => 'error',
                            'message' => $exception->getMessage(),
                        ], 422);
                    }

                    return back()->withErrors(['publish' => $exception->getMessage()]);
                }

                $variant = $result['content'];
                $variantState = collect($contentLocalizationService->statusMatrix($variant))
                    ->first(fn (array $row): bool => (string) ($row['locale'] ?? '') === (string) $result['locale']);
                $statusMessage = (bool) ($result['queued'] ?? false)
                    ? 'Laravel publication queued.'
                    : 'Laravel publication was already queued or processed.';
                if (! (bool) ($result['queued'] ?? false) && (string) ($result['skip_reason'] ?? '') === 'content_preflight_blocked') {
                    $statusMessage = (string) ($result['skip_message'] ?? 'Publication blocked by content preflight checks.');
                }

                if ($request->expectsJson() || $request->ajax()) {
                    if (! (bool) ($result['queued'] ?? false) && (string) ($result['skip_reason'] ?? '') === 'content_preflight_blocked') {
                        return response()->json([
                            'status' => 'error',
                            'message' => $statusMessage,
                            'content_id' => (string) $variant->id,
                            'locale' => (string) $result['locale'],
                            'publish_status' => (string) ($variant->publish_status ?? ''),
                            'queued' => false,
                        ], 422);
                    }

                    return response()->json([
                        'status' => 'ok',
                        'message' => $statusMessage,
                        'content_id' => (string) $variant->id,
                        'locale' => (string) $result['locale'],
                        'publish_status' => (string) ($variant->publish_status ?? ''),
                        'delivery_status' => (string) ($variantState['delivery_status'] ?? $variant->delivery_status ?? 'pending'),
                        'state' => $variantState,
                        'queued' => (bool) ($result['queued'] ?? false),
                    ]);
                }

                if ((string) $variant->id === (string) $content->id) {
                    $localePublishingSyncService->syncSourceImmediatePublish($variant);
                }

                if (! (bool) ($result['queued'] ?? false) && (string) ($result['skip_reason'] ?? '') === 'content_preflight_blocked') {
                    return back()->withErrors(['publish' => $statusMessage]);
                }

                return back()->with('status', $statusMessage);
            }

            try {
                $laravelPublishingService->publish($content, null, 'publish_now', 'app.content.publish-now', $humanContentPublicationContext);
            } catch (RuntimeException $exception) {
                return back()->withErrors(['publish' => $exception->getMessage()]);
            }

            $localePublishingSyncService->syncSourceImmediatePublish(
                $content->fresh(['clientSite', 'contentDestination', 'localizedVariants', 'translationSourceContent']) ?? $content
            );

            return back()->with('status', 'Content marked as published locally. Laravel connector sync is pending.');
        }

        abort(403, 'Publish now is not allowed for this site type.');
    }

    public function pushToSite(Request $request, Content $content): RedirectResponse
    {
        $this->assertContentInUserOrganization($request, $content);
        $this->authorize('update', $content);

        $data = $request->validate([
            'site_id' => ['required', 'uuid'],
        ]);

        $organizationId = (int) $request->user()->organization_id;
        $site = ClientSite::query()
            ->whereKey((string) $data['site_id'])
            ->where('type', ClientSite::TYPE_WORDPRESS)
            ->where('is_active', true)
            ->where('status', '!=', 'disabled')
            ->whereHas('workspace', fn ($q) => $q->where('organization_id', $organizationId))
            ->first();

        if (! $site) {
            return back()->withErrors(['publish' => 'Selected WordPress site is not available.']);
        }

        DB::transaction(function () use ($content, $site): void {
            $content->update([
                'client_site_id' => $site->id,
                'publish_status' => 'scheduled',
                'scheduled_publish_at' => now(),
                'publish_error' => null,
            ]);

            Draft::query()
                ->where('content_id', $content->id)
                ->update([
                    'client_site_id' => $site->id,
                ]);

            Brief::query()
                ->where('content_id', $content->id)
                ->update([
                    'client_site_id' => $site->id,
                ]);
        });

        app(ContentPublicationService::class)->dispatchWordPressPublication($content->fresh(), null, [
            'source' => 'app.content.push_to_site',
        ]);

        return back()->with('status', 'Draft queued for push to the selected WordPress site.');
    }

    private function assertContentInUserOrganization(Request $request, Content $content): void
    {
        $content->loadMissing('workspace', 'clientSite.workspace');

        $organizationId = (int) $request->user()->organization_id;
        $workspaceOrganizationId = (int) ($content->workspace?->organization_id ?? 0);
        $clientSiteOrganizationId = (int) ($content->clientSite?->workspace?->organization_id ?? 0);

        if ($workspaceOrganizationId !== $organizationId && $clientSiteOrganizationId !== $organizationId) {
            abort(404);
        }
    }

    private function resolveSelectedRefreshRecommendationsRun(Request $request, Content $content): ?AgentRun
    {
        $selectedRunId = trim((string) $request->query('refresh_recommendations_run', ''));
        $runs = AgentRun::query()
            ->where('agent_key', ContentRefreshAgent::KEY)
            ->where('content_id', (string) $content->id)
            ->whereIn('trigger_type', ['manual', 'event'])
            ->latest('created_at')
            ->limit(20)
            ->get();

        if ($runs->isEmpty()) {
            return null;
        }

        $currentHash = $this->resolveCurrentEditableRevisionHash($content);
        $latestMatchingCurrentRevision = $runs->first(function (AgentRun $run) use ($currentHash): bool {
            $runHash = trim((string) data_get($run->input_payload ?? [], 'metadata.source_revision_hash', ''));

            return $runHash !== '' && $runHash === $currentHash;
        });

        if ($selectedRunId !== '') {
            $selectedRun = $runs->firstWhere('id', $selectedRunId);
            if ($selectedRun) {
                $selectedHash = trim((string) data_get($selectedRun->input_payload ?? [], 'metadata.source_revision_hash', ''));
                if ($latestMatchingCurrentRevision && $selectedHash !== $currentHash) {
                    return $latestMatchingCurrentRevision;
                }

                return $selectedRun;
            }
        }

        return $latestMatchingCurrentRevision ?: $runs->first();
    }

    private function resolveSelectedInternalLinkingRun(Request $request, Content $content): ?AgentRun
    {
        $selectedRunId = trim((string) $request->query('internal_linking_run', ''));
        $baseQuery = AgentRun::query()
            ->where('agent_key', InternalLinkingAgent::KEY)
            ->where('content_id', (string) $content->id)
            ->whereIn('trigger_type', ['manual', 'event']);

        if ($selectedRunId !== '') {
            $selectedRun = (clone $baseQuery)->whereKey($selectedRunId)->first();
            if ($selectedRun) {
                return $selectedRun;
            }
        }

        return (clone $baseQuery)
            ->latest('created_at')
            ->first();
    }

    private function resolveSelectedLocalizationRun(Request $request, Content $content): ?AgentRun
    {
        $selectedRunId = trim((string) $request->query('localization_run', ''));
        $baseQuery = AgentRun::query()
            ->where('agent_key', LocalizationAgent::KEY)
            ->where('content_id', (string) $content->id)
            ->whereIn('trigger_type', ['manual', 'event']);

        if ($selectedRunId !== '') {
            $selectedRun = (clone $baseQuery)->whereKey($selectedRunId)->first();
            if ($selectedRun) {
                return $selectedRun;
            }
        }

        return (clone $baseQuery)
            ->latest('created_at')
            ->first();
    }

    private function resolveSelectedContentInsight(
        Request $request,
        bool $hasLocalization,
        bool $hasRefresh,
        bool $hasLinks,
    ): ?string {
        $selectedInsight = trim((string) $request->query('insight', ''));

        if (in_array($selectedInsight, ['localization', 'refresh', 'links'], true)) {
            return match ($selectedInsight) {
                'localization' => $hasLocalization ? 'localization' : null,
                'refresh' => $hasRefresh ? 'refresh' : null,
                'links' => $hasLinks ? 'links' : null,
            };
        }

        if (trim((string) $request->query('localization_run', '')) !== '' && $hasLocalization) {
            return 'localization';
        }

        if (trim((string) $request->query('refresh_recommendations_run', '')) !== '' && $hasRefresh) {
            return 'refresh';
        }

        if (trim((string) $request->query('internal_linking_run', '')) !== '' && $hasLinks) {
            return 'links';
        }

        return null;
    }

    private function hasLocalizationFindings(?AgentRun $run): bool
    {
        if (! $run) {
            return false;
        }

        $suggestions = collect((array) data_get(
            $run->output_payload ?? [],
            'suggestions',
            data_get($run->output_payload ?? [], 'raw_payload.recommendations', [])
        ))->filter(fn (mixed $item): bool => is_array($item));

        return $suggestions->isNotEmpty();
    }

    private function hasRefreshFindings(?AgentRun $run): bool
    {
        if (! $run) {
            return false;
        }

        $reasons = collect((array) data_get($run->output_payload ?? [], 'raw_payload.reasons', []))
            ->filter(fn (mixed $item): bool => is_array($item));
        $actions = collect((array) data_get($run->output_payload ?? [], 'raw_payload.suggested_actions', []))
            ->filter(fn (mixed $item): bool => is_array($item));
        $warnings = collect((array) data_get($run->output_payload ?? [], 'warnings', []))
            ->filter(fn (mixed $item): bool => is_array($item));

        if ($reasons->isNotEmpty() || $actions->isNotEmpty() || $warnings->isNotEmpty()) {
            return true;
        }

        $refreshScore = data_get($run->output_payload ?? [], 'metrics.refresh_score', data_get($run->output_payload ?? [], 'raw_payload.refresh_score'));

        return is_numeric($refreshScore) && (float) $refreshScore < 70;
    }

    private function hasInternalLinkFindings(?AgentRun $run): bool
    {
        if (! $run) {
            return false;
        }

        $suggestions = collect((array) data_get($run->output_payload ?? [], 'suggestions', []))
            ->filter(fn (mixed $item): bool => is_array($item));

        return $suggestions->isNotEmpty();
    }

    private function resolveInsightStatusLabel(bool $hasRun, bool $hasFindings): string
    {
        if (! $hasRun) {
            return 'Not analyzed';
        }

        return $hasFindings ? 'Needs attention' : 'Good';
    }

    private function resolveInsightStatusClass(string $statusLabel): string
    {
        return match ($statusLabel) {
            'Good' => 'inline-flex items-center rounded-full bg-emerald-50 px-3 py-1 text-sm font-medium text-emerald-700',
            'Needs attention' => 'inline-flex items-center rounded-full bg-amber-50 px-3 py-1 text-sm font-medium text-amber-700',
            default => 'inline-flex items-center rounded-full bg-slate-50 px-3 py-1 text-sm font-medium text-slate-600',
        };
    }

    private function calculateContentHealthScore(
        bool $hasLocalization,
        bool $hasLocalizationResults,
        bool $hasRefresh,
        bool $hasRefreshResults,
        bool $hasLinks,
        bool $hasLinksResults,
    ): int {
        $score = 100;

        $score -= ! $hasLocalization ? 10 : ($hasLocalizationResults ? 15 : 0);
        $score -= ! $hasRefresh ? 10 : ($hasRefreshResults ? 15 : 0);
        $score -= ! $hasLinks ? 10 : ($hasLinksResults ? 15 : 0);

        return max(0, min(100, $score));
    }

    private function resolveInsightSummary(?AgentRun $run, string $fallback): string
    {
        $summary = trim((string) data_get($run?->output_payload ?? [], 'summary', $run?->summary ?? ''));

        if ($summary === '') {
            return $fallback;
        }

        return (string) Str::of($summary)->squish()->limit(120);
    }

    private function assertImprovementRunBelongsToContent(ContentImprovementRun $run, Content $content): void
    {
        if ((string) $run->content_id !== (string) $content->id) {
            abort(404);
        }
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function contentImprovementJsonResponse(Content $content, ?string $toastMessage = null, array $extra = []): JsonResponse
    {
        /** @var ContentImprovementService $improvements */
        $improvements = app(ContentImprovementService::class);
        $dashboard = $improvements->dashboard($content->fresh());
        $options = $this->resolveContentImprovementOptions($content->fresh(), $dashboard, $improvements);

        return response()->json($extra + [
            'toast' => $toastMessage,
            'actions_html' => view('app.content.partials.content-improvement-actions', [
                'content' => $content->fresh(),
                'contentImprovementOptions' => $options,
                'contentImprovementDashboard' => $dashboard,
            ])->render(),
            'monitor_html' => view('app.content.partials.content-improvement-monitor', [
                'content' => $content->fresh(),
                'contentImprovementDashboard' => $dashboard,
            ])->render(),
            'generated_html' => view('app.content.partials.content-improvement-generated', [
                'content' => $content->fresh(),
                'contentImprovementDashboard' => $dashboard,
            ])->render(),
            'events' => collect($dashboard['events'] ?? [])->map(fn ($event): array => [
                'id' => (int) $event->id,
                'event_type' => (string) $event->event_type,
                'message' => (string) $event->message,
            ])->values()->all(),
            'latest_event_id' => (int) ($dashboard['latest_event_id'] ?? 0),
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function resolveContentImprovementOptions(
        Content $content,
        array $dashboard,
        ContentImprovementService $improvements,
    ): array {
        $options = $improvements->optionsForRecommendations(
            collect((array) data_get($content->aeo_breakdown, 'improvements', []))
                ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
                ->values()
                ->all()
        );

        $latestByRecommendationKey = $dashboard['latest_by_recommendation_key'] instanceof \Illuminate\Support\Collection
            ? $dashboard['latest_by_recommendation_key']
            : collect($dashboard['latest_by_recommendation_key'] ?? []);

        return array_map(function (array $option) use ($latestByRecommendationKey): array {
            /** @var \App\Models\ContentImprovementRun|null $latestRun */
            $latestRun = $latestByRecommendationKey->get((string) ($option['key'] ?? ''));
            if (! $latestRun) {
                $latestRun = $latestByRecommendationKey->first(function (ContentImprovementRun $candidate) use ($option): bool {
                    return trim(\Illuminate\Support\Str::lower((string) ($candidate->recommendation_label ?? ''))) === trim(\Illuminate\Support\Str::lower((string) ($option['description'] ?? '')));
                });
            }
            $state = 'generate';
            $stateLabel = 'Generate';

            if ($latestRun instanceof ContentImprovementRun) {
                if ($latestRun->status === ContentImprovementRun::STATUS_COMPLETED) {
                    $state = 'generated';
                    $stateLabel = $latestRun->target_draft_id ? 'Review draft' : 'Generated';
                } elseif ($latestRun->status === ContentImprovementRun::STATUS_NO_CHANGES) {
                    $state = 'no_changes';
                    $stateLabel = 'No changes';
                } elseif ($latestRun->status === ContentImprovementRun::STATUS_FAILED) {
                    $state = 'failed';
                    $stateLabel = 'Regenerate';
                }
            }

            $option['state'] = $state;
            $option['state_label'] = $stateLabel;
            $option['latest_run_id'] = $latestRun?->id ? (string) $latestRun->id : null;
            $option['latest_run_status'] = $latestRun?->status;
            $option['target_draft_id'] = $latestRun?->target_draft_id ? (string) $latestRun->target_draft_id : null;
            $option['latest_summary'] = $latestRun?->generated_summary;
            $option['latest_diff_summary'] = $latestRun?->diff_summary;

            return $option;
        }, $options);
    }

    private function resolveCurrentEditableRevisionHash(Content $content): string
    {
        $content->loadMissing(['drafts', 'currentVersion', 'currentRevision']);

        $latestDraft = $content->drafts
            ->sortByDesc(fn (Draft $draft): string => sprintf(
                '%010d-%s',
                max(
                    $draft->updated_at?->getTimestamp() ?? 0,
                    $draft->created_at?->getTimestamp() ?? 0,
                ),
                (string) $draft->id,
            ))
            ->first();

        $html = trim((string) (
            $latestDraft?->content_html
            ?: $content->currentRevision?->content_html
            ?: $content->currentVersion?->body
            ?: ''
        ));

        return sha1(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
    }

    private function answerBlocksJsonResponse(Content $content): JsonResponse
    {
        $content = $content->fresh(['answerBlocks']) ?? $content->loadMissing('answerBlocks');
        $status = (string) ($content->answer_block_generation_status ?? '');
        $persistedCount = (int) ($content->answer_block_generation_persisted_count ?? $content->answerBlocks->count());

        return response()->json([
            'content_id' => (string) $content->id,
            'answers' => $content->answerBlocks->map(fn (StructuredAnswerBlock $block): array => [
                'id' => (string) $block->id,
                'question' => (string) $block->question,
                'answer' => (string) $block->answer,
                'entities' => array_values((array) ($block->entities ?? [])),
                'platforms' => array_values((array) ($block->platforms ?? [])),
                'order' => (int) $block->order,
            ])->values()->all(),
            'aeo_score' => $content->aeo_score,
            'generation' => [
                'status' => $status !== '' ? $status : null,
                'persisted_blocks_count' => $persistedCount,
                'last_error' => $content->answer_block_generation_last_error,
                'last_warning' => $content->answer_block_generation_last_warning,
                'started_at' => $content->answer_block_generation_started_at?->toIso8601String(),
                'completed_at' => $content->answer_block_generation_completed_at?->toIso8601String(),
                'failed_at' => $content->answer_block_generation_failed_at?->toIso8601String(),
                'draft_revision_id' => $content->answer_block_generation_draft_revision_id,
                'is_active' => $content->answerBlockGenerationIsActive(),
                'meta' => $content->answer_block_generation_meta,
            ],
            'render' => [
                'mode' => $content->answer_block_render_mode,
                'visibility' => $content->answer_block_visibility,
                'position' => $content->answer_block_position,
                'max_visible' => $content->answer_block_max_visible,
            ],
            'status_html' => view('app.content.partials.answer-block-status', [
                'content' => $content,
            ])->render(),
            'list_html' => view('app.content.partials.answer-block-list', [
                'content' => $content,
            ])->render(),
        ]);
    }

    private function resolveCreditCostForRegeneration(Draft $draft): int
    {
        $currentCost = (int) ($draft->credit_cost ?? 0);
        if ($currentCost > 0) {
            return $currentCost;
        }

        $preferredActionKey = match (strtolower((string) $draft->output_type)) {
            'faq', 'faq_set' => 'content.faq_set',
            'outline' => 'content.outline',
            'brief' => 'content.brief',
            default => 'content.article',
        };

        $action = CreditAction::query()
            ->where('key', $preferredActionKey)
            ->where('is_active', true)
            ->first();

        if (! $action) {
            $action = CreditAction::query()
                ->where('category', 'content')
                ->where('is_active', true)
                ->orderBy('credits_cost')
                ->first();
        }

        if (! $action) {
            return 0;
        }

        $draft->credit_action_id = $draft->credit_action_id ?: $action->id;
        $draft->credit_cost = (int) $action->credits_cost;
        $draft->save();

        return (int) $draft->credit_cost;
    }

    private function resolveCalendarAnchor(mixed $raw): Carbon
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return now()->startOfDay();
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return now()->startOfDay();
        }
    }

    private function resolveCalendarSelectedDate(mixed $raw, Carbon $rangeStart, Carbon $rangeEnd): ?Carbon
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }

        if ($date->lt($rangeStart->copy()->startOfDay()) || $date->gt($rangeEnd->copy()->startOfDay())) {
            return null;
        }

        return $date;
    }

    private function parseScheduledPublishAt(mixed $raw): ?Carbon
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function presentCalendarItem(Request $request, Content $content): ?array
    {
        $placement = $this->resolveCalendarPlacement($content);
        if ($placement === null) {
            return null;
        }

        $presenter = ContentStatusPresenter::for($content);
        $state = $this->calendarItemState($content, $presenter);
        $status = $this->calendarItemStatus($state, $presenter);
        $canUpdate = $request->user()->can('update', $content);
        $supportsImmediatePublish = $this->calendarSupportsImmediatePublish($content);
        $continueUrl = $content->brief
            ? route('app.content.workspace.show', $content->brief)
            : route('app.content.show', $content);
        $openUrl = route('app.content.show', $content);

        return [
            'id' => (string) $content->id,
            'title' => (string) $content->title,
            'calendar_datetime' => $placement['datetime']->toIso8601String(),
            'calendar_timestamp' => $placement['datetime']->getTimestamp(),
            'calendar_kind' => $placement['kind'],
            'date_key' => $placement['datetime']->format('Y-m-d'),
            'scheduled_at_label' => $placement['datetime']->format('H:i'),
            'scheduled_at_value' => $placement['datetime']->format('Y-m-d\\TH:i')
                ?: $placement['datetime']->copy()->setTime(9, 0)->format('Y-m-d\\TH:i'),
            'channel_label' => $this->calendarChannelLabel($content),
            'site_name' => (string) ($content->clientSite?->name ?? 'Unknown site'),
            'status' => $status,
            'state' => $state,
            'open_url' => $openUrl,
            'schedule_url' => route('app.content.schedule', $content),
            'can_drag' => $canUpdate,
            'detail_actions' => $this->calendarItemActions(
                state: $state,
                canUpdate: $canUpdate,
                supportsImmediatePublish: $supportsImmediatePublish,
                openUrl: $openUrl,
                continueUrl: $continueUrl,
                viewUrl: $presenter->publishedUrl(),
                publishNowUrl: route('app.content.publish-now', $content),
            ),
            'schedule_action' => $this->calendarScheduleAction($state, $canUpdate, route('app.content.schedule', $content)),
        ];
    }

    private function resolveCalendarPlacement(Content $content): ?array
    {
        $explicitPublishedAt = $this->calendarDateFromValue($content->getAttribute('published_at'));
        $firstPublicationAt = $this->calendarDateFromValue($content->getAttribute('first_successful_publication_at'));
        $firstDeliveredDraftAt = $this->calendarDateFromValue($content->getAttribute('first_delivered_draft_at'));

        if ($explicitPublishedAt) {
            return [
                'datetime' => $explicitPublishedAt,
                'kind' => 'published',
            ];
        }

        if ($firstPublicationAt) {
            return [
                'datetime' => $firstPublicationAt,
                'kind' => 'published',
            ];
        }

        if ($firstDeliveredDraftAt) {
            return [
                'datetime' => $firstDeliveredDraftAt,
                'kind' => 'published',
            ];
        }

        if ($content->scheduled_publish_at) {
            return [
                'datetime' => $content->scheduled_publish_at->copy(),
                'kind' => 'scheduled',
            ];
        }

        return null;
    }

    private function calendarItemFallsInRange(array $item, Carbon $rangeStart, Carbon $rangeEnd): bool
    {
        $calendarDate = $this->calendarDateFromValue($item['calendar_datetime'] ?? null);

        if (! $calendarDate) {
            return false;
        }

        return $calendarDate->betweenIncluded($rangeStart, $rangeEnd);
    }

    private function calendarDateFromValue(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return null;
        }

        try {
            return Carbon::parse($stringValue);
        } catch (Throwable) {
            return null;
        }
    }

    private function calendarSuccessfulPublicationStatuses(): array
    {
        return [
            'delivered',
            'partial_success',
        ];
    }

    private function calendarSuccessfulDraftStatuses(): array
    {
        return [
            'delivered',
            'partial_success',
        ];
    }

    private function calendarSupportsExplicitPublishedAt(): bool
    {
        static $supportsExplicitPublishedAt;

        if ($supportsExplicitPublishedAt !== null) {
            return $supportsExplicitPublishedAt;
        }

        $supportsExplicitPublishedAt = Schema::hasColumn((new Content())->getTable(), 'published_at');

        return $supportsExplicitPublishedAt;
    }

    private function calendarItemState(Content $content, ContentStatusPresenter $presenter): string
    {
        if ($presenter->isFullyPublished()
            || (($content->publish_status ?? '') === 'published')
            || filled($presenter->publishedUrl())
            || $presenter->remotePublishStatus()?->isLive()
        ) {
            return 'published';
        }

        if ($presenter->deliveryStatus()->isInProgress()) {
            return 'processing';
        }

        if ($presenter->deliveryStatus()->needsAttention()) {
            return 'failed';
        }

        if ($presenter->lifecycleStatus()->value === 'archived') {
            return 'archived';
        }

        if ((($content->publish_status ?? '') === 'scheduled') || (($presenter->remotePublishStatus()?->value ?? '') === 'scheduled')) {
            return 'scheduled';
        }

        if ($presenter->lifecycleStatus()->value === 'review') {
            return 'review';
        }

        if ($presenter->lifecycleStatus()->value === 'ready_to_deliver') {
            return 'ready';
        }

        return 'draft';
    }

    private function calendarItemStatus(string $state, ContentStatusPresenter $presenter): array
    {
        return match ($state) {
            'published' => ['label' => 'Published', 'color' => 'green', 'icon' => 'globe-alt'],
            'scheduled' => ['label' => 'Scheduled', 'color' => 'sky', 'icon' => 'clock'],
            'processing' => ['label' => 'Publishing', 'color' => 'amber', 'icon' => 'arrow-path'],
            'failed' => [
                'label' => $presenter->deliveryLabel(),
                'color' => $presenter->deliveryColor(),
                'icon' => $presenter->deliveryIcon(),
            ],
            'archived' => ['label' => 'Archived', 'color' => 'gray', 'icon' => 'archive-box'],
            'review' => ['label' => 'In Review', 'color' => 'amber', 'icon' => 'eye'],
            'ready' => ['label' => 'Ready', 'color' => 'emerald', 'icon' => 'check-circle'],
            default => ['label' => 'Draft', 'color' => 'slate', 'icon' => 'pencil'],
        };
    }

    private function calendarItemActions(
        string $state,
        bool $canUpdate,
        bool $supportsImmediatePublish,
        string $openUrl,
        string $continueUrl,
        ?string $viewUrl,
        string $publishNowUrl,
    ): array {
        $actions = [];

        if ($state === 'published') {
            $actions[] = ['type' => 'link', 'label' => 'Open', 'url' => $openUrl];
            if ($viewUrl) {
                $actions[] = ['type' => 'external', 'label' => 'View', 'url' => $viewUrl];
            }

            return $actions;
        }

        if (in_array($state, ['draft', 'review', 'ready'], true)) {
            $actions[] = ['type' => 'link', 'label' => 'Continue', 'url' => $continueUrl];

            return $actions;
        }

        $actions[] = ['type' => 'link', 'label' => 'Open', 'url' => $openUrl];

        if ($state === 'scheduled' && $canUpdate && $supportsImmediatePublish) {
            $actions[] = ['type' => 'post', 'label' => 'Publish now', 'url' => $publishNowUrl];
        }

        if ($state === 'failed' && $canUpdate) {
            if ($supportsImmediatePublish) {
                $actions[] = ['type' => 'post', 'label' => 'Retry publish', 'url' => $publishNowUrl];
            }
            if ($viewUrl) {
                $actions[] = ['type' => 'external', 'label' => 'View', 'url' => $viewUrl];
            }
        }

        if ($state === 'archived' && $viewUrl) {
            $actions[] = ['type' => 'external', 'label' => 'View', 'url' => $viewUrl];
        }

        return $actions;
    }

    private function calendarScheduleAction(string $state, bool $canUpdate, string $url): ?array
    {
        if (! $canUpdate) {
            return null;
        }

        return match ($state) {
            'scheduled' => ['label' => 'Reschedule', 'url' => $url],
            'draft', 'review', 'ready', 'failed' => ['label' => 'Schedule', 'url' => $url],
            default => null,
        };
    }

    private function calendarSupportsImmediatePublish(Content $content): bool
    {
        $siteType = ClientSite::normalizeType((string) ($content->clientSite?->type ?? ''));

        return in_array($siteType, [ClientSite::TYPE_WORDPRESS, ClientSite::TYPE_LARAVEL], true);
    }

    private function calendarChannelLabel(Content $content): string
    {
        $siteType = ClientSite::normalizeType((string) ($content->clientSite?->type ?? ''));

        return match ($siteType) {
            ClientSite::TYPE_WORDPRESS => 'WordPress',
            ClientSite::TYPE_LARAVEL => 'Laravel',
            default => (string) ($content->clientSite?->name ?? 'Content'),
        };
    }

    private function calendarRoute(Carbon $anchor, string $mode, string $siteId, ?string $selectedDate, bool $showWeekNumbers = false): string
    {
        $query = [
            'mode' => $mode,
            'date' => $anchor->format('Y-m-d'),
        ];

        if ($siteId !== '') {
            $query['site'] = $siteId;
        }

        if ($selectedDate !== null && $selectedDate !== '') {
            $query['selected_date'] = $selectedDate;
        }

        if ($showWeekNumbers) {
            $query['week_numbers'] = 1;
        }

        return route('app.content.calendar', $query);
    }

    private function calendarCreateUrl(Carbon $date, string $siteId): string
    {
        $query = [
            'create' => 1,
            'scheduled_publish_at' => $date->copy()->setTime(9, 0)->format('Y-m-d\\TH:i'),
        ];

        if ($siteId !== '') {
            $query['site_id'] = $siteId;
        }

        return route('app.content.index', $query);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $days
     * @return array{planned_count: int, empty_days: int, week_empty_days: int, suggestion: string|null}
     */
    private function calculateCalendarStats(\Illuminate\Support\Collection $days, Carbon $today): array
    {
        $futureDays = $days->filter(fn (array $day): bool => ! $day['is_past']);
        $plannedCount = $futureDays->sum('item_count');
        $emptyDays = $futureDays->filter(fn (array $day): bool => $day['item_count'] === 0)->count();

        // Count empty days in current week
        $weekEmptyDays = $days
            ->filter(fn (array $day): bool => Carbon::parse($day['key'])->isSameWeek($today) && $day['item_count'] === 0 && ! $day['is_past'])
            ->count();

        $suggestion = null;
        if ($weekEmptyDays > 0) {
            $suggestion = $weekEmptyDays === 1
                ? 'Je hebt nog 1 lege dag deze week.'
                : "Je hebt nog {$weekEmptyDays} lege dagen deze week.";
        }

        return [
            'planned_count' => (int) $plannedCount,
            'empty_days' => (int) $emptyDays,
            'week_empty_days' => (int) $weekEmptyDays,
            'suggestion' => $suggestion,
        ];
    }

    public function quickPlan(Request $request): RedirectResponse
    {
        $this->authorize('create', Content::class);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'series_id' => ['nullable', 'uuid'],
            'type' => ['nullable', 'in:article,page,post'],
            'status' => ['nullable', 'in:brief,draft,scheduled'],
            'scheduled_date' => ['nullable', 'date'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'intent_keys' => ['nullable', 'array'],
            'intent_keys.*' => ['string', 'in:' . implode(',', ContentIntentCatalog::allowedKeys())],
            'site_id' => ['nullable', 'uuid'],
        ]);

        $organizationId = (int) $request->user()->organization_id;
        $siteId = trim((string) ($data['site_id'] ?? ''));

        // Validate site belongs to organization
        $site = null;
        if ($siteId !== '') {
            $site = ClientSite::query()
                ->whereHas('workspace', fn ($query) => $query->where('organization_id', $organizationId))
                ->where('id', $siteId)
                ->first();
        }

        // Fall back to first active site
        if (! $site) {
            $site = ClientSite::query()
                ->whereHas('workspace', fn ($query) => $query->where('organization_id', $organizationId))
                ->where('is_active', true)
                ->first();
        }

        if (! $site) {
            return redirect()
                ->route('app.content.calendar')
                ->with('error', 'Geen actieve site gevonden om content aan te koppelen.');
        }

        // Parse scheduled date/time
        $scheduledAt = null;
        if (! empty($data['scheduled_date'])) {
            $time = $data['scheduled_time'] ?? '09:00';
            $scheduledAt = Carbon::parse("{$data['scheduled_date']} {$time}");
        }

        // Map status to content status
        $status = match ($data['status'] ?? 'scheduled') {
            'brief' => 'brief',
            'draft' => 'draft',
            'scheduled' => 'draft',
            default => 'draft',
        };

        // Create content
        $defaultLocale = $site->workspace?->defaultContentLanguageCode() ?? SupportedLanguage::EN->value;

        $content = Content::create([
            'workspace_id' => $site->workspace_id,
            'client_site_id' => $site->id,
            'series_id' => $data['series_id'] ?? null,
            'title' => $data['title'],
            'status' => $status,
            'type' => $data['type'] ?? 'article',
            'language' => $defaultLocale,
            'translation_source_locale' => null,
            'is_source_locale' => true,
            'scheduled_publish_at' => $scheduledAt,
            'publish_status' => $scheduledAt ? 'scheduled' : 'draft',
            'source' => 'calendar',
            'created_by' => $request->user()->id,
        ]);

        // Store intent keys in metadata if provided
        if (! empty($data['intent_keys'])) {
            $content->update([
                'internal_links_meta' => array_merge(
                    is_array($content->internal_links_meta) ? $content->internal_links_meta : [],
                    ['intent_keys' => $data['intent_keys']]
                ),
            ]);
        }

        return redirect()
            ->route('app.content.calendar', [
                'mode' => 'month',
                'date' => $scheduledAt?->format('Y-m-d') ?? now()->format('Y-m-d'),
            ])
            ->with('success', "Content '{$content->title}' is aangemaakt.");
    }

    private function dispatchDuePublication(Content $content, ?Carbon $scheduledAt): void
    {
        if (! $scheduledAt || $scheduledAt->isFuture()) {
            return;
        }

        $siteType = ClientSite::normalizeType((string) ($content->clientSite?->type ?? ''));
        if (! in_array($siteType, [ClientSite::TYPE_WORDPRESS, ClientSite::TYPE_LARAVEL], true)) {
            return;
        }

        if ($siteType === ClientSite::TYPE_LARAVEL && ! app(LaravelConnectorDestinationResolver::class)->resolveForContent($content)) {
            app(LaravelConnectorPublishingService::class)->publish($content, null, 'scheduled_publish', 'app.content.dispatch_due');

            return;
        }

        app(ContentPublicationService::class)->dispatchPublication($content, null, [
            'source' => 'app.content.dispatch_due',
            'allow_stale_reclaim' => true,
        ]);
    }

    private function hasWpImagePushConnection(Content $content, ?Draft $draft): bool
    {
        if (! $content->client_site_id) {
            return false;
        }

        $siteType = ClientSite::normalizeType((string) ($content->clientSite?->type ?? ''));
        if (! in_array($siteType, [ClientSite::TYPE_WORDPRESS, ClientSite::TYPE_LARAVEL], true)) {
            return false;
        }

        $meta = is_array($draft?->meta) ? $draft->meta : [];
        $refs = is_array(data_get($meta, 'client_refs')) ? data_get($meta, 'client_refs') : [];

        $url = trim((string) ($refs['draft_webhook_url'] ?? $content->clientSite?->draft_webhook_url ?? ''));
        $secret = trim((string) ($refs['draft_webhook_secret'] ?? $content->clientSite?->draft_webhook_secret ?? ''));
        if ($url !== '' && $secret !== '') {
            return true;
        }

        return SiteToken::query()
            ->where('client_site_id', (string) $content->client_site_id)
            ->where('revoked', false)
            ->whereNull('revoked_at')
            ->whereNotNull('token_encrypted')
            ->exists();
    }

    private function assertImagePushSupportedForContent(Content $content): void
    {
        $siteType = ClientSite::normalizeType((string) ($content->clientSite?->type ?? ''));
        if (! in_array($siteType, [ClientSite::TYPE_WORDPRESS, ClientSite::TYPE_LARAVEL], true)) {
            abort(403, 'Image push is not allowed for this site type.');
        }
    }

    private function assertWordPressSiteForContent(Content $content): void
    {
        if (ClientSite::normalizeType((string) ($content->clientSite?->type ?? '')) !== ClientSite::TYPE_WORDPRESS) {
            abort(403, 'WordPress-only action is not allowed for this site type.');
        }
    }

    private function publishNowToLaravel(Content $content): void
    {
        $content->loadMissing('clientSite');

        $draft = Draft::query()
            ->where('content_id', $content->id)
            ->latest('created_at')
            ->first();

        if (! $draft) {
            throw new RuntimeException('No draft found for Laravel publishing.');
        }

        $draft->status = 'delivered';
        $draft->delivery_status = 'delivered';
        $draft->delivery_last_error = null;
        $draft->delivered_at = $draft->delivered_at ?: now();
        $draft->acked_at = $draft->acked_at ?: now();
        $draft->save();

        $publishedUrlResolution = $this->resolveLaravelPublishedUrl($content, $draft);
        $publishedUrl = $publishedUrlResolution['url'];
        $seoSnapshot = $this->buildLaravelConnectorSeoSnapshot($draft, $content, $publishedUrl);
        $seoFieldsAvailable = $this->resolveNonEmptySeoFields($seoSnapshot);

        $content->update([
            'publish_status' => 'published',
            'scheduled_publish_at' => null,
            'publish_error' => null,
            'status' => 'published',
            'delivery_status' => 'delivered',
            'published_url' => $publishedUrl,
        ]);

        app(ContentLifecycleService::class)->synchronizePublishedSnapshotFromDraft($draft);

        ContentPublishTarget::query()->updateOrCreate(
            [
                'content_id' => $content->id,
                'client_site_id' => $content->client_site_id,
                'target_type' => 'laravel',
            ],
            [
                'target_identifier' => (string) ($content->external_key ?: $content->id),
                'sync_status' => 'pending',
                'last_synced_at' => null,
                'seo_sync_status' => 'pending',
                'seo_synced_at' => null,
                'seo_sync_mode' => 'pull',
                'seo_sync_error' => null,
                'seo_synced_fields' => null,
                'meta' => [
                    'mode' => 'publish_now',
                    'source' => 'app.content.publish-now',
                    'delivery_model' => 'pull',
                    'publish_confirmation' => 'local_only',
                    'remote_sync_status' => 'pending',
                    'published_url' => $publishedUrl,
                    'published_url_source' => $publishedUrlResolution['source'],
                    'published_url_confirmed' => false,
                    'meta_title' => $seoSnapshot['meta_title'],
                    'meta_description' => $seoSnapshot['meta_description'],
                    'canonical_url' => $seoSnapshot['canonical_url'],
                    'og_image' => $seoSnapshot['og_image'],
                    'primary_keyword' => $seoSnapshot['primary_keyword'],
                    'focus_keyword' => $seoSnapshot['focus_keyword'],
                    'robots_index' => $seoSnapshot['robots_index'],
                    'robots_follow' => $seoSnapshot['robots_follow'],
                    'schema_type' => $seoSnapshot['schema_type'],
                    'seo_fields_available' => $seoFieldsAvailable,
                    'seo' => $seoSnapshot,
                ],
            ]
        );

        Event::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => $content->client_site_id,
            'type' => 'publish.local_marked',
            'occurred_at' => now(),
            'data' => [
                'content_id' => $content->id,
                'draft_id' => $draft->id,
                'target' => 'laravel',
                'mode' => 'publish_now',
                'publish_confirmation' => 'local_only',
                'remote_sync_status' => 'pending',
                'published_url_source' => $publishedUrlResolution['source'],
            ],
        ]);
    }

    /**
     * @return array{url:?string,source:string}
     */
    private function resolveLaravelPublishedUrl(Content $content, Draft $draft): array
    {
        $contentPublishedUrl = trim((string) ($content->published_url ?? ''));
        if ($contentPublishedUrl !== '') {
            return [
                'url' => app(CanonicalUrlService::class)->liveUrlForContent($content, $contentPublishedUrl),
                'source' => 'content.published_url',
            ];
        }

        $draftCanonical = trim((string) ($draft->seo_canonical ?? ''));
        if ($draftCanonical !== '') {
            return [
                'url' => app(CanonicalUrlService::class)->liveUrlForContent($content, $draftCanonical),
                'source' => 'draft.seo_canonical',
            ];
        }

        $metaCanonical = trim((string) data_get($draft->meta, 'canonical_url', ''));
        if ($metaCanonical !== '') {
            return [
                'url' => app(CanonicalUrlService::class)->liveUrlForContent($content, $metaCanonical),
                'source' => 'draft.meta.canonical_url',
            ];
        }

        $metaPublishedUrl = trim((string) data_get($draft->meta, 'published_url', ''));
        if ($metaPublishedUrl !== '') {
            return [
                'url' => app(CanonicalUrlService::class)->liveUrlForContent($content, $metaPublishedUrl),
                'source' => 'draft.meta.published_url',
            ];
        }

        $base = rtrim((string) ($content->clientSite?->site_url ?? ''), '/');
        if ($base !== '') {
            $postType = $content->wordPressPostType();
            $slug = Str::slug((string) $content->title);

            return [
                'url' => app(CanonicalUrlService::class)->liveUrlForContent(
                    $content,
                    $postType->buildPlannedUrl($base, $slug),
                    $slug
                ),
                'source' => 'site.slug_guess',
            ];
        }

        return ['url' => null, 'source' => 'none'];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildLaravelConnectorSeoSnapshot(Draft $draft, Content $content, ?string $publishedUrl): array
    {
        $resolved = SeoMetadata::resolveForDraftContext($draft, [
            'canonical_url' => $publishedUrl,
        ]);

        $metaTitle = $resolved['seo_title'] ?: trim((string) $content->title);

        return [
            'primary_keyword' => $resolved['primary_keyword'],
            'focus_keyword' => $resolved['primary_keyword'],
            'meta_title' => $metaTitle !== '' ? $metaTitle : null,
            'meta_description' => $resolved['seo_meta_description'],
            'canonical_url' => $resolved['seo_canonical'] ?: $publishedUrl,
            'og_image' => $resolved['seo_og_image'],
            'seo_title' => $resolved['seo_title'],
            'seo_meta_description' => $resolved['seo_meta_description'],
            'seo_h1' => $resolved['seo_h1'],
            'seo_canonical' => $resolved['seo_canonical'] ?: $publishedUrl,
            'seo_og_title' => $resolved['seo_og_title'],
            'seo_og_description' => $resolved['seo_og_description'],
            'seo_og_image' => $resolved['seo_og_image'],
            'seo_twitter_title' => $resolved['seo_twitter_title'],
            'seo_twitter_description' => $resolved['seo_twitter_description'],
            'robots_index' => $resolved['robots_index'],
            'robots_follow' => $resolved['robots_follow'],
            'schema_type' => $resolved['schema_type'],
        ];
    }

    /**
     * @param array<string,mixed> $seoSnapshot
     * @return array<int,string>
     */
    private function resolveNonEmptySeoFields(array $seoSnapshot): array
    {
        return collect($seoSnapshot)
            ->filter(fn ($value) => is_bool($value) || trim((string) $value) !== '')
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function parseDelimitedList(?string $value): array
    {
        return collect(explode(',', (string) $value))
            ->map(fn (string $entity): string => trim($entity))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function parsePlatforms(?string $value): array
    {
        $platforms = $this->parseDelimitedList($value);

        return $platforms === []
            ? ['Google', 'ChatGPT', 'Perplexity']
            : $platforms;
    }

    private function validateAnswerBlockPayload(Content $content, string $question, string $answer, ?string $ignoreId = null): void
    {
        if (mb_strlen(trim($answer)) < 3) {
            throw ValidationException::withMessages([
                'answer' => 'Answer must contain usable text.',
            ]);
        }

        $duplicateQuery = $content->answerBlocks()
            ->whereRaw('LOWER(question) = ?', [mb_strtolower(trim($question))]);

        if ($ignoreId !== null) {
            $duplicateQuery->whereKeyNot($ignoreId);
        }

        if ($duplicateQuery->exists()) {
            throw ValidationException::withMessages([
                'question' => 'Duplicate questions are not allowed.',
            ]);
        }
    }

    private function applyInboxFilter(Builder $query, string $inbox): void
    {
        match ($inbox) {
            'needs_brief' => $query
                ->where('status', 'brief')
                ->whereDoesntHave('brief')
                ->whereDoesntHave('briefVersion'),
            'brief_in_review' => $query
                ->where('status', 'review')
                ->where(function (Builder $nested): void {
                    $nested->whereHas('brief')
                        ->orWhereHas('briefVersion');
                })
                ->whereDoesntHave('currentVersion'),
            'needs_draft' => $query
                ->where('status', 'brief')
                ->where(function (Builder $nested): void {
                    $nested->whereHas('brief')
                        ->orWhereHas('briefVersion');
                }),
            'draft_in_review' => $query
                ->where('status', 'review')
                ->whereHas('currentVersion'),
            'ready_publish' => $query
                ->where('status', 'draft')
                ->whereIn('publish_status', ['draft', 'failed']),
            'published' => $query
                ->where(function (Builder $nested): void {
                    $nested->where('status', 'published')
                        ->orWhere('publish_status', 'published');
                }),
            default => null,
        };
    }
}
