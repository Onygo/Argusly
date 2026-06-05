@extends('layouts.app', ['title' => 'Agentic Marketing', 'pageWidth' => 'wide'])

@php
    $statusClasses = [
        'proposed' => 'bg-slate-100 text-slate-700',
        'approved' => 'bg-blue-100 text-blue-800',
        'running' => 'bg-amber-100 text-amber-800',
        'completed' => 'bg-emerald-100 text-emerald-800',
        'failed' => 'bg-rose-100 text-rose-800',
        'dismissed' => 'bg-slate-100 text-slate-500',
        'approval_required' => 'bg-amber-100 text-amber-800',
        'blocked' => 'bg-rose-100 text-rose-800',
        'queued' => 'bg-blue-100 text-blue-800',
        'cancelled' => 'bg-slate-100 text-slate-500',
    ];
@endphp

@section('content')
    <div class="space-y-6">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-xl font-semibold text-textPrimary">Agentic Marketing Command Center</h1>
                <p class="mt-1 max-w-3xl text-sm text-textSecondary">
                    Monitor objectives, opportunity intelligence, proposed actions, cost exposure, and supervised execution from one operating view.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('app.agentic-marketing.approvals.index') }}" class="pl-btn-ghost">
                    <i data-lucide="inbox" class="h-4 w-4"></i>
                    <span>Approval inbox</span>
                </a>
                <a href="{{ route('app.agentic-marketing.orchestration.index') }}" class="pl-btn-ghost">
                    <i data-lucide="bot" class="h-4 w-4"></i>
                    <span>Agent orchestration</span>
                </a>
                <a href="{{ route('app.agentic-marketing.campaign-clusters.index') }}" class="pl-btn-ghost">
                    <i data-lucide="network" class="h-4 w-4"></i>
                    <span>Campaign clusters</span>
                </a>
                <a href="{{ route('app.agentic-marketing.content-opportunities.index') }}" class="pl-btn-ghost">
                    <i data-lucide="lightbulb" class="h-4 w-4"></i>
                    <span>Content opportunities</span>
                </a>
                <a href="{{ route('app.agentic-marketing.objectives.create') }}" class="pl-btn-primary">
                    <i data-lucide="plus" class="h-4 w-4"></i>
                    <span>New objective</span>
                </a>
            </div>
        </header>

        @if (session('status'))
            <x-alert class="mb-4">{{ session('status') }}</x-alert>
        @endif

        @if (($budgetSummaries ?? collect())->contains(fn ($summary) => ($summary['is_low'] ?? false) || ($summary['is_exceeded'] ?? false) || ($summary['is_forecast_exceeded'] ?? false)))
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                One or more Agentic Marketing objectives are close to their monthly objective cap, or their proposed work exceeds it. Wallet credits may still be available; execution is checked per action before generation.
            </div>
        @endif

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-lg border border-border bg-surface px-4 py-3">
                <div class="text-xs text-textSecondary">Objectives</div>
                <div class="mt-2 text-2xl font-semibold text-textPrimary">{{ $overview['objectives'] ?? 0 }}</div>
                <p class="mt-1 text-xs text-textSecondary">{{ $overview['open_opportunities'] ?? 0 }} open opportunities</p>
            </div>
            <div class="rounded-lg border border-border bg-surface px-4 py-3">
                <div class="text-xs text-textSecondary">Action Readiness</div>
                <div class="mt-2 text-2xl font-semibold text-textPrimary">{{ $overview['approved_actions'] ?? 0 }}</div>
                <p class="mt-1 text-xs text-textSecondary">{{ $overview['proposed_actions'] ?? 0 }} proposed, {{ $overview['running_actions'] ?? 0 }} running</p>
            </div>
            <div class="rounded-lg border border-border bg-surface px-4 py-3">
                <div class="text-xs text-textSecondary">Cost Forecast</div>
                <div class="mt-2 text-2xl font-semibold text-textPrimary">{{ number_format((int) ($overview['forecast_credits'] ?? 0)) }}</div>
                <p class="mt-1 text-xs text-textSecondary">credits in proposed and approved work</p>
            </div>
            <div class="rounded-lg border border-border bg-surface px-4 py-3">
                <div class="text-xs text-textSecondary">Impact Tracked</div>
                <div class="mt-2 text-2xl font-semibold text-textPrimary">{{ $overview['completed_actions'] ?? 0 }}</div>
                <p class="mt-1 text-xs text-textSecondary">{{ $overview['high_risk_actions'] ?? 0 }} high-risk proposals</p>
            </div>
        </section>

        @if ($executionWorkspace && $executionSettings)
            @php
                $isAutonomous = $executionSettings->isAutonomous();
                $allowedSiteIds = collect((array) ($executionSettings->allowed_site_ids ?? []))->map(fn ($id) => (string) $id)->all();
            @endphp
            <section class="rounded-lg border {{ $isAutonomous ? 'border-amber-200 bg-amber-50/40' : 'border-border bg-surface' }}">
                <div class="grid gap-5 p-5 xl:grid-cols-[minmax(0,1fr)_360px]">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $isAutonomous ? 'bg-amber-100 text-amber-900' : 'bg-emerald-100 text-emerald-800' }}">
                                {{ $isAutonomous ? 'Autonomous mode active' : 'Guided mode active' }}
                            </span>
                            <span class="rounded-full border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ $executionWorkspace->display_name ?? $executionWorkspace->name }}</span>
                        </div>
                        <h2 class="mt-3 text-base font-semibold text-textPrimary">Agentic Marketing Execution Mode</h2>
                        <div class="mt-3 grid gap-3 md:grid-cols-2">
                            <div class="rounded-md border border-border bg-background p-3">
                                <p class="text-xs font-semibold text-textPrimary">Guided</p>
                                <p class="mt-1 text-xs text-textSecondary">Argusly proposes briefs, plans, and content changes. A customer reviews and approves before work runs.</p>
                            </div>
                            <div class="rounded-md border border-amber-200 bg-white p-3">
                                <p class="text-xs font-semibold text-amber-900">Autonomous</p>
                                <p class="mt-1 text-xs text-amber-900/80">Argusly can run selected action types within configured site, credit, priority, and approval limits.</p>
                            </div>
                        </div>
                        <div class="mt-4 grid gap-3 text-xs text-textSecondary sm:grid-cols-2 xl:grid-cols-4">
                            <div class="rounded-md border border-border bg-background px-3 py-2">Daily limit <span class="font-semibold text-textPrimary">{{ $executionSettings->max_autonomous_actions_per_day }}</span></div>
                            <div class="rounded-md border border-border bg-background px-3 py-2">Monthly credits <span class="font-semibold text-textPrimary">{{ number_format((int) $executionSettings->max_autonomous_credits_per_month) }}</span></div>
                            <div class="rounded-md border border-border bg-background px-3 py-2">Approval over score <span class="font-semibold text-textPrimary">{{ $executionSettings->require_approval_above_priority_score }}</span></div>
                            <div class="rounded-md border border-border bg-background px-3 py-2">Last autonomous <span class="font-semibold text-textPrimary">{{ $lastAutonomousAction?->updated_at?->diffForHumans() ?? 'Never' }}</span></div>
                        </div>

                        <div class="mt-4 rounded-md border border-border bg-background p-3">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-xs font-semibold text-textPrimary">Next eligible autonomous actions</p>
                                <span class="text-xs text-textSecondary">{{ $nextEligibleAutonomousActions->count() }} ready</span>
                            </div>
                            <div class="mt-2 space-y-2">
                                @forelse ($nextEligibleAutonomousActions as $item)
                                    @php
                                        $eligibleAction = $item['action'];
                                    @endphp
                                    <div class="flex flex-col gap-1 rounded-md border border-border bg-surface px-3 py-2 sm:flex-row sm:items-center sm:justify-between">
                                        <span class="text-xs text-textPrimary">{{ str_replace('_', ' ', $eligibleAction->action_type) }} · {{ $eligibleAction->opportunity?->title ?? $eligibleAction->objective?->name }}</span>
                                        <span class="text-xs text-emerald-700">{{ data_get($item, 'decision.reason') }}</span>
                                    </div>
                                @empty
                                    <p class="text-xs text-textSecondary">No actions are currently eligible for autonomous execution. Policy, approval, site, or credit rules may still require review.</p>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <form method="POST" action="{{ route('app.settings.agentic-marketing-execution.update') }}" class="rounded-lg border border-border bg-surface p-4">
                        @csrf
                        <div class="grid gap-3">
                            <label class="text-xs font-semibold text-textPrimary">Current mode</label>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <label class="flex items-center gap-2 rounded-md border border-border bg-background px-3 py-2 text-sm">
                                    <input type="radio" name="agentic_execution_mode" value="guided" @checked(! $isAutonomous)>
                                    <span>Guided</span>
                                </label>
                                <label class="flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                    <input type="radio" name="agentic_execution_mode" value="autonomous" @checked($isAutonomous)>
                                    <span>Autonomous</span>
                                </label>
                            </div>
                            @unless ($isAutonomous)
                                <label class="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                    <input class="mt-0.5" type="checkbox" name="autonomous_opt_in_confirmation" value="1">
                                    <span>I understand autonomous mode can execute selected actions automatically within these limits.</span>
                                </label>
                            @endunless
                            <div class="grid gap-2 text-xs text-textSecondary">
                                @foreach ([
                                    'autonomous_brief_generation_enabled' => 'Brief generation',
                                    'autonomous_chained_plans_enabled' => 'Chained plans',
                                    'autonomous_refresh_enabled' => 'Refresh and answer updates',
                                    'autonomous_internal_linking_enabled' => 'Internal linking',
                                    'autonomous_publication_enabled' => 'Publication',
                                ] as $field => $label)
                                    <label class="flex items-center justify-between gap-3 rounded-md border border-border bg-background px-3 py-2">
                                        <span>{{ $label }}</span>
                                        <input type="checkbox" name="{{ $field }}" value="1" @checked((bool) $executionSettings->{$field})>
                                    </label>
                                @endforeach
                            </div>
                            <div class="grid gap-2 sm:grid-cols-3">
                                <label class="text-xs text-textSecondary">Daily limit
                                    <input class="pl-input mt-1 w-full" type="number" min="1" max="100" name="max_autonomous_actions_per_day" value="{{ $executionSettings->max_autonomous_actions_per_day }}">
                                </label>
                                <label class="text-xs text-textSecondary">Monthly credits
                                    <input class="pl-input mt-1 w-full" type="number" min="1" max="100000" name="max_autonomous_credits_per_month" value="{{ $executionSettings->max_autonomous_credits_per_month }}">
                                </label>
                                <label class="text-xs text-textSecondary">Approval score
                                    <input class="pl-input mt-1 w-full" type="number" min="0" max="100" name="require_approval_above_priority_score" value="{{ $executionSettings->require_approval_above_priority_score }}">
                                </label>
                            </div>
                            <div class="grid gap-2 text-xs text-textSecondary">
                                <label class="flex items-center gap-2"><input type="checkbox" name="require_approval_for_new_pages" value="1" @checked($executionSettings->require_approval_for_new_pages)> Require approval for new pages</label>
                                <label class="flex items-center gap-2"><input type="checkbox" name="require_approval_for_external_publication" value="1" @checked($executionSettings->require_approval_for_external_publication)> Require approval for external publication</label>
                                <label class="flex items-center gap-2"><input type="checkbox" name="notification_email_enabled" value="1" @checked($executionSettings->notification_email_enabled)> Email notifications</label>
                            </div>
                            <div class="rounded-md border border-border bg-background p-3">
                                <p class="text-xs font-semibold text-textPrimary">Allowed publishing sites</p>
                                <div class="mt-2 grid gap-2 text-xs text-textSecondary">
                                    @forelse ($executionWorkspace->clientSites as $site)
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" name="allowed_site_ids[]" value="{{ $site->id }}" @checked(in_array((string) $site->id, $allowedSiteIds, true))>
                                            <span>{{ $site->name }}</span>
                                        </label>
                                    @empty
                                        <p>No active publishing sites are connected.</p>
                                    @endforelse
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if ($isAutonomous)
                                    <button class="pl-btn-ghost" name="agentic_execution_mode" value="guided" type="submit">
                                        <i data-lucide="pause-circle" class="h-4 w-4"></i>
                                        <span>Pause autonomous execution</span>
                                    </button>
                                @endif
                                <button class="pl-btn-primary" type="submit">
                                    <i data-lucide="shield-check" class="h-4 w-4"></i>
                                    <span>Save execution rules</span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </section>
        @endif

        <section class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-base font-semibold text-textPrimary">Recent Agentic Action Runs</h2>
                <p class="mt-1 text-sm text-textSecondary">A traceable ledger of proposed, approved, queued, blocked, failed, and completed Agentic Marketing actions.</p>
                <form method="GET" action="{{ route('app.agentic-marketing.index') }}" class="mt-4 grid gap-3 md:grid-cols-4">
                    <select name="run_status" class="pl-input w-full text-sm">
                        <option value="">All run statuses</option>
                        @foreach ($actionRunStatusOptions as $status)
                            <option value="{{ $status }}" @selected(($runFilters['run_status'] ?? '') === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>
                        @endforeach
                    </select>
                    <select name="run_action_type" class="pl-input w-full text-sm">
                        <option value="">All action types</option>
                        @foreach ($actionTypeOptions as $type)
                            <option value="{{ $type }}" @selected(($runFilters['run_action_type'] ?? '') === $type)>{{ str_replace('_', ' ', $type) }}</option>
                        @endforeach
                    </select>
                    <select name="run_execution_mode" class="pl-input w-full text-sm">
                        <option value="">All execution modes</option>
                        <option value="guided" @selected(($runFilters['run_execution_mode'] ?? '') === 'guided')>Guided</option>
                        <option value="autonomous" @selected(($runFilters['run_execution_mode'] ?? '') === 'autonomous')>Autonomous</option>
                    </select>
                    <button class="pl-btn-primary justify-center" type="submit">
                        <i data-lucide="filter" class="h-4 w-4"></i>
                        <span>Filter runs</span>
                    </button>
                </form>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-border text-sm">
                    <thead class="bg-background text-xs uppercase tracking-wide text-textSecondary">
                        <tr>
                            <th class="px-5 py-3 text-left font-medium">Action</th>
                            <th class="px-5 py-3 text-left font-medium">Mode</th>
                            <th class="px-5 py-3 text-left font-medium">Status</th>
                            <th class="px-5 py-3 text-left font-medium">Signal</th>
                            <th class="px-5 py-3 text-left font-medium">Credits</th>
                            <th class="px-5 py-3 text-left font-medium">Reason</th>
                            <th class="px-5 py-3 text-left font-medium">Updated</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($recentActionRuns as $run)
                            @php
                                $learningSignal = (array) data_get($run->output_snapshot, 'learning_signal', []);
                                $signalSummary = (string) data_get($learningSignal, 'summary', '');
                                $impactScore = data_get($learningSignal, 'impact_score');
                                $durationSeconds = data_get($learningSignal, 'measurements.job_duration_seconds');
                            @endphp
                            <tr>
                                <td class="px-5 py-3 text-textPrimary">
                                    @if ($run->action)
                                        <a href="{{ route('app.agentic-marketing.actions.show', $run->action) }}" class="font-medium hover:text-primary">{{ str_replace('_', ' ', $run->action_type) }}</a>
                                    @else
                                        <span class="font-medium">{{ str_replace('_', ' ', $run->action_type) }}</span>
                                    @endif
                                    <div class="mt-1 text-xs text-textSecondary">{{ $run->goal?->name ?? $run->workspace?->name }}</div>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ ucfirst($run->execution_mode_snapshot) }}</span>
                                    @if ($run->executed_by_agent)
                                        <span class="ml-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs text-amber-800">Agent</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $statusClasses[$run->status] ?? 'bg-slate-100 text-slate-700' }}">{{ str_replace('_', ' ', ucfirst($run->status)) }}</span>
                                </td>
                                <td class="max-w-xs px-5 py-3 text-xs text-textSecondary">
                                    @if ($learningSignal)
                                        <div class="font-medium text-textPrimary">{{ $signalSummary ?: 'Learning signal recorded.' }}</div>
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @if (is_numeric($impactScore))
                                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700">Impact {{ (int) $impactScore >= 0 ? '+' : '' }}{{ $impactScore }}</span>
                                            @endif
                                            @if (is_numeric($durationSeconds))
                                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600">{{ $durationSeconds }}s</span>
                                            @endif
                                            @if (data_get($learningSignal, 'classifiers.high_cost_low_impact'))
                                                <span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-800">High cost low impact</span>
                                            @endif
                                        </div>
                                    @else
                                        <span>No signal yet</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-textSecondary">{{ number_format((int) ($run->actual_credits ?? $run->estimated_credits ?? 0)) }}</td>
                                <td class="max-w-md px-5 py-3 text-textSecondary">{{ $run->error_message ?: $run->reason ?: 'Recorded for audit.' }}</td>
                                <td class="px-5 py-3 text-textSecondary">{{ $run->updated_at?->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-8 text-center text-sm text-textSecondary">No Agentic Action runs have been recorded yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface">
            <div class="flex flex-col gap-3 border-b border-border px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Objectives Overview</h2>
                    <p class="mt-1 text-sm text-textSecondary">Each objective anchors detection, planning, approvals, and reporting.</p>
                </div>
            </div>

            <div class="divide-y divide-border">
                @forelse ($objectives as $objective)
                    @php
                        $budget = ($budgetSummaries ?? collect())->get((string) $objective->id, []);
                    @endphp
                    <article class="px-5 py-4">
                        <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-800">{{ ucfirst((string) $objective->status) }}</span>
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ strtoupper((string) ($objective->locale ?? 'en')) }}</span>
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', (string) $objective->approval_mode) }}</span>
                                </div>
                                <h3 class="mt-2 text-sm font-semibold text-textPrimary">
                                    <a href="{{ route('app.agentic-marketing.objectives.show', $objective) }}" class="hover:text-primary">{{ $objective->name }}</a>
                                </h3>
                                <p class="mt-1 max-w-4xl text-sm text-textSecondary">{{ $objective->goal }}</p>
                                <div class="mt-3 grid gap-2 text-xs text-textSecondary sm:grid-cols-2 lg:grid-cols-4">
                                    <span class="rounded-md border border-border bg-background px-2 py-1">{{ $objective->workspace?->name ?? 'No workspace' }}</span>
                                    <span class="rounded-md border border-border bg-background px-2 py-1">{{ $objective->clientSite?->name ?? 'All sites' }}</span>
                                    <span class="rounded-md border border-border bg-background px-2 py-1">{{ $objective->opportunities_count }} opportunities</span>
                                    <span class="rounded-md border border-border bg-background px-2 py-1">{{ $objective->actions_count }} actions</span>
                                </div>
                                @if (($budget['is_low'] ?? false) || ($budget['is_exceeded'] ?? false) || ($budget['is_forecast_exceeded'] ?? false))
                                    <p class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                                        @if ($budget['is_exceeded'] ?? false)
                                            Objective cap exceeded: {{ number_format((int) ($budget['remaining'] ?? 0)) }} credits remaining after captured and reserved work.
                                        @elseif ($budget['is_forecast_exceeded'] ?? false)
                                            Proposed work exceeds this objective cap by {{ number_format(abs((int) ($budget['forecast_remaining'] ?? 0))) }} credits. Increase the objective budget or approve fewer actions.
                                        @else
                                            Objective cap running low: {{ number_format((int) ($budget['remaining'] ?? 0)) }} credits remaining before forecast.
                                        @endif
                                    </p>
                                @endif
                            </div>
                            <div class="flex shrink-0 flex-wrap gap-2">
                                <a href="{{ route('app.agentic-marketing.objectives.show', $objective) }}" class="pl-btn-ghost">
                                    <i data-lucide="layout-dashboard" class="h-4 w-4"></i>
                                    <span>Open</span>
                                </a>
                                <a href="{{ route('app.agentic-marketing.objectives.edit', $objective) }}" class="pl-btn-ghost">
                                    <i data-lucide="pencil" class="h-4 w-4"></i>
                                    <span>Edit</span>
                                </a>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="px-5 py-12 text-center">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-lg bg-primarySoftBg text-primary">
                            <i data-lucide="workflow" class="h-5 w-5"></i>
                        </div>
                        <p class="mt-4 text-sm font-medium text-textPrimary">Create your first Agentic Marketing objective</p>
                        <p class="mx-auto mt-2 max-w-2xl text-sm text-textSecondary">Objectives define the goal, audience, locale, workspace, approval posture, and budget that the command center monitors.</p>
                        <a href="{{ route('app.agentic-marketing.objectives.create') }}" class="pl-btn-primary mt-4">
                            <i data-lucide="plus" class="h-4 w-4"></i>
                            <span>New objective</span>
                        </a>
                    </div>
                @endforelse
            </div>
        </section>

        <section class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-base font-semibold text-textPrimary">Action Queue</h2>
                <p class="mt-1 text-sm text-textSecondary">Filter proposed and approved work before supervised execution.</p>
                <form method="GET" action="{{ route('app.agentic-marketing.index') }}" class="mt-4 grid gap-3 md:grid-cols-5">
                    <select name="status" class="pl-input w-full text-sm">
                        <option value="">All statuses</option>
                        @foreach ($actionStatusOptions as $status)
                            <option value="{{ $status }}" @selected(($actionFilters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
                        @endforeach
                    </select>
                    <select name="type" class="pl-input w-full text-sm">
                        <option value="">All action types</option>
                        @foreach ($actionTypeOptions as $type)
                            <option value="{{ $type }}" @selected(($actionFilters['type'] ?? '') === $type)>{{ str_replace('_', ' ', $type) }}</option>
                        @endforeach
                    </select>
                    <select name="risk" class="pl-input w-full text-sm">
                        <option value="">All risk levels</option>
                        @foreach ($riskOptions as $risk)
                            <option value="{{ $risk }}" @selected(($actionFilters['risk'] ?? '') === $risk)>{{ ucfirst($risk) }}</option>
                        @endforeach
                    </select>
                    <select name="approval_mode" class="pl-input w-full text-sm">
                        <option value="">All approval modes</option>
                        @foreach ($approvalModes as $mode)
                            <option value="{{ $mode }}" @selected(($actionFilters['approval_mode'] ?? '') === $mode)>{{ str_replace('_', ' ', $mode) }}</option>
                        @endforeach
                    </select>
                    <div class="flex gap-2">
                        <select name="objective" class="pl-input min-w-0 flex-1 text-sm">
                            <option value="">All objectives</option>
                            @foreach ($objectives as $objective)
                                <option value="{{ $objective->id }}" @selected(($actionFilters['objective'] ?? '') === (string) $objective->id)>{{ $objective->name }}</option>
                            @endforeach
                        </select>
                        <button class="pl-btn-primary" type="submit">
                            <i data-lucide="filter" class="h-4 w-4"></i>
                            <span>Apply</span>
                        </button>
                    </div>
                </form>
            </div>

            <div class="divide-y divide-border">
                @forelse ($actions as $action)
                    @php
                        $result = (array) ($action->result ?? []);
                        $payload = (array) ($action->payload ?? []);
                        $summary = (string) ($result['summary'] ?? $payload['recommendation'] ?? $payload['reason'] ?? 'No summary available yet.');
                        $scoreExplanation = (array) data_get($action->opportunity?->payload, 'score_explanation', []);
                        $planning = (array) data_get($payload, 'planning', []);
                        $scoreReasons = (array) ($scoreExplanation['reasons'] ?? []);
                        $gateDecision = ($actionGateDecisions ?? collect())->get((string) $action->id, []);
                        $gateMode = (string) data_get($gateDecision, 'policy_snapshot.mode', 'guided');
                        $gateAllowed = (bool) data_get($gateDecision, 'allowed', false);
                        $gateRequiresApproval = (bool) data_get($gateDecision, 'requires_approval', false);
                        $gateBlocked = (bool) data_get($gateDecision, 'blocked', false);
                        $destination = $action->objective?->clientSite?->name ?? data_get($payload, 'client_site_name') ?? data_get($payload, 'client_site_id') ?? 'No publishing site selected';
                    @endphp
                    <article class="px-5 py-4">
                        <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_320px_auto] xl:items-start">
                            <div class="min-w-0 space-y-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $statusClasses[$action->status] ?? 'bg-slate-100 text-slate-700' }}">{{ ucfirst($action->status) }}</span>
                                    @if ($gateBlocked)
                                        <span class="rounded-full bg-rose-100 px-2.5 py-1 text-xs font-medium text-rose-800">Blocked</span>
                                    @elseif ($gateRequiresApproval)
                                        <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-800">This action requires approval</span>
                                    @elseif ($gateMode === 'autonomous' && $gateAllowed)
                                        <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-medium text-emerald-800">This action can run autonomously</span>
                                    @else
                                        <span class="rounded-full bg-blue-100 px-2.5 py-1 text-xs font-medium text-blue-800">Guided execution</span>
                                    @endif
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $action->action_type) }}</span>
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ $action->objective?->name ?? 'No objective' }}</span>
                                </div>
                                <h3 class="text-sm font-semibold text-textPrimary">
                                    <a href="{{ route('app.agentic-marketing.actions.show', $action) }}" class="hover:text-primary">{{ $action->opportunity?->title ?? $action->objective?->goal ?? 'Agentic Marketing action' }}</a>
                                </h3>
                                <p class="max-w-4xl text-sm text-textSecondary">{{ $summary }}</p>
                                <div class="flex flex-wrap gap-1.5 text-[11px] text-textSecondary">
                                    @if ($action->estimated_credits)
                                        <span class="rounded-md border border-border bg-background px-2 py-1">Cost {{ $action->estimated_credits }} credits</span>
                                    @endif
                                    <span class="rounded-md border border-border bg-background px-2 py-1">Destination {{ $destination }}</span>
                                    @if (! empty($planning['risk_level']))
                                        <span class="rounded-md border border-border bg-background px-2 py-1">Risk {{ ucfirst((string) $planning['risk_level']) }}</span>
                                    @endif
                                    @if (array_key_exists('approval_required', $planning))
                                        <span class="rounded-md border border-border bg-background px-2 py-1">{{ $planning['approval_required'] ? 'Approval required' : 'Policy pre-cleared' }}</span>
                                    @endif
                                </div>
                            </div>

                            <aside class="rounded-md border border-border bg-background px-3 py-2">
                                <p class="text-xs font-semibold text-textPrimary">Why this action?</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ $scoreExplanation['summary'] ?? $planning['approval_reason'] ?? 'This action was generated from stored Agentic Marketing planning metadata.' }}</p>
                                <p class="mt-2 rounded-md border border-border bg-surface px-2 py-1 text-xs text-textSecondary">
                                    Execution: {{ data_get($gateDecision, 'reason', 'No execution decision has been recorded yet.') }}
                                </p>
                                @if ($gateBlocked)
                                    <p class="mt-2 rounded-md border border-rose-200 bg-rose-50 px-2 py-1 text-xs text-rose-800">Blocked reason: {{ data_get($gateDecision, 'reason') }}</p>
                                @endif
                                @if ($scoreReasons)
                                    <ul class="mt-2 list-disc space-y-1 pl-4 text-xs text-textSecondary">
                                        @foreach (array_slice($scoreReasons, 0, 2) as $reason)
                                            <li>{{ $reason }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </aside>

                            <div class="flex shrink-0 flex-wrap gap-2">
                                @if ($action->status === 'proposed')
                                    <form method="POST" action="{{ route('app.agentic-marketing.actions.approve', $action) }}">
                                        @csrf
                                        <button class="pl-btn-primary" type="submit"><i data-lucide="check" class="h-4 w-4"></i><span>{{ $gateMode === 'autonomous' && $gateAllowed ? 'Allow agent to execute' : 'Submit for approval' }}</span></button>
                                    </form>
                                    <form method="POST" action="{{ route('app.agentic-marketing.actions.dismiss', $action) }}">
                                        @csrf
                                        <button class="pl-btn-ghost" type="submit"><i data-lucide="x" class="h-4 w-4"></i><span>{{ $gateMode === 'autonomous' ? 'Require approval for this action' : 'Dismiss' }}</span></button>
                                    </form>
                                @elseif ($action->status === 'approved')
                                    <form method="POST" action="{{ route('app.agentic-marketing.actions.execute', $action) }}">
                                        @csrf
                                        <button class="pl-btn-primary" type="submit"><i data-lucide="play" class="h-4 w-4"></i><span>{{ $gateMode === 'autonomous' ? 'Run once manually' : 'Approve and run' }}</span></button>
                                    </form>
                                @elseif ($action->status === 'failed')
                                    <form method="POST" action="{{ route('app.agentic-marketing.actions.retry', $action) }}">
                                        @csrf
                                        <button class="pl-btn-primary" type="submit"><i data-lucide="rotate-cw" class="h-4 w-4"></i><span>Retry</span></button>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="px-5 py-10 text-center">
                        <p class="text-sm font-medium text-textPrimary">No actions match this view</p>
                        <p class="mt-1 text-sm text-textSecondary">Adjust filters or generate actions from objective opportunities.</p>
                    </div>
                @endforelse
            </div>

            @if ($actions->hasPages())
                <div class="border-t border-border px-5 py-4">{{ $actions->links() }}</div>
            @endif
        </section>
    </div>
@endsection
