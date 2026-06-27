<?php

namespace App\Http\Controllers\App;

use App\Actions\Agents\RunAgentForDraft;
use App\Actions\Agents\RunInternalLinkingForDraft;
use App\Actions\Agents\RunLocalizationForDraft;
use App\Actions\Content\ApplyInternalLinkSuggestion;
use App\Agents\Drafts\DraftSmartSuggestionsAgent;
use App\Agents\InternalLinking\InternalLinkingAgent;
use App\Agents\Localization\LocalizationAgent;
use App\Enums\DraftImprovementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\ImproveDraftRequest;
use App\Http\Requests\App\TranslateDraftRequest;
use App\Jobs\AnalyzeDraftJob;
use App\Jobs\BulkTranslateDraftJob;
use App\Jobs\DeliverDraftJob;
use App\Jobs\ImproveDraftSectionJob;
use App\Models\AgentRun;
use App\Models\ClientSite;
use App\Models\ContentImage;
use App\Models\Draft;
use App\Services\Drafts\Intelligence\DraftImprovementHistoryBuilder;
use App\Services\Drafts\Intelligence\DraftRecommendationPresenter;
use App\Services\Entitlements\WorkspaceEntitlementsService;
use App\Services\HumanContent\HumanContentGate;
use App\Services\HumanContent\HumanContentScoreService;
use App\Services\HumanContent\HumanizationService;
use App\Services\LinkIntelligence\DefaultLinkSuggestionService;
use App\Services\Seo\SeoFieldSyncCapabilityResolver;
use App\Services\Translation\TranslationService;
use App\Services\WordPress\WordPressLanguageSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class AppDraftsController extends Controller
{
    public function index(): View
    {
        $organization = request()->user()->organization;
        $siteId = trim((string) request()->query('site', ''));

        $query = Draft::query()
            ->with('brief', 'clientSite')
            ->whereHas('clientSite.workspace', function ($query) use ($organization) {
                $query->where('organization_id', $organization->id);
            })
            ->orderByDesc('created_at');

        if ($siteId !== '') {
            $query->where('client_site_id', $siteId);
        }

        $drafts = $query->paginate(20)->withQueryString();

        return view('app.drafts.index', [
            'drafts' => $drafts,
        ]);
    }

    public function show(
        Draft $draft,
        SeoFieldSyncCapabilityResolver $seoFieldSyncCapabilityResolver,
        DraftRecommendationPresenter $recommendationPresenter,
        TranslationService $translationService,
        WordPressLanguageSyncService $wordPressLanguageSyncService,
    ): View
    {
        $this->ensureDraftOrganizationAccess($draft);

        $lineageRoot = $draft->getOriginalSourceDraft() ?? $draft;

        $draft->load([
            'brief',
            'content',
            'content.publishTargets',
            'clientSite.workspace',
            'sourceDraft.content.publishTargets',
            'analysis.recommendations',
            'analyses' => fn ($query) => $query->latest('created_at')->limit(12),
            'latestImprovementResult.deltas',
            'improvementResults' => fn ($query) => $query->with('deltas')->latest('created_at')->limit(15),
        ]);
        $lineageRoot->loadMissing([
            'translations.content.publishTargets',
            'translations.brief',
        ]);

        $showDraftLinkSuggestions = (bool) config('features.draft_link_suggestions', false);
        $linkSuggestions = collect();
        $debugSuggestions = collect();
        $debugPool = [];
        $activeTab = trim((string) request()->query('tab', 'draft'));

        if (! in_array($activeTab, ['draft', 'intelligence', 'improve', 'history'], true)) {
            $activeTab = 'draft';
        }

        if ($showDraftLinkSuggestions) {
            $linkSuggestions = $draft->outboundLinkSuggestions()
                ->with('targetArticle.clientSite')
                ->orderByRaw("case when status = 'suggested' then 0 when status = 'approved' then 1 else 2 end")
                ->latest()
                ->limit(50)
                ->get();
        }

        if ($showDraftLinkSuggestions && request()->boolean('debug_links')) {
            $debugService = app(DefaultLinkSuggestionService::class);
            $debugSuggestions = $debugService->debugCandidates($draft)->take(50);
            $debugPool = $debugService->debugPoolSummary($draft);
        }

        $latestAnalysis = $draft->analysis;
        $latestImprovementResult = $draft->latestImprovementResult;
        $analysisStatus = $latestAnalysis?->effective_status ?? $latestAnalysis?->status ?? null;
        $canViewDiagnostics = request()->user()?->is_admin || session()->has('impersonating');
        $translationBaseDraft = $lineageRoot;
        $relatedTranslations = $lineageRoot->translations
            ->reject(fn (Draft $translation): bool => (string) $translation->id === (string) $draft->id)
            ->sortBy(fn (Draft $translation): string => $translation->language->value)
            ->values();
        $translationUnavailableReason = null;
        try {
            $translationService->validateSourceDraft($translationBaseDraft);
            $translationSourceIsReady = true;
        } catch (\RuntimeException $exception) {
            $translationSourceIsReady = false;
            $translationUnavailableReason = $exception->getMessage();
        }
        $translationTargets = $translationSourceIsReady ? collect($translationService->canTranslateToLanguages($translationBaseDraft))
            ->map(fn ($language) => [
                'value' => $language->value,
                'label' => $language->englishLabel(),
                'native_label' => $language->label(),
            ])
            ->values() : collect();
        $translationCreditCost = $translationService->estimateTranslationCredits($translationBaseDraft);
        $currentPublishTarget = $draft->content?->publishTargetForLanguage($draft->language);
        $smartSuggestionsRun = $this->resolveSelectedSmartSuggestionsRun(request(), $draft);
        $internalLinkingRun = $this->resolveSelectedInternalLinkingRun(request(), $draft);
        $localizationRun = $this->resolveSelectedLocalizationRun(request(), $draft);

        return view('app.drafts.show', [
            'draft' => $draft,
            'seoSyncCapability' => $seoFieldSyncCapabilityResolver->forSite($draft->clientSite, true),
            'showDraftLinkSuggestions' => $showDraftLinkSuggestions,
            'linkSuggestions' => $linkSuggestions,
            'debugSuggestions' => $debugSuggestions,
            'debugPool' => $debugPool,
            'activeTab' => $activeTab,
            'draftIntelligenceSections' => $this->draftIntelligenceSections($draft),
            'draftImprovementHistory' => $recommendationPresenter->recentImprovements($draft->improvementResults ?? collect()),
            'draftImprovementActions' => DraftImprovementAction::options(),
            'draftImprovementState' => is_array(data_get($draft->meta, 'draft_intelligence.latest_improvement'))
                ? data_get($draft->meta, 'draft_intelligence.latest_improvement')
                : null,
            'analysisStatus' => $analysisStatus,
            'canViewDiagnostics' => $canViewDiagnostics,
            'topPriorities' => $recommendationPresenter->topPrioritiesForAnalysis($latestAnalysis),
            'latestImprovementDeltaMap' => $recommendationPresenter->deltaMapForImprovement($latestImprovementResult),
            'latestImprovementResult' => $latestImprovementResult,
            'translationTargets' => $translationTargets,
            'translationSourceIsReady' => $translationSourceIsReady,
            'translationUnavailableReason' => $translationUnavailableReason,
            'translationCreditCost' => $translationCreditCost,
            'translationBaseDraft' => $translationBaseDraft,
            'translationLineageRoot' => $lineageRoot,
            'relatedTranslations' => $relatedTranslations,
            'availableLanguageGroups' => $this->buildAvailableLanguageGroups($draft, $lineageRoot),
            'currentPublishTarget' => $currentPublishTarget,
            'contentLanguageVersions' => $draft->content
                ? $wordPressLanguageSyncService->getAllLanguageVersions($draft->content)
                : [],
            'smartSuggestionsRun' => $smartSuggestionsRun,
            'internalLinkingRun' => $internalLinkingRun,
            'localizationRun' => $localizationRun,
        ]);
    }

    public function runSmartSuggestions(
        Request $request,
        Draft $draft,
        RunAgentForDraft $runAgentForDraft,
    ): RedirectResponse {
        $this->ensureDraftOrganizationAccess($draft);
        $this->authorize('runAgent', $draft);

        $run = $runAgentForDraft->execute($draft, $request->user());

        return redirect()
            ->route('app.drafts.show', [
                'draft' => $draft,
                'tab' => 'intelligence',
                'smart_suggestions_run' => $run->id,
            ])
            ->with('status', 'Smart suggestions updated.');
    }

    public function markReadyForReview(Request $request, Draft $draft): RedirectResponse
    {
        return $this->transitionGovernance($request, $draft, 'ready_for_review');
    }

    public function requestChanges(Request $request, Draft $draft): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->transitionGovernance($request, $draft, 'request_changes', (string) ($data['note'] ?? ''));
    }

    public function approveForPublishing(Request $request, Draft $draft): RedirectResponse
    {
        return $this->transitionGovernance($request, $draft, 'approve_for_publishing');
    }

    public function archiveGovernance(Request $request, Draft $draft): RedirectResponse
    {
        return $this->transitionGovernance($request, $draft, 'archive_governance');
    }

    public function runInternalLinking(
        Request $request,
        Draft $draft,
        RunInternalLinkingForDraft $runInternalLinkingForDraft,
    ): RedirectResponse {
        $this->ensureDraftOrganizationAccess($draft);
        $this->authorize('runAgent', $draft);

        $run = $runInternalLinkingForDraft->execute($draft, $request->user());

        return redirect()
            ->route('app.drafts.show', [
                'draft' => $draft,
                'tab' => (string) $request->input('tab', 'draft'),
                'internal_linking_run' => $run->id,
            ])
            ->with('status', 'Suggested internal links updated.');
    }

    public function runLocalization(
        Request $request,
        Draft $draft,
        RunLocalizationForDraft $runLocalizationForDraft,
    ): RedirectResponse {
        $this->ensureDraftOrganizationAccess($draft);
        $this->authorize('runAgent', $draft);

        $run = $runLocalizationForDraft->execute($draft, $request->user());

        return redirect()
            ->route('app.drafts.show', [
                'draft' => $draft,
                'tab' => (string) $request->input('tab', 'intelligence'),
                'localization_run' => $run->id,
            ])
            ->with('status', 'Localization recommendations updated.');
    }

    public function applyInternalLinkSuggestion(
        Request $request,
        Draft $draft,
        ApplyInternalLinkSuggestion $applyInternalLinkSuggestion,
    ): RedirectResponse {
        $this->ensureDraftOrganizationAccess($draft);
        $this->authorize('update', $draft);

        $data = $request->validate([
            'agent_run_id' => ['required', 'uuid'],
            'suggestion_index' => ['required', 'integer', 'min:0'],
            'tab' => ['nullable', 'string'],
        ]);

        try {
            $applyInternalLinkSuggestion->toDraft(
                $draft,
                (string) $data['agent_run_id'],
                (int) $data['suggestion_index'],
                $request->user(),
            );
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['internal_linking' => $exception->getMessage()]);
        }

        return redirect()
            ->route('app.drafts.show', [
                'draft' => $draft,
                'tab' => (string) ($data['tab'] ?? 'draft'),
                'internal_linking_run' => (string) $data['agent_run_id'],
            ])
            ->with('status', 'Internal link suggestion applied to the draft.');
    }

    public function analyze(Draft $draft): RedirectResponse
    {
        $this->ensureDraftOrganizationAccess($draft);

        AnalyzeDraftJob::dispatch((string) $draft->id, true, (string) request()->user()->id, (string) Str::uuid())
            ->onQueue((string) config('draft_intelligence.queue', 'ai-low'))
            ->afterCommit();

        return redirect()
            ->route('app.drafts.show', ['draft' => $draft, 'tab' => 'intelligence'])
            ->with('status', 'Draft intelligence queued.');
    }

    public function improve(
        ImproveDraftRequest $request,
        Draft $draft,
        DraftImprovementHistoryBuilder $historyBuilder,
    ): RedirectResponse
    {
        $this->ensureDraftOrganizationAccess($draft);

        $action = DraftImprovementAction::from($request->validated('action'));
        $meta = is_array($draft->meta) ? $draft->meta : [];
        data_set($meta, 'draft_intelligence.latest_improvement', [
            'action' => $action->value,
            'label' => $action->label(),
            'status' => 'queued',
            'queued_at' => now()->toIso8601String(),
            'operation_key' => (string) Str::uuid(),
            'requested_by_user_id' => (string) $request->user()->id,
            'error' => null,
            'change_summary' => null,
            'change_notes' => [],
        ]);
        $draft->forceFill([
            'meta' => $meta,
            'last_error' => null,
        ])->save();
        $historyBuilder->queue($draft->fresh(['analysis']), $action, (string) $request->user()->id, (string) data_get($meta, 'draft_intelligence.latest_improvement.operation_key'));

        ImproveDraftSectionJob::dispatch(
            (string) $draft->id,
            $action->value,
            (string) $request->user()->id,
            (string) data_get($meta, 'draft_intelligence.latest_improvement.operation_key'),
        )->onQueue((string) config('draft_intelligence.queue', 'ai-low'))
            ->afterCommit();

        return redirect()
            ->route('app.drafts.show', ['draft' => $draft, 'tab' => 'improve'])
            ->with('status', $action->queuedMessage());
    }

    public function humanize(
        Draft $draft,
        HumanContentScoreService $humanContentScore,
        HumanizationService $humanization,
        HumanContentGate $humanContentGate,
    ): RedirectResponse {
        $this->ensureDraftOrganizationAccess($draft);

        $draft->loadMissing('brief', 'content.brandVoice', 'content.writerProfile');
        $html = (string) $draft->content_html;

        if (trim(strip_tags($html)) === '') {
            return redirect()
                ->route('app.drafts.show', ['draft' => $draft, 'tab' => 'intelligence'])
                ->withErrors(['humanization' => 'Draft content is empty, so Humanization cannot run yet.']);
        }

        $beforeScore = $humanContentScore->scoreForDraft($draft);
        $humanized = [
            'version' => HumanizationService::VERSION,
            'changed' => false,
            'improved_html' => $html,
            'change_summary' => 'Humanization skipped because the draft already passed the human content threshold.',
            'before_after_notes' => [],
            'preserved_validation' => ['passed' => true],
            'status' => 'skipped',
        ];

        if ($humanization->shouldHumanize($beforeScore)) {
            try {
                $humanized = $humanization->humanize(
                    html: $html,
                    humanFindings: (array) data_get($beforeScore, 'findings', []),
                    aiFingerprintFindings: (array) data_get($beforeScore, 'ai_fingerprint.findings', []),
                    editorialPlan: (array) data_get($draft->meta, 'editorial_plan', []),
                    brief: $draft->brief,
                    brandVoice: $this->brandVoicePayload($draft),
                    writerProfile: $this->writerProfilePayload($draft),
                    corpusDiversityFindings: (array) data_get($beforeScore, 'corpus_diversity.findings', []),
                );
            } catch (Throwable $exception) {
                $humanized = [
                    'version' => HumanizationService::VERSION,
                    'changed' => false,
                    'improved_html' => $html,
                    'change_summary' => 'Humanization failed; original draft content was preserved.',
                    'before_after_notes' => [$exception->getMessage()],
                    'preserved_validation' => ['passed' => true, 'original_preserved' => true],
                    'status' => 'failed',
                ];
            }
        }

        if ((bool) data_get($humanized, 'changed', false)) {
            $draft->forceFill(['content_html' => (string) data_get($humanized, 'improved_html', $html)])->save();
            $draft->refresh();
        }

        $afterScore = $humanContentScore->scoreForDraft($draft);
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['human_content_score_before'] = (int) data_get($beforeScore, 'human_content_score', 0);
        $meta['human_content_score_after'] = (int) data_get($afterScore, 'human_content_score', 0);
        $meta['ai_fingerprint_score_before'] = (int) data_get($beforeScore, 'ai_fingerprint_score', 0);
        $meta['ai_fingerprint_score_after'] = (int) data_get($afterScore, 'ai_fingerprint_score', 0);
        $meta['fingerprint_findings'] = (array) data_get($afterScore, 'ai_fingerprint.findings', data_get($beforeScore, 'ai_fingerprint.findings', []));
        $meta['corpus_diversity_findings'] = (array) data_get($afterScore, 'corpus_diversity.findings', data_get($beforeScore, 'corpus_diversity.findings', []));
        $meta['humanization_status'] = (string) data_get($humanized, 'status', ((bool) data_get($humanized, 'changed', false) ? 'applied' : 'not_changed'));
        $meta['humanization_changes'] = [
            'version' => HumanizationService::VERSION,
            'change_summary' => (string) data_get($humanized, 'change_summary', ''),
            'before_after_notes' => (array) data_get($humanized, 'before_after_notes', []),
            'preserved_validation' => (array) data_get($humanized, 'preserved_validation', []),
            'score_delta' => [
                'human_content_score' => (int) data_get($afterScore, 'human_content_score', 0) - (int) data_get($beforeScore, 'human_content_score', 0),
                'ai_fingerprint_score' => (int) data_get($afterScore, 'ai_fingerprint_score', 0) - (int) data_get($beforeScore, 'ai_fingerprint_score', 0),
            ],
        ];
        data_set($meta, 'human_content.before', $this->humanContentScoreSummary($beforeScore));
        data_set($meta, 'human_content.after', $this->humanContentScoreSummary($afterScore));
        data_set($meta, 'humanization', array_merge($meta['humanization_changes'], [
            'status' => $meta['humanization_status'],
        ]));

        $gate = $humanContentGate->evaluateMetadata($meta, $draft, $draft->content);
        if ($meta['humanization_status'] === 'failed') {
            $gate['passed'] = false;
            $gate['status'] = HumanContentGate::STATUS_NEEDS_EDITORIAL_REVIEW;
            $gate['reasons'] = array_values(array_unique(array_merge(
                (array) data_get($gate, 'reasons', []),
                ['Humanization failed; editorial review is required before auto-publication.']
            )));
        }
        $meta['human_content_gate'] = $gate;
        $meta['publish_gate_status'] = (string) data_get($gate, 'status', HumanContentGate::STATUS_NEEDS_EDITORIAL_REVIEW);
        data_set($meta, 'humanization.publish_gate_status', $meta['publish_gate_status']);

        $draft->forceFill(['meta' => $meta])->save();

        AnalyzeDraftJob::dispatch((string) $draft->id, true, (string) request()->user()->id, (string) Str::uuid())
            ->onQueue((string) config('draft_intelligence.queue', 'ai-low'))
            ->afterCommit();

        return redirect()
            ->route('app.drafts.show', ['draft' => $draft, 'tab' => 'intelligence'])
            ->with('status', 'Humanization completed and Human Content re-score queued.');
    }

    public function republish(Draft $draft): RedirectResponse
    {
        $this->ensureDraftOrganizationAccess($draft);

        if (ClientSite::normalizeType((string) ($draft->clientSite?->type ?? '')) !== ClientSite::TYPE_WORDPRESS) {
            abort(403, 'WordPress-only action is not allowed for this site type.');
        }

        try {
            app(WorkspaceEntitlementsService::class)->assertCanPushToWp($draft->clientSite->workspace);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['sites' => $exception->getMessage()]);
        }

        $draft->update([
            'status' => 'ready_to_deliver',
            'delivery_status' => 'pending',
            'delivery_last_error' => null,
        ]);

        // Force delivery for explicit user-initiated republish (bypass checksum skip)
        DeliverDraftJob::dispatch((string) $draft->id, forceDelivery: true)
            ->onQueue((string) config('argusly.webhooks.queue', 'deliveries'));

        return back()->with('status', 'Draft queued for WordPress republish.');
    }

    public function translate(
        TranslateDraftRequest $request,
        Draft $draft,
        TranslationService $translationService,
    ): RedirectResponse {
        $this->authorize('translate', $draft);

        $draft->loadMissing('clientSite.workspace', 'content');
        $translationBaseDraft = $draft->getOriginalSourceDraft() ?? $draft;

        try {
            $translationService->validateSourceDraft($translationBaseDraft);

            foreach ($request->validated('target_languages') as $languageCode) {
                $translationService->validateTargetLanguage(
                    $translationBaseDraft,
                    \App\Enums\SupportedLanguage::fromStringOrDefault($languageCode)
                );
            }
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['translation' => $exception->getMessage()]);
        }

        BulkTranslateDraftJob::dispatch(
            sourceDraftId: (string) $translationBaseDraft->id,
            targetLanguages: $request->validated('target_languages'),
            userId: (string) $request->user()->id,
            modelOverride: $request->validated('model'),
        )->afterCommit();

        $count = count($request->validated('target_languages'));

        return back()->with('status', $count === 1
            ? 'Translation queued.'
            : "Queued {$count} translations.");
    }

    public function restoreImageVersion(Draft $draft, ContentImage $imageVersion): RedirectResponse
    {
        $this->ensureDraftOrganizationAccess($draft);

        if (! $draft->content_id) {
            return back()->withErrors(['image_restore' => 'This draft has no linked content image history.']);
        }

        if ((string) $imageVersion->content_id !== (string) $draft->content_id) {
            abort(403, 'Image version does not belong to this draft content.');
        }

        if ((string) $imageVersion->status !== 'ready') {
            return back()->withErrors(['image_restore' => 'Only ready image versions can be restored.']);
        }

        DB::transaction(function () use ($draft, $imageVersion): void {
            ContentImage::query()
                ->where('content_id', $draft->content_id)
                ->where('type', $imageVersion->type)
                ->update(['is_active' => false]);

            $imageVersion->forceFill(['is_active' => true])->save();
        });

        Artisan::call('optimize:clear');

        return back()->with('status', ucfirst((string) $imageVersion->type).' image version restored.');
    }

    private function ensureDraftOrganizationAccess(Draft $draft): void
    {
        $organization = request()->user()->organization;

        if ($draft->clientSite?->workspace?->organization_id !== $organization->id) {
            abort(404);
        }
    }

    private function transitionGovernance(Request $request, Draft $draft, string $action, ?string $note = null): RedirectResponse
    {
        $this->ensureDraftOrganizationAccess($draft);
        $this->authorize('update', $draft);

        try {
            match ($action) {
                'ready_for_review' => $draft->markReadyForReview($request->user()),
                'request_changes' => $draft->requestChanges($request->user(), $note),
                'approve_for_publishing' => $draft->approveForPublishing($request->user()),
                'archive_governance' => $draft->archiveGovernance($request->user()),
                default => throw new \InvalidArgumentException('Unsupported draft governance action.'),
            };
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['governance' => $exception->getMessage()]);
        }

        $messages = [
            'ready_for_review' => 'Draft marked as ready for review.',
            'request_changes' => 'Draft changes requested.',
            'approve_for_publishing' => 'Draft approved for publishing. No publication has been started.',
            'archive_governance' => 'Draft governance archived.',
        ];

        return redirect()
            ->route('app.drafts.show', $draft)
            ->with('status', $messages[$action] ?? 'Draft governance updated.');
    }

    private function buildAvailableLanguageGroups(Draft $currentDraft, Draft $lineageRoot): \Illuminate\Support\Collection
    {
        $lineageRoot->loadMissing([
            'content.publishTargets',
            'translations.content.publishTargets',
        ]);
        $currentDraft->loadMissing('content.publishTargets');

        $records = collect([$lineageRoot])
            ->merge($lineageRoot->translations ?? collect())
            ->push($currentDraft)
            ->filter(fn (?Draft $draft): bool => $draft instanceof Draft)
            ->unique(fn (Draft $draft): string => (string) $draft->id)
            ->values();

        $pendingStatuses = ['pending', 'queued'];
        $failedStatuses = ['failed'];

        return $records
            ->groupBy(fn (Draft $draft): string => $draft->content?->localeCode() ?: $draft->language->value)
            ->map(function (\Illuminate\Support\Collection $drafts, string $locale) use ($currentDraft, $lineageRoot, $pendingStatuses, $failedStatuses): array {
                $pendingJobs = $drafts
                    ->filter(fn (Draft $draft): bool => in_array((string) $draft->status, $pendingStatuses, true))
                    ->sortByDesc(fn (Draft $draft): int => (int) ($draft->created_at?->timestamp ?? 0))
                    ->values();

                $failedJobs = $drafts
                    ->filter(fn (Draft $draft): bool => in_array((string) $draft->status, $failedStatuses, true))
                    ->sortByDesc(fn (Draft $draft): int => (int) ($draft->created_at?->timestamp ?? 0))
                    ->values();

                $currentVersion = $drafts
                    ->reject(fn (Draft $draft): bool => in_array((string) $draft->status, [...$pendingStatuses, ...$failedStatuses], true))
                    ->sortBy(function (Draft $draft) use ($currentDraft, $lineageRoot): array {
                        return [
                            (string) $draft->id === (string) $currentDraft->id ? 0 : 1,
                            (string) $draft->id === (string) $lineageRoot->id ? 0 : 1,
                            -1 * (int) ($draft->updated_at?->timestamp ?? 0),
                        ];
                    })
                    ->first();

                $sourceDraft = $drafts->first(function (Draft $draft) use ($lineageRoot): bool {
                    return (string) $draft->id === (string) $lineageRoot->id
                        || (bool) ($draft->content?->is_source_locale ?? false);
                });

                $displayDraft = $currentVersion ?? $sourceDraft ?? $pendingJobs->first() ?? $failedJobs->first() ?? $drafts->first();
                $pendingTooltip = $pendingJobs
                    ->map(fn (Draft $draft): string => sprintf(
                        '%s · %s · %s',
                        (string) $draft->id,
                        (string) $draft->status,
                        $draft->created_at?->format('Y-m-d H:i') ?? 'unknown time'
                    ))
                    ->implode("\n");

                return [
                    'locale' => (string) $locale,
                    'label' => strtoupper((string) $locale),
                    'current_version' => $currentVersion,
                    'display_draft' => $displayDraft,
                    'source_draft' => $sourceDraft,
                    'is_source_locale' => $sourceDraft !== null,
                    'pending_jobs' => $pendingJobs,
                    'pending_count' => $pendingJobs->count(),
                    'pending_tooltip' => $pendingTooltip,
                    'failed_jobs' => $failedJobs,
                    'failed_count' => $failedJobs->count(),
                ];
            })
            ->sortBy(fn (array $group): array => [
                (string) data_get($group, 'source_draft.id') === (string) $lineageRoot->id ? 0 : 1,
                (string) $group['locale'],
            ])
            ->values();
    }

    private function resolveSelectedSmartSuggestionsRun(Request $request, Draft $draft): ?AgentRun
    {
        $selectedRunId = trim((string) $request->query('smart_suggestions_run', ''));
        $baseQuery = AgentRun::query()
            ->where('agent_key', DraftSmartSuggestionsAgent::KEY)
            ->where('draft_id', (string) $draft->id)
            ->where('trigger_type', 'manual');

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

    private function resolveSelectedInternalLinkingRun(Request $request, Draft $draft): ?AgentRun
    {
        $selectedRunId = trim((string) $request->query('internal_linking_run', ''));
        $baseQuery = AgentRun::query()
            ->where('agent_key', InternalLinkingAgent::KEY)
            ->where('draft_id', (string) $draft->id)
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

    private function resolveSelectedLocalizationRun(Request $request, Draft $draft): ?AgentRun
    {
        $selectedRunId = trim((string) $request->query('localization_run', ''));
        $baseQuery = AgentRun::query()
            ->where('agent_key', LocalizationAgent::KEY)
            ->where('draft_id', (string) $draft->id)
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

    /**
     * @return array<int,array<string,mixed>>
     */
    private function draftIntelligenceSections(Draft $draft): array
    {
        $analysis = $draft->analysis;
        $payload = $analysis?->canonicalPayload();
        $sections = is_array(data_get($payload, 'sections'))
            ? data_get($payload, 'sections')
            : [];
        $humanContent = is_array(data_get($payload, 'human_content'))
            ? data_get($payload, 'human_content')
            : [];

        return [
            [
                'key' => 'human_content',
                'label' => 'Human Content',
                'score' => data_get($sections, 'human_content.score') ?? data_get($humanContent, 'human_content_score'),
                'explanation' => data_get($sections, 'human_content.explanation'),
                'suggestions' => (array) data_get($sections, 'human_content.improvements', data_get($humanContent, 'recommendations', [])),
                'status_label' => data_get($sections, 'human_content.status_label', data_get($humanContent, 'status')),
                'findings' => (array) data_get($sections, 'human_content.findings', data_get($humanContent, 'findings', [])),
                'suggested_humanization_actions' => (array) data_get($sections, 'human_content.suggested_humanization_actions', data_get($humanContent, 'suggested_humanization_actions', [])),
                'dimension_breakdown' => (array) data_get($sections, 'human_content.dimension_breakdown', data_get($humanContent, 'dimension_breakdown', [])),
            ],
            [
                'key' => 'seo',
                'label' => 'SEO',
                'score' => $analysis?->seo_score ?? data_get($sections, 'seo.score'),
                'explanation' => data_get($sections, 'seo.explanation'),
                'suggestions' => (array) data_get($sections, 'seo.improvements', []),
            ],
            [
                'key' => 'readability',
                'label' => 'Readability',
                'score' => $analysis?->readability_score ?? data_get($sections, 'readability.score'),
                'explanation' => data_get($sections, 'readability.explanation'),
                'suggestions' => (array) data_get($sections, 'readability.improvements', []),
            ],
            [
                'key' => 'cta',
                'label' => 'CTA',
                'score' => $analysis?->cta_score ?? data_get($sections, 'cta.score'),
                'explanation' => data_get($sections, 'cta.explanation'),
                'suggestions' => (array) data_get($sections, 'cta.improvements', []),
            ],
            [
                'key' => 'headings',
                'label' => 'Headings',
                'score' => $analysis?->headings_score ?? data_get($sections, 'structure.score'),
                'explanation' => data_get($sections, 'structure.explanation'),
                'suggestions' => (array) data_get($sections, 'structure.improvements', []),
            ],
            [
                'key' => 'llm_visibility',
                'label' => 'LLM Visibility',
                'score' => $analysis?->llm_visibility_score ?? data_get($sections, 'llm_visibility.score'),
                'explanation' => data_get($sections, 'llm_visibility.explanation'),
                'suggestions' => (array) data_get($sections, 'llm_visibility.improvements', []),
            ],
            [
                'key' => 'brand_voice_fit',
                'label' => 'Brand Voice',
                'score' => $analysis?->brand_voice_fit_score ?? data_get($sections, 'brand_voice_fit.score'),
                'explanation' => data_get($sections, 'brand_voice_fit.explanation'),
                'suggestions' => (array) data_get($sections, 'brand_voice_fit.improvements', []),
            ],
            [
                'key' => 'conversion_fit',
                'label' => 'Conversion Fit',
                'score' => $analysis?->conversion_fit_score ?? data_get($sections, 'conversion_fit.score'),
                'explanation' => data_get($sections, 'conversion_fit.explanation'),
                'suggestions' => (array) data_get($sections, 'conversion_fit.improvements', []),
            ],
            [
                'key' => 'trust_evidence',
                'label' => 'Trust & Evidence',
                'score' => $analysis?->trust_evidence_score ?? data_get($sections, 'trust_evidence.score'),
                'explanation' => data_get($sections, 'trust_evidence.explanation'),
                'suggestions' => (array) data_get($sections, 'trust_evidence.improvements', []),
            ],
            [
                'key' => 'publish_readiness',
                'label' => 'Publish Readiness',
                'score' => $analysis?->publish_readiness_score ?? data_get($sections, 'publish_readiness.score'),
                'explanation' => data_get($sections, 'publish_readiness.explanation'),
                'suggestions' => (array) data_get($sections, 'publish_readiness.improvements', []),
                'status_label' => $analysis?->publish_readiness_status ?? data_get($sections, 'publish_readiness.status_label'),
                'blocking_issues' => (array) ($analysis?->publish_readiness_blocking_issues ?? data_get($sections, 'publish_readiness.blocking_issues', [])),
                'recommended_next_actions' => (array) ($analysis?->publish_readiness_next_actions ?? data_get($sections, 'publish_readiness.recommended_next_actions', [])),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $score
     * @return array<string,mixed>
     */
    private function humanContentScoreSummary(array $score): array
    {
        return [
            'status' => (string) data_get($score, 'status', ''),
            'passed' => (bool) data_get($score, 'passed', false),
            'human_content_score' => (int) data_get($score, 'human_content_score', 0),
            'editorial_quality_score' => (int) data_get($score, 'editorial_quality_score', 0),
            'originality_score' => (int) data_get($score, 'originality_score', 0),
            'narrative_flow_score' => (int) data_get($score, 'narrative_flow_score', 0),
            'human_voice_score' => (int) data_get($score, 'human_voice_score', 0),
            'expertise_score' => (int) data_get($score, 'expertise_score', 0),
            'rhythm_score' => (int) data_get($score, 'rhythm_score', 0),
            'curiosity_score' => (int) data_get($score, 'curiosity_score', 0),
            'uniqueness_score' => (int) data_get($score, 'uniqueness_score', 0),
            'ai_fingerprint_score' => (int) data_get($score, 'ai_fingerprint_score', 0),
            'ai_fingerprint_severity' => (string) data_get($score, 'ai_fingerprint.severity', ''),
            'corpus_diversity_score' => (int) data_get($score, 'corpus_diversity.score', 100),
            'corpus_diversity_risk_score' => (int) data_get($score, 'corpus_diversity.risk_score', 0),
            'corpus_diversity_status' => (string) data_get($score, 'corpus_diversity.status', ''),
            'corpus_diversity_findings' => (array) data_get($score, 'corpus_diversity.findings', []),
            'finding_count' => count((array) data_get($score, 'findings', [])),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function brandVoicePayload(Draft $draft): array
    {
        $brandVoice = $draft->content?->brandVoice;

        return [
            'tone' => (string) ($brandVoice?->tone_of_voice ?? $brandVoice?->default_tone ?? ''),
            'style' => (string) ($brandVoice?->writing_style ?? $brandVoice?->style_guide ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function writerProfilePayload(Draft $draft): array
    {
        $writerProfile = $draft->content?->writerProfile;

        return [
            'summary' => (string) ($writerProfile?->tone_summary ?? $writerProfile?->writing_style_summary ?? ''),
            'structure' => (string) ($writerProfile?->structure_summary ?? ''),
        ];
    }
}
