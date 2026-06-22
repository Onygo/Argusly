@extends('layouts.app')

@section('content')
@php
    $formatLabel = fn (?string $value): string => $value ? str($value)->replace(['_', '-'], ' ')->headline()->toString() : 'Any';
    $statusTone = fn (?string $status): string => match ($status) {
        'resolved', 'published' => 'green',
        'dismissed', 'archived' => 'slate',
        'reviewing', 'processing' => 'amber',
        'detected', 'new' => 'blue',
        default => 'slate',
    };
    $severityTone = fn (?string $severity): string => match ($severity) {
        'critical', 'high' => 'red',
        'medium' => 'amber',
        'low' => 'blue',
        default => 'slate',
    };
    $sectionSummary = function (string $title, array $summary) {
        return [
            'title' => $title,
            'total' => (int) ($summary['total'] ?? 0),
            'open' => (int) ($summary['open'] ?? 0),
            'high' => (int) ($summary['high'] ?? 0),
            'avg' => (float) ($summary['avg_priority'] ?? 0),
            'latest' => $summary['latest'] ?? collect(),
        ];
    };
    $summaries = [
        $sectionSummary('Brand Monitor', $brandSummary),
        $sectionSummary('Competitor Monitor', $competitorSummary),
        $sectionSummary('Trends', $trendSummary),
        $sectionSummary('Risks', $riskSummary),
    ];
    $sectionNavItems = [
        ['id' => 'overview', 'label' => 'Overview', 'url' => '#overview', 'active' => true],
        ['id' => 'feed', 'label' => 'Signal Feed', 'url' => '#feed'],
        ['id' => 'detections', 'label' => 'Detections', 'url' => '#detections'],
        ['id' => 'priority', 'label' => 'Candidates', 'url' => '#priority'],
    ];
    $nextDetection = $openDetections->first();
    $signalEventCount = (int) ($metrics['events'] ?? 0);
    $openDetectionCount = (int) ($metrics['open_detections'] ?? 0);
    $candidateCount = (int) ($metrics['opportunities'] ?? 0);
    $totalDetectionCount = method_exists($detections, 'total') ? (int) $detections->total() : (int) $detections->count();
    $humanDetectionSummary = function ($detection) use ($formatLabel): string {
        $category = (string) ($detection->category ?? '');

        if (str_contains($category, 'risk')) {
            return 'This may need attention because the evidence points to visibility loss, absence, or competitor pressure.';
        }

        if ((float) ($detection->opportunity_score ?? 0) >= 70) {
            return 'This looks actionable because the evidence points to a topic that could become an opportunity.';
        }

        return 'Related signal evidence has been grouped so you can decide whether it matters.';
    };
@endphp

<div class="space-y-6">
    <x-app.insights-header
        :site="null"
        title="Signal Intelligence"
        description="Monitor brand, competitor, trend, risk and opportunity signals before they become leads or execution tasks."
        :nav-items="$sectionNavItems"
        :meta-items="[
            'Workspace: '.($workspace->display_name ?: $workspace->name),
            'Window: '.($filters['date_from'] ?: 'All').' to '.($filters['date_to'] ?: 'now'),
        ]"
    >
        <form method="POST" action="{{ route('app.signal-intelligence.run') }}" class="flex flex-wrap items-center gap-2">
            @csrf
            <input type="hidden" name="workspace" value="{{ $workspace->id }}">
            @if ($filters['site'])
                <input type="hidden" name="site" value="{{ $filters['site'] }}">
            @endif
            @if ($filters['date_from'])
                <input type="hidden" name="date_from" value="{{ $filters['date_from'] }}">
            @endif
            @if ($filters['date_to'])
                <input type="hidden" name="date_to" value="{{ $filters['date_to'] }}">
            @endif
            <select name="category" class="pl-work-select">
                <option value="all">All categories</option>
                <option value="brand_monitoring">Brand monitoring</option>
                <option value="competitor_monitoring">Competitor monitoring</option>
                <option value="trend_detection">Trend detection</option>
                <option value="risk_detection">Risk detection</option>
            </select>
            <button type="submit" class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                <i data-lucide="play" class="h-4 w-4"></i>
                Run detection
            </button>
        </form>
    </x-app.insights-header>

    @if (session('status'))
        <x-alert class="md:items-center" :icon="true">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->any())
        <x-alert class="border-danger/30 bg-danger/5 text-danger" :icon="true">
            {{ $errors->first() }}
        </x-alert>
    @endif

    @if (!empty($activation) && !data_get($activation, 'is_active'))
        <x-activation-banner :activation="$activation" compact />
    @endif

    <x-first-value-celebrations :items="$firstValueCelebrations ?? collect()" />

    @if (! empty($firstSignalCard))
        <x-first-value-card :card="$firstSignalCard" />
    @endif

    @if (! empty($firstDetectionCard))
        <x-first-value-card :card="$firstDetectionCard" />
    @endif

    @if ((int) ($metrics['events'] ?? 0) === 0 && isset($readiness) && $readiness?->status !== 'active')
        <x-empty-state-guide
            :state="$emptyStateGuide"
            :setup-url="in_array($readiness?->status, ['not_ready', 'partially_ready'], true) ? route('app.setup.index', ['workspace' => $workspace->id]) : null"
        />
    @elseif ((int) ($metrics['events'] ?? 0) === 0)
        @php
            $firstSite = $sites->first();
        @endphp
        <section class="rounded-lg border border-amber-200 bg-amber-50/70 p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">No signal evidence in this view</p>
                    <h2 class="mt-2 text-lg font-semibold text-textPrimary">Create AI Visibility evidence first</h2>
                    <p class="mt-1 text-sm leading-6 text-textSecondary">
                        Signal Intelligence can only create detections after signal events exist for the selected site and date window.
                        Run an AI Visibility check, or widen the filters if you already have older evidence.
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    @if ($firstSite)
                        <a href="{{ route('app.sites.llm-tracking.index', $firstSite) }}" class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                            <i data-lucide="sparkles" class="h-4 w-4"></i>
                            Open AI Visibility
                        </a>
                    @endif
                    <a href="{{ route('app.signal-intelligence.index', ['workspace' => $workspace->id]) }}" class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                        <i data-lucide="calendar-days" class="h-4 w-4"></i>
                        Show all dates
                    </a>
                </div>
            </div>
        </section>
    @endif

    <section class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
        <x-llm-tracking.metric-card label="Signal events" :value="number_format($metrics['events'])" helper="Matching current filters" />
        <x-llm-tracking.metric-card label="Open detections" :value="number_format($metrics['open_detections'])" tone="blue" helper="Review queue" />
        <x-llm-tracking.metric-card label="High priority" :value="number_format($metrics['high_priority'])" tone="amber" helper="Priority score 75+" />
        <x-llm-tracking.metric-card label="Risks" :value="number_format($metrics['risks'])" tone="rose" helper="Risk detections" />
        <x-llm-tracking.metric-card label="Opportunities" :value="number_format($metrics['opportunities'])" tone="emerald" helper="Candidates only" />
        <x-llm-tracking.metric-card label="Avg priority" :value="number_format($metrics['avg_priority'], 1)" helper="Detection average" />
    </section>

    <x-filter-bar>
        <form method="GET" action="{{ route('app.signal-intelligence.index') }}" class="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
            <select name="workspace" class="pl-work-select">
                @foreach ($workspaces as $option)
                    <option value="{{ $option->id }}" @selected((string) $workspace->id === (string) $option->id)>{{ $option->display_name ?: $option->name }}</option>
                @endforeach
            </select>
            <select name="site" class="pl-work-select">
                <option value="">All sites</option>
                @foreach ($sites as $site)
                    <option value="{{ $site->id }}" @selected($filters['site'] === (string) $site->id)>{{ $site->name }}</option>
                @endforeach
            </select>
            <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="pl-work-input" aria-label="Date from">
            <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="pl-work-input" aria-label="Date to">
            <select name="category" class="pl-work-select">
                <option value="">All detection categories</option>
                @foreach ($filterOptions['categories'] as $category)
                    <option value="{{ $category }}" @selected($filters['category'] === $category)>{{ $formatLabel($category) }}</option>
                @endforeach
                @foreach ($filterOptions['event_categories'] as $category)
                    <option value="{{ $category }}" @selected($filters['category'] === $category)>Event: {{ $formatLabel($category) }}</option>
                @endforeach
            </select>
            <select name="type" class="pl-work-select">
                <option value="">All types</option>
                @foreach ($filterOptions['types'] as $type)
                    <option value="{{ $type }}" @selected($filters['type'] === $type)>{{ $formatLabel($type) }}</option>
                @endforeach
            </select>
            <select name="source_type" class="pl-work-select">
                <option value="">All source types</option>
                @foreach ($filterOptions['source_types'] as $sourceType)
                    <option value="{{ $sourceType }}" @selected($filters['source_type'] === $sourceType)>{{ $formatLabel($sourceType) }}</option>
                @endforeach
            </select>
            <select name="status" class="pl-work-select">
                <option value="">All statuses</option>
                @foreach ($filterOptions['statuses'] as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $formatLabel($status) }}</option>
                @endforeach
            </select>
            <select name="severity" class="pl-work-select">
                <option value="">All severities</option>
                @foreach ($filterOptions['severities'] as $severity)
                    <option value="{{ $severity }}" @selected($filters['severity'] === $severity)>{{ $formatLabel($severity) }}</option>
                @endforeach
            </select>
            <input type="number" min="0" max="100" name="confidence_min" value="{{ $filters['confidence_min'] }}" placeholder="Min confidence" class="pl-work-input">
            <input type="number" min="0" max="100" name="score_min" value="{{ $filters['score_min'] }}" placeholder="Min score" class="pl-work-input">
            <input type="text" name="entity_name" value="{{ $filters['entity_name'] }}" placeholder="Entity" class="pl-work-input">
            <input type="text" name="topic" value="{{ $filters['topic'] }}" placeholder="Topic" class="pl-work-input">
            <div class="flex gap-2 md:col-span-3 xl:col-span-6">
                <button type="submit" class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                    <i data-lucide="filter" class="h-4 w-4"></i>
                    Apply
                </button>
                <a href="{{ route('app.signal-intelligence.index', ['workspace' => $workspace->id]) }}" class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textSecondary hover:bg-surfaceMuted">
                    <i data-lucide="rotate-ccw" class="h-4 w-4"></i>
                    Reset
                </a>
            </div>
        </form>
    </x-filter-bar>

    @if ($signalEventCount > 0 || $detections->total() > 0)
        <section class="rounded-lg border border-border bg-surface p-5">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">How to use this screen</p>
                    @if ($openDetectionCount > 0)
                        <h2 class="mt-2 text-lg font-semibold text-textPrimary">Start with detections that need a decision</h2>
                        <p class="mt-1 text-sm leading-6 text-textSecondary">
                            The Signal Feed is raw evidence from AI Visibility and other sources. Detections group related evidence into something you can review.
                            If a detection looks useful, open it and promote it into an Opportunity. If it is noise, dismiss or resolve it.
                        </p>
                    @else
                        <h2 class="mt-2 text-lg font-semibold text-textPrimary">All detections in this view are processed</h2>
                        <p class="mt-1 text-sm leading-6 text-textSecondary">
                            There is nothing left to review here. The table below is history: resolved, dismissed, published, or archived detections stay visible for audit context.
                            There is no action waiting right now. Run detection again when new AI Visibility evidence is available.
                        </p>
                    @endif
                </div>
                @if ($nextDetection)
                    <a href="{{ route('app.signal-intelligence.detections.show', $nextDetection) }}" class="inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                        <i data-lucide="eye" class="h-4 w-4"></i>
                        Review next detection
                    </a>
                @endif
            </div>

            <div class="mt-5 grid gap-3 md:grid-cols-3">
                <div class="rounded-md border border-border bg-background p-4">
                    <p class="text-sm font-semibold text-textPrimary">1. Check the evidence</p>
                    <p class="mt-1 text-xs leading-5 text-textSecondary">{{ number_format($signalEventCount) }} signal {{ $signalEventCount === 1 ? 'event' : 'events' }} found in this view. These are observations, not decisions yet.</p>
                </div>
                <div class="rounded-md border border-border bg-background p-4">
                    <p class="text-sm font-semibold text-textPrimary">2. Review detections</p>
                    <p class="mt-1 text-xs leading-5 text-textSecondary">{{ number_format($openDetectionCount) }} open {{ $openDetectionCount === 1 ? 'detection' : 'detections' }} need a human decision: review, dismiss, or resolve.</p>
                </div>
                <div class="rounded-md border border-border bg-background p-4">
                    <p class="text-sm font-semibold text-textPrimary">3. Promote useful opportunities</p>
                    <p class="mt-1 text-xs leading-5 text-textSecondary">{{ number_format($candidateCount) }} open {{ $candidateCount === 1 ? 'candidate' : 'candidates' }} may be worth turning into an Opportunity for planning and content.</p>
                </div>
            </div>
        </section>
    @endif

    <section class="space-y-4" id="overview">
        <h2 class="text-lg font-semibold text-textPrimary">Overview</h2>
        <div class="grid gap-4 lg:grid-cols-4">
            @foreach ($summaries as $summary)
                <div class="rounded-md border border-border bg-surface p-4">
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-textPrimary">{{ $summary['title'] }}</h3>
                        <span class="text-xs text-textMuted">{{ $summary['avg'] }} avg</span>
                    </div>
                    <div class="mt-4 grid grid-cols-3 gap-2 text-sm">
                        <div><p class="text-textMuted">Total</p><p class="font-semibold">{{ $summary['total'] }}</p></div>
                        <div><p class="text-textMuted">Open</p><p class="font-semibold">{{ $summary['open'] }}</p></div>
                        <div><p class="text-textMuted">High</p><p class="font-semibold">{{ $summary['high'] }}</p></div>
                    </div>
                    <div class="mt-4 space-y-2">
                        @forelse ($summary['latest'] as $item)
                            <a href="{{ route('app.signal-intelligence.detections.show', $item) }}" class="block truncate text-xs font-medium text-primary hover:underline">{{ $item->title }}</a>
                        @empty
                            <p class="text-xs text-textMuted">No detections yet.</p>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <section class="space-y-4" id="feed">
        <h2 class="text-lg font-semibold text-textPrimary">Signal Feed</h2>
        <x-responsive-table>
            <thead>
                <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-textMuted">
                    <th class="px-4 py-3">Observed</th>
                    <th class="px-4 py-3">Signal</th>
                    <th class="px-4 py-3">Source</th>
                    <th class="px-4 py-3">Scores</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($recentEvents as $event)
                    <tr>
                        <td class="px-4 py-3 text-textSecondary">{{ $event->observed_at?->format('Y-m-d H:i') }}</td>
                        <td class="px-4 py-3">
                            <p class="font-medium text-textPrimary">{{ $formatLabel($event->type?->value ?? $event->type) }}</p>
                            <p class="text-xs text-textSecondary">{{ $event->topic ?: 'No topic' }} · {{ $event->entity_name ?: 'No entity' }}</p>
                        </td>
                        <td class="px-4 py-3 text-textSecondary">{{ $event->signalSource?->name ?? 'Unknown source' }}</td>
                        <td class="px-4 py-3 text-textSecondary">Strength {{ number_format((float) $event->signal_strength, 0) }} · Confidence {{ number_format((float) $event->confidence_score, 0) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-textMuted">No signal events match these filters.</td></tr>
                @endforelse
            </tbody>
        </x-responsive-table>
    </section>

    <section class="space-y-4" id="detections">
        <h2 class="text-lg font-semibold text-textPrimary">Detections</h2>
        <x-responsive-table>
            <thead>
                <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-textMuted">
                    <th class="px-4 py-3">Detection</th>
                    <th class="px-4 py-3">Category</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Severity</th>
                    <th class="px-4 py-3">Scores</th>
                    <th class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($detections as $detection)
                    @php
                        $canReviewDetection = $detection->canTransitionTo(\App\Enums\SignalStatus::REVIEWING);
                        $canDismissDetection = $detection->canTransitionTo(\App\Enums\SignalStatus::DISMISSED);
                        $canResolveDetection = $detection->canTransitionTo(\App\Enums\SignalStatus::RESOLVED);
                    @endphp
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('app.signal-intelligence.detections.show', $detection) }}" class="font-medium text-primary hover:underline">{{ $detection->title }}</a>
                            <p class="mt-1 line-clamp-2 text-xs text-textSecondary">{{ $humanDetectionSummary($detection) }}</p>
                        </td>
                        <td class="px-4 py-3 text-textSecondary">{{ $formatLabel($detection->category) }}</td>
                        <td class="px-4 py-3"><x-status-badge :status="$detection->status?->value ?? $detection->status" :color="$statusTone($detection->status?->value ?? $detection->status)" /></td>
                        <td class="px-4 py-3"><x-status-badge :status="$detection->severity?->value ?? $detection->severity" :color="$severityTone($detection->severity?->value ?? $detection->severity)" /></td>
                        <td class="px-4 py-3 text-textSecondary">P {{ number_format((float) $detection->priority_score, 0) }} · C {{ number_format((float) $detection->confidence_score, 0) }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('app.signal-intelligence.detections.show', $detection) }}" class="inline-flex h-8 items-center rounded-md border border-border bg-surface px-3 text-xs font-medium text-textPrimary hover:bg-surfaceMuted">Review</a>
                                <x-action-menu>
                                    @if ($canReviewDetection)
                                        <form method="POST" action="{{ route('app.signal-intelligence.detections.review', $detection) }}">@csrf<button class="block w-full rounded px-3 py-2 text-left text-sm text-textSecondary hover:bg-surfaceMuted" type="submit">Mark reviewing</button></form>
                                    @endif
                                    @if ($canDismissDetection)
                                        <form method="POST" action="{{ route('app.signal-intelligence.detections.dismiss', $detection) }}">@csrf<button class="block w-full rounded px-3 py-2 text-left text-sm text-textSecondary hover:bg-surfaceMuted" type="submit">Dismiss</button></form>
                                    @endif
                                    @if ($canResolveDetection)
                                        <form method="POST" action="{{ route('app.signal-intelligence.detections.resolve', $detection) }}">@csrf<button class="block w-full rounded px-3 py-2 text-left text-sm text-textSecondary hover:bg-surfaceMuted" type="submit">Resolve</button></form>
                                    @endif
                                </x-action-menu>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-textMuted">No detections match these filters.</td></tr>
                @endforelse
            </tbody>
        </x-responsive-table>
        {{ $detections->links() }}
    </section>

    <section class="grid gap-4 lg:grid-cols-3" id="priority">
        <div class="rounded-md border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">High Priority Detections</h2>
            <div class="mt-4 space-y-3">
                @forelse ($highPriorityDetections as $detection)
                    <a href="{{ route('app.signal-intelligence.detections.show', $detection) }}" class="block rounded-md border border-border p-3 hover:bg-surfaceMuted">
                        <p class="truncate text-sm font-medium text-textPrimary">{{ $detection->title }}</p>
                        <p class="mt-1 text-xs text-textSecondary">Priority {{ number_format((float) $detection->priority_score, 0) }}</p>
                    </a>
                @empty
                    <p class="text-sm text-textMuted">No open high priority detections.</p>
                @endforelse
            </div>
        </div>
        <div class="rounded-md border border-border bg-surface p-4 lg:col-span-2">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-textPrimary">Opportunity Candidates</h2>
                    <p class="mt-1 text-xs leading-5 text-textSecondary">Open detections appear here only when they look actionable enough for Opportunity Review.</p>
                </div>
                @if ($opportunityCandidates->isEmpty() && $nextDetection)
                    <a href="{{ route('app.signal-intelligence.detections.show', $nextDetection) }}" class="inline-flex h-8 shrink-0 items-center justify-center rounded-md border border-border bg-surface px-3 text-xs font-medium text-textPrimary hover:bg-surfaceMuted">Review open detection</a>
                @endif
            </div>
            <x-mobile-card-list class="mt-4">
                @forelse ($opportunityCandidates as $detection)
                    <a href="{{ route('app.signal-intelligence.detections.show', $detection) }}" class="block rounded-md border border-border p-3 hover:bg-surfaceMuted">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-textPrimary">{{ $detection->title }}</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ $detection->primary_topic ?: $formatLabel($detection->type) }}</p>
                            </div>
                            <span class="text-sm font-semibold text-emerald-700">{{ number_format((float) $detection->opportunity_score, 0) }}</span>
                        </div>
                    </a>
                @empty
                    <div class="rounded-md border border-dashed border-border bg-surfaceSubtle p-4">
                        @if ($openDetectionCount > 0)
                            <p class="text-sm font-medium text-textPrimary">No open detections qualify as opportunity candidates yet.</p>
                            <p class="mt-1 text-sm leading-6 text-textSecondary">Review the open detections first. If one is actionable, open it and promote it to Opportunity; otherwise dismiss or resolve it so the queue stays clean.</p>
                        @elseif ($totalDetectionCount > 0)
                            <p class="text-sm font-medium text-textPrimary">There is no user action waiting in this view.</p>
                            <p class="mt-1 text-sm leading-6 text-textSecondary">All detections shown here are already processed, so they cannot unlock Opportunity Review. Run Signal Intelligence again after new AI Visibility evidence, or widen the filters if you expect an older open candidate.</p>
                        @else
                            <p class="text-sm font-medium text-textPrimary">No opportunity candidates have been detected yet.</p>
                            <p class="mt-1 text-sm leading-6 text-textSecondary">Create or refresh AI Visibility evidence first, then run Signal Intelligence so Argusly can evaluate whether any signal should become a candidate.</p>
                        @endif
                    </div>
                @endforelse
            </x-mobile-card-list>
        </div>
    </section>
</div>
@endsection
