@extends('layouts.app', ['title' => $objective->name, 'pageWidth' => 'wide'])

@php
    $statusClasses = [
        'active' => 'bg-emerald-100 text-emerald-800',
        'paused' => 'bg-amber-100 text-amber-800',
        'archived' => 'bg-slate-100 text-slate-600',
        'proposed' => 'bg-slate-100 text-slate-700',
        'approved' => 'bg-blue-100 text-blue-800',
        'running' => 'bg-amber-100 text-amber-800',
        'completed' => 'bg-emerald-100 text-emerald-800',
        'failed' => 'bg-rose-100 text-rose-800',
        'dismissed' => 'bg-slate-100 text-slate-500',
    ];

    $proposedActions = $actions->where('status', 'proposed')->values();
    $approvedActions = $actions->where('status', 'approved')->values();
    $runningActions = $actions->where('status', 'running')->values();
    $failedActions = $actions->where('status', 'failed')->values();
    $openActions = $actions->whereIn('status', ['proposed', 'approved', 'running', 'failed'])->values();
    $focusActions = $openActions->take(5);
    $topOpportunities = $opportunities->take(8);
    $visibleActions = $actions->take(12);
    $recentRunItems = $runItems->take(8);
    $recentAuditLogs = $auditLogs->take(8);
    $hasAiVisibilitySignals = (int) ($aiVisibilitySummary['query_count'] ?? 0) > 0
        || ($aiVisibilitySummary['avg_ai_visibility_score'] ?? null) !== null;

    $formatActionType = fn (?string $type): string => str_replace('_', ' ', (string) $type);
    $riskClass = function (?string $risk): string {
        return match ($risk) {
            'high' => 'bg-rose-50 text-rose-800 border-rose-200',
            'medium' => 'bg-amber-50 text-amber-800 border-amber-200',
            'low' => 'bg-emerald-50 text-emerald-800 border-emerald-200',
            default => 'bg-background text-textSecondary border-border',
        };
    };
@endphp

@section('content')
    <div class="space-y-5">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a href="{{ route('app.agentic-marketing.index') }}" class="text-sm text-textSecondary hover:text-textPrimary">Agentic Marketing</a>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-semibold text-textPrimary">{{ $objective->name }}</h1>
                    <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $statusClasses[$objective->status] ?? 'bg-slate-100 text-slate-700' }}">{{ ucfirst((string) $objective->status) }}</span>
                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ strtoupper((string) $objective->locale) }}</span>
                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', (string) $objective->approval_mode) }}</span>
                </div>
                <p class="mt-2 max-w-4xl text-sm text-textSecondary">{{ $objective->goal }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('app.agentic-marketing.objectives.scan', $objective) }}">
                    @csrf
                    <button class="pl-btn-primary" type="submit">
                        <i data-lucide="sparkles" class="h-4 w-4"></i>
                        <span>Find actions</span>
                    </button>
                </form>
                <a href="{{ route('app.agentic-marketing.index', ['objective' => $objective->id]) }}" class="pl-btn-ghost">
                    <i data-lucide="list-filter" class="h-4 w-4"></i>
                    <span>Queue</span>
                </a>
                <a href="{{ route('app.agentic-marketing.objectives.edit', $objective) }}" class="pl-btn-primary">
                    <i data-lucide="pencil" class="h-4 w-4"></i>
                    <span>Edit</span>
                </a>
            </div>
        </header>

        @if (session('status'))
            <x-alert class="mb-4">{{ session('status') }}</x-alert>
        @endif

        @if (($budgetSummary['is_low'] ?? false) || ($budgetSummary['is_exceeded'] ?? false) || ($budgetSummary['is_forecast_exceeded'] ?? false))
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                @if ($budgetSummary['is_exceeded'] ?? false)
                    Objective monthly cap is exceeded by captured and reserved work.
                    {{ number_format((int) ($budgetSummary['remaining'] ?? 0)) }} credits remain against this objective cap.
                @elseif ($budgetSummary['is_forecast_exceeded'] ?? false)
                    Proposed work exceeds this objective's monthly cap by {{ number_format(abs((int) ($budgetSummary['forecast_remaining'] ?? 0))) }} credits.
                    Wallet credits may still be available; approve fewer actions or raise the objective budget before execution.
                @else
                    Objective monthly cap is running low.
                    {{ number_format((int) ($budgetSummary['remaining'] ?? 0)) }} credits remain before proposed work.
                @endif
            </div>
        @endif

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-border bg-surface px-4 py-3">
                <div class="text-xs text-textSecondary">Open Opportunities</div>
                <div class="mt-2 text-2xl font-semibold text-textPrimary">{{ $health['open_opportunities'] }}</div>
                <p class="mt-1 text-xs text-textSecondary">avg priority {{ $health['average_priority'] ?: 0 }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface px-4 py-3">
                <div class="text-xs text-textSecondary">Needs Review</div>
                <div class="mt-2 text-2xl font-semibold text-textPrimary">{{ $proposedActions->count() }}</div>
                <p class="mt-1 text-xs text-textSecondary">{{ $approvedActions->count() }} approved, {{ $runningActions->count() }} running</p>
            </div>
            <div class="rounded-lg border border-border bg-surface px-4 py-3">
                <div class="text-xs text-textSecondary">Cost Forecast</div>
                <div class="mt-2 text-2xl font-semibold text-textPrimary">{{ number_format((int) $health['forecast_credits']) }}</div>
                <p class="mt-1 text-xs text-textSecondary">{{ number_format((int) ($budgetSummary['remaining'] ?? 0)) }} credits remaining</p>
            </div>
            <div class="rounded-lg border border-border bg-surface px-4 py-3">
                <div class="text-xs text-textSecondary">Runs</div>
                <div class="mt-2 text-2xl font-semibold text-textPrimary">{{ $objective->runs_count }}</div>
                <p class="mt-1 text-xs text-textSecondary">{{ $failedActions->count() }} failed actions</p>
            </div>
        </section>

        <section class="grid gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(320px,0.55fr)]">
            <div class="rounded-lg border border-border bg-surface">
                <div class="flex flex-col gap-2 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-base font-semibold text-textPrimary">Focus Queue</h2>
                        <p class="mt-1 text-sm text-textSecondary">Top open recommendations for this objective.</p>
                    </div>
                    <a href="{{ route('app.agentic-marketing.index', ['objective' => $objective->id]) }}" class="text-sm text-primary hover:text-primaryDark">View all actions</a>
                </div>
                <div class="divide-y divide-border">
                    @forelse ($focusActions as $action)
                        @php
                            $planning = (array) data_get($action->payload, 'planning', []);
                            $scoreExplanation = (array) data_get($action->opportunity?->payload, 'score_explanation', []);
                            $risk = (string) ($planning['risk_level'] ?? 'unknown');
                        @endphp
                        <article class="px-5 py-4">
                            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $statusClasses[$action->status] ?? 'bg-slate-100 text-slate-700' }}">{{ ucfirst($action->status) }}</span>
                                        <span class="rounded-full border px-2.5 py-1 text-xs {{ $riskClass($risk) }}">Risk {{ ucfirst($risk) }}</span>
                                        @if ($action->estimated_credits)
                                            <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ $action->estimated_credits }} credits</span>
                                        @endif
                                    </div>
                                    <h3 class="mt-2 text-sm font-semibold text-textPrimary">
                                        <a href="{{ route('app.agentic-marketing.actions.show', $action) }}" class="hover:text-primary">{{ $action->opportunity?->title ?? $formatActionType($action->action_type) }}</a>
                                    </h3>
                                    <p class="mt-1 text-sm text-textSecondary">{{ $scoreExplanation['summary'] ?? data_get($action->payload, 'recommendation', 'Generated from stored objective signals.') }}</p>
                                </div>
                                <div class="flex shrink-0 flex-wrap gap-2">
                                    @if ($action->status === 'proposed')
                                        <form method="POST" action="{{ route('app.agentic-marketing.actions.approve', $action) }}">
                                            @csrf
                                            <button class="pl-btn-primary" type="submit"><i data-lucide="check" class="h-4 w-4"></i><span>Approve</span></button>
                                        </form>
                                        <form method="POST" action="{{ route('app.agentic-marketing.actions.dismiss', $action) }}">
                                            @csrf
                                            <button class="pl-btn-ghost" type="submit"><i data-lucide="x" class="h-4 w-4"></i><span>Dismiss</span></button>
                                        </form>
                                    @elseif ($action->status === 'approved')
                                        <form method="POST" action="{{ route('app.agentic-marketing.actions.execute', $action) }}">
                                            @csrf
                                            <button class="pl-btn-primary" type="submit"><i data-lucide="play" class="h-4 w-4"></i><span>Execute</span></button>
                                        </form>
                                    @elseif ($action->status === 'failed')
                                        <form method="POST" action="{{ route('app.agentic-marketing.actions.retry', $action) }}">
                                            @csrf
                                            <button class="pl-btn-primary" type="submit"><i data-lucide="rotate-cw" class="h-4 w-4"></i><span>Retry</span></button>
                                        </form>
                                    @endif
                                    <a href="{{ route('app.agentic-marketing.actions.show', $action) }}" class="pl-btn-ghost">
                                        <i data-lucide="arrow-right" class="h-4 w-4"></i>
                                    </a>
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="px-5 py-10">
                            <div class="mx-auto max-w-xl text-center">
                                <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-primary/10 text-primary">
                                    <i data-lucide="sparkles" class="h-5 w-5"></i>
                                </div>
                                <p class="mt-3 text-sm font-semibold text-textPrimary">No actions ready yet</p>
                                <p class="mt-1 text-sm text-textSecondary">
                                    Run a scan to convert this objective into concrete work: refreshes, answer blocks, internal links, metadata fixes, schema, or new content tasks.
                                </p>
                                <form method="POST" action="{{ route('app.agentic-marketing.objectives.scan', $objective) }}" class="mt-4">
                                    @csrf
                                    <button class="pl-btn-primary mx-auto" type="submit">
                                        <i data-lucide="play" class="h-4 w-4"></i>
                                        <span>Find actions for this objective</span>
                                    </button>
                                </form>
                                <p class="mt-3 text-xs text-textSecondary">
                                    Best results need existing content, lifecycle scores, SEO/AEO signals, or AI visibility data in this workspace.
                                </p>
                            </div>
                        </div>
                    @endforelse
                </div>
            </div>

            <aside class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Objective Snapshot</h2>
                <dl class="mt-4 space-y-3">
                    <div class="flex justify-between gap-4">
                        <dt class="text-sm text-textSecondary">KPI</dt>
                        <dd class="text-right text-sm text-textPrimary">{{ str_replace('_', ' ', (string) $objective->kpi_type) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-sm text-textSecondary">Workspace</dt>
                        <dd class="text-right text-sm text-textPrimary">{{ $objective->workspace?->name ?? 'Not set' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-sm text-textSecondary">Site</dt>
                        <dd class="text-right text-sm text-textPrimary">{{ $objective->clientSite?->name ?? 'All sites' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-sm text-textSecondary">Budget</dt>
                        <dd class="text-right text-sm text-textPrimary">{{ $objective->monthly_credit_budget !== null ? number_format((int) $objective->monthly_credit_budget).' credits/mo' : 'Not set' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-sm text-textSecondary">Audience</dt>
                        <dd class="max-w-[180px] text-right text-sm text-textPrimary">{{ $objective->audience ?: 'Not specified' }}</dd>
                    </div>
                </dl>
                @if (($budgetSummary['budget'] ?? null) !== null)
                    <div class="mt-5 space-y-2 text-xs text-textSecondary">
                        <div class="flex justify-between rounded-md border border-border bg-background px-3 py-2"><span>Captured</span><span>{{ number_format((int) $budgetSummary['captured']) }}</span></div>
                        <div class="flex justify-between rounded-md border border-border bg-background px-3 py-2"><span>Reserved</span><span>{{ number_format((int) ($budgetSummary['reserved'] ?? 0)) }}</span></div>
                        <div class="flex justify-between rounded-md border border-border bg-background px-3 py-2"><span>Forecast</span><span>{{ number_format((int) $budgetSummary['reserved_or_forecast']) }}</span></div>
                        <div class="flex justify-between rounded-md border border-border bg-background px-3 py-2"><span>Remaining</span><span>{{ number_format((int) $budgetSummary['remaining']) }}</span></div>
                    </div>
                @endif
            </aside>
        </section>

        <section class="grid gap-4 xl:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
            <div class="rounded-lg border border-border bg-surface">
                <div class="border-b border-border px-5 py-4">
                    <h2 class="text-base font-semibold text-textPrimary">Opportunity Mix</h2>
                    <p class="mt-1 text-sm text-textSecondary">Where the scan found the most work.</p>
                </div>
                <div class="grid gap-4 p-5 sm:grid-cols-2">
                    @foreach (['type' => 'Type', 'locale' => 'Locale', 'campaign_content' => 'Campaign / Content', 'risk' => 'Risk'] as $groupKey => $label)
                        <div>
                            <h3 class="text-sm font-semibold text-textPrimary">{{ $label }}</h3>
                            <div class="mt-3 space-y-2">
                                @forelse (($opportunityMap[$groupKey] ?? collect())->take(4) as $name => $count)
                                    @php $percent = max(8, min(100, ((int) $count / max(1, $opportunities->count())) * 100)); @endphp
                                    <div>
                                        <div class="flex items-center justify-between gap-3 text-xs">
                                            <span class="truncate text-textSecondary">{{ str_replace('_', ' ', ucfirst((string) $name)) }}</span>
                                            <span class="text-textPrimary">{{ $count }}</span>
                                        </div>
                                        <div class="mt-1 h-1.5 rounded-full bg-background">
                                            <div class="h-1.5 rounded-full bg-primary" style="width: {{ $percent }}%"></div>
                                        </div>
                                    </div>
                                @empty
                                    <p class="text-sm text-textSecondary">No signals yet.</p>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface">
                <div class="border-b border-border px-5 py-4">
                    <h2 class="text-base font-semibold text-textPrimary">Signals</h2>
                    <p class="mt-1 text-sm text-textSecondary">Current objective and AI visibility indicators.</p>
                </div>
                <div class="grid gap-3 p-5 sm:grid-cols-2">
                    <div class="rounded-md border border-border bg-background px-3 py-3">
                        <div class="text-xs text-textSecondary">Completed Impact</div>
                        <div class="mt-1 text-xl font-semibold text-textPrimary">{{ $health['completed_actions'] }}</div>
                    </div>
                    <div class="rounded-md border border-border bg-background px-3 py-3">
                        <div class="text-xs text-textSecondary">Blocked Actions</div>
                        <div class="mt-1 text-xl font-semibold text-textPrimary">{{ $health['blocked_actions'] }}</div>
                    </div>
                    @if ($hasAiVisibilitySignals)
                        <div class="rounded-md border border-border bg-background px-3 py-3">
                            <div class="text-xs text-textSecondary">Tracked AI Queries</div>
                            <div class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((int) ($aiVisibilitySummary['query_count'] ?? 0)) }}</div>
                        </div>
                        <div class="rounded-md border border-border bg-background px-3 py-3">
                            <div class="text-xs text-textSecondary">AI Visibility</div>
                            <div class="mt-1 text-xl font-semibold text-textPrimary">{{ ($aiVisibilitySummary['avg_ai_visibility_score'] ?? null) !== null ? ($aiVisibilitySummary['avg_ai_visibility_score'].'%') : 'n/a' }}</div>
                        </div>
                        <div class="rounded-md border border-border bg-background px-3 py-3">
                            <div class="text-xs text-textSecondary">Brand Presence</div>
                            <div class="mt-1 text-xl font-semibold text-textPrimary">{{ (int) ($aiVisibilitySummary['brand_presence_rate'] ?? 0) }}%</div>
                        </div>
                        <div class="rounded-md border border-border bg-background px-3 py-3">
                            <div class="text-xs text-textSecondary">Owned Citations</div>
                            <div class="mt-1 text-xl font-semibold text-textPrimary">{{ (int) ($aiVisibilitySummary['citation_rate'] ?? 0) }}%</div>
                        </div>
                    @else
                        <div class="rounded-md border border-border bg-background px-3 py-3 sm:col-span-2">
                            <div class="text-xs text-textSecondary">AI Visibility</div>
                            <div class="mt-1 text-sm text-textPrimary">No tracked query signals yet</div>
                        </div>
                    @endif
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface">
            <div class="flex flex-col gap-2 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Top Opportunities</h2>
                    <p class="mt-1 text-sm text-textSecondary">Highest ranked stored-signal recommendations.</p>
                </div>
                <span class="text-sm text-textSecondary">Showing {{ $topOpportunities->count() }} of {{ $opportunities->count() }}</span>
            </div>
            <div class="divide-y divide-border">
                @forelse ($topOpportunities as $opportunity)
                    @php
                        $scoreExplanation = (array) data_get($opportunity->payload, 'score_explanation', []);
                        $scoreReasons = (array) ($scoreExplanation['reasons'] ?? []);
                    @endphp
                    <article class="px-5 py-4">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', (string) $opportunity->type) }}</span>
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">Priority {{ $opportunity->priority_score }}</span>
                                </div>
                                <h3 class="mt-2 text-sm font-semibold text-textPrimary">{{ $opportunity->title }}</h3>
                                <p class="mt-1 text-sm text-textSecondary">{{ $scoreExplanation['summary'] ?? 'Ranked by deterministic stored-signal scoring.' }}</p>
                            </div>
                            @if ($scoreExplanation)
                                <details class="shrink-0 rounded-md border border-border bg-background px-3 py-2 text-xs text-textSecondary lg:w-80">
                                    <summary class="cursor-pointer font-medium text-textPrimary">Why</summary>
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        <span class="rounded-md border border-border bg-surface px-2 py-1">Impact {{ $scoreExplanation['impact_score'] ?? 'n/a' }}</span>
                                        <span class="rounded-md border border-border bg-surface px-2 py-1">Effort {{ $scoreExplanation['effort_score'] ?? 'n/a' }}</span>
                                        <span class="rounded-md border border-border bg-surface px-2 py-1">Confidence {{ $scoreExplanation['confidence_score'] ?? 'n/a' }}</span>
                                        <span class="rounded-md border border-border bg-surface px-2 py-1">Risk {{ $scoreExplanation['risk_score'] ?? 'n/a' }}</span>
                                    </div>
                                    @if ($scoreReasons)
                                        <ul class="mt-2 list-disc space-y-1 pl-4">
                                            @foreach (array_slice($scoreReasons, 0, 3) as $reason)
                                                <li>{{ $reason }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </details>
                            @endif
                        </div>
                    </article>
                @empty
                    <p class="px-5 py-6 text-sm text-textSecondary">No opportunities are linked yet.</p>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface">
            <div class="flex flex-col gap-2 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Actions</h2>
                    <p class="mt-1 text-sm text-textSecondary">Reviewable work generated from this objective.</p>
                </div>
                <span class="text-sm text-textSecondary">Showing {{ $visibleActions->count() }} of {{ $actions->count() }}</span>
            </div>
            <div class="divide-y divide-border">
                @forelse ($visibleActions as $action)
                    @php
                        $planning = (array) data_get($action->payload, 'planning', []);
                        $risk = (string) ($planning['risk_level'] ?? 'unknown');
                    @endphp
                    <article class="px-5 py-4">
                        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $statusClasses[$action->status] ?? 'bg-slate-100 text-slate-700' }}">{{ ucfirst($action->status) }}</span>
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ $formatActionType($action->action_type) }}</span>
                                    <span class="rounded-full border px-2.5 py-1 text-xs {{ $riskClass($risk) }}">Risk {{ ucfirst($risk) }}</span>
                                </div>
                                <p class="mt-2 text-sm font-medium text-textPrimary">{{ $action->opportunity?->title ?? data_get($action->payload, 'recommendation', 'Supervised action') }}</p>
                                <div class="mt-2 flex flex-wrap gap-1.5 text-[11px] text-textSecondary">
                                    @if ($action->estimated_credits)
                                        <span class="rounded-md border border-border bg-background px-2 py-1">Cost {{ $action->estimated_credits }} credits</span>
                                    @endif
                                    @if (array_key_exists('approval_required', $planning))
                                        <span class="rounded-md border border-border bg-background px-2 py-1">{{ $planning['approval_required'] ? 'Approval required' : 'Policy pre-cleared' }}</span>
                                    @endif
                                </div>
                            </div>
                            <a href="{{ route('app.agentic-marketing.actions.show', $action) }}" class="pl-btn-ghost">
                                <i data-lucide="arrow-right" class="h-4 w-4"></i>
                                <span>Open</span>
                            </a>
                        </div>
                    </article>
                @empty
                    <p class="px-5 py-6 text-sm text-textSecondary">No supervised actions are linked yet.</p>
                @endforelse
            </div>
        </section>

        <details class="rounded-lg border border-border bg-surface">
            <summary class="cursor-pointer px-5 py-4 text-base font-semibold text-textPrimary">Activity and Audit Trail</summary>
            <div class="grid gap-4 border-t border-border p-5 xl:grid-cols-2">
                <div>
                    <h3 class="text-sm font-semibold text-textPrimary">Recent Run Items</h3>
                    <div class="mt-3 divide-y divide-border rounded-lg border border-border">
                        @forelse ($recentRunItems as $item)
                            <article class="px-4 py-3">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="truncate text-sm font-medium text-textPrimary">{{ $item->name }}</p>
                                    <span class="shrink-0 text-xs text-textSecondary">{{ optional($item->created_at)->diffForHumans() }}</span>
                                </div>
                                <div class="mt-2 flex flex-wrap gap-1.5 text-[11px] text-textSecondary">
                                    <span class="rounded-md border border-border bg-background px-2 py-1">{{ ucfirst((string) $item->type) }}</span>
                                    <span class="rounded-md border border-border bg-background px-2 py-1">{{ ucfirst((string) $item->status) }}</span>
                                </div>
                                @if ($item->error_message)
                                    <p class="mt-2 rounded-md bg-rose-50 px-2 py-1 text-xs text-rose-800">{{ $item->error_message }}</p>
                                @endif
                            </article>
                        @empty
                            <p class="px-4 py-5 text-sm text-textSecondary">No analysis runs are linked yet.</p>
                        @endforelse
                    </div>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-textPrimary">Recent Audit Events</h3>
                    <div class="mt-3 divide-y divide-border rounded-lg border border-border">
                        @forelse ($recentAuditLogs as $audit)
                            <article class="px-4 py-3">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="truncate text-sm font-medium text-textPrimary">{{ str_replace('_', ' ', (string) $audit->event) }}</p>
                                    <span class="shrink-0 text-xs text-textSecondary">{{ optional($audit->created_at)->diffForHumans() }}</span>
                                </div>
                                <p class="mt-1 truncate text-xs text-textSecondary">{{ class_basename((string) $audit->subject_type) }} {{ $audit->subject_id }}</p>
                            </article>
                        @empty
                            <p class="px-4 py-5 text-sm text-textSecondary">No audit events recorded yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </details>
    </div>
@endsection
