@extends('layouts.app', ['title' => 'Brand Growth Plan', 'pageWidth' => 'wide'])

@php
    $status = $plan->status?->value ?? $plan->status;
    $brandGrowthBriefs = collect($brandGrowthBriefs ?? []);
    $approvedFindingCount = $plan->findings
        ->filter(fn ($finding): bool => ($finding->review_state?->value ?? $finding->review_state) === 'approved')
        ->count();
    $approvedAudienceCount = $plan->audienceProposals
        ->filter(fn ($proposal): bool => ($proposal->review_state?->value ?? $proposal->review_state) === 'approved')
        ->count();
    $approvedUnpromotedFindingCount = $plan->findings
        ->filter(fn ($finding): bool => ($finding->review_state?->value ?? $finding->review_state) === 'approved' && blank($finding->opportunity_id))
        ->count();
    $approvedUnpromotedAudienceCount = $plan->audienceProposals
        ->filter(fn ($proposal): bool => ($proposal->review_state?->value ?? $proposal->review_state) === 'approved' && blank($proposal->persona_id))
        ->count();
    $promotablePlanItemCount = $approvedUnpromotedFindingCount + $approvedUnpromotedAudienceCount;
    $promotedAudienceCount = max(0, $approvedAudienceCount - $approvedUnpromotedAudienceCount);
    $promotedFindingOpportunities = $plan->findings
        ->filter(fn ($finding): bool => ($finding->review_state?->value ?? $finding->review_state) === 'approved' && filled($finding->opportunity_id) && $finding->opportunity)
        ->map(fn ($finding) => $finding->opportunity)
        ->unique(fn ($opportunity): string => (string) $opportunity->id)
        ->values();
    $promotedOpportunityCount = $promotedFindingOpportunities->count();
    $brandGrowthExecutionRecommendations = $promotedFindingOpportunities
        ->flatMap(fn ($opportunity) => collect($opportunity->activeExecutionPlans ?? []))
        ->unique(fn ($executionPlan): string => (string) $executionPlan->id)
        ->values();
    $executionRecommendationCount = $brandGrowthExecutionRecommendations->count();
    $briefableExecutionRecommendationCount = $brandGrowthExecutionRecommendations
        ->filter(fn ($executionPlan): bool => in_array((string) $executionPlan->status, ['approved', 'planned'], true) && blank(data_get($executionPlan->metadata, 'brief_id')))
        ->count();
    $contentBriefCount = $brandGrowthExecutionRecommendations
        ->filter(fn ($executionPlan): bool => filled(data_get($executionPlan->metadata, 'brief_id')))
        ->count();
    $draftReadyBriefCount = $brandGrowthBriefs
        ->filter(fn ($brief): bool => in_array((string) $brief->status, ['draft', 'approved'], true) && blank(data_get($brief->client_refs, 'draft_id')))
        ->count();
    $contentDraftCount = $brandGrowthBriefs
        ->filter(fn ($brief): bool => filled(data_get($brief->client_refs, 'draft_id')))
        ->count();
    $executionRecommendationsNeedingApprovalCount = $brandGrowthExecutionRecommendations
        ->filter(fn ($executionPlan): bool => ! in_array((string) $executionPlan->status, ['approved', 'planned'], true) && blank(data_get($executionPlan->metadata, 'brief_id')))
        ->count();
    $promotedOpportunitiesMissingExecutionRecommendationCount = $promotedFindingOpportunities
        ->filter(fn ($opportunity): bool => collect($opportunity->activeExecutionPlans ?? [])->isEmpty())
        ->count();
    $stateClass = [
        'pending' => 'border-amber-200 bg-amber-50 text-amber-900',
        'approved' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
        'rejected' => 'border-rose-200 bg-rose-50 text-rose-800',
        'superseded' => 'border-slate-200 bg-slate-50 text-slate-700',
    ];
    $listText = function (mixed $items): string {
        return collect(\Illuminate\Support\Arr::wrap($items))
            ->map(function (mixed $item): string {
                if (is_array($item)) {
                    return (string) ($item['title'] ?? $item['action'] ?? $item['recommended_action'] ?? $item['summary'] ?? json_encode($item, JSON_UNESCAPED_SLASHES));
                }

                return (string) $item;
            })
            ->filter()
            ->implode("\n");
    };
    $planDiff = $planDiff ?? [];
    $baselineDiffPlan = data_get($planDiff, 'baseline');
    $findingChangeStates = collect(data_get($planDiff, 'findings.states', []));
    $audienceChangeStates = collect(data_get($planDiff, 'audiences.states', []));
    $versionChangeSummary = data_get($planDiff, 'summary', []);
    $isCurrentApprovedBaseline = $status === 'approved' && (string) ($currentApprovedPlanId ?? '') === (string) $plan->id;
    $isSupersededBaseline = $status === 'superseded' && filled($plan->approved_at);
@endphp

@section('pageHeader')
    <x-page-header title="Brand Growth Plan v{{ $plan->version }}" />
@endsection

@section('pageDescription')
    <x-page-description>{{ $plan->business_objective ?: 'Strategic snapshot for governed brand growth planning.' }}</x-page-description>
@endsection

@section('primaryActions')
    <a href="{{ route('app.agentic-marketing.brand-growth-plans.index', ['workspace_id' => $workspace->id]) }}" class="pl-btn-ghost">
        <i data-lucide="arrow-left" class="h-4 w-4"></i>
        <span>Plan history</span>
    </a>
    @can('create', \App\Models\BrandGrowthPlan::class)
        <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-plans.regenerate', ['plan' => $plan->id, 'workspace_id' => $workspace->id]) }}">
            @csrf
            <button class="pl-btn-ghost" type="submit">
                <i data-lucide="refresh-cw" class="h-4 w-4"></i>
                <span>Regenerate draft</span>
            </button>
        </form>
    @endcan
    @can('promote', $plan)
        @if ($promotablePlanItemCount > 0)
            <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-plans.promote-approved', ['plan' => $plan->id, 'workspace_id' => $workspace->id]) }}">
                @csrf
                <button class="pl-btn-primary" type="submit">
                    <i data-lucide="rocket" class="h-4 w-4"></i>
                    <span>Promote approved</span>
                </button>
            </form>
        @endif
    @endcan
    @can('planExecution', $plan)
        @if ($promotedOpportunitiesMissingExecutionRecommendationCount > 0)
            <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-plans.execution-recommendations.create', ['plan' => $plan->id, 'workspace_id' => $workspace->id]) }}">
                @csrf
                <button class="pl-btn-primary" type="submit">
                    <i data-lucide="clipboard-list" class="h-4 w-4"></i>
                    <span>Create recommendations</span>
                </button>
            </form>
        @endif
    @endcan
    @can('createBriefs', $plan)
        @if ($briefableExecutionRecommendationCount > 0)
            <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-plans.content-briefs.create', ['plan' => $plan->id, 'workspace_id' => $workspace->id]) }}">
                @csrf
                <button class="pl-btn-primary" type="submit">
                    <i data-lucide="file-text" class="h-4 w-4"></i>
                    <span>Create briefs</span>
                </button>
            </form>
        @endif
    @endcan
    @can('createDrafts', $plan)
        @if ($draftReadyBriefCount > 0)
            <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-plans.drafts.create', ['plan' => $plan->id, 'workspace_id' => $workspace->id]) }}">
                @csrf
                <button class="pl-btn-primary" type="submit">
                    <i data-lucide="file-pen-line" class="h-4 w-4"></i>
                    <span>Create drafts</span>
                </button>
            </form>
        @endif
    @endcan
    @if ($status !== 'approved')
        <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-plans.approve', ['plan' => $plan->id, 'workspace_id' => $workspace->id]) }}">
            @csrf
            <button class="pl-btn-primary" type="submit">
                <i data-lucide="check-circle-2" class="h-4 w-4"></i>
                <span>Approve plan</span>
            </button>
        </form>
    @endif
@endsection

@section('metricSection')
        <x-metric-section>
        <x-metric-card label="Confidence" :value="number_format((float) $plan->confidence_score, 1)" helper="average analyzer confidence" />
        <x-metric-card label="Findings" :value="$plan->findings->count()" :helper="$approvedFindingCount.' approved · '.$approvedUnpromotedFindingCount.' ready'" />
        <x-metric-card label="Audiences" :value="$plan->audienceProposals->count()" :helper="$approvedAudienceCount.' approved · '.$approvedUnpromotedAudienceCount.' ready'" />
        <x-metric-card label="Missing data" :value="count((array) $plan->missing_information)" helper="unresolved inputs" />
    </x-metric-section>
@endsection

@section('content')
    <div class="space-y-6">
        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                {{ $errors->first() }}
            </div>
        @endif

        <section class="rounded-lg border border-border bg-surface p-5">
            <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap gap-2">
                        <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $status) }}</span>
                        @if ($isCurrentApprovedBaseline)
                            <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs text-emerald-800">current baseline</span>
                        @elseif ($isSupersededBaseline)
                            <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs text-slate-700">superseded baseline</span>
                        @endif
                        <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $plan->planning_horizon) }}</span>
                        <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ $plan->source_data_cutoff_at?->toDayDateTimeString() }}</span>
                        @if ($plan->clientSite)
                            <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ $plan->clientSite->name }}</span>
                        @endif
                    </div>
                    <h2 class="mt-3 text-xl font-semibold text-textPrimary">{{ $plan->business_objective ?: 'Brand Growth Plan' }}</h2>
                    <p class="mt-2 max-w-4xl text-sm text-textSecondary">{{ $plan->brand_objective }}</p>
                </div>
                <div class="grid min-w-64 gap-2 text-xs text-textSecondary sm:grid-cols-2 xl:grid-cols-1">
                    <div class="rounded-md border border-border bg-background px-3 py-2">Generated <span class="block font-medium text-textPrimary">{{ $plan->generated_at?->diffForHumans() ?? 'Draft' }}</span></div>
                    <div class="rounded-md border border-border bg-background px-3 py-2">Supersedes <span class="block font-medium text-textPrimary">{{ $plan->supersedesPlan ? 'v'.$plan->supersedesPlan->version : 'None' }}</span></div>
                </div>
            </div>
        </section>

        @if ($baselineDiffPlan)
            <section class="rounded-lg border border-border bg-surface p-5">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-textPrimary">Version Changes</h2>
                        <p class="mt-1 text-sm text-textSecondary">
                            Compared with v{{ data_get($baselineDiffPlan, 'version') }}{{ data_get($baselineDiffPlan, 'generated_at') ? ' from '.data_get($baselineDiffPlan, 'generated_at')->toFormattedDateString() : '' }}.
                        </p>
                    </div>
                    <div class="text-sm font-semibold {{ (float) data_get($planDiff, 'confidence.delta', 0) >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">
                        {{ (float) data_get($planDiff, 'confidence.delta', 0) >= 0 ? '+' : '' }}{{ number_format((float) data_get($planDiff, 'confidence.delta', 0), 1) }} confidence
                    </div>
                </div>

                <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-6">
                    @foreach ([
                        'Added findings' => $versionChangeSummary['added_findings'] ?? 0,
                        'Updated findings' => $versionChangeSummary['updated_findings'] ?? 0,
                        'Removed findings' => $versionChangeSummary['removed_findings'] ?? 0,
                        'Added audiences' => $versionChangeSummary['added_audiences'] ?? 0,
                        'Updated audiences' => $versionChangeSummary['updated_audiences'] ?? 0,
                        'Changed sections' => $versionChangeSummary['changed_sections'] ?? 0,
                    ] as $label => $value)
                        <div class="rounded-md border border-border bg-background px-3 py-2 text-xs text-textSecondary">
                            {{ $label }}
                            <span class="block text-base font-semibold text-textPrimary">{{ $value }}</span>
                        </div>
                    @endforeach
                </div>

                @if (! data_get($planDiff, 'has_changes'))
                    <div class="mt-4 rounded-md border border-border bg-background px-3 py-2 text-sm text-textSecondary">
                        No material changes were detected against the prior version.
                    </div>
                @else
                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                        <div class="rounded-md border border-border bg-background p-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Changed sections</h3>
                            <div class="mt-2 space-y-2">
                                @forelse (collect(data_get($planDiff, 'sections.changed', []))->take(8) as $section)
                                    <div class="text-sm text-textPrimary">
                                        <div class="flex items-center justify-between gap-3">
                                            <span class="font-medium">{{ data_get($section, 'label') }}</span>
                                            <span class="text-xs text-textSecondary">{{ data_get($section, 'previous_count') }} to {{ data_get($section, 'current_count') }}</span>
                                        </div>
                                        @if (data_get($section, 'current_preview'))
                                            <p class="mt-1 text-xs text-textSecondary">{{ data_get($section, 'current_preview') }}</p>
                                        @endif
                                    </div>
                                @empty
                                    <p class="text-sm text-textSecondary">No strategic sections changed.</p>
                                @endforelse
                            </div>
                        </div>

                        <div class="rounded-md border border-border bg-background p-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Finding movement</h3>
                            <div class="mt-2 grid gap-3 md:grid-cols-2">
                                <div>
                                    <p class="text-xs font-medium text-emerald-700">Added</p>
                                    <div class="mt-1 space-y-1">
                                        @forelse (collect(data_get($planDiff, 'findings.added', []))->take(4) as $item)
                                            <p class="text-sm text-textPrimary">{{ data_get($item, 'title') }}</p>
                                        @empty
                                            <p class="text-sm text-textSecondary">None</p>
                                        @endforelse
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs font-medium text-rose-700">Removed</p>
                                    <div class="mt-1 space-y-1">
                                        @forelse (collect(data_get($planDiff, 'findings.removed', []))->take(4) as $item)
                                            <p class="text-sm text-textPrimary">{{ data_get($item, 'title') }}</p>
                                        @empty
                                            <p class="text-sm text-textSecondary">None</p>
                                        @endforelse
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-md border border-border bg-background p-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Audience movement</h3>
                            <div class="mt-2 grid gap-3 md:grid-cols-2">
                                <div>
                                    <p class="text-xs font-medium text-emerald-700">Added</p>
                                    <div class="mt-1 space-y-1">
                                        @forelse (collect(data_get($planDiff, 'audiences.added', []))->take(4) as $item)
                                            <p class="text-sm text-textPrimary">{{ data_get($item, 'title') }}</p>
                                        @empty
                                            <p class="text-sm text-textSecondary">None</p>
                                        @endforelse
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs font-medium text-rose-700">Removed</p>
                                    <div class="mt-1 space-y-1">
                                        @forelse (collect(data_get($planDiff, 'audiences.removed', []))->take(4) as $item)
                                            <p class="text-sm text-textPrimary">{{ data_get($item, 'title') }}</p>
                                        @empty
                                            <p class="text-sm text-textSecondary">None</p>
                                        @endforelse
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="rounded-md border border-border bg-background p-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Missing data</h3>
                            <div class="mt-2 grid gap-3 md:grid-cols-2">
                                <div>
                                    <p class="text-xs font-medium text-amber-700">New gaps</p>
                                    <div class="mt-1 space-y-1">
                                        @forelse (collect(data_get($planDiff, 'missing_information.added', []))->take(4) as $item)
                                            <p class="text-sm text-textPrimary">{{ $item }}</p>
                                        @empty
                                            <p class="text-sm text-textSecondary">None</p>
                                        @endforelse
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs font-medium text-emerald-700">Resolved</p>
                                    <div class="mt-1 space-y-1">
                                        @forelse (collect(data_get($planDiff, 'missing_information.resolved', []))->take(4) as $item)
                                            <p class="text-sm text-textPrimary">{{ $item }}</p>
                                        @empty
                                            <p class="text-sm text-textSecondary">None</p>
                                        @endforelse
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </section>
        @endif

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_360px]">
            <main class="space-y-6">
                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Strategic Priorities</h2>
                    <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-plans.update', ['plan' => $plan->id, 'workspace_id' => $workspace->id]) }}" class="mt-4 grid gap-4">
                        @csrf
                        @method('PUT')
                        <div class="grid gap-4 lg:grid-cols-2">
                            <label class="text-xs font-medium text-textSecondary">Business objective
                                <textarea name="business_objective" rows="3" class="pl-input mt-1 w-full">{{ old('business_objective', $plan->business_objective) }}</textarea>
                            </label>
                            <label class="text-xs font-medium text-textSecondary">Brand objective
                                <textarea name="brand_objective" rows="3" class="pl-input mt-1 w-full">{{ old('brand_objective', $plan->brand_objective) }}</textarea>
                            </label>
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <label class="text-xs font-medium text-textSecondary">Messaging priorities
                                <textarea name="messaging_priorities_text" rows="5" class="pl-input mt-1 w-full">{{ old('messaging_priorities_text', $listText($plan->messaging_priorities)) }}</textarea>
                            </label>
                            <label class="text-xs font-medium text-textSecondary">Content priorities
                                <textarea name="content_priorities_text" rows="5" class="pl-input mt-1 w-full">{{ old('content_priorities_text', $listText($plan->content_priorities)) }}</textarea>
                            </label>
                            <label class="text-xs font-medium text-textSecondary">Top actions
                                <textarea name="top_prioritized_actions_text" rows="5" class="pl-input mt-1 w-full">{{ old('top_prioritized_actions_text', $listText($plan->top_prioritized_actions)) }}</textarea>
                            </label>
                            <label class="text-xs font-medium text-textSecondary">KPI recommendations
                                <textarea name="kpi_recommendations_text" rows="5" class="pl-input mt-1 w-full">{{ old('kpi_recommendations_text', $listText($plan->kpi_recommendations)) }}</textarea>
                            </label>
                        </div>
                        <div>
                            <button class="pl-btn-primary" type="submit">
                                <i data-lucide="save" class="h-4 w-4"></i>
                                <span>Save priorities</span>
                            </button>
                        </div>
                    </form>
                </section>

                <section class="rounded-lg border border-border bg-surface">
                    <div class="border-b border-border px-5 py-4">
                        <h2 class="text-sm font-semibold text-textPrimary">Strategic Findings</h2>
                    </div>
                    <div class="divide-y divide-border">
                        @forelse ($plan->findings as $finding)
                            @php
                                $reviewState = $finding->review_state?->value ?? $finding->review_state;
                                $findingType = $finding->type?->value ?? $finding->type;
                                $findingChangeState = $findingChangeStates->get((string) $finding->dedupe_hash);
                                $findingExecutionPlan = $finding->opportunity ? $finding->opportunity->activeExecutionPlans->first() : null;
                                $findingBriefId = $findingExecutionPlan ? data_get($findingExecutionPlan->metadata, 'brief_id') : null;
                                $findingBrief = $findingBriefId ? $brandGrowthBriefs->get((string) $findingBriefId) : null;
                                $findingDraftId = $findingBrief ? data_get($findingBrief->client_refs, 'draft_id') : null;
                            @endphp
                            <article class="p-5">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap gap-2">
                                            <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $findingType) }}</span>
                                            <span class="rounded-full border px-2.5 py-1 text-xs {{ $stateClass[$reviewState] ?? 'border-border bg-background text-textSecondary' }}">{{ str_replace('_', ' ', $reviewState) }}</span>
                                            <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">inferred conclusion</span>
                                            @if ($findingChangeState === 'added')
                                                <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs text-emerald-800">new in v{{ $plan->version }}</span>
                                            @elseif ($findingChangeState === 'changed')
                                                <span class="rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 text-xs text-sky-800">updated in v{{ $plan->version }}</span>
                                            @endif
                                            @if ($finding->promoted_at)
                                                <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs text-emerald-800">promoted</span>
                                            @endif
                                        </div>
                                        <h3 class="mt-3 text-base font-semibold text-textPrimary">{{ $finding->title }}</h3>
                                        <p class="mt-2 text-sm text-textSecondary">{{ $finding->description }}</p>
                                        @if ($finding->rationale)
                                            <p class="mt-2 text-xs text-textSecondary"><span class="font-semibold text-textPrimary">Rationale:</span> {{ $finding->rationale }}</p>
                                        @endif
                                        @if ($finding->recommended_action)
                                            <div class="mt-3 rounded-md border border-primary/20 bg-primarySoftBg/50 p-3 text-sm text-textPrimary">
                                                <span class="font-semibold">Recommendation:</span> {{ $finding->recommended_action }}
                                            </div>
                                        @endif
                                    </div>
                                    <div class="grid min-w-56 grid-cols-3 gap-2 text-xs text-textSecondary">
                                        <div class="rounded-md border border-border bg-background px-3 py-2">Impact <span class="block font-semibold text-textPrimary">{{ number_format((float) $finding->impact_score, 0) }}</span></div>
                                        <div class="rounded-md border border-border bg-background px-3 py-2">Urgency <span class="block font-semibold text-textPrimary">{{ number_format((float) $finding->urgency_score, 0) }}</span></div>
                                        <div class="rounded-md border border-border bg-background px-3 py-2">Confidence <span class="block font-semibold text-textPrimary">{{ number_format((float) $finding->confidence_score, 0) }}</span></div>
                                    </div>
                                </div>

                                <details class="mt-4 rounded-md border border-border bg-background px-3 py-2">
                                    <summary class="cursor-pointer text-xs font-medium text-textPrimary">Evidence references</summary>
                                    <pre class="mt-2 max-h-44 overflow-auto whitespace-pre-wrap text-xs text-textSecondary">{{ json_encode(['source_references' => $finding->source_references, 'source_summary' => $finding->source_summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>

                                <div class="mt-4 flex flex-wrap gap-2">
                                    <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-findings.approve', ['finding' => $finding->id, 'workspace_id' => $workspace->id]) }}">
                                        @csrf
                                        <button class="pl-btn-ghost" type="submit"><i data-lucide="check" class="h-4 w-4"></i><span>Approve</span></button>
                                    </form>
                                    <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-findings.reject', ['finding' => $finding->id, 'workspace_id' => $workspace->id]) }}">
                                        @csrf
                                        <button class="pl-btn-ghost" type="submit"><i data-lucide="x" class="h-4 w-4"></i><span>Reject</span></button>
                                    </form>
                                    @if ($finding->opportunity)
                                        <a href="{{ route('app.opportunities.show', ['opportunity' => $finding->opportunity->id, 'workspace_id' => $workspace->id]) }}" class="pl-btn-primary">
                                            <i data-lucide="arrow-up-right" class="h-4 w-4"></i>
                                            <span>Open opportunity</span>
                                        </a>
                                        @if ($findingExecutionPlan)
                                            <a href="{{ route('app.opportunities.execution-recommendations.show', ['plan' => $findingExecutionPlan->id, 'workspace_id' => $workspace->id]) }}" class="pl-btn-ghost">
                                                <i data-lucide="clipboard-check" class="h-4 w-4"></i>
                                                <span>Open recommendation</span>
                                            </a>
                                        @endif
                                        @if ($findingBriefId)
                                            <a href="{{ route('app.content.workspace.show', ['brief' => $findingBriefId, 'workspace_id' => $workspace->id]) }}" class="pl-btn-ghost">
                                                <i data-lucide="file-text" class="h-4 w-4"></i>
                                                <span>Open brief</span>
                                            </a>
                                        @endif
                                        @if ($findingDraftId)
                                            <a href="{{ route('app.drafts.show', ['draft' => $findingDraftId, 'workspace_id' => $workspace->id]) }}" class="pl-btn-ghost">
                                                <i data-lucide="file-pen-line" class="h-4 w-4"></i>
                                                <span>Open draft</span>
                                            </a>
                                        @endif
                                    @else
                                        <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-findings.promote', ['finding' => $finding->id, 'workspace_id' => $workspace->id]) }}">
                                            @csrf
                                            <button class="pl-btn-primary" type="submit" @disabled($reviewState !== 'approved')>
                                                <i data-lucide="radar" class="h-4 w-4"></i>
                                                <span>Promote to opportunity</span>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <div class="p-5 text-sm text-textSecondary">No findings were generated for this plan.</div>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface">
                    <div class="border-b border-border px-5 py-4">
                        <h2 class="text-sm font-semibold text-textPrimary">Inferred Audiences</h2>
                    </div>
                    <div class="divide-y divide-border">
                        @forelse ($plan->audienceProposals as $proposal)
                            @php
                                $proposalState = $proposal->review_state?->value ?? $proposal->review_state;
                                $audienceChangeState = $audienceChangeStates->get((string) $proposal->dedupe_hash);
                            @endphp
                            <article class="p-5">
                                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <div class="flex flex-wrap gap-2">
                                            <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $proposal->proposal_type?->value ?? $proposal->proposal_type) }}</span>
                                            <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $proposal->source_type?->value ?? $proposal->source_type) }}</span>
                                            <span class="rounded-full border px-2.5 py-1 text-xs {{ $stateClass[$proposalState] ?? 'border-border bg-background text-textSecondary' }}">{{ str_replace('_', ' ', $proposalState) }}</span>
                                            @if ($audienceChangeState === 'added')
                                                <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs text-emerald-800">new in v{{ $plan->version }}</span>
                                            @elseif ($audienceChangeState === 'changed')
                                                <span class="rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 text-xs text-sky-800">updated in v{{ $plan->version }}</span>
                                            @endif
                                            @if ($proposal->persona)
                                                <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs text-emerald-800">persona</span>
                                            @endif
                                        </div>
                                        <h3 class="mt-3 text-base font-semibold text-textPrimary">{{ $proposal->name }}</h3>
                                        <p class="mt-1 text-sm text-textSecondary">{{ collect([$proposal->role, $proposal->seniority, $proposal->department, $proposal->industry])->filter()->implode(' · ') }}</p>
                                    </div>
                                    <div class="text-sm font-semibold text-textPrimary">{{ number_format((float) $proposal->confidence_score, 0) }} confidence</div>
                                </div>
                                <details class="mt-4 rounded-md border border-border bg-background px-3 py-2">
                                    <summary class="cursor-pointer text-xs font-medium text-textPrimary">Evidence and attributes</summary>
                                    <pre class="mt-2 max-h-44 overflow-auto whitespace-pre-wrap text-xs text-textSecondary">{{ json_encode(['source_references' => $proposal->source_references, 'goals' => $proposal->goals, 'pain_points' => $proposal->pain_points, 'kpis' => $proposal->kpis], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                </details>
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-audiences.approve', ['proposal' => $proposal->id, 'workspace_id' => $workspace->id]) }}">
                                        @csrf
                                        <button class="pl-btn-ghost" type="submit"><i data-lucide="check" class="h-4 w-4"></i><span>Approve</span></button>
                                    </form>
                                    <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-audiences.reject', ['proposal' => $proposal->id, 'workspace_id' => $workspace->id]) }}">
                                        @csrf
                                        <button class="pl-btn-ghost" type="submit"><i data-lucide="x" class="h-4 w-4"></i><span>Reject</span></button>
                                    </form>
                                    @unless ($proposal->persona)
                                        <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-audiences.promote', ['proposal' => $proposal->id, 'workspace_id' => $workspace->id]) }}">
                                            @csrf
                                            <button class="pl-btn-primary" type="submit" @disabled($proposalState !== 'approved')>
                                                <i data-lucide="user-check" class="h-4 w-4"></i>
                                                <span>Promote to persona</span>
                                            </button>
                                        </form>
                                    @endunless
                                </div>
                            </article>
                        @empty
                            <div class="p-5 text-sm text-textSecondary">No inferred audiences were generated for this plan.</div>
                        @endforelse
                    </div>
                </section>
            </main>

            <aside class="space-y-6">
                <section class="rounded-lg border border-border bg-surface p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-textPrimary">Execution Readiness</h2>
                        <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ $promotablePlanItemCount }} promote · {{ $promotedOpportunitiesMissingExecutionRecommendationCount }} plan · {{ $briefableExecutionRecommendationCount }} brief · {{ $draftReadyBriefCount }} draft</span>
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-textSecondary">
                        <div class="rounded-md border border-border bg-background px-3 py-2">Findings ready <span class="block text-base font-semibold text-textPrimary">{{ $approvedUnpromotedFindingCount }}</span></div>
                        <div class="rounded-md border border-border bg-background px-3 py-2">Audiences ready <span class="block text-base font-semibold text-textPrimary">{{ $approvedUnpromotedAudienceCount }}</span></div>
                        <div class="rounded-md border border-border bg-background px-3 py-2">Opportunities <span class="block text-base font-semibold text-textPrimary">{{ $promotedOpportunityCount }}</span></div>
                        <div class="rounded-md border border-border bg-background px-3 py-2">Personas <span class="block text-base font-semibold text-textPrimary">{{ $promotedAudienceCount }}</span></div>
                        <div class="rounded-md border border-border bg-background px-3 py-2">Recommendations <span class="block text-base font-semibold text-textPrimary">{{ $executionRecommendationCount }}</span></div>
                        <div class="rounded-md border border-border bg-background px-3 py-2">Needs plan <span class="block text-base font-semibold text-textPrimary">{{ $promotedOpportunitiesMissingExecutionRecommendationCount }}</span></div>
                        <div class="rounded-md border border-border bg-background px-3 py-2">Brief-ready <span class="block text-base font-semibold text-textPrimary">{{ $briefableExecutionRecommendationCount }}</span></div>
                        <div class="rounded-md border border-border bg-background px-3 py-2">Briefs <span class="block text-base font-semibold text-textPrimary">{{ $contentBriefCount }}</span></div>
                        <div class="rounded-md border border-border bg-background px-3 py-2">Draft-ready <span class="block text-base font-semibold text-textPrimary">{{ $draftReadyBriefCount }}</span></div>
                        <div class="rounded-md border border-border bg-background px-3 py-2">Drafts <span class="block text-base font-semibold text-textPrimary">{{ $contentDraftCount }}</span></div>
                    </div>
                    @can('promote', $plan)
                        @if ($promotablePlanItemCount > 0)
                            <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-plans.promote-approved', ['plan' => $plan->id, 'workspace_id' => $workspace->id]) }}" class="mt-3">
                                @csrf
                                <button class="pl-btn-primary w-full justify-center" type="submit">
                                    <i data-lucide="rocket" class="h-4 w-4"></i>
                                    <span>Promote approved</span>
                                </button>
                            </form>
                        @endif
                    @endcan
                    @can('planExecution', $plan)
                        @if ($promotedOpportunitiesMissingExecutionRecommendationCount > 0)
                            <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-plans.execution-recommendations.create', ['plan' => $plan->id, 'workspace_id' => $workspace->id]) }}" class="mt-3">
                                @csrf
                                <button class="pl-btn-primary w-full justify-center" type="submit">
                                    <i data-lucide="clipboard-list" class="h-4 w-4"></i>
                                    <span>Create recommendations</span>
                                </button>
                            </form>
                        @endif
                    @endcan
                    @can('createBriefs', $plan)
                        @if ($briefableExecutionRecommendationCount > 0)
                            <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-plans.content-briefs.create', ['plan' => $plan->id, 'workspace_id' => $workspace->id]) }}" class="mt-3">
                                @csrf
                                <button class="pl-btn-primary w-full justify-center" type="submit">
                                    <i data-lucide="file-text" class="h-4 w-4"></i>
                                    <span>Create briefs</span>
                                </button>
                            </form>
                        @elseif ($executionRecommendationsNeedingApprovalCount > 0)
                            <p class="mt-3 rounded-md border border-border bg-background px-3 py-2 text-xs text-textSecondary">
                                Approve execution recommendations before creating briefs.
                            </p>
                        @endif
                    @endcan
                    @can('createDrafts', $plan)
                        @if ($draftReadyBriefCount > 0)
                            <form method="POST" action="{{ route('app.agentic-marketing.brand-growth-plans.drafts.create', ['plan' => $plan->id, 'workspace_id' => $workspace->id]) }}" class="mt-3">
                                @csrf
                                <button class="pl-btn-primary w-full justify-center" type="submit">
                                    <i data-lucide="file-pen-line" class="h-4 w-4"></i>
                                    <span>Create drafts</span>
                                </button>
                            </form>
                        @endif
                    @endcan
                </section>

                <section class="rounded-lg border border-border bg-surface p-4">
                    <h2 class="text-sm font-semibold text-textPrimary">Recommended Audiences</h2>
                    <div class="mt-3 space-y-3">
                        @foreach (['Primary' => $plan->recommended_primary_audiences, 'Secondary' => $plan->recommended_secondary_audiences, 'Buying roles' => $plan->buying_committee_roles] as $label => $items)
                            <div>
                                <p class="text-xs font-semibold text-textSecondary">{{ $label }}</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @forelse ((array) $items as $item)
                                        <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ $item }}</span>
                                    @empty
                                        <span class="text-xs text-textFaint">None</span>
                                    @endforelse
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-4">
                    <h2 class="text-sm font-semibold text-textPrimary">Analyzed Sources</h2>
                    <div class="mt-3 space-y-3">
                        @foreach ((array) data_get($plan->confidence_summary, 'analyzers', []) as $analyzer)
                            <div class="rounded-md border border-border bg-background p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-xs font-semibold text-textPrimary">{{ str_replace('_', ' ', data_get($analyzer, 'key')) }}</p>
                                    <span class="text-xs text-textSecondary">{{ number_format((float) data_get($analyzer, 'confidence'), 0) }}</span>
                                </div>
                                <p class="mt-1 text-xs text-textSecondary">{{ data_get($analyzer, 'summary') }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-4">
                    <h2 class="text-sm font-semibold text-textPrimary">Missing Data</h2>
                    <div class="mt-3 space-y-2">
                        @forelse ((array) $plan->missing_information as $item)
                            <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">{{ $item }}</div>
                        @empty
                            <p class="text-sm text-textSecondary">No missing data recorded.</p>
                        @endforelse
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-4">
                    <h2 class="text-sm font-semibold text-textPrimary">Plan History</h2>
                    <div class="mt-3 space-y-2">
                        @forelse ($planHistory as $historyPlan)
                            <a href="{{ route('app.agentic-marketing.brand-growth-plans.show', ['plan' => $historyPlan->id, 'workspace_id' => $workspace->id]) }}" class="block rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary hover:border-primary/40">
                                v{{ $historyPlan->version }} · {{ str_replace('_', ' ', $historyPlan->status?->value ?? $historyPlan->status) }}
                            </a>
                        @empty
                            <p class="text-sm text-textSecondary">No earlier plans.</p>
                        @endforelse
                    </div>
                </section>
            </aside>
        </div>
    </div>
@endsection
