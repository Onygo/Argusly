@extends('layouts.app', ['title' => 'Growth Program'])

@php
        $status = $program->status instanceof \App\Enums\GrowthProgramStatus ? $program->status : \App\Enums\GrowthProgramStatus::tryFrom((string) $program->status);
        $programNameParts = collect(explode(':', (string) $program->name))
            ->map(fn ($part) => trim($part))
            ->filter()
            ->values();
        $knownProgramPrefixes = ['draft', 'brief', 'execution plan', 'opportunity', 'brand visibility', 'content gap', 'competitor gap'];
        $shouldSplitProgramName = $programNameParts->count() > 1
            && ($programNameParts->count() > 2 || in_array(strtolower((string) $programNameParts->first()), $knownProgramPrefixes, true));
        $programNameLabels = $shouldSplitProgramName
            ? $programNameParts->take(min(3, $programNameParts->count() - 1))->values()
            : collect();
        $programNameTitle = $shouldSplitProgramName
            ? $programNameParts->slice($programNameLabels->count())->join(': ')
            : (string) $program->name;
        $displayProgramName = \Illuminate\Support\Str::limit($programNameTitle, 110, '...');
        $assetUrl = function ($asset) {
            $model = $asset->assetable;
            if ($model instanceof \App\Models\Opportunity) {
                return route('app.opportunity-intelligence.opportunities.show', $model);
            }
            if ($model instanceof \App\Models\OpportunityExecutionPlan) {
                return route('app.opportunity-intelligence.execution-plans.show', $model);
            }
            if ($model instanceof \App\Models\Brief) {
                return route('app.content.workspace.show', $model);
            }
            if ($model instanceof \App\Models\Draft) {
                return route('app.drafts.show', $model);
            }
            if ($model instanceof \App\Models\Content) {
                return route('app.content.show', $model);
            }
            if ($model instanceof \App\Models\CampaignCluster) {
                return route('app.agentic-marketing.campaign-clusters.show', $model);
            }
            if ($model instanceof \App\Models\AgenticMarketingOpportunity) {
                return route('app.agentic-marketing.opportunities.execution.show', $model);
            }
            if ($model instanceof \App\Models\ProgrammaticOpportunity) {
                return route('app.programmatic-opportunities.show', $model);
            }
            if ($model instanceof \App\Models\ProgrammaticCluster) {
                return route('app.programmatic-clusters.show', $model);
            }
            if ($model instanceof \App\Models\ProgrammaticBriefBlueprint) {
                return route('app.programmatic-brief-blueprints.show', $model);
            }
            if ($model instanceof \App\Models\ProgrammaticDraftRequest) {
                return route('app.programmatic-draft-requests.show', $model);
            }
            if ($model instanceof \App\Models\ProgrammaticDraftReview) {
                return route('app.programmatic-draft-reviews.show', $model);
            }
            if ($model instanceof \App\Models\ProgrammaticPublicationReadiness) {
                return route('app.programmatic-publication-readiness.show', $model);
            }
            if ($model instanceof \App\Models\ProgrammaticPublicationPlan) {
                return route('app.programmatic-publication-plans.show', $model);
            }
            return null;
        };
        $assetSections = [
            'Opportunities' => [
                \App\Models\GrowthAsset::ROLE_OPPORTUNITY,
                \App\Models\GrowthAsset::ROLE_CONTENT_OPPORTUNITY,
                \App\Models\GrowthAsset::ROLE_COMPETITOR_GAP,
                \App\Models\GrowthAsset::ROLE_AGENTIC_OPPORTUNITY,
            ],
            'Signals' => [\App\Models\GrowthAsset::ROLE_SIGNAL],
            'Programmatic Opportunities' => [\App\Models\GrowthAsset::ROLE_PROGRAMMATIC_OPPORTUNITY],
            'Programmatic Clusters' => [\App\Models\GrowthAsset::ROLE_PROGRAMMATIC_CLUSTER],
            'Brief Blueprints' => [\App\Models\GrowthAsset::ROLE_BRIEF_BLUEPRINT],
            'Draft Requests' => [\App\Models\GrowthAsset::ROLE_DRAFT_REQUEST],
            'Draft Reviews' => [\App\Models\GrowthAsset::ROLE_DRAFT_REVIEW],
            'Publication Readiness' => [\App\Models\GrowthAsset::ROLE_PUBLICATION_READINESS],
            'Publication Plans' => [\App\Models\GrowthAsset::ROLE_PUBLICATION_PLAN],
            'Execution Plans' => [\App\Models\GrowthAsset::ROLE_EXECUTION_PLAN, \App\Models\GrowthAsset::ROLE_CAMPAIGN_CLUSTER],
            'Briefs' => [\App\Models\GrowthAsset::ROLE_BRIEF],
            'Drafts' => [\App\Models\GrowthAsset::ROLE_DRAFT],
            'Content' => [\App\Models\GrowthAsset::ROLE_CONTENT],
            'Publications' => [\App\Models\GrowthAsset::ROLE_PUBLICATION],
        ];
        $programmaticAssetTypeSummary = $assetsByRole
            ->get(\App\Models\GrowthAsset::ROLE_PROGRAMMATIC_CLUSTER, collect())
            ->flatMap(fn ($asset) => $asset->assetable?->items ?? collect())
            ->map(function ($item) {
                if ($item->growth_asset_type instanceof \App\Enums\GrowthAssetType) {
                    return $item->growth_asset_type->value;
                }

                return $item->growth_asset_type ?: 'unclassified';
            })
            ->countBy()
            ->sortDesc();
        $primaryAction = $commandCenter['primary_action'] ?? null;
        $secondaryActions = collect($commandCenter['secondary_actions'] ?? []);
        $lifecycleSteps = $commandCenter['steps'] ?? [];
        $healthItems = $commandCenter['health'] ?? [];
        $timeToValue = $betaMetrics['time_to_value'] ?? [];
        $productMetrics = $betaMetrics['product_metrics'] ?? [];
        $frictionMetrics = $betaMetrics['friction'] ?? [];
        $feedbackMetrics = $betaMetrics['feedback'] ?? [];
        $successScore = (int) ($betaMetrics['success_score'] ?? 0);
        $formatMinutes = function ($minutes) {
            if ($minutes === null) {
                return 'Pending';
            }

            if ((int) $minutes < 60) {
                return (int) $minutes.'m';
            }

            return floor(((int) $minutes) / 60).'h '.(((int) $minutes) % 60).'m';
        };
@endphp

@section('pageHeader')
    <x-page-header :title="$displayProgramName" eyebrow="Growth Program" />
@endsection

@section('pageDescription')
    <x-page-description>{{ $status?->label() ?? $program->status }}@if ($program->description) · {{ $program->description }}@endif</x-page-description>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Status" :value="$status?->label() ?? $program->status" />
        <x-metric-card label="Program" :value="$programNameLabels->isNotEmpty() ? $programNameLabels->join(' · ') : 'Growth Program'" />
        <x-metric-card label="Assets" :value="$program->assets->count()" />
    </x-metric-section>
@endsection

@section('content')

    <div class="space-y-6">
        @include('app.programmatic-growth._beta-banner')

        @if ($canUseInternalBetaMode)
            <section class="rounded-lg border border-border bg-surface p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-textPrimary">Internal Beta Tester Mode</p>
                        <p class="mt-1 text-xs text-textSecondary">Shows extra context, tester prompts and beta report access.</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('app.programmatic-growth.beta-report') }}" class="rounded-md border border-border bg-background px-3 py-2 text-xs font-medium text-textPrimary">Open Beta Report</a>
                        <form method="POST" action="{{ route('app.programmatic-growth.internal-beta-mode') }}">
                            @csrf
                            <input type="hidden" name="enabled" value="{{ $internalBetaMode ? 0 : 1 }}">
                            <button class="rounded-md {{ $internalBetaMode ? 'border border-border bg-background text-textPrimary' : 'bg-primary text-white' }} px-3 py-2 text-xs font-semibold">
                                {{ $internalBetaMode ? 'Disable' : 'Enable' }}
                            </button>
                        </form>
                    </div>
                </div>
            </section>
        @endif

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2 text-xs text-textSecondary">
                    <span>Growth Program</span>
                    <span>·</span>
                    <span>{{ $status?->label() ?? $program->status }}</span>
                    @foreach ($programNameLabels as $label)
                        <span class="rounded-full border border-border bg-background px-2 py-0.5 font-medium text-textSecondary">{{ $label }}</span>
                    @endforeach
                </div>
                @if ($program->description)
                    <p class="mt-2 max-w-3xl text-textSecondary">{{ $program->description }}</p>
                @endif
            </div>
        </div>

        <section class="rounded-lg border border-border bg-surface p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Command Center</p>
                    <h2 class="mt-1 text-lg font-semibold text-textPrimary">{{ $primaryAction['label'] ?? 'No action available' }}</h2>
                    <p class="mt-2 max-w-3xl text-sm text-textSecondary">{{ $primaryAction['helper'] ?? 'This program is ready for monitoring.' }}</p>
                    @if ($internalBetaMode)
                        <p class="mt-2 max-w-3xl rounded-md border border-border bg-background px-3 py-2 text-xs text-textSecondary">Checklist: docs/programmatic-growth-beta-test-checklist.md · Validate whether the next action is obvious before clicking.</p>
                    @endif
                    @if (! empty($primaryAction['missing']))
                        <p class="mt-2 text-xs text-amber-800">{{ implode(' ', $primaryAction['missing']) }}</p>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($primaryAction && ! empty($primaryAction['route']) && auth()->user()?->can($primaryAction['ability'] ?? 'prepare', $program))
                        <form method="{{ $primaryAction['method'] ?? 'POST' }}" action="{{ $primaryAction['route'] }}">
                            @csrf
                            <button class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white">{{ $primaryAction['label'] }}</button>
                        </form>
                    @endif
                    @can('prepare', $program)
                        <form method="POST" action="{{ route('app.growth-programs.transition', $program) }}" class="flex flex-wrap items-center gap-2">
                            @csrf
                            <select name="status" class="pl-select bg-background">
                                @foreach (\App\Enums\GrowthProgramStatus::cases() as $item)
                                    <option value="{{ $item->value }}" @selected($status === $item)>{{ $item->label() }}</option>
                                @endforeach
                            </select>
                            <button class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">Update</button>
                        </form>
                    @endcan
                </div>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($secondaryActions as $action)
                    @if (auth()->user()?->can($action['ability'] ?? 'prepare', $program))
                        <form method="POST" action="{{ $action['route'] }}">
                            @csrf
                            <button class="rounded-md border border-border bg-background px-3 py-2 text-xs font-medium text-textPrimary">{{ $action['label'] }}</button>
                        </form>
                    @endif
                @endforeach
            </div>
        </section>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif
        @if ($errors->any())
            <x-alert type="error">{{ $errors->first() }}</x-alert>
        @endif

        <div class="rounded-lg border border-border bg-surface p-4 text-sm text-textSecondary">
            <span class="font-medium text-textPrimary">Generation safety:</span>
            {{ (int) ($metrics['approved_draft_requests_count'] ?? 0) }} approved requests ·
            {{ number_format((int) ($metrics['estimated_generation_tokens'] ?? 0)) }} estimated tokens ·
            €{{ number_format((float) ($metrics['estimated_generation_cost'] ?? 0), 4) }} estimated cost ·
            batch generation {{ config('argusly_programmatic.allow_batch_generation') ? 'allowed' : 'disabled' }} ·
            {{ (int) ($metrics['approved_draft_reviews_count'] ?? 0) }} approved quality checks ·
            {{ (int) ($metrics['converted_content_count'] ?? 0) }} converted content ·
            {{ (int) ($metrics['publication_ready_content_count'] ?? 0) }} publication ready ·
            {{ (int) ($metrics['scheduled_programmatic_publications_count'] ?? 0) }} scheduled assets.
        </div>

        <section class="rounded-lg border border-border bg-surface p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-textPrimary">Health</h2>
                <span class="text-xs text-textSecondary">Safety and traceability</span>
            </div>
            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($healthItems as $item)
                    <div class="rounded-md border {{ in_array($item['status'], ['blocked', 'incomplete'], true) ? 'border-amber-200 bg-amber-50' : 'border-border bg-background' }} p-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">{{ $item['label'] }}</p>
                            <span class="text-xs font-medium {{ in_array($item['status'], ['blocked', 'incomplete'], true) ? 'text-amber-900' : 'text-textPrimary' }}">{{ str($item['status'])->headline() }}</span>
                        </div>
                        <p class="mt-2 text-xs text-textSecondary">{{ $item['detail'] }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Impact score</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((float) $program->score, 1) }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Success score</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ $successScore }}%</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Estimated reach</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((float) ($metrics['estimated_reach'] ?? 0), 0) }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Estimated traffic</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((float) ($metrics['estimated_traffic'] ?? 0), 0) }}</p>
            </div>
        </div>

        <section class="rounded-lg border border-border bg-surface p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-textPrimary">Time To Value</h2>
                <span class="text-xs text-textSecondary">Measured from Growth Program creation</span>
            </div>
            <div class="mt-4 grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                @foreach ([
                    'first_cluster_minutes' => 'First cluster',
                    'first_blueprint_minutes' => 'First blueprint',
                    'first_brief_minutes' => 'First brief',
                    'first_draft_minutes' => 'First draft',
                    'first_content_asset_minutes' => 'First content asset',
                    'first_scheduled_publication_record_minutes' => 'First scheduled publication record',
                ] as $key => $label)
                    <div class="rounded-md border border-border bg-background p-3">
                        <p class="text-xs text-textSecondary">{{ $label }}</p>
                        <p class="mt-1 text-lg font-semibold text-textPrimary">{{ $formatMinutes($timeToValue[$key] ?? null) }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <div class="rounded-lg border border-border bg-surface p-5">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="text-sm font-semibold text-textPrimary">Progress</h2>
                <span class="text-xs text-textSecondary">{{ $program->progress() }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-background">
                <div class="h-full rounded-full bg-primary" style="width: {{ $program->progress() }}%"></div>
            </div>
            <div class="mt-4 grid gap-2 md:grid-cols-3 xl:grid-cols-6">
                @foreach ($lifecycleSteps as $step)
                    <div class="rounded-md border {{ $step['status'] === 'blocked' ? 'border-amber-200 bg-amber-50' : ($step['status'] === 'complete' ? 'border-primary/40 bg-primary/5' : 'border-border bg-background') }} p-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs font-semibold text-textPrimary">{{ $step['label'] }}</p>
                            <span class="text-xs text-textSecondary">{{ $step['count'] }}</span>
                        </div>
                        <p class="mt-1 text-xs {{ $step['status'] === 'blocked' ? 'text-amber-900' : 'text-textSecondary' }}">{{ str($step['status'])->headline() }}</p>
                        <p class="mt-2 text-xs text-textMuted">{{ $step['next_action'] }}</p>
                        @if ($step['blocked_reason'])
                            <p class="mt-2 rounded border border-amber-200 bg-amber-100/60 px-2 py-1 text-xs text-amber-900">{{ $step['blocked_reason'] }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Mapped Workflow</h2>
                    <div class="mt-4 space-y-5">
                        @foreach ($assetSections as $sectionLabel => $roles)
                            @php($sectionAssets = collect($roles)->flatMap(fn ($role) => $assetsByRole->get($role, collect())))
                            <div>
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-textSecondary">{{ $sectionLabel }}</h3>
                                <div class="mt-2 space-y-2">
                                    @forelse ($sectionAssets as $asset)
                                        @php($model = $asset->assetable)
                                        @php($url = $assetUrl($asset))
                                        <div class="rounded-md border border-border bg-background p-3">
                                            <div class="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <p class="text-sm font-medium text-textPrimary">
                                                        @if ($url)
                                                            <a href="{{ $url }}" class="hover:text-primary">{{ $model->title ?? $model->name ?? class_basename($asset->assetable_type) }}</a>
                                                        @else
                                                            {{ $model->title ?? $model->name ?? class_basename($asset->assetable_type) }}
                                                        @endif
                                                    </p>
                                                    <p class="text-xs text-textSecondary">{{ class_basename($asset->assetable_type) }} · {{ $asset->status_at_link ?: 'linked' }}</p>
                                                </div>
                                                <span class="text-xs text-textSecondary">{{ optional($asset->created_at)->format('Y-m-d H:i') }}</span>
                                            </div>
                                        </div>
                                    @empty
                                        <p class="rounded-md border border-dashed border-border bg-background px-3 py-2 text-sm text-textMuted">No linked {{ strtolower($sectionLabel) }} yet.</p>
                                    @endforelse
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Draft Quality Checks</h2>
                    <div class="mt-4 grid gap-3 md:grid-cols-4 text-sm">
                        <div><p class="text-xs text-textSecondary">Quality checks</p><p class="mt-1 font-semibold text-textPrimary">{{ (int) ($metrics['draft_reviews_count'] ?? 0) }}</p></div>
                        <div><p class="text-xs text-textSecondary">Approved</p><p class="mt-1 font-semibold text-textPrimary">{{ (int) ($metrics['approved_draft_reviews_count'] ?? 0) }}</p></div>
                        <div><p class="text-xs text-textSecondary">Blocked</p><p class="mt-1 font-semibold text-textPrimary">{{ (int) ($metrics['blocked_draft_reviews_count'] ?? 0) }}</p></div>
                        <div><p class="text-xs text-textSecondary">Avg quality</p><p class="mt-1 font-semibold text-textPrimary">{{ number_format((float) ($metrics['average_draft_quality_score'] ?? 0), 1) }}</p></div>
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse ($assetsByRole->get(\App\Models\GrowthAsset::ROLE_DRAFT_REVIEW, collect()) as $asset)
                            @php($review = $asset->assetable)
                            <a href="{{ route('app.programmatic-draft-reviews.show', $review) }}" class="block rounded-md border {{ $review?->status === \App\Models\ProgrammaticDraftReview::STATUS_BLOCKED ? 'border-amber-200 bg-amber-50' : 'border-border bg-background' }} p-3 hover:bg-surfaceMuted">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <p class="text-sm font-medium text-textPrimary">{{ $review?->draft?->title }}</p>
                                    <p class="text-xs text-textSecondary">{{ str($review?->status)->headline() }} · {{ number_format((float) ($review?->overall_score ?? 0), 1) }}</p>
                                </div>
                                @if ($review?->status === \App\Models\ProgrammaticDraftReview::STATUS_BLOCKED)
                                    <p class="mt-2 text-xs text-amber-900">{{ data_get($review->blocking_issues, '0.message') ?: data_get($review->blocking_issues, '0') ?: 'Blocked quality check needs attention.' }}</p>
                                @endif
                            </a>
                        @empty
                            <p class="rounded-md border border-dashed border-border bg-background px-3 py-2 text-sm text-textMuted">No quality checks yet. Create approved drafts, then run draft quality checks.</p>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-textPrimary">Publication Readiness</h2>
                        <form method="POST" action="{{ route('app.growth-programs.publication-readiness', $program) }}">
                            @csrf
                            <button class="rounded-md border border-border bg-background px-3 py-2 text-xs font-medium text-textPrimary">Run Publication Readiness</button>
                        </form>
                    </div>
                    <div class="mt-4 grid gap-3 md:grid-cols-4 text-sm">
                        <div><p class="text-xs text-textSecondary">Checks</p><p class="mt-1 font-semibold text-textPrimary">{{ (int) ($metrics['publication_readiness_count'] ?? 0) }}</p></div>
                        <div><p class="text-xs text-textSecondary">Approved</p><p class="mt-1 font-semibold text-textPrimary">{{ (int) ($metrics['approved_publication_readiness_count'] ?? 0) }}</p></div>
                        <div><p class="text-xs text-textSecondary">Blocked</p><p class="mt-1 font-semibold text-textPrimary">{{ (int) ($metrics['blocked_publication_readiness_count'] ?? 0) }}</p></div>
                        <div><p class="text-xs text-textSecondary">Avg readiness</p><p class="mt-1 font-semibold text-textPrimary">{{ number_format((float) ($metrics['average_publication_readiness_score'] ?? 0), 1) }}</p></div>
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse ($assetsByRole->get(\App\Models\GrowthAsset::ROLE_PUBLICATION_READINESS, collect()) as $asset)
                            @php($readiness = $asset->assetable)
                            <a href="{{ route('app.programmatic-publication-readiness.show', $readiness) }}" class="block rounded-md border {{ $readiness?->status === \App\Models\ProgrammaticPublicationReadiness::STATUS_BLOCKED ? 'border-amber-200 bg-amber-50' : 'border-border bg-background' }} p-3 hover:bg-surfaceMuted">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <p class="text-sm font-medium text-textPrimary">{{ $readiness?->content?->title }}</p>
                                    <p class="text-xs text-textSecondary">{{ str($readiness?->status)->headline() }} · {{ number_format((float) ($readiness?->readiness_score ?? 0), 1) }}</p>
                                </div>
                                @if ($readiness?->status === \App\Models\ProgrammaticPublicationReadiness::STATUS_BLOCKED)
                                    <p class="mt-2 text-xs text-amber-900">{{ data_get($readiness->missing_requirements, '0.message') ?: data_get($readiness->missing_requirements, '0') ?: 'Blocked publication readiness needs attention.' }}</p>
                                @endif
                            </a>
                        @empty
                            <p class="rounded-md border border-dashed border-border bg-background px-3 py-2 text-sm text-textMuted">No publication readiness yet. Convert approved quality checks to content first.</p>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-textPrimary">Publication Plans</h2>
                        <form method="POST" action="{{ route('app.growth-programs.publication-plans.create', $program) }}">
                            @csrf
                            <button class="rounded-md border border-border bg-background px-3 py-2 text-xs font-medium text-textPrimary">Create Publication Plan</button>
                        </form>
                        <form method="POST" action="{{ route('app.growth-programs.publication-plans.schedule', $program) }}">
                            @csrf
                            <button class="rounded-md border border-border bg-background px-3 py-2 text-xs font-medium text-textPrimary">Prepare Scheduled Publications</button>
                        </form>
                    </div>
                    <p class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">This prepares scheduled publication assets. It does not publish content live.</p>
                    <div class="mt-4 grid gap-3 md:grid-cols-5 text-sm">
                        <div><p class="text-xs text-textSecondary">Plans</p><p class="mt-1 font-semibold text-textPrimary">{{ (int) ($metrics['publication_plans_count'] ?? 0) }}</p></div>
                        <div><p class="text-xs text-textSecondary">Plan items</p><p class="mt-1 font-semibold text-textPrimary">{{ (int) ($metrics['publication_plan_items_count'] ?? 0) }}</p></div>
                        <div><p class="text-xs text-textSecondary">Approved items</p><p class="mt-1 font-semibold text-textPrimary">{{ (int) ($metrics['approved_publication_plan_items_count'] ?? 0) }}</p></div>
                        <div><p class="text-xs text-textSecondary">Scheduled assets</p><p class="mt-1 font-semibold text-textPrimary">{{ (int) ($metrics['scheduled_programmatic_publications_count'] ?? 0) }}</p></div>
                        <div><p class="text-xs text-textSecondary">Pending assets</p><p class="mt-1 font-semibold text-textPrimary">{{ (int) ($metrics['pending_programmatic_publications_count'] ?? 0) }}</p></div>
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse ($assetsByRole->get(\App\Models\GrowthAsset::ROLE_PUBLICATION_PLAN, collect()) as $asset)
                            @php($plan = $asset->assetable)
                            @php($conflicts = $plan?->items?->filter(fn ($item) => data_get($item->metadata, 'conflict.reason')) ?? collect())
                            <a href="{{ route('app.programmatic-publication-plans.show', $plan) }}" class="block rounded-md border {{ $conflicts->isNotEmpty() ? 'border-amber-200 bg-amber-50' : 'border-border bg-background' }} p-3 hover:bg-surfaceMuted">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <p class="text-sm font-medium text-textPrimary">{{ $plan?->name }}</p>
                                    <p class="text-xs text-textSecondary">{{ str($plan?->status)->headline() }} · {{ $plan?->items?->count() ?? 0 }} items</p>
                                </div>
                                @if ($conflicts->isNotEmpty())
                                    <div class="mt-2 space-y-1">
                                        @foreach ($conflicts->take(3) as $item)
                                            <p class="text-xs text-amber-900">
                                                {{ match (data_get($item->metadata, 'conflict.reason')) {
                                                    'missing_destination' => 'Missing destination: choose a destination before preparing scheduled publications.',
                                                    'existing_publication_terminal' => 'Terminal publication conflict: existing delivered or published asset will not be changed.',
                                                    'content_already_scheduled_in_active_plan' => 'Active plan conflict: this content is already scheduled in another active plan.',
                                                    default => str(data_get($item->metadata, 'conflict.reason', 'conflict'))->replace('_', ' ')->headline(),
                                                } }}
                                            </p>
                                        @endforeach
                                    </div>
                                @endif
                            </a>
                        @empty
                            <p class="rounded-md border border-dashed border-border bg-background px-3 py-2 text-sm text-textMuted">No publication plan yet. Run publication readiness first, then create a plan from approved readiness.</p>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Draft Requests</h2>
                    <div class="mt-4 space-y-2">
                        @forelse ($assetsByRole->get(\App\Models\GrowthAsset::ROLE_DRAFT_REQUEST, collect()) as $asset)
                            @php($draftRequest = $asset->assetable)
                            @php($type = $draftRequest?->growth_asset_type instanceof \App\Enums\GrowthAssetType ? $draftRequest->growth_asset_type : \App\Enums\GrowthAssetType::tryFrom((string) $draftRequest?->growth_asset_type))
                            <a href="{{ route('app.programmatic-draft-requests.show', $draftRequest) }}" class="block rounded-md border border-border bg-background p-3 hover:bg-surfaceMuted">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-medium text-textPrimary">{{ $draftRequest?->title }}</p>
                                        <p class="mt-1 text-xs text-textSecondary">{{ $type?->label() ?? $draftRequest?->growth_asset_type }} · {{ str($draftRequest?->status)->headline() }} · {{ $draftRequest?->generation_mode }}</p>
                                    </div>
                                    <div class="text-right text-xs text-textSecondary">
                                        <p>{{ number_format((int) ($draftRequest?->estimated_tokens ?? 0)) }} tokens</p>
                                        <p>€{{ number_format((float) ($draftRequest?->estimated_cost ?? 0), 4) }}</p>
                                    </div>
                                </div>
                            </a>
                        @empty
                            <p class="rounded-md border border-dashed border-border bg-background px-3 py-2 text-sm text-textMuted">No draft requests yet. Prepare draft requests from converted briefs.</p>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Programmatic Opportunities</h2>
                    <div class="mt-4 space-y-2">
                        @forelse ($assetsByRole->get(\App\Models\GrowthAsset::ROLE_PROGRAMMATIC_OPPORTUNITY, collect()) as $asset)
                            @php($item = $asset->assetable)
                            @php($pattern = $item?->pattern_type instanceof \App\Enums\ProgrammaticPatternType ? $item->pattern_type : \App\Enums\ProgrammaticPatternType::tryFrom((string) $item?->pattern_type))
                            <a href="{{ route('app.programmatic-opportunities.show', $item) }}" class="block rounded-md border border-border bg-background p-3 hover:bg-surfaceMuted">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-medium text-textPrimary">{{ $pattern?->label() ?? $item?->pattern_type }}</p>
                                        <p class="mt-1 text-xs text-textSecondary">{{ $item?->base_topic }} · {{ $item?->variable_axis ?: 'n/a' }}</p>
                                    </div>
                                    <div class="text-right text-xs text-textSecondary">
                                        <p>{{ $item?->estimated_variants_count ?? 'n/a' }} variants</p>
                                        <p>Scale {{ $item?->scale_score === null ? 'n/a' : number_format((float) $item->scale_score, 1) }}</p>
                                        <p>AI {{ $item?->ai_visibility_score === null ? 'n/a' : number_format((float) $item->ai_visibility_score, 1) }}</p>
                                        <p>Confidence {{ $item?->confidence_score === null ? 'n/a' : number_format((float) $item->confidence_score, 1) }}</p>
                                    </div>
                                </div>
                            </a>
                        @empty
                            <p class="rounded-md border border-dashed border-border bg-background px-3 py-2 text-sm text-textMuted">No programmatic opportunities yet. Detect programmatic opportunities from attached opportunities or signals.</p>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Programmatic Clusters</h2>
                    @if ($programmaticAssetTypeSummary->isNotEmpty())
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach ($programmaticAssetTypeSummary as $type => $count)
                                @php($resolvedType = \App\Enums\GrowthAssetType::tryFrom((string) $type))
                                <span class="rounded-full border border-border bg-background px-3 py-1 text-xs font-medium text-textSecondary">
                                    {{ $count }} {{ str($resolvedType?->label() ?? $type)->plural((int) $count) }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                    <div class="mt-4 space-y-2">
                        @forelse ($assetsByRole->get(\App\Models\GrowthAsset::ROLE_PROGRAMMATIC_CLUSTER, collect()) as $asset)
                            @php($cluster = $asset->assetable)
                            @php($pattern = $cluster?->pattern_type instanceof \App\Enums\ProgrammaticPatternType ? $cluster->pattern_type : \App\Enums\ProgrammaticPatternType::tryFrom((string) $cluster?->pattern_type))
                            <a href="{{ route('app.programmatic-clusters.show', $cluster) }}" class="block rounded-md border border-border bg-background p-3 hover:bg-surfaceMuted">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-medium text-textPrimary">{{ $cluster?->name }}</p>
                                        <p class="mt-1 text-xs text-textSecondary">{{ $pattern?->label() ?? $cluster?->pattern_type }} · {{ $cluster?->estimated_assets_count }} items · {{ $cluster?->status }}</p>
                                    </div>
                                    <div class="text-right text-xs text-textSecondary">
                                        <p>Reach {{ $cluster?->estimated_reach === null ? 'n/a' : number_format((float) $cluster->estimated_reach, 0) }}</p>
                                        <p>AI {{ $cluster?->estimated_ai_visibility === null ? 'n/a' : number_format((float) $cluster->estimated_ai_visibility, 1) }}</p>
                                        <p>Impact {{ $cluster?->estimated_business_impact === null ? 'n/a' : number_format((float) $cluster->estimated_business_impact, 1) }}</p>
                                    </div>
                                </div>
                            </a>
                        @empty
                            <p class="rounded-md border border-dashed border-border bg-background px-3 py-2 text-sm text-textMuted">No cluster preview yet. Build a cluster preview from a programmatic opportunity.</p>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Brief Blueprints</h2>
                    <div class="mt-4 space-y-2">
                        @forelse ($assetsByRole->get(\App\Models\GrowthAsset::ROLE_BRIEF_BLUEPRINT, collect()) as $asset)
                            @php($blueprint = $asset->assetable)
                            @php($type = $blueprint?->growth_asset_type instanceof \App\Enums\GrowthAssetType ? $blueprint->growth_asset_type : \App\Enums\GrowthAssetType::tryFrom((string) $blueprint?->growth_asset_type))
                            <a href="{{ route('app.programmatic-brief-blueprints.show', $blueprint) }}" class="block rounded-md border border-border bg-background p-3 hover:bg-surfaceMuted">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-medium text-textPrimary">{{ $blueprint?->title }}</p>
                                        <p class="mt-1 text-xs text-textSecondary">{{ $type?->label() ?? $blueprint?->growth_asset_type }} · {{ $blueprint?->intent ?: 'n/a' }} · {{ $blueprint?->primary_keyword ?: 'n/a' }}</p>
                                        <p class="mt-1 text-xs text-textMuted">{{ $blueprint?->cluster?->name }}</p>
                                    </div>
                                    <div class="text-right text-xs text-textSecondary">
                                        <p>{{ str($blueprint?->status)->headline() }}</p>
                                        <p>{{ $blueprint?->readinessPercentage() ?? 0 }}% readiness</p>
                                    </div>
                                </div>
                            </a>
                        @empty
                            <p class="rounded-md border border-dashed border-border bg-background px-3 py-2 text-sm text-textMuted">No approved blueprints yet. Build, review and approve blueprints before creating briefs.</p>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Runs</h2>
                    <div class="mt-4 space-y-2">
                        @forelse ($program->runs as $run)
                            <div class="rounded-md border border-border bg-background p-3 text-sm">
                                <div class="flex flex-wrap justify-between gap-2">
                                    <span class="font-medium text-textPrimary">{{ str_replace('_', ' ', (string) $run->triggered_by) }}</span>
                                    <span class="text-xs text-textSecondary">{{ strtoupper($run->status) }}</span>
                                </div>
                                <p class="mt-1 text-xs text-textSecondary">{{ $run->stage }} · {{ optional($run->started_at)->format('Y-m-d H:i') }}</p>
                            </div>
                        @empty
                            <p class="text-sm text-textSecondary">No runs yet.</p>
                        @endforelse
                    </div>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Metrics</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-3">
                            <dt class="text-textSecondary">Growth Program Success Score</dt>
                            <dd class="font-medium text-textPrimary">{{ $successScore }}%</dd>
                        </div>
                        @foreach ([
                            'opportunities_detected' => 'Opportunities detected',
                            'opportunities_converted' => 'Opportunities converted',
                            'clusters_created' => 'Clusters created',
                            'blueprints_created' => 'Blueprints created',
                            'briefs_created' => 'Briefs created',
                            'drafts_generated' => 'Drafts created',
                            'content_created' => 'Content assets created',
                            'readiness_approved' => 'Publication readiness approved',
                            'publication_plans_created' => 'Publication plans created',
                        ] as $key => $label)
                            <div class="flex justify-between gap-3">
                                <dt class="text-textSecondary">{{ $label }}</dt>
                                <dd class="font-medium text-textPrimary">{{ (int) ($productMetrics[$key] ?? 0) }}</dd>
                            </div>
                        @endforeach
                        @foreach ([
                            'opportunities_count' => 'Opportunities',
                            'signals_count' => 'Signals',
                            'execution_plans_count' => 'Execution plans',
                            'programmatic_opportunities_count' => 'Programmatic opportunities',
                            'programmatic_clusters_count' => 'Programmatic clusters',
                            'programmatic_cluster_items_count' => 'Cluster items',
                            'accepted_cluster_items_count' => 'Accepted cluster items',
                            'rejected_cluster_items_count' => 'Rejected cluster items',
                            'brief_blueprints_count' => 'Brief blueprints',
                            'approved_brief_blueprints_count' => 'Approved blueprints',
                            'rejected_brief_blueprints_count' => 'Rejected blueprints',
                            'converted_blueprints_count' => 'Converted blueprints',
                            'programmatic_briefs_count' => 'Programmatic briefs',
                            'blueprint_readiness_percentage' => 'Blueprint readiness',
                            'draft_requests_count' => 'Draft requests',
                            'approved_draft_requests_count' => 'Approved draft requests',
                            'queued_draft_requests_count' => 'Queued draft requests',
                            'generated_draft_requests_count' => 'Created draft requests',
                            'generated_programmatic_drafts_count' => 'Created drafts',
                            'failed_programmatic_drafts_count' => 'Failed draft requests',
                            'queued_programmatic_drafts_count' => 'Queued programmatic drafts',
                            'estimated_generation_tokens' => 'Estimated tokens',
                            'estimated_generation_cost' => 'Estimated cost',
                            'actual_generation_tokens' => 'Actual tokens',
                            'actual_generation_cost' => 'Actual cost',
                            'draft_reviews_count' => 'Quality checks',
                            'approved_draft_reviews_count' => 'Approved checks',
                            'blocked_draft_reviews_count' => 'Blocked checks',
                            'average_draft_quality_score' => 'Avg quality',
                            'average_seo_score' => 'Avg SEO',
                            'average_ai_visibility_score' => 'Avg AI',
                            'average_risk_score' => 'Avg risk',
                            'programmatic_content_count' => 'Programmatic content',
                            'converted_content_count' => 'Converted content',
                            'content_ready_for_publication_count' => 'Ready content',
                            'publication_readiness_count' => 'Publication readiness',
                            'approved_publication_readiness_count' => 'Approved readiness',
                            'blocked_publication_readiness_count' => 'Blocked readiness',
                            'average_publication_readiness_score' => 'Avg readiness',
                            'publication_ready_content_count' => 'Publication ready',
                            'publication_plans_count' => 'Publication plans',
                            'publication_plan_items_count' => 'Plan items',
                            'approved_publication_plan_items_count' => 'Approved plan items',
                            'scheduled_programmatic_publications_count' => 'Scheduled assets',
                            'pending_programmatic_publications_count' => 'Pending assets',
                            'briefs_count' => 'Briefs',
                            'drafts_count' => 'Drafts',
                            'publications_count' => 'Publications',
                            'scheduled_count' => 'Scheduled',
                            'published_count' => 'Published',
                            'measured_count' => 'Measured',
                            'progress_percentage' => 'Progress',
                        ] as $key => $label)
                            <div class="flex justify-between gap-3">
                                <dt class="text-textSecondary">{{ $label }}</dt>
                                <dd class="font-medium text-textPrimary">
                                    @if ($key === 'estimated_generation_cost')
                                        €{{ number_format((float) ($metrics[$key] ?? 0), 4) }}
                                    @else
                                        {{ (int) ($metrics[$key] ?? 0) }}{{ in_array($key, ['progress_percentage', 'blueprint_readiness_percentage'], true) ? '%' : '' }}
                                    @endif
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Recommended Next Action</h2>
                    <p class="mt-3 text-sm leading-6 text-textSecondary">{{ $metrics['next_recommended_action'] ?? 'No next action currently recommended.' }}</p>
                    <p class="mt-2 text-xs text-textMuted">Current stage: {{ $metrics['current_stage_label'] ?? ($status?->label() ?? $program->status) }}</p>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Beta Friction</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        @foreach ([
                            'workflow_abandoned' => 'Abandoned workflows',
                            'back_navigation' => 'Back actions',
                            'action_failed' => 'Failed actions',
                            'conflict' => 'Conflicts',
                            'blocked' => 'Blockers',
                            'cancel' => 'Cancel actions',
                        ] as $key => $label)
                            <div class="flex justify-between gap-3">
                                <dt class="text-textSecondary">{{ $label }}</dt>
                                <dd class="font-medium text-textPrimary">{{ (int) ($frictionMetrics[$key] ?? 0) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Was this step clear?</h2>
                    <form method="POST" action="{{ route('app.growth-programs.feedback', $program) }}" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="step" value="{{ $commandCenter['current_stage']['label'] ?? ($metrics['current_stage_label'] ?? '') }}">
                        <div class="grid grid-cols-3 gap-2">
                            @foreach (['yes' => 'Yes', 'somewhat' => 'Somewhat', 'no' => 'No'] as $value => $label)
                                <label class="flex items-center justify-center rounded-md border border-border bg-background px-2 py-2 text-xs font-medium text-textPrimary">
                                    <input type="radio" name="clarity" value="{{ $value }}" class="sr-only" @checked($loop->first)>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                        <textarea name="message" rows="3" class="pl-input w-full bg-background text-sm" placeholder="Optional note"></textarea>
                        <button class="w-full rounded-md bg-primary px-3 py-2 text-xs font-semibold text-white">Save Feedback</button>
                    </form>
                    <p class="mt-3 text-xs text-textSecondary">{{ (int) ($feedbackMetrics['total'] ?? 0) }} responses · {{ (int) ($feedbackMetrics['yes'] ?? 0) }} yes · {{ (int) ($feedbackMetrics['somewhat'] ?? 0) }} somewhat · {{ (int) ($feedbackMetrics['no'] ?? 0) }} no</p>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Timeline</h2>
                    <div class="mt-4 space-y-3">
                        @foreach ($timeline as $item)
                            <div class="flex gap-3 text-sm">
                                <div class="mt-1 h-2.5 w-2.5 rounded-full {{ $item['complete'] ? 'bg-primary' : 'bg-border' }}"></div>
                                <div>
                                    <p class="font-medium text-textPrimary">{{ $item['label'] }}</p>
                                    <p class="text-xs text-textSecondary">{{ $item['at'] ? $item['at']->format('Y-m-d H:i') : 'Pending' }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            </aside>
        </div>
    </div>
@endsection
