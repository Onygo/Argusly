@extends('layouts.app', ['title' => 'Draft detail'])

@section('content')
    @php
        $latestAnalysis = $draft->analysis;
        $analysisPayload = $latestAnalysis?->canonicalPayload() ?? [];
        $analysisSections = collect($draftIntelligenceSections ?? []);
        $analysisHistory = $draft->analyses ?? collect();
        $analysisSummary = is_array(data_get($analysisPayload, 'summary')) ? data_get($analysisPayload, 'summary') : [];
        $analysisTopImprovements = collect((array) data_get($analysisPayload, 'top_improvements', []))->filter()->values();
        $internalLinkOpportunities = collect((array) ($latestAnalysis?->internal_link_opportunities ?? []))->filter()->values();
        $internalLinkSummary = trim((string) data_get($analysisPayload, 'internal_link_summary', ''));
        $topPriorities = collect($topPriorities ?? [])->values();
        $improvementActions = collect($draftImprovementActions ?? []);
        $primaryImprovementAction = $improvementActions->firstWhere('primary', true);
        $secondaryImprovementActions = $improvementActions->reject(fn (array $action): bool => (bool) ($action['primary'] ?? false))->values();
        $latestImprovement = is_array($draftImprovementState ?? null) ? $draftImprovementState : [];
        $latestImprovementResult = $latestImprovementResult ?? null;
        $latestImprovementDeltaMap = is_array($latestImprovementDeltaMap ?? null) ? $latestImprovementDeltaMap : [];
        $latestImprovementStatus = trim((string) data_get($latestImprovement, 'status', ''));
        $latestImprovementLabel = trim((string) data_get($latestImprovement, 'label', 'Draft improvement'));
        $latestImprovementError = trim((string) data_get($latestImprovement, 'error', ''));
        $latestImprovementNotes = collect((array) data_get($latestImprovement, 'change_notes', []))
            ->filter(fn (mixed $note): bool => trim((string) $note) !== '')
            ->values();
        $publishReadinessSection = $analysisSections->firstWhere('key', 'publish_readiness');
        $humanContentPayload = is_array(data_get($analysisPayload, 'human_content')) ? data_get($analysisPayload, 'human_content') : [];
        $humanContentSection = $analysisSections->firstWhere('key', 'human_content');
        $humanContentFindings = collect((array) data_get($humanContentPayload, 'findings', data_get($humanContentSection, 'findings', [])))
            ->filter()
            ->take(5)
            ->values();
        $humanContentActions = collect((array) data_get($humanContentPayload, 'suggested_humanization_actions', data_get($humanContentSection, 'suggested_humanization_actions', [])))
            ->filter()
            ->take(5)
            ->values();
        $humanContentGate = is_array(data_get($draft->meta, 'human_content_gate'))
            ? data_get($draft->meta, 'human_content_gate')
            : (is_array(data_get($analysisPayload, 'human_content_gate')) ? data_get($analysisPayload, 'human_content_gate') : []);
        $humanContentGateReasons = collect((array) data_get($humanContentGate, 'reasons', []))->filter()->values();
        $humanizationMeta = is_array(data_get($draft->meta, 'humanization')) ? data_get($draft->meta, 'humanization') : [];
        $humanizationNotes = collect((array) data_get($humanizationMeta, 'before_after_notes', []))->filter()->take(5)->values();
        $humanContentAfter = is_array(data_get($draft->meta, 'human_content.after')) ? data_get($draft->meta, 'human_content.after') : [];
        $humanContentBefore = is_array(data_get($draft->meta, 'human_content.before')) ? data_get($draft->meta, 'human_content.before') : [];
        $humanContentDimensions = is_array(data_get($humanContentPayload, 'dimension_breakdown')) ? data_get($humanContentPayload, 'dimension_breakdown') : [];
        $humanContentScoreValue = data_get($humanContentPayload, 'human_content_score', data_get($humanContentSection, 'score', data_get($humanContentAfter, 'human_content_score')));
        $humanContentBeforeScoreValue = data_get($humanContentBefore, 'human_content_score');
        $humanContentMetricDefinitions = [
            ['key' => 'human_content_score', 'label' => 'Human Content Score', 'score' => $humanContentScoreValue, 'before' => $humanContentBeforeScoreValue, 'status' => data_get($humanContentPayload, 'status', data_get($humanContentAfter, 'status')), 'direction' => 'higher_is_better'],
            ['key' => 'editorial_quality_score', 'label' => 'Editorial Quality', 'score' => data_get($humanContentPayload, 'editorial_quality_score', data_get($humanContentAfter, 'editorial_quality_score')), 'before' => data_get($humanContentBefore, 'editorial_quality_score'), 'status' => data_get($humanContentDimensions, 'editorial_quality_score.band'), 'direction' => 'higher_is_better'],
            ['key' => 'originality_score', 'label' => 'Originality', 'score' => data_get($humanContentPayload, 'originality_score', data_get($humanContentAfter, 'originality_score')), 'before' => data_get($humanContentBefore, 'originality_score'), 'status' => data_get($humanContentDimensions, 'originality_score.band'), 'direction' => 'higher_is_better'],
            ['key' => 'ai_fingerprint_score', 'label' => 'AI Fingerprint', 'score' => data_get($humanContentPayload, 'ai_fingerprint_score', data_get($humanContentAfter, 'ai_fingerprint_score')), 'before' => data_get($humanContentBefore, 'ai_fingerprint_score'), 'status' => data_get($humanContentDimensions, 'ai_fingerprint_score.band', data_get($humanContentPayload, 'ai_fingerprint.severity')), 'direction' => 'lower_is_better'],
            ['key' => 'narrative_flow_score', 'label' => 'Narrative Flow', 'score' => data_get($humanContentPayload, 'narrative_flow_score'), 'before' => data_get($humanContentBefore, 'narrative_flow_score'), 'status' => data_get($humanContentDimensions, 'narrative_flow_score.band'), 'direction' => 'higher_is_better'],
            ['key' => 'human_voice_score', 'label' => 'Human Voice', 'score' => data_get($humanContentPayload, 'human_voice_score'), 'before' => data_get($humanContentBefore, 'human_voice_score'), 'status' => data_get($humanContentDimensions, 'human_voice_score.band'), 'direction' => 'higher_is_better'],
            ['key' => 'expertise_score', 'label' => 'Expertise', 'score' => data_get($humanContentPayload, 'expertise_score'), 'before' => data_get($humanContentBefore, 'expertise_score'), 'status' => data_get($humanContentDimensions, 'expertise_score.band'), 'direction' => 'higher_is_better'],
            ['key' => 'rhythm_score', 'label' => 'Rhythm', 'score' => data_get($humanContentPayload, 'rhythm_score'), 'before' => data_get($humanContentBefore, 'rhythm_score'), 'status' => data_get($humanContentDimensions, 'rhythm_score.band'), 'direction' => 'higher_is_better'],
            ['key' => 'curiosity_score', 'label' => 'Curiosity', 'score' => data_get($humanContentPayload, 'curiosity_score'), 'before' => data_get($humanContentBefore, 'curiosity_score'), 'status' => data_get($humanContentDimensions, 'curiosity_score.band'), 'direction' => 'higher_is_better'],
            ['key' => 'publish_gate_status', 'label' => 'Publish Gate Status', 'score' => null, 'before' => null, 'status' => data_get($humanContentGate, 'status', data_get($draft->meta, 'publish_gate_status')), 'direction' => 'status'],
        ];
        $humanContentMetricCards = collect($humanContentMetricDefinitions)
            ->map(function (array $metric): array {
                $score = $metric['score'];
                $before = $metric['before'];
                $metric['score'] = is_numeric($score) ? (int) round((float) $score) : null;
                $metric['before'] = is_numeric($before) ? (int) round((float) $before) : null;
                $metric['status'] = trim((string) ($metric['status'] ?? ''));

                return $metric;
            })
            ->values();
        $hasHumanContentMetrics = $humanContentMetricCards->contains(fn (array $metric): bool => $metric['score'] !== null || $metric['status'] !== '');
        $draftLocaleLabel = strtoupper((string) $draft->language->value);
        $sourceDraftLocaleLabel = $draft->sourceDraft ? strtoupper((string) $draft->sourceDraft->language->value) : null;
        $sourceContext = is_array(data_get($draft->meta, 'source_context')) ? data_get($draft->meta, 'source_context') : [];
        $isOpportunityExecutionDraft = trim((string) data_get($sourceContext, 'execution_plan_id', data_get($sourceContext, 'opportunity_execution_plan_id', ''))) !== '';
        $governance = is_array(data_get($draft->meta, 'governance')) ? data_get($draft->meta, 'governance') : [];
        $governanceStatusLabel = \Illuminate\Support\Str::headline(str_replace('_', ' ', (string) $draft->status));
        $executionPlanId = trim((string) (data_get($sourceContext, 'execution_plan_id') ?: data_get($sourceContext, 'opportunity_execution_plan_id', '')));
        $opportunityId = trim((string) data_get($sourceContext, 'opportunity_id', ''));
        $signalDetectionIds = collect((array) data_get($sourceContext, 'signal_detection_ids', []))->filter()->values();
        $draftContent = $draft->content;
        $draftSiteType = \App\Models\ClientSite::normalizeType((string) ($draft->clientSite?->type ?? ''));
        $supportsImmediatePublish = $draftContent && in_array($draftSiteType, [
            \App\Models\ClientSite::TYPE_WORDPRESS,
            \App\Models\ClientSite::TYPE_LARAVEL,
        ], true);
        $statusPresenter = $draftContent ? \App\View\Presenters\ContentStatusPresenter::for($draftContent) : null;
        $publishStatus = trim((string) ($draftContent?->publish_status ?: $draftContent?->status ?: $draft->delivery_status ?: $draft->status));
        $publishStatusLabel = $statusPresenter?->deliveryLabel() ?: \Illuminate\Support\Str::headline(str_replace('_', ' ', $publishStatus ?: 'draft'));
        $remotePublishLabel = $statusPresenter?->remotePublishLabel();
        $remotePublishLabel = $remotePublishLabel === 'Unknown' ? null : $remotePublishLabel;
        $publishedUrl = $statusPresenter?->publishedUrl() ?: trim((string) ($draftContent?->published_url ?? ''));
        $lastPublishError = $statusPresenter?->lastErrorMessage() ?: trim((string) ($draftContent?->publish_error ?? ''));
        $isPublishInProgress = in_array($publishStatus, ['publishing', 'queued', 'processing'], true)
            || (bool) ($statusPresenter?->deliveryStatus()->isInProgress() ?? false);
        $isPublished = in_array($publishStatus, ['published', 'delivered'], true)
            || filled($publishedUrl)
            || (bool) ($statusPresenter?->remotePublishStatus()?->isLive() ?? false);
        $publishActionRoute = $draftContent
            ? route($isPublished ? 'app.content.republish' : 'app.content.publish-now', $draftContent)
            : null;
        $publishActionLabel = match (true) {
            $isPublishInProgress => 'Publishing',
            $isPublished => 'Republish',
            in_array($publishStatus, ['failed', 'missing_remote', 'failed_delivered'], true) => 'Retry publish',
            default => 'Publish article',
        };
        $destinationLabel = match ($draftSiteType) {
            \App\Models\ClientSite::TYPE_WORDPRESS => 'WordPress',
            \App\Models\ClientSite::TYPE_LARAVEL => 'Laravel',
            default => 'No publish destination',
        };
        $editorialPlan = is_array(data_get($draft->meta, 'editorial_plan')) ? data_get($draft->meta, 'editorial_plan') : [];
        $editorialSectionIntentions = collect((array) data_get($editorialPlan, 'section_intentions', []))->take(6)->values();
        $editorialEvidencePlan = collect((array) data_get($editorialPlan, 'evidence_plan', []))->take(5)->values();
        $editorialAvoidList = collect((array) data_get($editorialPlan, 'things_to_avoid', []))->take(5)->values();
        $editorialPrimaryPattern = is_array(data_get($editorialPlan, 'primary_pattern')) ? data_get($editorialPlan, 'primary_pattern') : [];
        $editorialSecondaryPattern = is_array(data_get($editorialPlan, 'secondary_pattern')) ? data_get($editorialPlan, 'secondary_pattern') : [];
    @endphp

    <div class="mb-6">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">{{ $draft->title }}</h1>
                <p class="text-textSecondary mt-1">Status: {{ $draft->status }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                    <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1 text-textPrimary">
                        Language: {{ $draftLocaleLabel }}@if ($draft->isTranslation() && $sourceDraftLocaleLabel) (Source: {{ $sourceDraftLocaleLabel }})@endif
                    </span>
                    <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1 text-textPrimary">Type: {{ $draft->draft_type->label() }}</span>
                    @if ($draft->isTranslation() && $draft->sourceDraft)
                        <a href="{{ route('app.drafts.show', $draft->sourceDraft) }}" class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1 text-textPrimary hover:bg-surfaceSubtle">
                            Source draft: {{ $sourceDraftLocaleLabel }}
                        </a>
                    @endif
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                @if ($publishedUrl !== '')
                    <a href="{{ $publishedUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-md border border-border bg-background px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                        <i data-lucide="external-link" class="h-4 w-4" aria-hidden="true"></i>
                        View live
                    </a>
                @endif

                @if ($supportsImmediatePublish && $draftContent)
                    @can('update', $draftContent)
                        <form method="POST" action="{{ $publishActionRoute }}">
                            @csrf
                            <input type="hidden" name="locale" value="{{ $draft->language->value }}">
                            <button
                                class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primaryHover disabled:cursor-not-allowed disabled:opacity-60"
                                @disabled($isPublishInProgress)
                            >
                                <i data-lucide="{{ $isPublished ? 'refresh-cw' : 'send' }}" class="h-4 w-4" aria-hidden="true"></i>
                                {{ $publishActionLabel }}
                            </button>
                        </form>
                    @endcan
                @endif
            </div>
        </div>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->has('action'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">
            {{ $errors->first('action') }}
        </div>
    @endif

    @if ($errors->has('governance'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">
            {{ $errors->first('governance') }}
        </div>
    @endif

    @if ($errors->has('translation'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">
            {{ $errors->first('translation') }}
        </div>
    @endif

    @if ($errors->has('publish'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">
            {{ $errors->first('publish') }}
        </div>
    @endif

    <div class="mb-6">
        @include('app.growth-programs._connection', [
            'subject' => $draft,
            'workspaceId' => $draft->clientSite?->workspace_id,
            'createRoute' => route('app.growth-programs.from-draft', $draft),
            'attachRoute' => route('app.growth-programs.attach.draft', $draft),
        ])
    </div>

    @if ($editorialPlan !== [])
        <section class="mb-6 rounded-lg border border-border bg-surface p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Editorial Plan</h2>
                    <p class="mt-1 text-sm text-textSecondary">{{ data_get($editorialPlan, 'central_thesis') }}</p>
                </div>
                <span class="rounded-md border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">
                    {{ data_get($editorialPlan, 'version', 'editorial_plan') }}
                </span>
            </div>
            <div class="mt-4 grid gap-4 lg:grid-cols-3">
                <div>
                    <div class="text-xs font-semibold uppercase text-textSecondary">Goal</div>
                    <p class="mt-1 text-sm text-textPrimary">{{ data_get($editorialPlan, 'editorial_goal') }}</p>
                    @if ($editorialPrimaryPattern !== [])
                        <div class="mt-3 text-xs font-semibold uppercase text-textSecondary">Pattern</div>
                        <p class="mt-1 text-sm font-medium text-textPrimary">
                            {{ data_get($editorialPrimaryPattern, 'name') }}
                            @if ($editorialSecondaryPattern !== [])
                                <span class="font-normal text-textSecondary">+ {{ data_get($editorialSecondaryPattern, 'name') }}</span>
                            @endif
                        </p>
                        <p class="mt-1 text-sm text-textSecondary">{{ data_get($editorialPrimaryPattern, 'article_movement') }}</p>
                    @endif
                    <div class="mt-3 text-xs font-semibold uppercase text-textSecondary">Reader Takeaway</div>
                    <p class="mt-1 text-sm text-textPrimary">{{ data_get($editorialPlan, 'expected_reader_takeaway') }}</p>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase text-textSecondary">Section Intentions</div>
                    <ul class="mt-2 space-y-1 text-sm text-textPrimary">
                        @foreach ($editorialSectionIntentions as $intention)
                            <li>{{ data_get($intention, 'intention') }}: <span class="text-textSecondary">{{ data_get($intention, 'job') }}</span></li>
                        @endforeach
                    </ul>
                </div>
                <div>
                    <div class="text-xs font-semibold uppercase text-textSecondary">Evidence</div>
                    <ul class="mt-2 space-y-1 text-sm text-textPrimary">
                        @foreach ($editorialEvidencePlan as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                    @if ($editorialAvoidList->isNotEmpty())
                        <div class="mt-3 text-xs font-semibold uppercase text-textSecondary">Avoid</div>
                        <ul class="mt-2 space-y-1 text-sm text-textSecondary">
                            @foreach ($editorialAvoidList as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </section>
    @endif

    @if ($activeTab === 'improve' && $latestImprovementStatus !== '')
        <div class="mb-4 rounded-md border px-3 py-2 text-sm
            {{ $latestImprovementStatus === 'failed' ? 'border-rose-500/30 bg-rose-500/10 text-rose-800' : 'border-border bg-surface text-textPrimary' }}">
            <div class="font-medium">
                {{ $latestImprovementLabel }}:
                @if ($latestImprovementStatus === 'queued')
                    Queued
                @elseif ($latestImprovementStatus === 'processing')
                    Processing
                @elseif ($latestImprovementStatus === 'completed')
                    Completed
                @elseif ($latestImprovementStatus === 'failed')
                    Failed
                @else
                    {{ \Illuminate\Support\Str::headline($latestImprovementStatus) }}
                @endif
            </div>

            @if ($latestImprovementError !== '')
                <div class="mt-1 text-xs">{{ $latestImprovementError }}</div>
            @elseif ($latestImprovementStatus === 'completed' && $latestImprovementNotes->isNotEmpty())
                <ul class="mt-2 space-y-1 text-xs text-textSecondary">
                    @foreach ($latestImprovementNotes as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            @elseif ($latestImprovementStatus === 'completed' && data_get($latestImprovement, 'change_summary'))
                <div class="mt-1 text-xs text-textSecondary">{{ data_get($latestImprovement, 'change_summary') }}</div>
            @endif
        </div>
    @endif

    @if ($errors->has('internal_linking'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('internal_linking') }}</div>
    @endif
    @if ($errors->has('localization'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('localization') }}</div>
    @endif

    @php
        $compactDraftChecks = collect([
            [
                'label' => 'Smart suggestions',
                'run' => $smartSuggestionsRun ?? null,
                'action' => route('app.drafts.smart-suggestions.run', $draft),
                'ability' => 'runAgent',
                'button' => 'Run',
            ],
            [
                'label' => 'Localization',
                'run' => $localizationRun ?? null,
                'action' => route('app.drafts.localization.run', $draft),
                'ability' => 'runAgent',
                'button' => 'Check',
            ],
            [
                'label' => 'Internal links',
                'run' => $internalLinkingRun ?? null,
                'action' => route('app.drafts.internal-linking.run', $draft),
                'ability' => 'update',
                'button' => 'Find',
            ],
        ]);
    @endphp

    <div class="mb-4 rounded-lg border border-border bg-surface px-3 py-2">
        <div class="grid gap-2 md:grid-cols-3">
            @foreach ($compactDraftChecks as $check)
                @php
                    $checkRun = $check['run'];
                    $checkStatus = $checkRun?->status instanceof \App\Agents\Support\AgentRunStatus ? $checkRun->status->value : (string) ($checkRun?->status ?? '');
                    $checkCount = collect((array) data_get($checkRun?->output_payload ?? [], 'suggestions', []))->count()
                        + collect((array) data_get($checkRun?->output_payload ?? [], 'actions', []))->count();
                @endphp
                <div class="flex items-center justify-between gap-3 rounded-md border border-border bg-background px-3 py-2">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-medium text-textPrimary">{{ $check['label'] }}</div>
                        <div class="truncate text-xs text-textSecondary">
                            @if ($checkRun)
                                {{ $checkStatus !== '' ? ucfirst($checkStatus) : 'Completed' }}
                                @if ($checkCount > 0)
                                    · {{ $checkCount }} {{ \Illuminate\Support\Str::plural('item', $checkCount) }}
                                @endif
                                · {{ $checkRun->finished_at?->diffForHumans() ?? $checkRun->created_at?->diffForHumans() }}
                            @else
                                Not run yet
                            @endif
                        </div>
                    </div>
                    @can($check['ability'], $draft)
                        <form method="POST" action="{{ $check['action'] }}" class="shrink-0">
                            @csrf
                            <input type="hidden" name="tab" value="{{ $activeTab }}">
                            <button class="rounded-md border border-border px-2.5 py-1.5 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">
                                {{ $check['button'] }}
                            </button>
                        </form>
                    @endcan
                </div>
            @endforeach
        </div>
    </div>

    @if ($isOpportunityExecutionDraft)
        <section class="mb-4 rounded-lg border border-border bg-surface p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">Draft Governance</div>
                    <h2 class="mt-1 text-lg font-semibold text-textPrimary">{{ $governanceStatusLabel }}</h2>
                    <p class="mt-1 text-sm text-textSecondary">
                        @if ((string) $draft->status === \App\Models\Draft::STATUS_APPROVED_FOR_PUBLISHING)
                            Ready for publishing. No delivery or publication has been started.
                        @else
                            Review flow for an Opportunity Execution draft. Publication remains a separate manual step.
                        @endif
                    </p>
                </div>

                @can('update', $draft)
                    <div class="flex flex-wrap items-center gap-2">
                        @if (in_array((string) $draft->status, [\App\Models\Draft::STATUS_DRAFT, \App\Models\Draft::STATUS_CHANGES_REQUESTED], true))
                            <form method="POST" action="{{ route('app.drafts.ready-for-review', $draft) }}">
                                @csrf
                                <button class="inline-flex items-center gap-2 rounded-md border border-border bg-background px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                    <i data-lucide="eye" class="h-4 w-4" aria-hidden="true"></i>
                                    Mark ready for review
                                </button>
                            </form>
                        @endif

                        @if ((string) $draft->status === \App\Models\Draft::STATUS_READY_FOR_REVIEW)
                            <form method="POST" action="{{ route('app.drafts.approve-for-publishing', $draft) }}">
                                @csrf
                                <button class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white hover:bg-primaryHover">
                                    <i data-lucide="check" class="h-4 w-4" aria-hidden="true"></i>
                                    Approve for publishing
                                </button>
                            </form>
                        @endif

                        @if ((string) $draft->status !== \App\Models\Draft::STATUS_ARCHIVED)
                            <form method="POST" action="{{ route('app.drafts.archive-governance', $draft) }}">
                                @csrf
                                <button class="inline-flex items-center gap-2 rounded-md border border-border bg-background px-3 py-2 text-sm font-medium text-textSecondary hover:bg-surfaceSubtle">
                                    <i data-lucide="archive" class="h-4 w-4" aria-hidden="true"></i>
                                    Archive governance
                                </button>
                            </form>
                        @endif
                    </div>
                @endcan
            </div>

            @if ((string) $draft->status === \App\Models\Draft::STATUS_READY_FOR_REVIEW)
                @can('update', $draft)
                    <form method="POST" action="{{ route('app.drafts.request-changes', $draft) }}" class="mt-4 rounded-md border border-border bg-background p-3">
                        @csrf
                        <label for="governance_note" class="text-sm font-medium text-textPrimary">Request changes</label>
                        <div class="mt-2 flex flex-col gap-2 sm:flex-row">
                            <input id="governance_note" name="note" type="text" maxlength="2000" class="min-h-10 flex-1 rounded-md border border-border bg-surface px-3 text-sm text-textPrimary" placeholder="Optional review note">
                            <button class="inline-flex items-center justify-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                <i data-lucide="message-square-warning" class="h-4 w-4" aria-hidden="true"></i>
                                Request changes
                            </button>
                        </div>
                    </form>
                @endcan
            @endif

            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <div class="rounded-md border border-border bg-background p-3">
                    <div class="text-xs text-textSecondary">Source</div>
                    <div class="mt-2 space-y-1 text-sm">
                        @if ($draft->brief)
                            <a href="{{ route('app.content.workspace.show', $draft->brief) }}" class="block font-medium text-primary hover:underline">Brief</a>
                        @endif
                        @if ($executionPlanId !== '' && \Illuminate\Support\Facades\Route::has('app.opportunity-intelligence.execution-plans.show'))
                            <a href="{{ route('app.opportunity-intelligence.execution-plans.show', $executionPlanId) }}" class="block font-medium text-primary hover:underline">Execution plan</a>
                        @endif
                        @if ($opportunityId !== '' && \Illuminate\Support\Facades\Route::has('app.opportunity-intelligence.opportunities.show'))
                            <a href="{{ route('app.opportunity-intelligence.opportunities.show', $opportunityId) }}" class="block font-medium text-primary hover:underline">Opportunity</a>
                        @endif
                    </div>
                </div>

                <div class="rounded-md border border-border bg-background p-3">
                    <div class="text-xs text-textSecondary">Signal lineage</div>
                    <div class="mt-2 flex flex-wrap gap-2 text-xs">
                        @forelse ($signalDetectionIds as $detectionId)
                            @if (\Illuminate\Support\Facades\Route::has('app.signal-intelligence.detections.show'))
                                <a href="{{ route('app.signal-intelligence.detections.show', $detectionId) }}" class="rounded-md border border-border bg-surface px-2 py-1 font-medium text-primary hover:underline">Detection</a>
                            @else
                                <span class="rounded-md border border-border bg-surface px-2 py-1 text-textSecondary">Detection</span>
                            @endif
                        @empty
                            <span class="text-textSecondary">No linked detections</span>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-md border border-border bg-background p-3">
                    <div class="text-xs text-textSecondary">Audit</div>
                    <dl class="mt-2 space-y-1 text-xs text-textSecondary">
                        @foreach ([
                            'ready_for_review_at' => 'Ready',
                            'changes_requested_at' => 'Changes',
                            'approved_for_publishing_at' => 'Approved',
                            'archived_at' => 'Archived',
                        ] as $key => $label)
                            @if (!empty($governance[$key]))
                                <div><dt class="inline text-textMuted">{{ $label }}:</dt> <dd class="inline text-textPrimary">{{ $governance[$key] }}</dd></div>
                            @endif
                        @endforeach
                        @if (!empty($governance['changes_requested_note']))
                            <div><dt class="text-textMuted">Note:</dt><dd class="text-textPrimary">{{ $governance['changes_requested_note'] }}</dd></div>
                        @endif
                    </dl>
                </div>
            </div>
        </section>
    @endif

    <div class="mb-6 inline-flex rounded-lg border border-border bg-surface p-1 text-sm font-medium">
        @foreach ([
            'draft' => 'Draft',
            'intelligence' => 'Intelligence',
            'improve' => 'Improve',
            'history' => 'History',
        ] as $tabKey => $tabLabel)
            <a
                href="{{ route('app.drafts.show', ['draft' => $draft, 'tab' => $tabKey]) }}"
                class="rounded-md px-3 py-2 {{ $activeTab === $tabKey ? 'bg-background text-textPrimary shadow-sm' : 'text-textSecondary hover:text-textPrimary' }}"
            >
                {{ $tabLabel }}
            </a>
        @endforeach
    </div>

    @if ($activeTab === 'draft')
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2 rounded-lg border border-border bg-surface p-5">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-3">
                    <div>
                        <div class="text-sm text-textSecondary">Output type</div>
                        <div class="text-sm text-textPrimary">{{ $draft->output_type }}</div>
                    </div>
                    <div class="inline-flex rounded-md border border-border bg-background p-1 text-xs font-medium" data-draft-render-tabs>
                        <button
                            type="button"
                            class="rounded px-2.5 py-1.5 text-textPrimary bg-surface shadow-sm"
                            data-draft-render-tab
                            data-target="preview"
                            aria-selected="true"
                        >
                            Preview
                        </button>
                        <button
                            type="button"
                            class="rounded px-2.5 py-1.5 text-textSecondary hover:text-textPrimary"
                            data-draft-render-tab
                            data-target="edit"
                            aria-selected="false"
                        >
                            Edit
                        </button>
                        <button
                            type="button"
                            class="rounded px-2.5 py-1.5 text-textSecondary hover:text-textPrimary"
                            data-draft-render-tab
                            data-target="split"
                            aria-selected="false"
                        >
                            Split
                        </button>
                    </div>
                </div>

                <div class="mt-4" data-draft-render-panels>
                    <div data-draft-render-panel="preview">
                        <x-content.rendered-article :html="$draft->rendered_content_html" />
                    </div>

                    <div data-draft-render-panel="edit" class="hidden space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-sm text-textSecondary">Raw source content (local preview only)</div>
                            @if ($draft->content)
                                <a href="{{ route('app.content.show', ['content' => $draft->content, 'tab' => 'draft']) }}"
                                   class="rounded border border-border px-2.5 py-1.5 text-xs text-textPrimary hover:bg-surfaceSubtle">
                                    Open content editor
                                </a>
                            @endif
                        </div>
                        <textarea
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary"
                            rows="18"
                            data-draft-render-source
                        >{{ $draft->content_html }}</textarea>
                        <p class="text-xs text-textSecondary">
                            Changes here are for review only. Use the content editor to persist revisions.
                        </p>
                    </div>

                    <div data-draft-render-panel="split" class="hidden">
                        <div class="grid gap-4 xl:grid-cols-2">
                            <div class="space-y-2">
                                <div class="text-sm text-textSecondary">Raw source</div>
                                <textarea
                                    class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary"
                                    rows="18"
                                    readonly
                                >{{ $draft->content_html }}</textarea>
                            </div>
                            <div class="space-y-2">
                                <div class="text-sm text-textSecondary">Rendered preview</div>
                                <div class="rounded-md border border-border bg-background p-4">
                                    <x-content.rendered-article :html="$draft->rendered_content_html" compact />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-4 space-y-4">
                <div class="rounded-md border border-border bg-background p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="text-sm font-medium text-textPrimary">Publishing</div>
                            <div class="mt-1 text-xs text-textSecondary">
                                {{ $destinationLabel }}
                                @if ($remotePublishLabel)
                                    · {{ $remotePublishLabel }}
                                @endif
                            </div>
                        </div>
                        <span class="rounded border border-border bg-surface px-2 py-1 text-xs font-medium text-textPrimary">{{ $publishStatusLabel }}</span>
                    </div>

                    @if ($lastPublishError !== '')
                        <p class="mt-3 rounded border border-rose-200 bg-rose-50 px-2 py-1.5 text-xs text-rose-700">{{ $lastPublishError }}</p>
                    @endif

                    <div class="mt-3 flex flex-wrap gap-2">
                        @if ($supportsImmediatePublish && $draftContent)
                            @can('update', $draftContent)
                                <form method="POST" action="{{ $publishActionRoute }}">
                                    @csrf
                                    <input type="hidden" name="locale" value="{{ $draft->language->value }}">
                                    <button
                                        class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white hover:bg-primaryHover disabled:cursor-not-allowed disabled:opacity-60"
                                        @disabled($isPublishInProgress)
                                    >
                                        <i data-lucide="{{ $isPublished ? 'refresh-cw' : 'send' }}" class="h-4 w-4" aria-hidden="true"></i>
                                        {{ $publishActionLabel }}
                                    </button>
                                </form>
                            @endcan
                        @endif

                        @if ($draftContent)
                            <a href="{{ route('app.content.show', ['content' => $draftContent, 'tab' => 'overview']) }}" class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                <i data-lucide="settings-2" class="h-4 w-4" aria-hidden="true"></i>
                                Settings
                            </a>
                        @endif

                        @if ($publishedUrl !== '')
                            <a href="{{ $publishedUrl }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                <i data-lucide="external-link" class="h-4 w-4" aria-hidden="true"></i>
                                View live
                            </a>
                        @endif
                    </div>
                </div>

                <div class="space-y-2">
                    <div class="text-sm text-textSecondary">Brief</div>
                    <div class="text-sm text-textPrimary">{{ $draft->brief?->title }}</div>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="rounded border border-border bg-background px-2 py-1">{{ $draftLocaleLabel }}</span>
                        <span class="rounded border border-border bg-background px-2 py-1">{{ $draft->draft_type->label() }}</span>
                        <span class="rounded border border-border bg-background px-2 py-1">{{ $draft->clientSite?->name }}</span>
                    </div>
                    <div class="text-xs text-textSecondary">
                        @if ($draft->isTranslation() && $draft->sourceDraft)
                            Translation from <a href="{{ route('app.drafts.show', $draft->sourceDraft) }}" class="underline">{{ strtoupper((string) $draft->sourceDraft->language->value) }}</a>
                        @else
                            Original source draft
                        @endif
                        @if ($draft->delivered_at)
                            · Delivered {{ $draft->delivered_at->format('Y-m-d H:i') }}
                        @endif
                    </div>
                </div>
                @if (! empty($currentPublishTarget))
                    <div class="rounded-md border border-border bg-background px-3 py-2 text-xs">
                        <span class="text-textSecondary">WordPress sync:</span>
                        <span class="text-textPrimary">
                            {{ ucfirst((string) ($currentPublishTarget->sync_status ?? 'pending')) }}
                            @if ($currentPublishTarget->wp_post_id)
                                · Remote ID {{ $currentPublishTarget->wp_post_id }}
                            @endif
                        </span>
                    </div>
                @endif
                @if (($translationTargets ?? collect())->isNotEmpty())
                    <div class="rounded-md border border-border bg-background p-3">
                        <div class="flex items-center justify-between gap-2">
                            <div>
                                <div class="text-sm font-medium text-textPrimary">Translate draft</div>
                                <p class="mt-0.5 text-xs text-textSecondary">{{ (int) ($translationCreditCost ?? 0) }} credits per language</p>
                            </div>
                        </div>
                        <form method="POST" action="{{ route('app.drafts.translate', $draft) }}" class="mt-3 space-y-2">
                            @csrf
                            <div class="space-y-2">
                                @foreach (($translationTargets ?? collect()) as $target)
                                    <label class="flex items-center gap-3 rounded-md border border-border bg-surface px-3 py-2 text-sm">
                                        <input class="mt-0.5" type="checkbox" name="target_languages[]" value="{{ $target['value'] }}">
                                        <span class="min-w-0">
                                            <span class="block truncate font-medium text-textPrimary">{{ $target['label'] }}</span>
                                            <span class="block truncate text-xs text-textSecondary">{{ strtoupper($target['value']) }} · {{ $target['native_label'] }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                            <button class="inline-flex items-center rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-textInverse">Queue translation</button>
                        </form>
                    </div>
                @elseif (! ($translationSourceIsReady ?? true) && trim((string) ($translationUnavailableReason ?? '')) !== '')
                    <div class="rounded-md border border-border bg-background p-3">
                        <div class="text-sm font-medium text-textPrimary">Translation unavailable</div>
                        <p class="mt-1 text-xs text-textSecondary">{{ $translationUnavailableReason }}</p>
                    </div>
                @endif
                @if (($availableLanguageGroups ?? collect())->isNotEmpty())
                    <div class="rounded-md border border-border bg-background p-3">
                        <div class="flex items-center justify-between gap-2">
                            <div class="text-sm font-medium text-textPrimary">Languages</div>
                            <div class="text-xs text-textSecondary">{{ ($availableLanguageGroups ?? collect())->count() }} total</div>
                        </div>
                        <div class="mt-2 space-y-2 text-sm">
                            @foreach (($availableLanguageGroups ?? collect()) as $languageGroup)
                                @php
                                    $currentVersion = $languageGroup['current_version'] ?? null;
                                    $displayDraft = $languageGroup['display_draft'] ?? null;
                                    $sourceDraft = $languageGroup['source_draft'] ?? null;
                                    $pendingJobs = collect($languageGroup['pending_jobs'] ?? []);
                                    $failedJobs = collect($languageGroup['failed_jobs'] ?? []);
                                    $pendingCount = (int) ($languageGroup['pending_count'] ?? 0);
                                    $failedCount = (int) ($languageGroup['failed_count'] ?? 0);
                                    $locale = (string) ($languageGroup['locale'] ?? '');
                                    $publishStatus = $currentVersion?->content?->publish_status ?: $currentVersion?->content?->status;
                                @endphp
                                <div class="rounded border border-border bg-surface px-3 py-2" data-language-locale="{{ $locale }}">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <span class="font-medium text-textPrimary">{{ $languageGroup['label'] ?? strtoupper($locale) }}</span>
                                                @if ($pendingCount > 0)
                                                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-medium text-amber-800" title="{{ $languageGroup['pending_tooltip'] ?? '' }}">
                                                        {{ $pendingCount }} pending
                                                    </span>
                                                @endif
                                                @if ($failedCount > 0)
                                                    <span class="rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-medium text-rose-800">
                                                        {{ $failedCount }} failed
                                                    </span>
                                                @endif
                                            </div>
                                            @if ($currentVersion)
                                                <div class="mt-1 truncate text-xs text-textSecondary">
                                                    Current: <span class="text-textPrimary">{{ $currentVersion->title }}</span>
                                                    @if ($publishStatus)
                                                        · {{ ucfirst((string) $publishStatus) }}
                                                    @endif
                                                </div>
                                            @else
                                                <div class="mt-1 text-xs text-textSecondary">Current: none yet</div>
                                            @endif
                                            @if ($failedCount > 0)
                                                <div class="mt-1 text-xs text-rose-700">
                                                    Failed: {{ $failedCount }} {{ \Illuminate\Support\Str::plural('job', $failedCount) }}
                                                </div>
                                            @endif
                                        </div>
                                        <div class="flex shrink-0 flex-wrap justify-end gap-1.5">
                                            @if ($displayDraft)
                                                <a href="{{ route('app.drafts.show', $displayDraft) }}" class="rounded border border-border px-2 py-1 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">View</a>
                                            @endif
                                            @if ($pendingCount > 0)
                                                <span class="rounded border border-border px-2 py-1 text-xs font-medium text-textSecondary" title="{{ $languageGroup['pending_tooltip'] ?? '' }}">Queued</span>
                                            @endif
                                            @if ($currentVersion?->content)
                                                <form method="POST" action="{{ route('app.content.regenerate', $currentVersion->content) }}">
                                                    @csrf
                                                    <button class="rounded border border-border px-2 py-1 text-xs font-medium text-textPrimary hover:bg-surfaceSubtle">
                                                        Regenerate
                                                    </button>
                                                </form>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
                <div class="pt-1">
                    @php
                        $seoCapabilityFields = collect(data_get($seoSyncCapability ?? [], 'fields', []))->keyBy('key');
                        $providerLabel = (string) data_get($seoSyncCapability ?? [], 'provider_label', 'No SEO plugin detected');
                        $seoRows = collect([
                            ['label' => 'SEO title', 'value' => $draft->seo_title, 'key' => 'seo_title'],
                            ['label' => 'Meta description', 'value' => $draft->seo_meta_description, 'key' => 'seo_meta_description'],
                            ['label' => 'H1', 'value' => $draft->seo_h1, 'key' => 'seo_h1'],
                            ['label' => 'Canonical', 'value' => $draft->seo_canonical, 'key' => 'seo_canonical'],
                            ['label' => 'OG title', 'value' => $draft->seo_og_title, 'key' => 'seo_og_title'],
                            ['label' => 'OG description', 'value' => $draft->seo_og_description, 'key' => 'seo_og_description'],
                            ['label' => 'Twitter title', 'value' => $draft->seo_twitter_title, 'key' => 'seo_twitter_title'],
                            ['label' => 'Twitter description', 'value' => $draft->seo_twitter_description, 'key' => 'seo_twitter_description'],
                        ]);
                        $filledSeoRows = $seoRows->filter(fn (array $row): bool => trim((string) $row['value']) !== '')->values();
                        $visibleSeoRows = $filledSeoRows->isNotEmpty() ? $filledSeoRows : $seoRows->take(3);
                        $syncableSeoCount = $seoCapabilityFields->filter(fn (array $field): bool => ($field['status_label'] ?? '') === 'Can sync to WordPress')->count();
                        $unsupportedSeoCount = $seoCapabilityFields->filter(fn (array $field): bool => ($field['status_label'] ?? '') === 'Requires supported SEO plugin')->count();
                    @endphp
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <div class="text-sm font-medium text-textPrimary">SEO metadata</div>
                            <div class="mt-0.5 text-[11px] text-textSecondary">{{ $providerLabel }}</div>
                        </div>
                        <div class="flex shrink-0 flex-wrap justify-end gap-1">
                            @if ($syncableSeoCount > 0)
                                <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-[11px] font-medium text-emerald-700">{{ $syncableSeoCount }} syncable</span>
                            @endif
                            @if ($unsupportedSeoCount > 0)
                                <span class="rounded-full bg-rose-500/10 px-2 py-0.5 text-[11px] font-medium text-rose-700">{{ $unsupportedSeoCount }} plugin-only</span>
                            @endif
                        </div>
                    </div>
                    <div class="mt-2 space-y-1.5 text-xs text-textPrimary">
                        @foreach ($visibleSeoRows as $seoField)
                            @php
                                $capability = $seoCapabilityFields->get($seoField['key'], []);
                            @endphp
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0 truncate">
                                    <span class="text-textSecondary">{{ $seoField['label'] }}:</span>
                                    <span class="text-textPrimary">{{ $seoField['value'] ?: 'n/a' }}</span>
                                </div>
                                @if (! empty($capability) && ($capability['status_label'] ?? '') !== 'Can sync to WordPress')
                                    <span class="shrink-0 rounded border px-1.5 py-0.5 {{ $capability['status_badge_class'] ?? 'border-border text-textSecondary' }}">{{ $capability['status_label'] ?? 'Advice only' }}</span>
                                @endif
                            </div>
                        @endforeach
                        @if ($draft->robots_index !== null || $draft->robots_follow !== null || $draft->schema_type)
                            <div class="pt-1 text-textSecondary">
                                @if ($draft->robots_index !== null)
                                    Robots: {{ $draft->robots_index ? 'index' : 'noindex' }}@if ($draft->robots_follow !== null), {{ $draft->robots_follow ? 'follow' : 'nofollow' }}@endif
                                @endif
                                @if ($draft->schema_type)
                                    {{ $draft->robots_index !== null ? ' · ' : '' }}Schema: {{ $draft->schema_type }}
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        @if ($showDraftLinkSuggestions ?? false)
            <div class="mt-8 rounded-lg border border-border bg-surface p-5 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-textPrimary">Link Suggestions</h2>
                        <p class="text-sm text-textSecondary">Editorial suggestions only. Manual approval is required before apply.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="{{ request()->boolean('debug_links') ? route('app.drafts.show', ['draft' => $draft, 'tab' => 'draft']) : route('app.drafts.show', ['draft' => $draft, 'tab' => 'draft', 'debug_links' => 1]) }}"
                           class="inline-flex items-center rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                            {{ request()->boolean('debug_links') ? 'Debug off' : 'Debug on' }}
                        </a>
                        <form method="POST" action="{{ route('app.drafts.link-suggestions.generate', $draft) }}">
                            @csrf
                            <button class="inline-flex items-center rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                Regenerate
                            </button>
                        </form>
                        <form method="POST" action="{{ route('app.drafts.link-suggestions.reset-applied', $draft) }}">
                            @csrf
                            <button class="inline-flex items-center rounded-md border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm font-medium text-rose-700 hover:bg-rose-500/20">
                                Reset applied
                            </button>
                        </form>
                        <form method="POST" action="{{ route('app.drafts.link-suggestions.clear-rejected', $draft) }}">
                            @csrf
                            <button class="inline-flex items-center rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                Clear rejected
                            </button>
                        </form>
                    </div>
                </div>

                @include('app.drafts.link-suggestions.list', ['draft' => $draft, 'linkSuggestions' => $linkSuggestions])

                @if (request()->boolean('debug_links'))
                    <div class="mt-6 rounded-md border border-amber-500/30 bg-amber-500/5 p-4 space-y-3">
                        <h3 class="font-semibold text-textPrimary">Debug: candidate evaluation</h3>
                        @if (!empty($debugPool))
                            <div class="rounded-md border border-border bg-background p-3 text-xs text-textSecondary space-y-1">
                                <div>Internal total same site: <span class="text-textPrimary">{{ data_get($debugPool, 'internal.total_other_drafts_same_site', 0) }}</span></div>
                                <div>Internal status counts:
                                    <span class="text-textPrimary">
                                        {{ json_encode(data_get($debugPool, 'internal.status_counts', []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}
                                    </span>
                                </div>
                                <div>Internal with non-empty html: <span class="text-textPrimary">{{ data_get($debugPool, 'internal.with_non_empty_html', 0) }}</span></div>
                                <div>Internal with eligible status: <span class="text-textPrimary">{{ data_get($debugPool, 'internal.with_eligible_status', 0) }}</span></div>
                                <div>Internal eligible after filters: <span class="text-textPrimary">{{ data_get($debugPool, 'internal.eligible_after_filters', 0) }}</span></div>
                                <div>External enabled: <span class="text-textPrimary">{{ data_get($debugPool, 'profile.external_enabled', false) ? 'yes' : 'no' }}</span></div>
                                <div>External approved workspaces: <span class="text-textPrimary">{{ data_get($debugPool, 'external.approved_workspace_count', 0) }}</span></div>
                                <div>External eligible after filters: <span class="text-textPrimary">{{ data_get($debugPool, 'external.eligible_after_filters', 0) }}</span></div>
                            </div>
                        @endif
                        @if (($debugSuggestions ?? collect())->isEmpty())
                            <p class="text-sm text-textSecondary">No candidates found for this source article.</p>
                        @else
                            <div class="space-y-2">
                                @foreach ($debugSuggestions as $item)
                                    <div class="rounded-md border border-border p-3">
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="text-sm font-medium text-textPrimary">{{ $item['target_title'] }}</div>
                                            <div class="text-xs px-2 py-1 rounded {{ $item['accepted'] ? 'bg-emerald-500/10 text-emerald-700' : 'bg-rose-500/10 text-rose-700' }}">
                                                {{ $item['accepted'] ? 'accepted' : 'rejected' }}
                                            </div>
                                        </div>
                                        <div class="mt-1 text-xs text-textSecondary">
                                            similarity {{ number_format($item['similarity_score'], 2) }},
                                            intent {{ number_format($item['intent_match_score'], 2) }},
                                            audience {{ number_format($item['audience_overlap_score'], 2) }}
                                        </div>
                                        <div class="mt-1 text-xs text-textSecondary">
                                            reasons: <span class="text-textPrimary">{{ implode(' | ', $item['reasons']) }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    @elseif ($activeTab === 'intelligence')
        @php
            $analysisStatusValue = $analysisStatus ?? ($latestAnalysis ? 'completed' : null);
            $isPartialAnalysis = $analysisStatusValue === 'partial';
            $isFailedAnalysis = $analysisStatusValue === 'failed';
            $isPendingAnalysis = $analysisStatusValue === 'pending';
            $isProcessingAnalysis = $analysisStatusValue === 'processing';
            $hasUsableAnalysis = $latestAnalysis && in_array($analysisStatusValue, ['completed', 'partial'], true);
            $missingSections = $latestAnalysis?->missing_sections ?? [];
            $availableSections = $latestAnalysis?->available_sections ?? [];
            $missingSectionsFormatted = implode(', ', array_map('ucfirst', $missingSections));
        @endphp

        <div class="space-y-6">
            @if ($isPartialAnalysis)
                <div class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-500/20">
                                <svg class="h-4 w-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="font-semibold text-amber-800">Analysis Incomplete</h3>
                                <p class="text-sm text-amber-700">We received a partial response and could not populate all intelligence sections.</p>
                                @if (! empty($missingSections))
                                    <p class="mt-2 text-xs text-amber-600">Missing sections: {{ $missingSectionsFormatted }}</p>
                                @endif
                            </div>
                        </div>
                        <form method="POST" action="{{ route('app.drafts.analyze', $draft) }}">
                            @csrf
                            <button class="inline-flex items-center rounded-md border border-amber-500/40 bg-amber-500/20 px-3 py-2 text-sm font-medium text-amber-800 hover:bg-amber-500/30">
                                Re-run analysis
                            </button>
                        </form>
                    </div>
                </div>
            @elseif ($isFailedAnalysis)
                <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 p-5">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-start gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-rose-500/20">
                                <svg class="h-4 w-4 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </div>
                            <div>
                                <h3 class="font-semibold text-rose-800">Analysis Failed</h3>
                                <p class="text-sm text-rose-700">We could not parse a valid intelligence result from the analysis model.</p>
                                @if ($internalLinkSummary !== '')
                                    <p class="mt-2 text-xs text-rose-600">{{ $internalLinkSummary }}</p>
                                @elseif (! empty($latestAnalysis?->validation_errors))
                                    <p class="mt-2 text-xs text-rose-600">{{ data_get($latestAnalysis?->validation_errors, '0', '') }}</p>
                                @endif
                            </div>
                        </div>
                        <form method="POST" action="{{ route('app.drafts.analyze', $draft) }}">
                            @csrf
                            <button class="inline-flex items-center rounded-md border border-rose-500/40 bg-rose-500/20 px-3 py-2 text-sm font-medium text-rose-800 hover:bg-rose-500/30">
                                Re-run analysis
                            </button>
                        </form>
                    </div>
                </div>
            @endif

            <div class="rounded-lg border border-border bg-surface p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-textPrimary">Draft Intelligence</h2>
                        <p class="mt-1 text-sm text-textSecondary">
                            {{ data_get($analysisSummary, 'headline', 'Latest editorial intelligence for this draft.') }}
                        </p>
                    </div>
                    <form method="POST" action="{{ route('app.drafts.analyze', $draft) }}">
                        @csrf
                        <button class="inline-flex items-center rounded-md border border-border px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                            {{ $latestAnalysis ? 'Re-run analysis' : 'Run analysis' }}
                        </button>
                    </form>
                </div>
                @if ($latestAnalysis)
                    <div class="mt-3 text-xs text-textSecondary">
                        Last analysis: {{ $latestAnalysis->created_at?->format('Y-m-d H:i') ?? 'n/a' }}
                        @if ($latestAnalysis->analysis_model)
                            · Model: {{ $latestAnalysis->analysis_model }}
                        @endif
                        @if ($latestAnalysis->tokens_used)
                            · Tokens: {{ number_format((int) $latestAnalysis->tokens_used) }}
                        @endif
                    </div>
                @endif
                @if (data_get($analysisSummary, 'overall_explanation'))
                    <p class="mt-4 text-sm text-textPrimary">{{ data_get($analysisSummary, 'overall_explanation') }}</p>
                @endif
            </div>

            @if ($hasUsableAnalysis)
                <div class="rounded-lg border border-border bg-surface p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-xs uppercase tracking-wide text-textSecondary">Human Content metrics</div>
                            <div class="mt-1 text-2xl font-semibold text-textPrimary">
                                {{ $hasHumanContentMetrics ? 'Editorial quality signal' : 'No Human Content score yet' }}
                            </div>
                            <div class="mt-2 text-sm text-textSecondary">
                                {{ $hasHumanContentMetrics ? 'Human, originality and AI-fingerprint signals from the latest draft intelligence pass.' : 'This draft was analyzed before Human Content scoring was added. Re-score to populate editorial quality metrics.' }}
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('app.drafts.analyze', $draft) }}">
                                @csrf
                                <button class="inline-flex items-center gap-2 rounded-md border border-border bg-background px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                    <i data-lucide="refresh-cw" class="h-4 w-4" aria-hidden="true"></i>
                                    Re-score Human Content
                                </button>
                            </form>
                            <form method="POST" action="{{ route('app.drafts.humanize', $draft) }}" data-improvement-form>
                                @csrf
                                <button
                                    class="inline-flex items-center gap-2 rounded-md bg-primary px-3 py-2 text-sm font-medium text-white hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-60"
                                    data-improvement-button
                                    data-loading-label="Queueing Humanization..."
                                >
                                    <i data-lucide="wand-sparkles" class="h-4 w-4" aria-hidden="true"></i>
                                    Run Humanization
                                </button>
                            </form>
                            <form method="POST" action="{{ route('app.drafts.improve', $draft) }}" data-improvement-form>
                                @csrf
                                <input type="hidden" name="action" value="human_content">
                                <button
                                    class="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                                    data-improvement-button
                                    data-loading-label="Queueing Human Content fixes..."
                                >
                                    <i data-lucide="sparkles" class="h-4 w-4" aria-hidden="true"></i>
                                    Apply Human Content fixes
                                </button>
                            </form>
                        </div>
                    </div>

                    @if ($hasHumanContentMetrics)
                        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                            @foreach ($humanContentMetricCards as $metric)
                                @php
                                    $direction = (string) ($metric['direction'] ?? 'higher_is_better');
                                    $score = $metric['score'];
                                    $before = $metric['before'];
                                    $status = trim((string) ($metric['status'] ?? ''));
                                    $scoreTone = match (true) {
                                        $direction === 'lower_is_better' && is_numeric($score) && $score <= 35 => 'text-emerald-700',
                                        $direction === 'lower_is_better' && is_numeric($score) && $score >= 60 => 'text-rose-700',
                                        $direction === 'higher_is_better' && is_numeric($score) && $score >= 75 => 'text-emerald-700',
                                        $direction === 'higher_is_better' && is_numeric($score) && $score < 60 => 'text-rose-700',
                                        $direction === 'status' && in_array($status, ['passed', 'pass'], true) => 'text-emerald-700',
                                        $direction === 'status' && $status !== '' => 'text-amber-700',
                                        default => 'text-textPrimary',
                                    };
                                @endphp
                                <div class="rounded-md border border-border bg-background p-3">
                                    <div class="text-xs uppercase tracking-wide text-textSecondary">{{ $metric['label'] }}</div>
                                    <div class="mt-2 flex items-end justify-between gap-2">
                                        <div class="text-2xl font-semibold {{ $scoreTone }}">
                                            @if ($score !== null)
                                                {{ $score }}
                                            @elseif ($status !== '')
                                                {{ \Illuminate\Support\Str::headline($status) }}
                                            @else
                                                n/a
                                            @endif
                                        </div>
                                        @if ($status !== '' && $score !== null)
                                            <div class="rounded-full border border-border px-2 py-0.5 text-xs text-textSecondary">{{ \Illuminate\Support\Str::headline($status) }}</div>
                                        @endif
                                    </div>
                                    <div class="mt-2 text-xs text-textSecondary">
                                        @if ($before !== null && $score !== null)
                                            Before {{ $before }} · After {{ $score }}
                                        @elseif ($direction === 'lower_is_better')
                                            Lower is better
                                        @elseif ($direction === 'higher_is_better')
                                            Higher is better
                                        @else
                                            Publication decision
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="mt-4 rounded-md bg-background px-3 py-3 text-sm text-textSecondary">
                            Human Content metrics are not available for this older draft analysis yet.
                        </div>
                    @endif
                </div>

                @if ($humanContentGate !== [])
                    <div class="rounded-lg border {{ data_get($humanContentGate, 'passed') ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-amber-500/30 bg-amber-500/5' }} p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-textSecondary">Human Content publish gate</div>
                                <div class="mt-1 text-2xl font-semibold text-textPrimary">
                                    {{ \Illuminate\Support\Str::headline((string) data_get($humanContentGate, 'status', 'pending')) }}
                                </div>
                                <div class="mt-2 text-sm text-textSecondary">
                                    {{ data_get($humanContentGate, 'passed') ? 'Automatic publication is allowed by the editorial quality gate.' : 'Automatic publication is blocked until these editorial quality issues are resolved.' }}
                                </div>
                            </div>
                            <div class="rounded-full border border-border px-3 py-1 text-sm font-medium text-textPrimary">
                                {{ data_get($humanContentGate, 'passed') ? 'Passed' : 'Blocked' }}
                            </div>
                        </div>
                        @if ($humanContentGateReasons->isNotEmpty())
                            <div class="mt-4 space-y-2">
                                @foreach ($humanContentGateReasons as $reason)
                                    <div class="rounded-md bg-background px-3 py-2 text-sm text-textPrimary">{{ $reason }}</div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                @if ($publishReadinessSection && (data_get($publishReadinessSection, 'score') !== null || data_get($publishReadinessSection, 'status_label') || ! empty(data_get($publishReadinessSection, 'blocking_issues', []))))
                    <div class="rounded-lg border border-border bg-surface p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-textSecondary">Publish readiness</div>
                                <div class="mt-1 text-2xl font-semibold text-textPrimary">{{ data_get($publishReadinessSection, 'status_label', 'Needs revision') }}</div>
                                <div class="mt-2 text-sm text-textSecondary">{{ data_get($publishReadinessSection, 'explanation') ?: 'No publish-readiness explanation recorded.' }}</div>
                            </div>
                            <div class="rounded-full border border-border px-3 py-1 text-sm font-medium text-textPrimary">
                                Score {{ data_get($publishReadinessSection, 'score', 'n/a') }}
                            </div>
                        </div>
                        <div class="mt-4 grid gap-4 xl:grid-cols-2">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-textSecondary">Blocking issues</div>
                                <div class="mt-2 space-y-2">
                                    @forelse ((array) data_get($publishReadinessSection, 'blocking_issues', []) as $issue)
                                        <div class="rounded-md bg-background px-3 py-2 text-sm text-textPrimary">{{ $issue }}</div>
                                    @empty
                                        <div class="rounded-md bg-background px-3 py-2 text-sm text-textSecondary">No blocking issues recorded.</div>
                                    @endforelse
                                </div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-textSecondary">Recommended next actions</div>
                                <div class="mt-2 space-y-2">
                                    @forelse ((array) data_get($publishReadinessSection, 'recommended_next_actions', data_get($publishReadinessSection, 'suggestions', [])) as $action)
                                        <div class="rounded-md bg-background px-3 py-2 text-sm text-textPrimary">{{ $action }}</div>
                                    @empty
                                        <div class="rounded-md bg-background px-3 py-2 text-sm text-textSecondary">No next actions recorded.</div>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @if ($humanContentSection && (data_get($humanContentSection, 'score') !== null || $humanContentFindings->isNotEmpty() || $humanContentActions->isNotEmpty()))
                    <div class="rounded-lg border border-border bg-surface p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-textSecondary">Human Content</div>
                                <div class="mt-1 text-2xl font-semibold text-textPrimary">
                                    {{ ucfirst((string) data_get($humanContentSection, 'status_label', data_get($humanContentPayload, 'status', 'pending'))) }}
                                </div>
                                <div class="mt-2 text-sm text-textSecondary">{{ data_get($humanContentSection, 'explanation') ?: 'No human-content explanation recorded.' }}</div>
                            </div>
                            <div class="rounded-full border border-border px-3 py-1 text-sm font-medium text-textPrimary">
                                Score {{ data_get($humanContentSection, 'score', data_get($humanContentPayload, 'human_content_score', 'n/a')) }}
                            </div>
                        </div>
                        <div class="mt-4 grid gap-4 xl:grid-cols-2">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-textSecondary">Findings</div>
                                <div class="mt-2 space-y-2">
                                    @forelse ($humanContentFindings as $finding)
                                        <div class="rounded-md bg-background px-3 py-2 text-sm text-textPrimary">{{ $finding }}</div>
                                    @empty
                                        <div class="rounded-md bg-background px-3 py-2 text-sm text-textSecondary">No human-content findings recorded.</div>
                                    @endforelse
                                </div>
                            </div>
                            <div>
                                <div class="text-xs uppercase tracking-wide text-textSecondary">Humanization actions</div>
                                <div class="mt-2 space-y-2">
                                    @forelse ($humanContentActions as $action)
                                        <div class="rounded-md bg-background px-3 py-2 text-sm text-textPrimary">{{ $action }}</div>
                                    @empty
                                        <div class="rounded-md bg-background px-3 py-2 text-sm text-textSecondary">No humanization actions recorded.</div>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                @if ($humanizationMeta !== [])
                    <div class="rounded-lg border border-border bg-surface p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="text-xs uppercase tracking-wide text-textSecondary">Humanization pass</div>
                                <div class="mt-1 text-xl font-semibold text-textPrimary">
                                    {{ \Illuminate\Support\Str::headline((string) data_get($humanizationMeta, 'status', 'recorded')) }}
                                </div>
                                <div class="mt-2 text-sm text-textSecondary">{{ data_get($humanizationMeta, 'change_summary') ?: data_get($humanizationMeta, 'reason', 'No humanization summary recorded.') }}</div>
                            </div>
                            <div class="rounded-full border border-border px-3 py-1 text-sm font-medium text-textPrimary">
                                {{ data_get($humanizationMeta, 'preserved_validation.passed') === false ? 'Validation issue' : 'Preserved' }}
                            </div>
                        </div>
                        @if ($humanizationNotes->isNotEmpty())
                            <div class="mt-4 space-y-2">
                                @foreach ($humanizationNotes as $note)
                                    <div class="rounded-md bg-background px-3 py-2 text-sm text-textPrimary">{{ $note }}</div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($analysisSections as $section)
                        @php
                            $missingKey = $section['key'] === 'headings' ? 'structure' : $section['key'];
                            $isMissing = in_array($missingKey, $missingSections, true);
                            $delta = $latestImprovementDeltaMap[$section['key']] ?? null;
                        @endphp
                        <div class="rounded-lg border {{ $isMissing ? 'border-amber-500/30 bg-amber-500/5' : 'border-border bg-surface' }} p-4">
                            <div class="text-xs uppercase tracking-wide text-textSecondary">{{ $section['label'] }}</div>
                            <div class="mt-2 text-3xl font-semibold {{ $isMissing ? 'text-amber-600' : 'text-textPrimary' }}">{{ $section['score'] ?? 'n/a' }}</div>
                            @if ($delta)
                                <div class="mt-1 text-xs {{ data_get($delta, 'delta_value') === null || data_get($delta, 'delta_value', 0) >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                                    {{ data_get($delta, 'display_transition') }}
                                </div>
                            @endif
                            <div class="mt-2 text-sm {{ $isMissing ? 'text-amber-600' : 'text-textSecondary' }}">
                                @if ($isMissing)
                                    Section data unavailable in this analysis.
                                @else
                                    {{ $section['explanation'] ?: 'Analysis completed without detailed explanation.' }}
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="grid gap-4 xl:grid-cols-2">
                    @foreach ($analysisSections as $section)
                        @php
                            $missingKey = $section['key'] === 'headings' ? 'structure' : $section['key'];
                            $isMissing = in_array($missingKey, $missingSections, true);
                        @endphp
                        <div class="rounded-lg border {{ $isMissing ? 'border-amber-500/30 bg-amber-500/5' : 'border-border bg-surface' }} p-5">
                            <div class="flex items-center justify-between gap-3">
                                <h3 class="text-base font-semibold text-textPrimary">{{ $section['label'] }}</h3>
                                <span class="rounded-full border {{ $isMissing ? 'border-amber-500/30 text-amber-600' : 'border-border text-textSecondary' }} px-2.5 py-1 text-xs">
                                    Score {{ $section['score'] ?? 'n/a' }}
                                </span>
                            </div>
                            <p class="mt-3 text-sm {{ $isMissing ? 'text-amber-600' : 'text-textSecondary' }}">
                                @if ($isMissing)
                                    This section's data was not available in the analysis response. Re-run the analysis to attempt recovery.
                                @else
                                    {{ $section['explanation'] ?: 'Analysis completed without detailed explanation.' }}
                                @endif
                            </p>
                            <div class="mt-4 space-y-2">
                                @if ($isMissing)
                                    <div class="rounded-md bg-amber-500/10 px-3 py-2 text-sm text-amber-700">Section improvements not available.</div>
                                @else
                                    @forelse ($section['suggestions'] as $suggestion)
                                        <div class="rounded-md bg-background px-3 py-2 text-sm text-textPrimary">{{ $suggestion }}</div>
                                    @empty
                                        <div class="rounded-md bg-background px-3 py-2 text-sm text-textSecondary">No specific improvements identified.</div>
                                    @endforelse
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="grid gap-4 xl:grid-cols-2">
                    <div class="rounded-lg border border-border bg-surface p-5">
                        <h3 class="text-base font-semibold text-textPrimary">Top priorities</h3>
                        <div class="mt-4 space-y-3">
                            @forelse ($topPriorities as $priority)
                                <div class="rounded-md border border-border bg-background p-3">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <div class="text-sm font-medium text-textPrimary">{{ data_get($priority, 'title') }}</div>
                                            <div class="mt-1 text-xs text-textSecondary">{{ data_get($priority, 'summary') }}</div>
                                        </div>
                                        <div class="text-[11px] uppercase tracking-wide text-textSecondary">{{ strtoupper((string) data_get($priority, 'metric_key')) }}</div>
                                    </div>
                                    <div class="mt-2 text-xs text-textSecondary">{{ data_get($priority, 'why_it_matters') }}</div>
                                    <div class="mt-2 text-xs text-textPrimary">Suggested action: {{ data_get($priority, 'suggested_action') }}</div>
                                    <div class="mt-2 text-[11px] text-textSecondary">
                                        Impact {{ data_get($priority, 'impact_level', 'n/a') }}
                                        · Effort {{ data_get($priority, 'effort_level', 'n/a') }}
                                        · Confidence {{ data_get($priority, 'confidence_level', 'n/a') }}
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-md bg-background px-3 py-2 text-sm text-textSecondary">No prioritized recommendations are available yet.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="rounded-lg border border-border bg-surface p-5">
                        <h3 class="text-base font-semibold text-textPrimary">Latest improvement deltas</h3>
                        <div class="mt-4 space-y-3">
                            @forelse ($latestImprovementDeltaMap as $metricKey => $delta)
                                <div class="rounded-md border border-border bg-background p-3">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="text-sm font-medium text-textPrimary">{{ \Illuminate\Support\Str::headline((string) $metricKey) }}</div>
                                        <div class="text-xs {{ data_get($delta, 'delta_value') === null || data_get($delta, 'delta_value', 0) >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                                            {{ data_get($delta, 'display_transition') }}
                                        </div>
                                    </div>
                                    <div class="mt-2 text-xs text-textSecondary">{{ data_get($delta, 'explanation') }}</div>
                                </div>
                            @empty
                                <div class="rounded-md bg-background px-3 py-2 text-sm text-textSecondary">No improvement deltas have been recorded yet.</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                <div class="grid gap-4 xl:grid-cols-2">
                    <div class="rounded-lg border border-border bg-surface p-5">
                        <h3 class="text-base font-semibold text-textPrimary">Internal link opportunities</h3>
                        <div class="mt-4 space-y-3">
                            @forelse ($internalLinkOpportunities as $opportunity)
                                <div class="rounded-md border border-border bg-background p-3">
                                    <div class="text-sm font-medium text-textPrimary">{{ data_get($opportunity, 'target_title', 'Untitled target') }}</div>
                                    <div class="mt-1 text-xs text-textSecondary">{{ data_get($opportunity, 'reason') ?: 'Suggested based on content relevance.' }}</div>
                                    <div class="mt-2 text-xs text-textSecondary">
                                        Anchor: <span class="text-textPrimary">{{ data_get($opportunity, 'anchor_text', 'n/a') }}</span>
                                        · Placement: <span class="text-textPrimary">{{ data_get($opportunity, 'placement', 'n/a') }}</span>
                                    </div>
                                </div>
                            @empty
                                <div class="rounded-md bg-background px-3 py-2 text-sm text-textSecondary">
                                    {{ $internalLinkSummary !== '' ? $internalLinkSummary : 'No internal link opportunities identified for this content.' }}
                                </div>
                            @endforelse
                        </div>
                    </div>

                    <div class="rounded-lg border border-border bg-surface p-5">
                        <h3 class="text-base font-semibold text-textPrimary">Top improvements</h3>
                        <div class="mt-4 space-y-2">
                            @forelse ($analysisTopImprovements as $item)
                                <div class="rounded-md bg-background px-3 py-2 text-sm text-textPrimary">{{ $item }}</div>
                            @empty
                                <div class="rounded-md bg-background px-3 py-2 text-sm text-textSecondary">No priority improvements identified.</div>
                            @endforelse
                        </div>
                    </div>
                </div>

                @if ($canViewDiagnostics ?? false)
                    <details class="rounded-lg border border-slate-500/30 bg-slate-500/5">
                        <summary class="cursor-pointer px-5 py-3 text-sm font-medium text-slate-600 hover:text-slate-800">
                            Diagnostics (Admin Only)
                        </summary>
                        <div class="border-t border-slate-500/20 p-5 space-y-4">
                            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-slate-500">Status</div>
                                    <div class="mt-1 text-sm font-medium text-textPrimary">{{ $latestAnalysis?->effective_status ?? $analysisStatusValue ?? 'n/a' }}</div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-slate-500">Provider</div>
                                    <div class="mt-1 text-sm font-medium text-textPrimary">{{ $latestAnalysis->analysis_provider ?? data_get($analysisPayload, 'context.provider', 'n/a') }}</div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-slate-500">Model</div>
                                    <div class="mt-1 text-sm font-medium text-textPrimary">{{ $latestAnalysis->analysis_model ?? 'n/a' }}</div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-slate-500">Prompt Version</div>
                                    <div class="mt-1 text-sm font-medium text-textPrimary">{{ $latestAnalysis->prompt_version ?? data_get($analysisPayload, 'context.prompt_version', 'n/a') }}</div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-slate-500">Tokens Used</div>
                                    <div class="mt-1 text-sm font-medium text-textPrimary">{{ number_format((int) ($latestAnalysis->tokens_used ?? 0)) }}</div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-slate-500">Sections</div>
                                    <div class="mt-1 text-sm font-medium text-textPrimary">{{ count($availableSections) }}/{{ count((array) data_get($analysisPayload, 'sections', [])) ?: 6 }} available</div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-slate-500">Parser</div>
                                    <div class="mt-1 text-sm font-medium text-textPrimary">{{ empty($latestAnalysis->parser_errors) ? 'success' : 'failed' }}</div>
                                </div>
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-slate-500">Validator</div>
                                    <div class="mt-1 text-sm font-medium text-textPrimary">{{ empty($latestAnalysis->validation_errors) ? 'success' : 'failed' }}</div>
                                </div>
                            </div>

                            @if (! empty($missingSections))
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-slate-500">Missing Sections</div>
                                    <div class="mt-1 text-sm text-textPrimary">{{ $missingSectionsFormatted }}</div>
                                </div>
                            @endif

                            @if (! empty($latestAnalysis->parser_errors))
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-slate-500">Parser Errors</div>
                                    <ul class="mt-1 list-inside list-disc text-sm text-rose-600">
                                        @foreach ((array) $latestAnalysis->parser_errors as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if (! empty($latestAnalysis->validation_errors))
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-slate-500">Validation Errors</div>
                                    <ul class="mt-1 list-inside list-disc text-sm text-amber-600">
                                        @foreach ((array) $latestAnalysis->validation_errors as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if ($latestAnalysis->raw_response)
                                <div>
                                    <div class="text-xs uppercase tracking-wide text-slate-500">Raw Response Preview</div>
                                    <pre class="mt-1 max-h-48 overflow-auto rounded-md bg-slate-100 p-3 text-xs text-slate-700">{{ \Illuminate\Support\Str::limit($latestAnalysis->raw_response, 2000) }}</pre>
                                </div>
                            @endif
                        </div>
                    </details>
                @endif
            @else
                <div class="rounded-lg border border-dashed border-border bg-surface p-8 text-center text-sm text-textSecondary">
                    No draft analysis is available yet. Run intelligence to generate score cards and improvement suggestions.
                </div>
            @endif
        </div>
    @elseif ($activeTab === 'improve')
        <div class="space-y-6">
            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-lg font-semibold text-textPrimary">Improve Draft</h2>
                <p class="mt-1 text-sm text-textSecondary">Use a holistic pass when you want SEO, readability, headings, and CTA to improve together. The focused actions below still handle narrower edits.</p>

                @if ($primaryImprovementAction)
                    <div class="mt-4 rounded-lg border border-sky-500/25 bg-sky-500/5 p-4">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <div class="text-sm font-semibold text-textPrimary">{{ $primaryImprovementAction['label'] }}</div>
                                <p class="mt-1 text-sm text-textSecondary">{{ $primaryImprovementAction['description'] }}</p>
                            </div>
                            <form method="POST" action="{{ route('app.drafts.improve', $draft) }}" data-improvement-form>
                                @csrf
                                <input type="hidden" name="action" value="{{ $primaryImprovementAction['key'] }}">
                                <button
                                    class="inline-flex w-full items-center justify-center rounded-lg bg-sky-600 px-4 py-3 text-sm font-medium text-white hover:bg-sky-700 disabled:cursor-not-allowed disabled:opacity-60 lg:w-auto"
                                    data-improvement-button
                                    data-loading-label="Queueing {{ $primaryImprovementAction['label'] }}..."
                                >
                                    {{ $primaryImprovementAction['label'] }}
                                </button>
                            </form>
                        </div>
                    </div>
                @endif

                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    @foreach ($secondaryImprovementActions as $action)
                        <form method="POST" action="{{ route('app.drafts.improve', $draft) }}" data-improvement-form>
                            @csrf
                            <input type="hidden" name="action" value="{{ $action['key'] }}">
                            <button
                                class="flex w-full items-center justify-center rounded-lg border border-border bg-background px-4 py-3 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle disabled:cursor-not-allowed disabled:opacity-60"
                                data-improvement-button
                                data-loading-label="Queueing {{ $action['label'] }}..."
                            >
                                {{ $action['label'] }}
                            </button>
                            <p class="mt-2 text-xs text-textSecondary">{{ $action['description'] }}</p>
                        </form>
                    @endforeach
                </div>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                <div class="rounded-lg border border-border bg-surface p-5">
                    <h3 class="text-base font-semibold text-textPrimary">Current intelligence context</h3>
                    <div class="mt-4 space-y-3">
                        @forelse ($analysisSections as $section)
                            <div class="flex items-start justify-between gap-3 rounded-md bg-background px-3 py-2">
                                <div>
                                    <div class="text-sm font-medium text-textPrimary">{{ $section['label'] }}</div>
                                    <div class="text-xs text-textSecondary">{{ $section['explanation'] ?: 'No explanation recorded.' }}</div>
                                </div>
                                <div class="text-sm font-semibold text-textPrimary">{{ $section['score'] ?? 'n/a' }}</div>
                            </div>
                        @empty
                            <div class="rounded-md bg-background px-3 py-2 text-sm text-textSecondary">Run draft intelligence first to target improvements with current analysis.</div>
                        @endforelse
                    </div>
                </div>

                <div class="rounded-lg border border-border bg-surface p-5">
                    <h3 class="text-base font-semibold text-textPrimary">Recent improvements</h3>
                    <div class="mt-4 space-y-3">
                        @forelse ($draftImprovementHistory ?? [] as $item)
                            @php
                                $changedAt = data_get($item, 'displayed_at');
                                $historyLabel = (string) data_get($item, 'label', \Illuminate\Support\Str::headline((string) data_get($item, 'action', data_get($item, 'section', 'update'))));
                                $deltaSnapshot = collect((array) data_get($item, 'score_delta_snapshot', []));
                            @endphp
                            <div class="rounded-md border border-border bg-background p-3">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="text-sm font-medium text-textPrimary">{{ $historyLabel }}</div>
                                    <div class="text-xs text-textSecondary">
                                        {{ data_get($item, 'status_label', 'Unknown') }}
                                        @if ($changedAt)
                                            · {{ \Illuminate\Support\Carbon::parse((string) $changedAt)->format('Y-m-d H:i') }}
                                        @endif
                                    </div>
                                </div>
                                @php
                                    $historyNotes = collect((array) data_get($item, 'change_notes', []))
                                        ->filter(fn (mixed $note): bool => trim((string) $note) !== '')
                                        ->values();
                                @endphp
                                @if ($historyNotes->isNotEmpty())
                                    <ul class="mt-2 space-y-1 text-sm text-textSecondary">
                                        @foreach ($historyNotes as $note)
                                            <li>{{ $note }}</li>
                                        @endforeach
                                    </ul>
                                @else
                                    <div class="mt-2 text-sm text-textSecondary">{{ data_get($item, 'summary', data_get($item, 'change_summary', 'No change summary recorded.')) }}</div>
                                @endif
                                @if ($deltaSnapshot->isNotEmpty())
                                    <div class="mt-3 grid gap-2 md:grid-cols-2">
                                        @foreach ($deltaSnapshot as $metricKey => $delta)
                                            <div class="rounded-md bg-surface px-3 py-2 text-xs text-textSecondary">
                                                <div class="font-medium text-textPrimary">{{ \Illuminate\Support\Str::headline((string) $metricKey) }}</div>
                                                <div>{{ data_get($delta, 'display_transition') }}</div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @empty
                            <div class="rounded-md bg-background px-3 py-2 text-sm text-textSecondary">No improvement actions have been recorded yet.</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    @else
        <div class="grid gap-4 xl:grid-cols-2">
            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-lg font-semibold text-textPrimary">Analysis history</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($analysisHistory as $analysis)
                        <div class="rounded-md border border-border bg-background p-3">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-sm font-medium text-textPrimary">
                                    {{ data_get($analysis->suggestions, 'summary.headline', 'Draft intelligence run') }}
                                </div>
                                <div class="text-xs text-textSecondary">{{ $analysis->created_at?->format('Y-m-d H:i') ?? 'n/a' }}</div>
                            </div>
                            <div class="mt-2 text-xs text-textSecondary">
                                SEO {{ $analysis->seo_score ?? 'n/a' }} · Readability {{ $analysis->readability_score ?? 'n/a' }} · CTA {{ $analysis->cta_score ?? 'n/a' }} · Headings {{ $analysis->headings_score ?? 'n/a' }} · LLM Visibility {{ $analysis->llm_visibility_score ?? 'n/a' }} · Brand Voice {{ $analysis->brand_voice_fit_score ?? 'n/a' }} · Conversion {{ $analysis->conversion_fit_score ?? 'n/a' }} · Trust {{ $analysis->trust_evidence_score ?? 'n/a' }} · Publish Readiness {{ $analysis->publish_readiness_score ?? 'n/a' }}
                            </div>
                            <div class="mt-2 text-sm text-textSecondary">{{ data_get($analysis->suggestions, 'summary.overall_explanation', 'No summary recorded.') }}</div>
                        </div>
                    @empty
                        <div class="rounded-md bg-background px-3 py-2 text-sm text-textSecondary">No analysis history recorded yet.</div>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-lg font-semibold text-textPrimary">Improvement history</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($draftImprovementHistory ?? [] as $item)
                        @php
                            $changedAt = data_get($item, 'displayed_at');
                            $historyLabel = (string) data_get($item, 'label', \Illuminate\Support\Str::headline((string) data_get($item, 'action', data_get($item, 'section', 'update'))));
                            $deltaSnapshot = collect((array) data_get($item, 'score_delta_snapshot', []));
                        @endphp
                        <div class="rounded-md border border-border bg-background p-3">
                            <div class="flex items-center justify-between gap-2">
                                <div class="text-sm font-medium text-textPrimary">{{ $historyLabel }}</div>
                                <div class="text-xs text-textSecondary">
                                    {{ data_get($item, 'status_label', 'Unknown') }}
                                    @if ($changedAt)
                                        · {{ \Illuminate\Support\Carbon::parse((string) $changedAt)->format('Y-m-d H:i') }}
                                    @endif
                                </div>
                            </div>
                            @php
                                $historyNotes = collect((array) data_get($item, 'change_notes', []))
                                    ->filter(fn (mixed $note): bool => trim((string) $note) !== '')
                                    ->values();
                            @endphp
                            @if ($historyNotes->isNotEmpty())
                                <ul class="mt-2 space-y-1 text-sm text-textSecondary">
                                    @foreach ($historyNotes as $note)
                                        <li>{{ $note }}</li>
                                    @endforeach
                                </ul>
                            @else
                                <div class="mt-2 text-sm text-textSecondary">{{ data_get($item, 'summary', data_get($item, 'change_summary', 'No change summary recorded.')) }}</div>
                            @endif
                            @if ($deltaSnapshot->isNotEmpty())
                                <div class="mt-3 grid gap-2 md:grid-cols-2">
                                    @foreach ($deltaSnapshot as $metricKey => $delta)
                                        <div class="rounded-md bg-surface px-3 py-2 text-xs text-textSecondary">
                                            <div class="font-medium text-textPrimary">{{ \Illuminate\Support\Str::headline((string) $metricKey) }}</div>
                                            <div>{{ data_get($delta, 'display_transition') }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                            <div class="mt-2 text-xs text-textSecondary">
                                Prompt {{ data_get($item, 'prompt_version', 'n/a') }} · {{ data_get($item, 'fully_applied', false) ? 'Fully applied' : 'Partially applied' }}
                            </div>
                        </div>
                    @empty
                        <div class="rounded-md bg-background px-3 py-2 text-sm text-textSecondary">No improvement history recorded yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const tabsRoot = document.querySelector('[data-draft-render-tabs]');
            const panelsRoot = document.querySelector('[data-draft-render-panels]');
            const improvementForms = Array.from(document.querySelectorAll('[data-improvement-form]'));

            if (tabsRoot && panelsRoot) {
                const tabs = Array.from(tabsRoot.querySelectorAll('[data-draft-render-tab]'));
                const panels = Array.from(panelsRoot.querySelectorAll('[data-draft-render-panel]'));

                const activate = (target) => {
                    tabs.forEach((tab) => {
                        const isActive = tab.dataset.target === target;
                        tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
                        tab.classList.toggle('bg-surface', isActive);
                        tab.classList.toggle('shadow-sm', isActive);
                        tab.classList.toggle('text-textPrimary', isActive);
                        tab.classList.toggle('text-textSecondary', !isActive);
                    });

                    panels.forEach((panel) => {
                        panel.classList.toggle('hidden', panel.dataset.draftRenderPanel !== target);
                    });
                };

                tabs.forEach((tab) => {
                    tab.addEventListener('click', () => activate(tab.dataset.target || 'preview'));
                });
            }

            improvementForms.forEach((form) => {
                form.addEventListener('submit', () => {
                    const button = form.querySelector('[data-improvement-button]');
                    if (!button || button.disabled) {
                        return;
                    }

                    button.disabled = true;
                    button.textContent = button.dataset.loadingLabel || 'Queueing...';
                });
            });
        });
    </script>
@endsection
