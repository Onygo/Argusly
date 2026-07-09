<?php

namespace App\Http\Controllers\App;

use App\Actions\Briefs\CreateBriefFromResearchAction;
use App\Actions\Briefs\EnhanceBriefAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\ApplyBriefSuggestionRequest;
use App\Http\Requests\App\CreateBriefFromResearchRequest;
use App\Http\Requests\App\EnhanceBriefRequest;
use App\Http\Requests\App\GenerateUrlBriefSourceRequest;
use App\Http\Requests\App\PreviewUrlBriefSourceRequest;
use App\Http\Requests\App\RejectBriefSuggestionRequest;
use App\Http\Requests\App\SaveUrlBriefSourceRequest;
use App\Jobs\GenerateSourceBriefJob;
use Illuminate\Http\JsonResponse;
use App\Models\Brief;
use App\Models\BriefSuggestion;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentSeries;
use App\Models\ContentSeriesArticle;
use App\Models\ContentSource;
use App\Models\ResearchProject;
use App\Models\Workspace;
use App\Services\BriefProcessing\BriefToDraftService;
use App\Services\Briefs\BriefIntelligenceService;
use App\Services\Credits\GenerationPricing;
use App\Services\CreditWalletService;
use App\Services\DraftComparison\DraftComparisonService;
use App\Services\Entitlements\FeatureGate;
use App\Services\Entitlements\WorkspaceEntitlementsService;
use App\Services\Integrations\DestinationBillingSiteService;
use App\Services\OpportunityIntelligence\BriefDraftService;
use App\Services\SourceBriefing\SourceBriefingService;
use App\Services\SourceBriefing\Exceptions\SourceBriefingException;
use App\Services\SourceBriefing\Exceptions\SourcePreviewException;
use App\Support\FeatureFlags;
use App\Support\CompleteContentBriefingParser;
use App\Support\ContentPersistencePayloadNormalizer;
use App\Support\EditorialTaxonomyService;
use App\Support\Interaction\Action;
use App\Support\Interaction\AppInteractionRegistry;
use App\Support\Interaction\ResourceContext;
use App\Support\Interaction\ResourceType;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AppBriefsController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Brief::class);

        $organization = $request->user()->organization;

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'site' => trim((string) $request->query('site', '')),
            'status' => trim((string) $request->query('status', '')),
            'source' => trim((string) $request->query('source', '')),
            'language' => trim((string) $request->query('language', '')),
            'content_type' => trim((string) $request->query('content_type', '')),
        ];

        $query = Brief::query()
            ->with('clientSite.workspace', 'content')
            ->whereHas('clientSite.workspace', function ($q) use ($organization): void {
                $q->where('organization_id', $organization->id);
            })
            ->when($filters['q'] !== '', function ($q) use ($filters): void {
                $q->where(function ($nested) use ($filters): void {
                    $nested->where('title', 'like', '%'.$filters['q'].'%')
                        ->orWhere('primary_keyword', 'like', '%'.$filters['q'].'%')
                        ->orWhere('target_audience', 'like', '%'.$filters['q'].'%');
                });
            })
            ->when($filters['site'] !== '', fn ($q) => $q->where('client_site_id', $filters['site']))
            ->when($filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['source'] !== '', fn ($q) => $q->where('source', $filters['source']))
            ->when($filters['language'] !== '', fn ($q) => $q->where('language', $filters['language']))
            ->when($filters['content_type'] !== '', fn ($q) => $q->where('content_type', $filters['content_type']))
            ->orderByDesc('created_at');

        $briefs = $query->paginate(20)->withQueryString();
        [$interactionResourcesByKey, $interactionActionsByKey] = $this->resolveBriefIndexInteractionMetadata(
            $briefs->getCollection()
        );

        return view('app.briefs.index', [
            'briefs' => $briefs,
            'filters' => $filters,
            'sites' => $this->sitesForOrganization((int) $organization->id),
            'statusOptions' => $this->statusOptions(),
            'sourceOptions' => $this->sourceOptions(),
            'contentTypeOptions' => $this->contentTypeOptions(),
            'interactionResourcesByKey' => $interactionResourcesByKey,
            'interactionActionsByKey' => $interactionActionsByKey,
        ]);
    }

    /**
     * @param iterable<int, Brief> $briefs
     * @return array{0: array<string, array>, 1: array<string, array<string, array>>}
     */
    private function resolveBriefIndexInteractionMetadata(iterable $briefs): array
    {
        $user = request()->user();
        $briefs = collect($briefs)->values();
        $resourceRegistry = AppInteractionRegistry::resourceRegistryFor($briefs);
        $actionRegistry = AppInteractionRegistry::actionRegistry();

        $resourcesByKey = [];
        $actionsByKey = [];

        foreach ($briefs as $brief) {
            $resourceKey = ResourceType::BRIEF.':'.$brief->getKey();
            $context = ResourceContext::make([
                'user' => $user,
                'surface' => Action::SURFACE_ROW,
                'page_key' => 'app.briefs.index',
                'route_name' => 'app.briefs',
                'organization_id' => $user?->organization_id,
                'site_id' => $brief->client_site_id,
                'resource_type' => ResourceType::BRIEF,
                'resource_id' => $brief->getKey(),
                'subject' => $brief,
                'metadata' => [
                    'subject' => $brief,
                ],
            ]);

            $resource = $resourceRegistry->resolve($resourceKey, $context);

            if ($resource === null) {
                continue;
            }

            $resourcesByKey[$resourceKey] = $resource;
            $actionsByKey[$resourceKey] = [];

            foreach ($resource['available_actions'] as $actionKey) {
                if (! $actionRegistry->has($actionKey)) {
                    continue;
                }

                $action = $actionRegistry->resolve($actionKey, $context->toActionContext());

                if ($action['visible']) {
                    $actionsByKey[$resourceKey][$actionKey] = $action;
                }
            }
        }

        return [$resourcesByKey, $actionsByKey];
    }

    public function create(
        Request $request,
        EditorialTaxonomyService $taxonomyService,
        FeatureGate $featureGate,
        FeatureFlags $featureFlags
    ): View
    {
        $this->authorize('create', Brief::class);

        $organizationId = (int) $request->user()->organization_id;
        $briefIntelligenceEnabled = $featureFlags->isEnabled('brief_intelligence')
            && $this->organizationHasBriefIntelligenceEnabledWorkspace($organizationId, $featureGate);
        $sourcePreview = $this->resolveSourceForOrganization(
            trim((string) $request->query('source', '')),
            $organizationId
        );

        $sourceRecoverableExtractionFailure = $sourcePreview
            && $this->isRecoverableSourceExtractionFailure($sourcePreview);

        // Check if generation is pending/running
        $sourceGenerationPending = $sourcePreview && ($sourceRecoverableExtractionFailure || $sourcePreview->isGenerationPending())
            && (string) $sourcePreview->generation_status !== ContentSource::GENERATION_STATUS_PENDING;

        // Check if generation failed
        $sourceGenerationFailed = $sourcePreview && $sourcePreview->isGenerationFailed() && ! $sourceRecoverableExtractionFailure;
        $sourceExtractionPending = $sourcePreview && in_array((string) $sourcePreview->extraction_status, ['pending', 'fetching', 'extracting'], true);

        return view('app.briefs.create', [
            'sites' => $this->sitesForOrganization($organizationId),
            'destinations' => $this->destinationsForOrganization($organizationId),
            'contentTypeOptions' => $this->contentTypeOptions(),
            'funnelStageOptions' => $this->funnelStageOptions(),
            'searchIntentOptions' => $taxonomyService->activeItemMapByTenantAndType($organizationId, 'intent'),
            'audienceOptions' => $taxonomyService->activeItemMapByTenantAndType($organizationId, 'audience'),
            'briefIntelligenceEnabled' => $briefIntelligenceEnabled,
            'researchProjects' => $briefIntelligenceEnabled
                ? $this->researchProjectsForOrganization($organizationId, 60)
                : collect(),
            'sourcePreview' => $sourcePreview,
            'sourceGenerated' => $sourcePreview?->generated_payload_json,
            'sourceGenerationPending' => $sourceGenerationPending,
            'sourceGenerationFailed' => $sourceGenerationFailed,
            'sourceExtractionPending' => $sourceExtractionPending,
            'canViewSourceDiagnostics' => (bool) ($request->user()?->is_admin || $request->session()->has('impersonating')),
        ]);
    }

    public function store(
        Request $request,
        WorkspaceEntitlementsService $entitlements,
        EditorialTaxonomyService $taxonomyService,
        DestinationBillingSiteService $destinationBillingSiteService,
    ): RedirectResponse {
        $this->authorize('create', Brief::class);

        $organizationId = (int) $request->user()->organization_id;
        $data = $this->validateBrief($request, true);
        $completeBriefing = CompleteContentBriefingParser::parse((string) ($data['complete_briefing'] ?? ''));
        $data = $this->hydrateBriefDataFromCompleteBriefing($data, $completeBriefing);
        $this->assertRequiredBriefData($data);
        [$searchIntent, $audienceKeys, $audienceLabels] = $this->resolveTaxonomySelections($data, $organizationId, $taxonomyService);

        $destinationMode = (string) ($data['destination_mode'] ?? 'connected');
        $selectedDestination = null;

        if (! empty($data['content_destination_id'])) {
            $selectedDestination = $this->resolveDestinationForOrganization((string) $data['content_destination_id'], $organizationId);
        }

        if ($destinationMode === 'connected' || ($destinationMode === 'hybrid' && ! empty($data['site_id']))) {
            $site = $this->resolveSiteForOrganization((string) $data['site_id'], $organizationId);
        } else {
            if (! $selectedDestination) {
                throw ValidationException::withMessages([
                    'content_destination_id' => 'Please select a destination for API-only or hybrid mode.',
                ]);
            }
            $site = $destinationBillingSiteService->ensureBillingSite($selectedDestination);
        }

        try {
            $entitlements->consumeBriefQuota($site->workspace);
        } catch (\RuntimeException $exception) {
            return back()->withInput()->withErrors(['site_id' => $exception->getMessage()]);
        }

        $brief = Brief::query()->create([
            'client_site_id' => (string) $site->id,
            'content_destination_id' => $selectedDestination?->id,
            'created_by_user_id' => (int) $request->user()->id,
            'status' => 'draft',
            'source' => 'client_ui',
            'title' => $data['title'],
            'language' => $data['language'],
            'content_type' => $data['content_type'],
            'output_type' => $this->mapContentTypeToOutputType($data['content_type']),
            'primary_keyword' => ($data['primary_keyword'] ?? '') ?: null,
            'secondary_keywords' => $this->toArrayList($data['secondary_keywords'] ?? ''),
            'audience' => $audienceKeys !== [] ? implode(',', $audienceKeys) : (($data['target_audience'] ?? '') ?: null),
            'target_audience' => $audienceLabels !== [] ? implode(', ', $audienceLabels) : (($data['target_audience'] ?? '') ?: null),
            'funnel_stage' => ($data['funnel_stage'] ?? '') ?: null,
            'search_intent' => $searchIntent,
            'tone_of_voice' => ($data['tone_of_voice'] ?? '') ?: null,
            'unique_angle' => ($data['unique_angle'] ?? '') ?: null,
            'key_points' => $this->toArrayList($data['key_points'] ?? ''),
            'call_to_action' => ($data['call_to_action'] ?? '') ?: null,
            'desired_length_min' => ($data['desired_length_min'] ?? 0) ?: null,
            'desired_length_max' => ($data['desired_length_max'] ?? 0) ?: null,
            'notes' => ($data['notes'] ?? '') ?: null,
            'progress' => 0,
            'client_refs' => array_filter([
                'client_type' => 'client_ui',
                'site_url' => (string) ($site->site_url ?? ''),
                'destination_mode' => $destinationMode,
                'content_destination_id' => $selectedDestination?->id,
                'complete_briefing' => $completeBriefing['raw'] !== '' ? [
                    'raw' => $completeBriefing['raw'],
                    'sections' => $completeBriefing['sections'],
                    'derived' => $completeBriefing['derived'],
                    'created_at' => now()->toIso8601String(),
                ] : null,
            ], fn ($value): bool => $value !== null),
            'wp_site_id' => (string) $site->id,
        ]);

        return redirect()
            ->route('app.content.workspace.show', $brief)
            ->with('status', 'Content created. Brief settings saved.');
    }

    public function storeFromResearch(
        CreateBriefFromResearchRequest $request,
        CreateBriefFromResearchAction $action
    ): RedirectResponse {
        $this->authorize('createFromResearch', Brief::class);

        try {
            $brief = $action->execute($request->user(), $request->validated());
        } catch (AuthorizationException $exception) {
            return back()->withErrors([
                'research_project_id' => $exception->getMessage(),
            ]);
        } catch (\RuntimeException $exception) {
            return back()->withInput()->withErrors([
                'research_project_id' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('app.content.workspace.show', $brief)
            ->with('status', 'Brief created from research.');
    }

    public function previewUrlSource(
        PreviewUrlBriefSourceRequest $request,
        SourceBriefingService $sourceBriefingService
    ): JsonResponse|RedirectResponse {
        $this->authorize('create', Brief::class);

        try {
            $organizationId = (int) $request->user()->organization_id;
            $workspace = $this->primaryWorkspaceForOrganization($organizationId);
            $source = $sourceBriefingService->preview(
                $workspace,
                (string) $request->validated('source_url'),
                $request->user(),
                (string) ($request->validated('extraction_mode') ?? 'default')
            );
        } catch (SourcePreviewException $exception) {
            return redirect()
                ->route('app.content.create', ['source' => $exception->source->id])
                ->withInput()
                ->withErrors([
                    'source_url' => $exception->getMessage(),
                ]);
        } catch (\RuntimeException $exception) {
            $this->logUrlGenerationFailure($request, $exception, [
                'operation' => 'preview',
                'workspace_id' => (string) ($workspace->id ?? $request->input('workspace_id', '')),
            ]);

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => 'URL generation failed',
                    'error' => $exception->getMessage(),
                ], 500);
            }

            return back()->withInput()->withErrors([
                'source_url' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            $this->logUrlGenerationFailure($request, $exception, [
                'operation' => 'preview',
                'workspace_id' => (string) ($workspace->id ?? $request->input('workspace_id', '')),
            ]);

            return $this->urlGenerationFailureResponse($request, $exception);
        }

        return redirect()
            ->route('app.content.create', ['source' => $source->id])
            ->with('status', 'Source analyzed. Review the preview before generating the brief.');
    }

    public function generateFromUrlSource(
        GenerateUrlBriefSourceRequest $request
    ): JsonResponse|RedirectResponse {
        $this->authorize('create', Brief::class);

        try {
            $organizationId = (int) $request->user()->organization_id;
            $workspace = $this->primaryWorkspaceForOrganization($organizationId);
            $validated = $request->validated();
            $outputMode = (string) $request->validated('output_mode');
            $locale = $this->normalizeSourceGenerationLocale(
                (string) ($validated['locale'] ?? '')
            );
            $forceNew = $request->boolean('force_new');
            $source = $this->resolveSourceForOrganization((string) ($validated['content_source_id'] ?? ''), $organizationId);
            $sourceUrl = trim((string) ($validated['source_url'] ?? $source?->source_url ?? ''));
            $manualSourceNotes = trim((string) ($validated['manual_source_notes'] ?? ''));
            $chainSettings = $this->sourceChainSettings($validated);

            if ($sourceUrl === '') {
                return $this->sourceGenerationValidationError($request, 'A valid source URL is required.');
            }

            $idempotencyKey = $this->buildSourceGenerationIdempotencyKey(
                (string) $workspace->id,
                (int) $request->user()->id,
                $sourceUrl,
                $locale,
                $outputMode
            );

            if (! $forceNew) {
                $existing = $this->findSourceByGenerationIdempotencyKey(
                    (string) $workspace->id,
                    (int) $request->user()->id,
                    $idempotencyKey
                );

                if ($existing instanceof ContentSource && ! $existing->isGenerationFailed()) {
                    return $this->sourceGenerationStartResponse($request, $existing);
                }

                if ($existing instanceof ContentSource) {
                    $source = $existing;
                }
            }

            if (! $source instanceof ContentSource || $forceNew) {
                $source = ContentSource::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => (string) $workspace->id,
                    'type' => 'url',
                    'source_url' => $sourceUrl,
                    'extraction_status' => 'pending',
                    'generation_status' => ContentSource::GENERATION_STATUS_PENDING,
                    'created_by_user_id' => (int) $request->user()->id,
                    'metadata_json' => [
                        'fetch' => [
                            'requested_mode' => (string) ($validated['extraction_mode'] ?? 'default'),
                        ],
                        'manual_source_notes' => $manualSourceNotes !== '' ? $manualSourceNotes : null,
                        'chain_settings' => $chainSettings !== [] ? $chainSettings : null,
                    ],
                ]);
            } else {
                $source->update([
                    'source_url' => $sourceUrl,
                    'metadata_json' => array_merge((array) $source->metadata_json, [
                        'fetch' => [
                            'requested_mode' => (string) ($validated['extraction_mode'] ?? data_get($source->metadata_json, 'fetch.requested_mode', 'default')),
                        ],
                        'manual_source_notes' => $manualSourceNotes !== ''
                            ? $manualSourceNotes
                            : data_get($source->metadata_json, 'manual_source_notes'),
                        'chain_settings' => $chainSettings !== []
                            ? $chainSettings
                            : data_get($source->metadata_json, 'chain_settings'),
                    ]),
                ]);
            }

            $source->markGenerationQueued($outputMode, $locale, $outputMode, $idempotencyKey);

            try {
                $this->dispatchSourceBriefGeneration($source, $outputMode);
            } catch (Throwable $exception) {
                $freshSource = $source->fresh();

                $this->logUrlGenerationFailure($request, $exception, [
                    'operation' => 'dispatch',
                    'workspace_id' => (string) $workspace->id,
                    'content_source_id' => (string) $source->id,
                    'output_mode' => $outputMode,
                ]);

                if ($freshSource instanceof ContentSource && $freshSource->isGenerationFailed()) {
                    return $this->sourceGenerationStartResponse($request, $freshSource);
                }

                $message = 'We could not start the brief generation job. Please try again in a moment.';

                $source->markGenerationFailed('GENERATION_DISPATCH_FAILED', $message, [
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                    'trace' => Str::limit($exception->getTraceAsString(), 2000, ''),
                ]);

                if ($request->expectsJson() || $request->ajax()) {
                    return response()->json([
                        'message' => $message,
                        'error' => $message,
                        'error_code' => 'GENERATION_DISPATCH_FAILED',
                    ], 500);
                }

                return back()->withInput()->withErrors([
                    'source_url' => $message,
                ]);
            }

            return $this->sourceGenerationStartResponse($request, $source->fresh());
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            $this->logUrlGenerationFailure($request, $exception, [
                'operation' => 'generate',
            ]);

            return $this->urlGenerationFailureResponse($request, $exception);
        }
    }

    public function sourceGenerationStatus(Request $request, string $sourceId): JsonResponse
    {
        $this->authorize('create', Brief::class);

        $organizationId = (int) $request->user()->organization_id;
        $source = $this->resolveSourceForOrganization($sourceId, $organizationId);

        if (! $source instanceof ContentSource) {
            return response()->json([
                'error' => 'Source not found',
            ], 404);
        }

        return response()->json($this->sourceGenerationStatusPayload($source));
    }

    public function retrySourceGeneration(Request $request, string $sourceId): JsonResponse|RedirectResponse
    {
        $this->authorize('create', Brief::class);

        try {
            $organizationId = (int) $request->user()->organization_id;
            $source = $this->resolveSourceForOrganization($sourceId, $organizationId);

            if (! $source instanceof ContentSource) {
                abort(404);
            }

            if (! $source->isGenerationFailed()) {
                return $this->sourceGenerationValidationError($request, 'Retry is only available for failed generations.');
            }

            $outputMode = (string) ($source->generation_output_mode ?: 'brief_only');
            $locale = $this->normalizeSourceGenerationLocale((string) ($source->generation_locale ?: $source->source_language ?: 'en'));
            $idempotencyKey = $this->buildSourceGenerationIdempotencyKey(
                (string) $source->workspace_id,
                (int) $request->user()->id,
                (string) $source->source_url,
                $locale,
                $outputMode
            );

            $source->markGenerationQueued($outputMode, $locale, $outputMode, $idempotencyKey);

            GenerateSourceBriefJob::dispatch($source->id, $outputMode)
                ->onQueue('generation');

            return $this->sourceGenerationStartResponse($request, $source->fresh());
        } catch (Throwable $exception) {
            Log::error('Failed to retry source generation', [
                'user_id' => $request->user()?->id,
                'source_id' => $sourceId,
                'error' => $exception->getMessage(),
                'exception_class' => $exception::class,
            ]);

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'error' => 'We could not retry URL generation right now. Please try again.',
                    'error_code' => 'PL-URL-GEN-RETRY',
                ], 500);
            }

            return back()->withErrors([
                'source_url' => 'We could not retry URL generation right now. Please try again.',
            ]);
        }
    }

    public function saveFromUrlSource(
        SaveUrlBriefSourceRequest $request,
        WorkspaceEntitlementsService $entitlements,
        DestinationBillingSiteService $destinationBillingSiteService,
        BriefToDraftService $briefToDraftService,
        CreditWalletService $creditWalletService,
        GenerationPricing $pricing
    ): JsonResponse|RedirectResponse {
        $this->authorize('create', Brief::class);

        try {
            $organizationId = (int) $request->user()->organization_id;
            $source = $this->resolveSourceForOrganization((string) $request->validated('content_source_id'), $organizationId);
            if (! $source instanceof ContentSource) {
                abort(404);
            }

            $nextAction = (string) ($request->validated('next_action') ?? 'save');
            $generated = is_array($source->generated_payload_json) ? $source->generated_payload_json : [];
            $briefPayload = is_array($generated['brief'] ?? null) ? $generated['brief'] : [];
            if ($briefPayload === []) {
                return back()->withErrors([
                    'source_url' => 'Generate the source-based brief before saving it.',
                ]);
            }

            $existingBrief = Brief::query()
                ->where('content_source_id', (string) $source->id)
                ->latest('created_at')
                ->first();

            if ($existingBrief instanceof Brief && ! in_array($nextAction, ['create_chain', 'create_selected_chain_items'], true)) {
                return redirect()
                    ->route('app.content.workspace.show', $existingBrief)
                    ->with('status', 'This generated source was already saved. Reusing the existing brief.');
            }

            if ($existingBrief instanceof Brief && in_array($nextAction, ['create_chain', 'create_selected_chain_items'], true)) {
                $existingSeriesId = trim((string) data_get($source->metadata_json, 'result_chain_series_id', ''));

                if ($existingSeriesId !== '') {
                    $existingSeries = ContentSeries::query()
                        ->whereKey($existingSeriesId)
                        ->where('organization_id', $organizationId)
                        ->first();

                    if ($existingSeries instanceof ContentSeries) {
                        return redirect()
                            ->route('app.content.series.show', $existingSeries)
                            ->with('status', 'This source has already been converted into a content chain.');
                    }
                }
            }

            $destinationMode = (string) ($request->validated('destination_mode') ?? 'connected');
            $selectedDestination = null;

            if ($request->filled('content_destination_id')) {
                $selectedDestination = $this->resolveDestinationForOrganization((string) $request->validated('content_destination_id'), $organizationId);
            }

            if ($destinationMode === 'connected' || ($destinationMode === 'hybrid' && $request->filled('site_id'))) {
                $site = $this->resolveSiteForOrganization((string) $request->validated('site_id'), $organizationId);
            } else {
                if (! $selectedDestination) {
                    throw ValidationException::withMessages([
                        'content_destination_id' => 'Please select a destination for API-only or hybrid mode.',
                    ]);
                }

                $site = $destinationBillingSiteService->ensureBillingSite($selectedDestination);
            }

            try {
                $entitlements->consumeBriefQuota($site->workspace);
            } catch (\RuntimeException $exception) {
                return back()->withErrors(['site_id' => $exception->getMessage()]);
            }

            if (in_array($nextAction, ['create_chain', 'create_selected_chain_items'], true)
                && ((string) ($source->generation_output_mode ?? '') === 'full_chain' || $request->has('chain_items'))
            ) {
                $series = $this->createFullChainFromSourceBrief($request, $source->fresh() ?? $source, null, $site, $selectedDestination);

                return redirect()
                    ->route('app.content.series.show', $series)
                    ->with('status', sprintf(
                        'Content chain created with %d approved item(s). Review the chain before publishing.',
                        (int) $series->contents()->count()
                    ));
            }

            $notes = $this->composeSourceBriefNotes($source, $generated);
            $contentDbType = 'article';
            $briefContentType = 'blog';

            $content = Content::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $site->workspace_id,
                'client_site_id' => $site->id,
                'content_destination_id' => $selectedDestination?->id,
                'title' => (string) ($briefPayload['working_title'] ?? $source->source_title ?? 'Source-based brief'),
                'language' => (string) ($briefPayload['language'] ?? $source->source_language ?? 'en'),
                'type' => $contentDbType,
                'status' => 'brief',
                'source' => 'manual',
                'external_key' => (string) Str::uuid(),
                'primary_keyword' => (string) ($briefPayload['primary_keyword'] ?? ''),
                'generation_mode' => 'balanced',
                'preferred_length' => 'medium',
                'created_by' => (int) $request->user()->id,
                'updated_by' => (int) $request->user()->id,
            ]);

            $brief = Brief::query()->create([
                'client_site_id' => (string) $site->id,
                'content_destination_id' => $selectedDestination?->id,
                'created_by_user_id' => (int) $request->user()->id,
                'content_id' => (string) $content->id,
                'content_source_id' => (string) $source->id,
                'status' => 'draft',
                'source' => 'url_source',
                'title' => (string) ($briefPayload['working_title'] ?? $source->source_title ?? 'Source-based brief'),
                'language' => (string) ($briefPayload['language'] ?? $source->source_language ?? 'en'),
                'content_type' => $briefContentType,
                'output_type' => $this->mapContentTypeToOutputType($briefContentType),
                'primary_keyword' => (string) ($briefPayload['primary_keyword'] ?? ''),
                'secondary_keywords' => array_values((array) ($briefPayload['secondary_keywords'] ?? [])),
                'audience' => (string) ($briefPayload['target_audience'] ?? ''),
                'target_audience' => (string) ($briefPayload['target_audience'] ?? ''),
                'funnel_stage' => data_get($source->analysis_json, 'funnel_stage'),
                'search_intent' => (string) ($briefPayload['search_intent'] ?? ''),
                'tone_of_voice' => data_get($source->analysis_json, 'source_tone'),
                'unique_angle' => (string) ($briefPayload['summary'] ?? ''),
                'key_points' => array_values((array) ($briefPayload['key_talking_points'] ?? [])),
                'call_to_action' => (string) ($briefPayload['cta_recommendation'] ?? ''),
                'desired_length_min' => 900,
                'desired_length_max' => 1200,
                'notes' => $notes,
                'progress' => 0,
                'client_refs' => [
                    'client_type' => 'source_briefing',
                    'site_url' => (string) ($site->site_url ?? ''),
                    'destination_mode' => $destinationMode,
                    'content_destination_id' => $selectedDestination?->id,
                    'source_briefing' => [
                        'content_source_id' => (string) $source->id,
                        'source_url' => (string) $source->source_url,
                        'final_url' => (string) ($source->final_url ?? ''),
                        'source_domain' => (string) ($source->source_domain ?? ''),
                        'source_title' => (string) ($source->source_title ?? ''),
                        'source_language' => (string) ($source->source_language ?? ''),
                        'analysis' => $source->analysis_json,
                        'keywords' => $generated['keywords'] ?? null,
                        'chain_proposal' => $generated['chain_proposal'] ?? null,
                        'generated_at' => now()->toIso8601String(),
                    ],
                ],
                'wp_site_id' => (string) $site->id,
            ]);

            $source->update([
                'result_content_id' => (string) $content->id,
                'result_brief_id' => (string) $brief->id,
            ]);

            if ($nextAction === 'generate_draft') {
                return $this->queueDraftGeneration(
                    $request,
                    $brief,
                    $briefToDraftService,
                    $creditWalletService,
                    $pricing
                );
            }

            if (in_array($nextAction, ['create_chain', 'create_selected_chain_items'], true)) {
                return redirect()
                    ->route('app.content.series.create', ['source_brief' => $brief->id])
                    ->with('status', 'Brief saved. Review the chain proposal before creating content items.');
            }

            return redirect()
                ->route('app.content.workspace.show', $brief)
                ->with('status', 'Source-based brief saved.');
        } catch (ValidationException $exception) {
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'error' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                ], 422);
            }

            throw $exception;
        } catch (Throwable $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            $this->logUrlGenerationFailure($request, $exception, [
                'operation' => 'save',
                'content_source_id' => (string) $request->validated('content_source_id'),
            ]);

            return $this->urlGenerationFailureResponse($request, $exception);
        }
    }

    public function discardUrlSource(Request $request, string $sourceId): RedirectResponse
    {
        $this->authorize('create', Brief::class);

        $organizationId = (int) $request->user()->organization_id;
        $source = $this->resolveSourceForOrganization($sourceId, $organizationId);
        if ($source instanceof ContentSource) {
            $source->update([
                'generated_payload_json' => null,
                'analysis_json' => null,
                'extraction_status' => trim((string) $source->extracted_text) !== '' ? 'extracted' : 'pending',
                'generation_status' => ContentSource::GENERATION_STATUS_PENDING,
                'generation_progress_step' => null,
                'generation_failure_code' => null,
                'generation_failure_message' => null,
                'generation_diagnostics_json' => null,
                'generation_started_at' => null,
                'generation_completed_at' => null,
            ]);
        }

        return redirect()
            ->route('app.content.create')
            ->with('status', 'Source-based draft discarded.');
    }

    public function show(
        Request $request,
        Brief $brief,
        GenerationPricing $pricing,
        DraftComparisonService $draftComparisonService,
        FeatureGate $featureGate,
        FeatureFlags $featureFlags
    ): View {
        $this->authorize('view', $brief);

        $brief->load([
            'drafts',
            'clientSite',
            'creator',
            'content.contentDestination',
            'contentDestination',
            'clientSite.workspace',
            'suggestions' => fn ($query) => $query->latest('created_at')->limit(120),
            'draftComparisons' => fn ($query) => $query->latest('created_at')->limit(8),
        ]);
        $activeSection = trim((string) ($request->route('workspace_section') ?: $request->query('section', 'overview')));
        if (! in_array($activeSection, ['overview', 'brief', 'drafts', 'compare'], true)) {
            $activeSection = 'overview';
        }

        $generationType = $this->generationTypeForBrief($brief);
        $options = $pricing->outputTokenOptions($generationType);
        $maxCredits = (int) config('credits.generation_pricing.article.max_credits', 16);
        $draftCompareCapabilities = $draftComparisonService->compareCapabilitiesForBrief($brief);
        $modelOptions = $draftComparisonService->availableModelOptionsForBrief($brief);
        $defaultModelKeys = collect($modelOptions)
            ->pluck('key')
            ->take(max(1, min(2, (int) ($draftCompareCapabilities['max_models'] ?? 2))))
            ->values()
            ->all();
        $draftCompareModeLabels = [
            'compare_two' => 'Compare 2 models',
            'compare_multi' => 'Compare multiple models',
        ];
        $briefIntelligenceContext = $this->briefIntelligenceContext($brief, $featureGate);
        $briefIntelligenceEnabled = $featureFlags->isEnabled('brief_intelligence') && $briefIntelligenceContext['enabled'];
        $executionPlanDraft = $this->executionPlanDraft($brief);

        return view('app.briefs.show', [
            'brief' => $brief,
            'activeSection' => $activeSection,
            'statusOptions' => $this->statusOptions(),
            'outputTokenOptions' => $options,
            'estimatedCredits' => [
                'standard' => $pricing->requiredCredits($generationType, $options['standard']),
                'long' => $pricing->requiredCredits($generationType, $options['long']),
                'max' => $pricing->requiredCredits($generationType, $options['max']),
            ],
            'maxCredits' => $maxCredits,
            'draftCompareModelOptions' => $modelOptions,
            'draftCompareDefaultModelKeys' => $defaultModelKeys,
            'draftCompareModes' => collect((array) ($draftCompareCapabilities['allowed_modes'] ?? []))
                ->mapWithKeys(fn (string $mode): array => [$mode => (string) ($draftCompareModeLabels[$mode] ?? \Illuminate\Support\Str::headline(str_replace('_', ' ', $mode)))])
                ->all(),
            'draftCompareCapabilities' => $draftCompareCapabilities,
            'briefIntelligenceEnabled' => $briefIntelligenceEnabled,
            'briefIntelligenceContext' => $briefIntelligenceContext,
            'canEnhanceBrief' => $request->user()?->can('enhance', $brief) ?? false,
            'canCreateBriefFromResearch' => $request->user()?->can('createFromResearch', Brief::class) ?? false,
            'canManageBriefSuggestions' => $request->user()?->can('applySuggestion', $brief) ?? false,
            'canCreateFirstDraft' => $this->canCreateFirstDraft($request, $brief, $executionPlanDraft),
            'executionPlanDraft' => $executionPlanDraft,
        ]);
    }

    public function createDraft(Request $request, Brief $brief, BriefDraftService $service): RedirectResponse
    {
        $this->authorize('generateDraft', $brief);

        try {
            $draft = $service->createDraft($brief, $request->user());
        } catch (AuthorizationException $exception) {
            return back()->withErrors(['brief' => $exception->getMessage()]);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['brief' => $exception->getMessage()]);
        }

        if (Route::has('app.drafts.show')) {
            return redirect()
                ->route('app.drafts.show', $draft)
                ->with('status', 'First draft created from execution plan brief.');
        }

        return back()->with('status', 'First draft created from execution plan brief.');
    }

    public function edit(
        Request $request,
        Brief $brief,
        EditorialTaxonomyService $taxonomyService,
        GenerationPricing $pricing,
        FeatureGate $featureGate,
        FeatureFlags $featureFlags
    ): View {
        $this->authorize('update', $brief);

        $organizationId = (int) $request->user()->organization_id;
        $brief->loadMissing(['suggestions' => fn ($query) => $query->latest('created_at')->limit(120), 'clientSite.workspace']);
        $briefIntelligenceContext = $this->briefIntelligenceContext($brief, $featureGate);
        $briefIntelligenceEnabled = $featureFlags->isEnabled('brief_intelligence') && $briefIntelligenceContext['enabled'];
        $generationType = $this->generationTypeForBrief($brief);
        $options = $pricing->outputTokenOptions($generationType);
        $maxCredits = (int) config('credits.generation_pricing.article.max_credits', 16);
        $latestDraft = $brief->drafts()->latest('created_at')->first();

        return view('app.briefs.edit', [
            'brief' => $brief,
            'sites' => $this->sitesForOrganization($organizationId),
            'contentTypeOptions' => $this->contentTypeOptions(),
            'funnelStageOptions' => $this->funnelStageOptions(),
            'searchIntentOptions' => $taxonomyService->activeItemMapByTenantAndType($organizationId, 'intent'),
            'audienceOptions' => $taxonomyService->activeItemMapByTenantAndType($organizationId, 'audience'),
            'selectedAudienceKeys' => $this->parseAudienceKeys((string) ($brief->audience ?? '')),
            'latestDraft' => $latestDraft,
            'canChangeBriefSite' => $this->canChangeBriefSite($brief),
            'outputTokenOptions' => $options,
            'estimatedCredits' => [
                'standard' => $pricing->requiredCredits($generationType, $options['standard']),
                'long' => $pricing->requiredCredits($generationType, $options['long']),
                'max' => $pricing->requiredCredits($generationType, $options['max']),
            ],
            'maxCredits' => $maxCredits,
            'briefIntelligenceEnabled' => $briefIntelligenceEnabled,
            'briefIntelligenceContext' => $briefIntelligenceContext,
            'canEnhanceBrief' => $request->user()?->can('enhance', $brief) ?? false,
            'canCreateBriefFromResearch' => $request->user()?->can('createFromResearch', Brief::class) ?? false,
            'canManageBriefSuggestions' => $request->user()?->can('applySuggestion', $brief) ?? false,
            'researchProjects' => $briefIntelligenceEnabled
                ? $this->researchProjectsForOrganization($organizationId, 60)
                : collect(),
        ]);
    }

    public function update(
        Request $request,
        Brief $brief,
        EditorialTaxonomyService $taxonomyService,
        BriefToDraftService $briefToDraftService,
        CreditWalletService $creditWalletService,
        GenerationPricing $pricing
    ): RedirectResponse {
        $this->authorize('update', $brief);

        $organizationId = (int) $request->user()->organization_id;

        $data = $this->validateBrief($request, false);
        [$searchIntent, $audienceKeys, $audienceLabels] = $this->resolveTaxonomySelections($data, $organizationId, $taxonomyService);
        $site = $this->resolveSiteForOrganization((string) $data['site_id'], $organizationId);
        $siteChanged = (string) $brief->client_site_id !== (string) $site->id;

        if ($siteChanged && ! $this->canChangeBriefSite($brief)) {
            throw ValidationException::withMessages([
                'site_id' => 'The publishing site can only be changed before a draft or publication has been created.',
            ]);
        }

        $brief->update([
            'client_site_id' => (string) $site->id,
            'title' => $data['title'],
            'language' => $data['language'],
            'content_type' => $data['content_type'],
            'output_type' => $this->mapContentTypeToOutputType($data['content_type']),
            'primary_keyword' => ($data['primary_keyword'] ?? '') ?: null,
            'secondary_keywords' => $this->toArrayList($data['secondary_keywords'] ?? ''),
            'audience' => $audienceKeys !== [] ? implode(',', $audienceKeys) : (($data['target_audience'] ?? '') ?: null),
            'target_audience' => $audienceLabels !== [] ? implode(', ', $audienceLabels) : (($data['target_audience'] ?? '') ?: null),
            'funnel_stage' => ($data['funnel_stage'] ?? '') ?: null,
            'search_intent' => $searchIntent,
            'tone_of_voice' => ($data['tone_of_voice'] ?? '') ?: null,
            'unique_angle' => ($data['unique_angle'] ?? '') ?: null,
            'key_points' => $this->toArrayList($data['key_points'] ?? ''),
            'call_to_action' => ($data['call_to_action'] ?? '') ?: null,
            'desired_length_min' => ($data['desired_length_min'] ?? 0) ?: null,
            'desired_length_max' => ($data['desired_length_max'] ?? 0) ?: null,
            'notes' => ($data['notes'] ?? '') ?: null,
            'status' => $brief->status === 'archived' ? 'archived' : (($data['status'] ?? '') ?: $brief->status),
            'client_refs' => array_merge((array) $brief->client_refs, [
                'client_type' => (string) ($brief->source ?: 'client_ui'),
                'site_url' => (string) ($site->site_url ?? ''),
            ]),
        ]);

        if ($brief->content) {
            $brief->content->update([
                'workspace_id' => $siteChanged ? (string) $site->workspace_id : $brief->content->workspace_id,
                'client_site_id' => $siteChanged ? (string) $site->id : $brief->content->client_site_id,
                'type' => $this->mapBriefContentTypeToContentType((string) ($data['content_type'] ?? '')),
            ]);
        }

        $brief->refresh();

        if ($request->boolean('generate_draft')) {
            return $this->queueDraftGeneration(
                $request,
                $brief,
                $briefToDraftService,
                $creditWalletService,
                $pricing
            );
        }

        return redirect()
            ->route('app.content.workspace.brief.edit', $brief)
            ->with('status', 'Saved at '.now()->format('Y-m-d H:i:s').'.');
    }

    public function archive(Request $request, Brief $brief): RedirectResponse
    {
        $this->authorize('archive', $brief);

        $brief->update(['status' => 'archived']);

        return redirect()->route('app.content.workspace.show', $brief)->with('status', 'Content archived.');
    }

    public function generateDraft(
        Request $request,
        Brief $brief,
        BriefToDraftService $briefToDraftService,
        CreditWalletService $creditWalletService,
        GenerationPricing $pricing
    ): RedirectResponse {
        $this->authorize('generateDraft', $brief);

        return $this->queueDraftGeneration(
            $request,
            $brief,
            $briefToDraftService,
            $creditWalletService,
            $pricing
        );
    }

    public function enhance(
        EnhanceBriefRequest $request,
        Brief $brief,
        EnhanceBriefAction $enhanceAction,
        FeatureGate $featureGate
    ): RedirectResponse {
        $this->authorize('enhance', $brief);

        $workspace = $brief->clientSite?->workspace;
        if (! $workspace) {
            abort(422, 'Workspace context is missing for this brief.');
        }

        $this->assertBriefIntelligenceEnabledForWorkspace($featureGate, $workspace);

        try {
            $enhanceAction->queue(
                $brief,
                $request->user(),
                (bool) $request->boolean('force')
            );
        } catch (AuthorizationException $exception) {
            return back()->withErrors([
                'brief_intelligence' => $exception->getMessage(),
            ]);
        } catch (\RuntimeException $exception) {
            return back()->withErrors([
                'brief_intelligence' => $exception->getMessage(),
            ]);
        }

        return back()->with('status', $request->boolean('force')
            ? 'Brief intelligence rerun queued.'
            : 'Brief intelligence run queued.');
    }

    public function applySuggestion(
        ApplyBriefSuggestionRequest $request,
        Brief $brief,
        string $suggestion,
        BriefIntelligenceService $briefIntelligenceService,
        FeatureGate $featureGate
    ): RedirectResponse {
        $this->authorize('applySuggestion', $brief);

        $workspace = $brief->clientSite?->workspace;
        if (! $workspace) {
            abort(422, 'Workspace context is missing for this brief.');
        }

        $this->assertBriefIntelligenceEnabledForWorkspace($featureGate, $workspace);

        $suggestionModel = $this->resolveBriefSuggestion($brief, $suggestion);

        try {
            $briefIntelligenceService->applySuggestion($brief, $suggestionModel, (int) $request->user()->id);
        } catch (\RuntimeException $exception) {
            return back()->withErrors([
                'brief_intelligence' => $exception->getMessage(),
            ]);
        }

        return back()->with('status', 'Suggestion applied to brief.');
    }

    public function rejectSuggestion(
        RejectBriefSuggestionRequest $request,
        Brief $brief,
        string $suggestion,
        BriefIntelligenceService $briefIntelligenceService,
        FeatureGate $featureGate
    ): RedirectResponse {
        $this->authorize('rejectSuggestion', $brief);

        $workspace = $brief->clientSite?->workspace;
        if (! $workspace) {
            abort(422, 'Workspace context is missing for this brief.');
        }

        $this->assertBriefIntelligenceEnabledForWorkspace($featureGate, $workspace);

        $suggestionModel = $this->resolveBriefSuggestion($brief, $suggestion);

        try {
            $briefIntelligenceService->rejectSuggestion(
                $brief,
                $suggestionModel,
                (int) $request->user()->id,
                $request->validated('reason')
            );
        } catch (\RuntimeException $exception) {
            return back()->withErrors([
                'brief_intelligence' => $exception->getMessage(),
            ]);
        }

        return back()->with('status', 'Suggestion rejected.');
    }

    private function queueDraftGeneration(
        Request $request,
        Brief $brief,
        BriefToDraftService $briefToDraftService,
        CreditWalletService $creditWalletService,
        GenerationPricing $pricing
    ): RedirectResponse {
        $brief->loadMissing('clientSite.workspace');

        if ((string) $brief->status === 'archived') {
            return back()->withErrors(['brief' => 'Archived briefs cannot generate drafts.']);
        }

        if (! $brief->content_id) {
            $content = Content::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $brief->clientSite?->workspace_id,
                'client_site_id' => $brief->client_site_id,
                'title' => (string) $brief->title,
                'primary_keyword' => (string) ($brief->primary_keyword ?? ''),
                'type' => $this->mapBriefContentTypeToContentType((string) ($brief->content_type ?? '')),
                'status' => 'brief',
                'source' => 'manual',
                'external_key' => (string) Str::uuid(),
                'generation_mode' => 'balanced',
                'preferred_length' => $this->preferredLengthFromBounds(
                    (int) ($brief->desired_length_min ?? 0),
                    (int) ($brief->desired_length_max ?? 0)
                ),
            ]);

            $brief->update(['content_id' => $content->id]);
        }

        if ((string) $brief->status === 'draft') {
            $brief->update(['status' => 'ready_for_generation']);
        }

        $generationType = $this->generationTypeForBrief($brief);
        $tokenOptions = $pricing->outputTokenOptions($generationType);
        $validated = $request->validate([
            'requested_max_output_tokens' => [
                'nullable',
                'integer',
                'min:'.$tokenOptions['standard'],
                'max:'.$tokenOptions['max'],
            ],
        ]);

        $requestedMaxOutputTokens = $pricing->normalizeRequestedMaxOutputTokens(
            $generationType,
            isset($validated['requested_max_output_tokens']) ? (int) $validated['requested_max_output_tokens'] : null
        );
        $requiredCredits = $pricing->requiredCredits($generationType, $requestedMaxOutputTokens);
        $availableCredits = $creditWalletService->ensureAvailableForClientSite(
            (string) $brief->client_site_id,
            $requiredCredits,
            $request->user()?->id,
            [
                'feature' => 'content_workspace.generate_draft',
                'brief_id' => (string) $brief->id,
            ]
        );
        if ($availableCredits < $requiredCredits) {
            return back()->withErrors([
                'brief' => sprintf(
                    'Insufficient credits. Required: %d, available: %d.',
                    $requiredCredits,
                    $availableCredits
                ),
            ]);
        }

        $refs = is_array($brief->client_refs) ? $brief->client_refs : [];
        $refs['requested_max_output_tokens'] = $requestedMaxOutputTokens;
        $refs['required_credits'] = $requiredCredits;
        $refs['generation_type'] = $generationType;
        $brief->client_refs = $refs;
        $brief->save();

        $draft = $briefToDraftService->claimAndCreateDraft((string) $brief->id);
        if (! $draft) {
            return back()->withErrors(['brief' => 'Brief could not be queued for generation.']);
        }

        $draftMeta = is_array($draft->meta) ? $draft->meta : [];
        $draftMeta['requested_max_output_tokens'] = $requestedMaxOutputTokens;
        $draftMeta['required_credits'] = $requiredCredits;
        $draftMeta['generation_type'] = $generationType;
        $draft->meta = $draftMeta;
        $draft->credit_cost = $requiredCredits;
        $draft->save();

        return redirect()->route('app.drafts.show', $draft)->with('status', 'Draft generation queued.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validateBrief(Request $request, bool $isCreate): array
    {
        $statusRule = ['nullable', 'in:draft,ready_for_generation,archived,queued,processing,done'];
        if ($isCreate) {
            $statusRule = ['nullable', 'in:draft,ready_for_generation,queued'];
        }

        $siteRule = $isCreate
            ? ['nullable', 'string', 'required_if:destination_mode,connected']
            : ['required', 'string'];

        $validated = $request->validate([
            'destination_mode' => ['nullable', 'in:connected,api_only,hybrid'],
            'site_id' => $siteRule,
            'content_destination_id' => ['nullable', 'string'],
            'title' => [$isCreate ? 'nullable' : 'required', 'string', 'max:255'],
            'content_type' => ['required', 'in:'.implode(',', array_keys($this->contentTypeOptions()))],
            'language' => ['required', 'in:nl,en'],
            'complete_briefing' => ['nullable', 'string', 'max:50000'],
            'primary_keyword' => ['nullable', 'string', 'max:255'],
            'secondary_keywords' => ['nullable', 'string'],
            'audience_keys' => ['nullable', 'array'],
            'audience_keys.*' => ['string', 'max:100'],
            'target_audience' => ['nullable', 'string'],
            'funnel_stage' => ['nullable', 'in:'.implode(',', array_keys($this->funnelStageOptions()))],
            'search_intent' => ['nullable', 'string', 'max:100'],
            'tone_of_voice' => ['nullable', 'string'],
            'unique_angle' => ['nullable', 'string'],
            'key_points' => ['nullable', 'string'],
            'call_to_action' => ['nullable', 'string'],
            'desired_length_min' => ['nullable', 'integer', 'min:300', 'max:10000'],
            'desired_length_max' => ['nullable', 'integer', 'min:300', 'max:10000'],
            'notes' => ['nullable', 'string'],
            'status' => $statusRule,
        ], [
            'site_id.required' => 'Please select a site.',
        ], [
            'site_id' => 'site',
            'desired_length_min' => 'minimum length',
            'desired_length_max' => 'maximum length',
        ]);

        $min = (int) ($validated['desired_length_min'] ?? 0);
        $max = (int) ($validated['desired_length_max'] ?? 0);
        if ($min > 0 && $max > 0 && $min > $max) {
            throw ValidationException::withMessages([
                'desired_length_min' => 'Minimum length cannot be greater than maximum length.',
            ]);
        }

        return $validated;
    }

    /**
     * @param array<string,mixed> $data
     * @param array{raw:string,sections:array<string,string>,derived:array<string,mixed>} $completeBriefing
     * @return array<string,mixed>
     */
    private function hydrateBriefDataFromCompleteBriefing(array $data, array $completeBriefing): array
    {
        if (($completeBriefing['raw'] ?? '') === '') {
            return $data;
        }

        $derived = (array) ($completeBriefing['derived'] ?? []);
        $fillString = function (string $key, string $derivedKey) use (&$data, $derived): void {
            if (trim((string) ($data[$key] ?? '')) !== '') {
                return;
            }

            $value = trim((string) ($derived[$derivedKey] ?? ''));
            if ($value !== '') {
                $data[$key] = $value;
            }
        };

        $fillString('title', 'title');
        $fillString('primary_keyword', 'primary_keyword');
        $fillString('unique_angle', 'unique_angle');
        $fillString('call_to_action', 'call_to_action');

        if (trim((string) ($data['secondary_keywords'] ?? '')) === '' && ! empty($derived['secondary_keywords'])) {
            $data['secondary_keywords'] = implode("\n", (array) $derived['secondary_keywords']);
        }

        if (trim((string) ($data['target_audience'] ?? '')) === '' && ! empty($derived['target_audience'])) {
            $data['target_audience'] = implode(', ', (array) $derived['target_audience']);
        }

        if (trim((string) ($data['tone_of_voice'] ?? '')) === '' && trim((string) ($derived['tone'] ?? '')) !== '') {
            $data['tone_of_voice'] = (string) $derived['tone'];
        }

        if (trim((string) ($data['funnel_stage'] ?? '')) === '' && trim((string) ($derived['funnel_stage'] ?? '')) !== '') {
            $data['funnel_stage'] = (string) $derived['funnel_stage'];
        }

        if (trim((string) ($data['key_points'] ?? '')) === '' && ! empty($derived['key_points'])) {
            $data['key_points'] = implode("\n", (array) $derived['key_points']);
        }

        $derivedNotes = trim((string) ($derived['notes'] ?? ''));
        if ($derivedNotes !== '') {
            $data['notes'] = trim(implode("\n\n", array_filter([
                trim((string) ($data['notes'] ?? '')),
                $derivedNotes,
            ])));
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function assertRequiredBriefData(array $data): void
    {
        if (trim((string) ($data['title'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'title' => 'Add a title or paste a complete briefing with a working title.',
            ]);
        }
    }

    private function canChangeBriefSite(Brief $brief): bool
    {
        if ($brief->drafts()->exists()) {
            return false;
        }

        $content = $brief->content;
        if (! $content) {
            return true;
        }

        if ($content->drafts()->exists()) {
            return false;
        }

        if ($content->publications()->exists()) {
            return false;
        }

        return ! in_array((string) $content->status, ['published', 'scheduled', 'publishing'], true)
            && ! in_array((string) ($content->publish_status ?? ''), ['published', 'scheduled', 'publishing'], true)
            && ! in_array((string) ($content->delivery_status ?? ''), ['delivered', 'partial_success'], true);
    }

    private function executionPlanDraft(Brief $brief): ?\App\Models\Draft
    {
        $draftId = (string) data_get($brief->client_refs, 'draft_id', '');
        if ($draftId === '') {
            return null;
        }

        return $brief->drafts
            ->first(fn (\App\Models\Draft $draft): bool => (string) $draft->id === $draftId);
    }

    private function canCreateFirstDraft(Request $request, Brief $brief, ?\App\Models\Draft $executionPlanDraft): bool
    {
        return $executionPlanDraft === null
            && (string) $brief->source === 'opportunity_execution_plan'
            && in_array((string) $brief->status, ['draft', 'approved'], true)
            && ($request->user()?->can('generateDraft', $brief) ?? false);
    }

    private function resolveSiteForOrganization(string $siteId, int $organizationId): ClientSite
    {
        $site = ClientSite::query()
            ->where('id', $siteId)
            ->whereHas('workspace', fn ($q) => $q->where('organization_id', $organizationId))
            ->first();

        if (! $site) {
            throw ValidationException::withMessages([
                'site_id' => 'Selected site is not available for your workspace.',
            ]);
        }

        return $site;
    }

    private function resolveDestinationForOrganization(string $destinationId, int $organizationId): ContentDestination
    {
        $destination = ContentDestination::query()
            ->where('id', $destinationId)
            ->whereHas('workspace', fn ($query) => $query->where('organization_id', $organizationId))
            ->first();

        if (! $destination) {
            throw ValidationException::withMessages([
                'content_destination_id' => 'Selected destination is not available for your workspace.',
            ]);
        }

        return $destination;
    }

    private function resolveSourceForOrganization(string $sourceId, int $organizationId): ?ContentSource
    {
        if ($sourceId === '') {
            return null;
        }

        return ContentSource::query()
            ->where('id', $sourceId)
            ->whereHas('workspace', fn ($query) => $query->where('organization_id', $organizationId))
            ->first();
    }

    private function findSourceByGenerationIdempotencyKey(
        string $workspaceId,
        int $userId,
        string $idempotencyKey
    ): ?ContentSource {
        return ContentSource::query()
            ->where('workspace_id', $workspaceId)
            ->where('created_by_user_id', $userId)
            ->where('generation_idempotency_key', $idempotencyKey)
            ->orderByDesc('created_at')
            ->first();
    }

    private function buildSourceGenerationIdempotencyKey(
        string $workspaceId,
        int $userId,
        string $sourceUrl,
        string $locale,
        string $intent
    ): string {
        return 'source-generation:' . sha1(implode('|', [
            $workspaceId,
            (string) $userId,
            Str::lower(trim($sourceUrl)),
            $locale,
            $intent,
        ]));
    }

    private function normalizeSourceGenerationLocale(string $locale): string
    {
        return ContentPersistencePayloadNormalizer::normalizeLocale($locale !== '' ? $locale : 'en');
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceGenerationStatusPayload(ContentSource $source): array
    {
        $isCompleted = $source->isGenerationCompleted();
        $hasRecoverableExtractionFailure = $this->isRecoverableSourceExtractionFailure($source);
        $isFailed = $source->isGenerationFailed() && ! $hasRecoverableExtractionFailure;
        $isExtractionPending = in_array((string) $source->extraction_status, ['pending', 'fetching', 'extracting'], true)
            && ! $source->isGenerationPending();
        $isPending = $source->isGenerationPending() || $isExtractionPending || $hasRecoverableExtractionFailure;
        $redirectUrl = route('app.content.create', ['source' => $source->id]);

        if ($source->result_brief_id) {
            $redirectUrl = route('app.content.workspace.show', $source->result_brief_id);
        }

        return [
            'job_id' => (string) $source->id,
            'source_id' => (string) $source->id,
            'status' => $hasRecoverableExtractionFailure ? ContentSource::GENERATION_STATUS_QUEUED : (string) $source->generation_status,
            'progress_step' => $hasRecoverableExtractionFailure ? 'fallback_extraction' : (string) ($source->generation_progress_step ?: $source->generation_status),
            'progress_label' => $hasRecoverableExtractionFailure
                ? 'Trying fallback extraction methods'
                : $source->getGenerationProgressLabel(),
            'extraction_status' => (string) $source->extraction_status,
            'is_pending' => $isPending,
            'is_extraction_pending' => $isExtractionPending || $hasRecoverableExtractionFailure,
            'is_completed' => $isCompleted,
            'is_failed' => $isFailed,
            'content_id' => $source->result_content_id ? (string) $source->result_content_id : null,
            'brief_id' => $source->result_brief_id ? (string) $source->result_brief_id : null,
            'failure_message' => $isFailed ? $this->safeSourceGenerationFailureMessage($source) : null,
            'error_code' => $isFailed ? $this->safeSourceGenerationFailureCode($source) : null,
            'redirect_url' => $isCompleted ? $redirectUrl : route('app.content.create', ['source' => $source->id, 'pending' => 1]),
            'generated_at' => $source->generation_completed_at?->toIso8601String(),
            'debug' => app()->isLocal() || (bool) config('app.debug') ? [
                'extracted_characters' => data_get($source->metadata_json, 'extraction.extracted_characters'),
                'estimated_tokens' => data_get($source->metadata_json, 'extraction.estimated_tokens'),
                'parser_used' => data_get($source->metadata_json, 'extraction.method'),
                'ai_provider' => data_get($source->analysis_json, '_debug.ai_provider'),
                'ai_model' => data_get($source->analysis_json, '_debug.ai_model'),
                'generation_duration_ms' => data_get($source->analysis_json, '_debug.generation_duration_ms'),
            ] : null,
        ];
    }

    private function sourceGenerationStartResponse(Request $request, ContentSource $source): JsonResponse|RedirectResponse
    {
        $payload = $this->sourceGenerationStatusPayload($source);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'status' => ($payload['is_completed'] ?? false)
                    ? 'completed'
                    : (($payload['is_failed'] ?? false) ? 'failed' : 'processing'),
                'job_id' => (string) $payload['job_id'],
                'redirect_url' => (string) $payload['redirect_url'],
                'failure_message' => $payload['failure_message'] ?? null,
                'error_code' => $payload['error_code'] ?? null,
                'debug' => $payload['debug'] ?? null,
            ]);
        }

        return redirect()
            ->to((string) $payload['redirect_url'])
            ->with('status', $source->isGenerationCompleted()
                ? 'Source-based brief already generated. Save it when you are ready.'
                : 'Brief generation started. This page will update when ready.');
    }

    private function isRecoverableSourceExtractionFailure(ContentSource $source): bool
    {
        if (! $source->isGenerationFailed()) {
            return false;
        }

        return in_array((string) $source->generation_failure_code, [
            'SOURCE_FETCH_TIMEOUT',
            'SOURCE_FETCH_BLOCKED',
            'SOURCE_FETCH_UNAVAILABLE',
            'SOURCE_FETCH_FAILED',
        ], true);
    }

    private function safeSourceGenerationFailureCode(ContentSource $source): string
    {
        $code = strtoupper((string) ($source->generation_failure_code ?: 'PL-URL-GEN-FAILED'));

        return match ($code) {
            'DISPATCH_FAILED' => 'GENERATION_DISPATCH_FAILED',
            default => $code,
        };
    }

    private function safeSourceGenerationFailureMessage(ContentSource $source): string
    {
        $message = trim((string) $source->generation_failure_message);
        $code = $this->safeSourceGenerationFailureCode($source);

        if ($this->looksLikeInternalExceptionMessage($message)) {
            return match ($code) {
                'GENERATION_DISPATCH_FAILED' => 'We could not start the brief generation job. Please try again in a moment.',
                default => 'Brief generation failed. Please try again, paste source notes manually, or use another public URL.',
            };
        }

        return $message !== ''
            ? $message
            : 'Brief generation failed. Please try again, paste source notes manually, or use another public URL.';
    }

    private function looksLikeInternalExceptionMessage(string $message): bool
    {
        return Str::contains($message, [
            'SQLSTATE[',
            'Base table or view not found',
            'Connection:',
            'Stack trace',
            'vendor/laravel',
            'Illuminate\\',
            'App\\',
        ]);
    }

    private function sourceGenerationValidationError(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'message' => $message,
                'error' => $message,
                'error_code' => 'PL-URL-GEN-VALIDATION',
            ], 422);
        }

        return back()->withErrors(['source_url' => $message]);
    }

    private function dispatchSourceBriefGeneration(ContentSource $source, string $outputMode): void
    {
        $job = (new GenerateSourceBriefJob($source->id, $outputMode))
            ->onQueue('generation');

        /** @var Dispatcher $dispatcher */
        $dispatcher = app(Dispatcher::class);

        if ($this->shouldDispatchSourceBriefGenerationSynchronously()) {
            $dispatcher->dispatchSync($job);

            return;
        }

        $dispatcher->dispatch($job);
    }

    private function shouldDispatchSourceBriefGenerationSynchronously(): bool
    {
        $connection = (string) config('queue.default', 'database');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);

        return $driver === 'sync';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logUrlGenerationFailure(Request $request, Throwable $exception, array $context = []): void
    {
        Log::error('URL generation failed', array_merge($context, [
            'url' => $request->input('source_url'),
            'user_id' => $request->user()?->id,
            'workspace_id' => $request->input('workspace_id'),
            'exception_message' => $exception->getMessage(),
            'exception_class' => $exception::class,
            'trace' => Str::limit($exception->getTraceAsString(), 2000, ''),
        ]));
    }

    private function urlGenerationFailureResponse(Request $request, Throwable $exception): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->ajax()) {
            $message = $exception instanceof SourceBriefingException
                ? $exception->userMessage
                : 'We could not generate a brief from this URL. Please try another public article URL.';

            return response()->json([
                'message' => $message,
                'error' => $message,
                'error_code' => $exception instanceof SourceBriefingException
                    ? $exception->failureCode
                    : 'PL-URL-GEN-FAILED',
            ], 500);
        }

        return back()->withInput()->withErrors([
            'source_url' => $exception instanceof SourceBriefingException
                ? $exception->userMessage
                : 'We could not generate a brief from this URL. Please try another public article URL.',
        ]);
    }

    private function primaryWorkspaceForOrganization(int $organizationId): Workspace
    {
        $workspace = Workspace::query()
            ->where('organization_id', $organizationId)
            ->orderBy('created_at')
            ->first();

        if (! $workspace) {
            abort(422, 'No workspace found for this organization.');
        }

        return $workspace;
    }

    /**
     * @return \Illuminate\Support\Collection<int,ClientSite>
     */
    private function sitesForOrganization(int $organizationId)
    {
        return ClientSite::query()
            ->whereHas('workspace', fn ($q) => $q->where('organization_id', $organizationId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'workspace_id']);
    }

    /**
     * @return \Illuminate\Support\Collection<int,ContentDestination>
     */
    private function destinationsForOrganization(int $organizationId)
    {
        return ContentDestination::query()
            ->whereHas('workspace', fn ($query) => $query->where('organization_id', $organizationId))
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'workspace_id', 'type', 'status']);
    }

    private function assertBriefIntelligenceEnabledForWorkspace(FeatureGate $featureGate, Workspace $workspace): void
    {
        if (! $this->toBool($featureGate->value($workspace, 'brief_intelligence_enabled', false), false)) {
            abort(403, 'Brief intelligence is not enabled for this workspace.');
        }
    }

    /**
     * @param array<string, mixed> $generated
     */
    private function composeSourceBriefNotes(ContentSource $source, array $generated): string
    {
        $brief = is_array($generated['brief'] ?? null) ? $generated['brief'] : [];
        $keywords = is_array($generated['keywords'] ?? null) ? $generated['keywords'] : [];
        $chain = is_array($generated['chain_proposal'] ?? null) ? $generated['chain_proposal'] : [];

        $lines = [
            'Source-based briefing note',
            'This brief was generated from external content analysis to support original, brand-aligned content creation. Do not reproduce source wording or structure.',
            '',
            'Source URL: ' . (string) ($source->final_url ?: $source->source_url),
            'Source domain: ' . (string) ($source->source_domain ?? ''),
            'Source title: ' . (string) ($source->source_title ?? ''),
            '',
            'Recommended angle:',
            (string) ($brief['summary'] ?? ''),
            '',
            'Suggested structure:',
        ];

        foreach ((array) ($brief['recommended_structure'] ?? []) as $item) {
            $lines[] = '- ' . trim((string) $item);
        }

        if (! empty($brief['recommended_differentiators'])) {
            $lines[] = '';
            $lines[] = 'Recommended differentiators:';
            foreach ((array) $brief['recommended_differentiators'] as $item) {
                $lines[] = '- ' . trim((string) $item);
            }
        }

        if (! empty($brief['things_to_avoid'])) {
            $lines[] = '';
            $lines[] = 'Things to avoid:';
            foreach ((array) $brief['things_to_avoid'] as $item) {
                $lines[] = '- ' . trim((string) $item);
            }
        }

        if ($keywords !== []) {
            $lines[] = '';
            $lines[] = 'Keyword opportunities:';
            foreach ((array) ($keywords['faq_opportunities'] ?? []) as $item) {
                $lines[] = '- ' . trim((string) $item);
            }
        }

        if ($chain !== []) {
            $lines[] = '';
            $lines[] = 'Chain proposal:';
            $lines[] = 'Pillar topic: ' . (string) ($chain['pillar_topic'] ?? '');
            foreach ((array) ($chain['supporting_subtopics'] ?? []) as $row) {
                $lines[] = '- ' . trim((string) data_get($row, 'title', ''));
            }
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param array<string,mixed> $validated
     * @return array<string,mixed>
     */
    private function sourceChainSettings(array $validated): array
    {
        $settings = [
            'title' => $this->nullableText($validated['chain_title'] ?? null),
            'goal' => $this->nullableText($validated['chain_goal'] ?? null),
            'main_topic' => $this->nullableText($validated['chain_main_topic'] ?? null),
            'primary_keyword' => $this->nullableText($validated['chain_primary_keyword'] ?? null),
            'secondary_keywords' => $this->toArrayList($validated['chain_secondary_keywords'] ?? ''),
            'target_audience' => $this->nullableText($validated['chain_target_audience'] ?? null),
            'funnel_stage' => $this->nullableText($validated['chain_funnel_stage'] ?? null),
            'search_intent' => $this->nullableText($validated['chain_search_intent'] ?? null),
            'tone_of_voice' => $this->nullableText($validated['chain_tone_of_voice'] ?? null),
            'unique_angle' => $this->nullableText($validated['chain_unique_angle'] ?? null),
            'items_count' => isset($validated['chain_items_count']) ? max(1, min(20, (int) $validated['chain_items_count'])) : null,
            'item_types' => collect((array) ($validated['chain_item_types'] ?? []))
                ->map(fn (mixed $value): string => trim((string) $value))
                ->filter()
                ->values()
                ->all(),
            'language' => $this->nullableText($validated['chain_language'] ?? null),
            'destination_site' => $this->nullableText($validated['chain_destination_site'] ?? null),
            'cms_destination' => $this->nullableText($validated['chain_cms_destination'] ?? null),
            'cta' => $this->nullableText($validated['chain_cta'] ?? null),
            'internal_link_targets' => $this->toArrayList($validated['chain_internal_link_targets'] ?? ''),
            'notes' => $this->nullableText($validated['chain_notes'] ?? null),
        ];

        return array_filter($settings, fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function createFullChainFromSourceBrief(
        SaveUrlBriefSourceRequest $request,
        ContentSource $source,
        ?Brief $brief,
        ClientSite $site,
        ?ContentDestination $destination
    ): ContentSeries {
        $generated = is_array($source->generated_payload_json) ? $source->generated_payload_json : [];
        $briefPayload = is_array($generated['brief'] ?? null) ? $generated['brief'] : [];
        $chainProposal = is_array($generated['chain_proposal'] ?? null) ? $generated['chain_proposal'] : [];
        $settings = array_replace(
            (array) data_get($source->metadata_json, 'chain_settings', []),
            $this->sourceChainSettings($request->validated())
        );
        $items = $this->approvedChainItems($request, $chainProposal, $briefPayload, $settings);

        if ($items === []) {
            throw ValidationException::withMessages([
                'chain_items' => 'Review and approve the proposed chain items before creating them.',
            ]);
        }

        return DB::transaction(function () use ($request, $source, $brief, $site, $destination, $generated, $briefPayload, $chainProposal, $settings, $items): ContentSeries {
            $seriesId = (string) Str::uuid();
            $primaryKeyword = $this->nullableText($settings['primary_keyword'] ?? null)
                ?: $this->nullableText(data_get($items, '0.primary_keyword'))
                ?: $this->nullableText($briefPayload['primary_keyword'] ?? null)
                ?: (string) ($source->source_title ?? 'Content chain');
            $seriesName = $this->nullableText($settings['title'] ?? null)
                ?: $this->nullableText($settings['main_topic'] ?? null)
                ?: $this->nullableText($chainProposal['pillar_topic'] ?? null)
                ?: $this->nullableText($briefPayload['working_title'] ?? null)
                ?: 'Content chain';
            $mainTopic = $this->nullableText($settings['main_topic'] ?? null)
                ?: $this->nullableText($chainProposal['pillar_topic'] ?? null)
                ?: $seriesName;

            $strategyArticles = collect($items)->map(function (array $item, int $index) use ($items): array {
                $articleNumber = $index + 1;

                return [
                    'article_number' => $articleNumber,
                    'title' => (string) $item['title'],
                    'content_type' => (string) ($item['content_type'] ?? 'supporting_blog'),
                    'primary_keyword' => (string) ($item['primary_keyword'] ?? ''),
                    'secondary_keywords' => (array) ($item['secondary_keywords'] ?? []),
                    'search_intent' => (string) ($item['search_intent'] ?? ''),
                    'funnel_stage' => (string) ($item['funnel_stage'] ?? ''),
                    'target_audience' => (string) ($item['target_audience'] ?? ''),
                    'editorial_angle' => (string) ($item['angle'] ?? ''),
                    'key_points' => (array) ($item['key_points'] ?? []),
                    'cta' => (string) ($item['cta'] ?? ''),
                    'internal_links_to' => $articleNumber === 1 && count($items) > 1
                        ? collect(range(2, count($items)))->values()->all()
                        : ($articleNumber === 1 ? [] : [1]),
                    'suggested_internal_links' => (array) ($item['suggested_internal_links'] ?? []),
                    'is_pillar' => $articleNumber === 1,
                    'proposal_status' => 'approved',
                ];
            })->values()->all();

            $series = ContentSeries::query()->create([
                'id' => $seriesId,
                'organization_id' => (int) $request->user()->organization_id,
                'site_id' => (string) $site->id,
                'name' => Str::limit($seriesName, 255, ''),
                'main_topic' => Str::limit($mainTopic, 255, ''),
                'primary_keyword' => Str::limit($primaryKeyword, 255, ''),
                'supporting_keywords' => collect($items)
                    ->flatMap(fn (array $item): array => (array) ($item['secondary_keywords'] ?? []))
                    ->filter()
                    ->unique()
                    ->take(40)
                    ->values()
                    ->all(),
                'intent_keys' => [],
                'audience' => $this->nullableText($settings['target_audience'] ?? null) ?: $this->nullableText(data_get($items, '0.target_audience')),
                'tone' => $this->nullableText($settings['tone_of_voice'] ?? null),
                'funnel_stage' => $this->nullableText($settings['funnel_stage'] ?? null) ?: $this->nullableText(data_get($items, '0.funnel_stage')),
                'articles_count' => count($items),
                'content_type' => 'post',
                'status' => ContentSeries::STATUS_DRAFT,
                'is_locked' => false,
                'strategy_json' => [
                    'angle' => $this->nullableText($settings['unique_angle'] ?? null)
                        ?: $this->nullableText($briefPayload['summary'] ?? null)
                        ?: 'Use the approved source-derived chain proposal as the content plan.',
                    'articles' => $strategyArticles,
                    'meta' => [
                        'source' => 'source_url_chain_proposal',
                        'chain_mode' => (string) ($source->generation_output_mode ?: 'full_chain'),
                        'proposal_only' => false,
                        'source_brief_id' => $brief?->id,
                        'content_source_id' => (string) $source->id,
                        'source_url' => (string) ($source->final_url ?: $source->source_url),
                        'source_title' => (string) ($source->source_title ?? ''),
                        'source_summary' => (string) data_get($source->metadata_json, 'extraction.summary', ''),
                        'detected_topic' => (string) data_get($source->analysis_json, 'main_topic', ''),
                        'detected_keywords' => (array) data_get($generated, 'keywords.secondary_keywords', []),
                        'recommended_structure' => (array) data_get($briefPayload, 'recommended_structure', []),
                        'extraction_metadata' => (array) data_get($source->metadata_json, 'extraction', []),
                        'chain_settings' => $settings,
                        'chain_proposal' => $chainProposal,
                        'created_at' => now()->toIso8601String(),
                    ],
                ],
                'created_by' => (int) $request->user()->id,
            ]);

            foreach ($items as $index => $item) {
                $articleNumber = $index + 1;
                $content = Content::query()->create([
                    'id' => (string) Str::uuid(),
                    'workspace_id' => (string) $site->workspace_id,
                    'client_site_id' => (string) $site->id,
                    'content_destination_id' => $destination?->id,
                    'series_id' => (string) $series->id,
                    'title' => Str::limit((string) $item['title'], 255, ''),
                    'language' => (string) ($settings['language'] ?? $briefPayload['language'] ?? $source->source_language ?? 'en'),
                    'translation_source_locale' => null,
                    'is_source_locale' => true,
                    'type' => 'article',
                    'status' => 'brief',
                    'source' => 'manual',
                    'external_key' => sprintf('series-%s-article-%d', (string) $series->id, $articleNumber),
                    'primary_keyword' => (string) ($item['primary_keyword'] ?? ''),
                    'generation_mode' => 'balanced',
                    'preferred_length' => 'medium',
                    'internal_links_meta' => [
                        'chain_item_type' => (string) ($item['content_type'] ?? ''),
                        'search_intent' => (string) ($item['search_intent'] ?? ''),
                        'funnel_stage' => (string) ($item['funnel_stage'] ?? ''),
                        'target_audience' => (string) ($item['target_audience'] ?? ''),
                        'angle' => (string) ($item['angle'] ?? ''),
                        'key_points' => (array) ($item['key_points'] ?? []),
                        'cta' => (string) ($item['cta'] ?? ''),
                        'suggested_internal_links' => (array) ($item['suggested_internal_links'] ?? []),
                        'source_content_source_id' => (string) $source->id,
                    ],
                    'created_by' => (int) $request->user()->id,
                    'updated_by' => (int) $request->user()->id,
                ]);

                ContentSeriesArticle::query()
                    ->where('series_id', (string) $series->id)
                    ->where('article_number', $articleNumber)
                    ->first()
                    ?->update([
                        'content_id' => (string) $content->id,
                        'title' => Str::limit((string) $item['title'], 255, ''),
                        'primary_keyword' => (string) ($item['primary_keyword'] ?? ''),
                        'secondary_keywords' => (array) ($item['secondary_keywords'] ?? []),
                        'is_pillar' => $articleNumber === 1,
                        'meta' => [
                            'proposal_status' => 'approved',
                            'content_type' => (string) ($item['content_type'] ?? ''),
                            'search_intent' => (string) ($item['search_intent'] ?? ''),
                            'funnel_stage' => (string) ($item['funnel_stage'] ?? ''),
                            'target_audience' => (string) ($item['target_audience'] ?? ''),
                            'angle' => (string) ($item['angle'] ?? ''),
                            'key_points' => (array) ($item['key_points'] ?? []),
                            'cta' => (string) ($item['cta'] ?? ''),
                            'suggested_internal_links' => (array) ($item['suggested_internal_links'] ?? []),
                        ],
                    ]);
            }

            $source->update([
                'metadata_json' => array_merge((array) $source->metadata_json, [
                    'result_chain_series_id' => (string) $series->id,
                ]),
                'result_content_id' => (string) ($series->contents()->orderBy('created_at')->value('id') ?? ''),
            ]);

            return $series->fresh(['contents', 'seriesArticles']) ?? $series;
        });
    }

    /**
     * @param array<string,mixed> $chainProposal
     * @param array<string,mixed> $briefPayload
     * @param array<string,mixed> $settings
     * @return array<int,array<string,mixed>>
     */
    private function approvedChainItems(SaveUrlBriefSourceRequest $request, array $chainProposal, array $briefPayload, array $settings): array
    {
        $submitted = collect((array) $request->validated('chain_items', []));

        return $submitted
            ->map(function (mixed $row, int|string $index) use ($briefPayload, $settings): array {
                $row = is_array($row) ? $row : [];
                $title = $this->nullableText($row['title'] ?? null);

                return [
                    'order' => (int) ($row['order'] ?? ((int) $index + 1)),
                    'status' => (string) ($row['status'] ?? 'proposed'),
                    'title' => $title,
                    'content_type' => $this->nullableText($row['content_type'] ?? null) ?: 'supporting_blog',
                    'primary_keyword' => $this->nullableText($row['primary_keyword'] ?? null) ?: $title,
                    'secondary_keywords' => $this->toArrayList($row['secondary_keywords'] ?? ''),
                    'search_intent' => $this->nullableText($row['search_intent'] ?? null) ?: $this->nullableText($briefPayload['search_intent'] ?? null),
                    'funnel_stage' => $this->nullableText($row['funnel_stage'] ?? null) ?: $this->nullableText($settings['funnel_stage'] ?? null),
                    'target_audience' => $this->nullableText($row['target_audience'] ?? null) ?: $this->nullableText($settings['target_audience'] ?? null) ?: $this->nullableText($briefPayload['target_audience'] ?? null),
                    'angle' => $this->nullableText($row['angle'] ?? null) ?: $this->nullableText($settings['unique_angle'] ?? null),
                    'key_points' => $this->toArrayList($row['key_points'] ?? ''),
                    'cta' => $this->nullableText($row['cta'] ?? null) ?: $this->nullableText($settings['cta'] ?? null) ?: $this->nullableText($briefPayload['cta_recommendation'] ?? null),
                    'suggested_internal_links' => $this->toArrayList($row['suggested_internal_links'] ?? ''),
                ];
            })
            ->filter(fn (array $row): bool => (string) $row['status'] === 'approved' && filled($row['title']))
            ->sortBy('order')
            ->values()
            ->take(20)
            ->all();
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{
     *   enabled:bool,
     *   meta:array<string,mixed>,
     *   completeness:array<string,mixed>,
     *   runtime:array<string,mixed>,
     *   intelligence_summary:string,
     *   linked_research:array<string,mixed>,
     *   linked_research_project:?ResearchProject
     * }
     */
    private function briefIntelligenceContext(Brief $brief, FeatureGate $featureGate): array
    {
        $workspace = $brief->clientSite?->workspace;
        $refs = is_array($brief->client_refs) ? $brief->client_refs : [];
        $meta = is_array($refs['brief_intelligence'] ?? null) ? $refs['brief_intelligence'] : [];
        $linkedResearch = is_array($meta['linked_research'] ?? null) ? $meta['linked_research'] : [];

        $linkedResearchId = trim((string) ($meta['research_project_id'] ?? $linkedResearch['project_id'] ?? ''));
        $linkedProject = null;

        if ($linkedResearchId !== '') {
            $linkedProject = ResearchProject::query()
                ->where('id', $linkedResearchId)
                ->first(['id', 'workspace_id', 'name', 'status', 'created_at', 'completed_at']);
        }

        return [
            'enabled' => $workspace
                ? $this->toBool($featureGate->value($workspace, 'brief_intelligence_enabled', false), false)
                : false,
            'meta' => $meta,
            'completeness' => is_array($meta['completeness'] ?? null) ? $meta['completeness'] : [],
            'runtime' => is_array($meta['runtime'] ?? null) ? $meta['runtime'] : [],
            'intelligence_summary' => trim((string) ($meta['intelligence_summary'] ?? '')),
            'linked_research' => $linkedResearch,
            'linked_research_project' => $linkedProject,
        ];
    }

    private function resolveBriefSuggestion(Brief $brief, string $suggestionId): BriefSuggestion
    {
        $suggestion = $brief->suggestions()
            ->where('id', trim($suggestionId))
            ->first();

        if (! $suggestion) {
            abort(404);
        }

        return $suggestion;
    }

    /**
     * @return Collection<int,ResearchProject>
     */
    private function researchProjectsForOrganization(int $organizationId, int $limit = 40): Collection
    {
        return ResearchProject::query()
            ->whereHas('workspace', fn ($query) => $query->where('organization_id', $organizationId))
            ->whereIn('status', ['completed', 'failed', 'summarizing', 'extracting', 'fetching', 'queued'])
            ->with(['brief:id,title', 'clientSite:id,name', 'workspace:id,name'])
            ->latest('created_at')
            ->limit(max(1, min(200, $limit)))
            ->get(['id', 'workspace_id', 'brief_id', 'client_site_id', 'name', 'status', 'created_at', 'completed_at']);
    }

    private function organizationHasBriefIntelligenceEnabledWorkspace(int $organizationId, FeatureGate $featureGate): bool
    {
        $workspaces = Workspace::query()
            ->where('organization_id', $organizationId)
            ->get(['id', 'organization_id']);

        foreach ($workspaces as $workspace) {
            if ($this->toBool($featureGate->value($workspace, 'brief_intelligence_enabled', false), false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function toArrayList(mixed $value): array
    {
        if (is_array($value)) {
            return collect($value)
                ->map(fn (mixed $item): string => trim((string) $item))
                ->filter()
                ->values()
                ->all();
        }

        $string = trim((string) $value);
        if ($string === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[,\n]/', $string) ?: [])));
    }

    private function mapContentTypeToOutputType(string $contentType): string
    {
        return match ($contentType) {
            'landing' => 'seo_page',
            'linkedin' => 'linkedin_post',
            'email' => 'email',
            default => 'kb_article',
        };
    }

    private function mapBriefContentTypeToContentType(string $contentType): string
    {
        return match (strtolower(trim($contentType))) {
            'knowledge_base' => 'knowledge_base',
            'landing' => 'seo_page',
            default => 'article',
        };
    }

    private function generationTypeForBrief(Brief $brief): string
    {
        return match ((string) ($brief->output_type ?? 'kb_article')) {
            'kb_article', 'article' => GenerationPricing::TYPE_ARTICLE,
            default => GenerationPricing::TYPE_ARTICLE,
        };
    }

    private function preferredLengthFromBounds(int $min, int $max): string
    {
        if ($min >= 2000 || $max >= 2200) {
            return 'pillar';
        }

        if ($min >= 1300 || $max >= 1400) {
            return 'long';
        }

        if ($max > 0 && $max <= 850) {
            return 'short';
        }

        return 'medium';
    }

    /**
     * @return array<string,string>
     */
    private function contentTypeOptions(): array
    {
        return [
            'blog' => 'Blog',
            'knowledge_base' => 'Knowledge base',
            'landing' => 'Landing page',
            'linkedin' => 'LinkedIn',
            'email' => 'Email',
            'other' => 'Other',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function funnelStageOptions(): array
    {
        return [
            'awareness' => 'Awareness',
            'consideration' => 'Consideration',
            'decision' => 'Decision',
            'retention' => 'Retention',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function statusOptions(): array
    {
        return [
            'draft' => 'Draft',
            'ready_for_generation' => 'Ready for generation',
            'queued' => 'Queued',
            'processing' => 'Processing',
            'done' => 'Done',
            'archived' => 'Archived',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function sourceOptions(): array
    {
        return [
            'client_ui' => 'Client UI',
            'wp_plugin' => 'WordPress plugin',
            'api' => 'API',
            'import' => 'Import',
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{0:?string,1:array<int,string>,2:array<int,string>}
     */
    private function resolveTaxonomySelections(
        array $data,
        int $organizationId,
        EditorialTaxonomyService $taxonomyService
    ): array {
        $intentOptions = $taxonomyService->activeItemMapByTenantAndType($organizationId, 'intent');
        $audienceOptions = $taxonomyService->activeItemMapByTenantAndType($organizationId, 'audience');

        $searchIntentKey = $taxonomyService->normalizeKey((string) ($data['search_intent'] ?? ''));
        if ($searchIntentKey !== '' && ! array_key_exists($searchIntentKey, $intentOptions)) {
            throw ValidationException::withMessages([
                'search_intent' => 'Selected search intent is not available for your tenant taxonomy.',
            ]);
        }

        $audienceKeys = collect((array) ($data['audience_keys'] ?? []))
            ->map(fn ($value): string => $taxonomyService->normalizeKey((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $invalidAudience = collect($audienceKeys)
            ->first(fn (string $key): bool => ! array_key_exists($key, $audienceOptions));

        if ($invalidAudience) {
            throw ValidationException::withMessages([
                'audience_keys' => 'Selected audience contains an unavailable taxonomy item: '.$invalidAudience,
            ]);
        }

        $audienceLabels = collect($audienceKeys)
            ->map(fn (string $key): string => (string) $audienceOptions[$key])
            ->values()
            ->all();

        return [
            $searchIntentKey !== '' ? $searchIntentKey : null,
            $audienceKeys,
            $audienceLabels,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function parseAudienceKeys(string $value): array
    {
        return collect(preg_split('/\s*,\s*/', trim($value)) ?: [])
            ->map(fn ($part): string => strtolower(trim((string) $part)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return ! in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'off', 'no'], true);
    }
}
