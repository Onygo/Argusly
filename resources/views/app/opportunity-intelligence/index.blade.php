@extends('layouts.app', ['title' => __('app.runtime.Opportunity Intelligence')])

@section('content')
    @php
        $rt = function (string $key, array $replace = []): string {
            $line = (__('app.runtime')[$key] ?? $key);

            foreach ($replace as $name => $value) {
                $line = str_replace(':'.$name, (string) $value, $line);
            }

            return $line;
        };
    @endphp

    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">{{ __('app.runtime.Opportunity Intelligence') }}</h1>
            <p class="mt-1 text-sm text-textSecondary">{{ $rt('Explainable signals, ranked opportunities, and recommended actions across search, AI visibility, competitors, content decay, and engagement.') }}</p>
        </div>
        <form method="POST" action="{{ route('app.opportunity-intelligence.run', request()->query()) }}" class="flex flex-wrap items-center gap-2">
            @csrf
            @if ($canRunOpportunityEngine ?? false)
                <button class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">{{ __('app.runtime.Run Opportunity Intelligence') }}</button>
            @else
                <a href="{{ route('app.signal-intelligence.index', ['workspace' => $workspace->id]) }}" class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">{{ __('app.runtime.Open Signal Intelligence') }}</a>
                <button type="button" disabled class="cursor-not-allowed rounded-md border border-border bg-surfaceMuted px-4 py-2 text-sm font-medium text-textFaint" title="{{ $rt('Promote at least one Signal Intelligence detection first.') }}">{{ __('app.runtime.Run Opportunity Intelligence') }}</button>
            @endif
        </form>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    @if (!empty($activation) && !data_get($activation, 'is_active'))
        <x-activation-banner class="mb-6" :activation="$activation" compact />
    @endif

    <x-first-value-celebrations class="mb-6" :items="$firstValueCelebrations ?? collect()" />

    @if (! empty($firstOpportunityCard))
        <x-first-value-card class="mb-6" :card="$firstOpportunityCard" />
    @endif

    <div class="mb-6 rounded-lg border border-border bg-surface p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-textSecondary">Programmatic Growth</p>
                <h2 class="mt-1 text-base font-semibold text-textPrimary">Scale approved opportunities into controlled content flows</h2>
            </div>
            <a href="{{ route('app.growth-programs.index', ['workspace_id' => $workspace->id]) }}" class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white">Open Growth Programs</a>
        </div>
        <div class="mt-4 grid gap-3 text-sm sm:grid-cols-5">
            <div><p class="text-xs text-textSecondary">Active growth programs</p><p class="mt-1 font-semibold text-textPrimary">{{ (int) data_get($programmaticGrowthSummary, 'active_growth_programs', 0) }}</p></div>
            <div><p class="text-xs text-textSecondary">Opportunities ready for scaling</p><p class="mt-1 font-semibold text-textPrimary">{{ (int) data_get($programmaticGrowthSummary, 'opportunities_ready_for_scaling', 0) }}</p></div>
            <div><p class="text-xs text-textSecondary">Content assets ready</p><p class="mt-1 font-semibold text-textPrimary">{{ (int) data_get($programmaticGrowthSummary, 'content_assets_ready', 0) }}</p></div>
            <div><p class="text-xs text-textSecondary">Scheduled publication records</p><p class="mt-1 font-semibold text-textPrimary">{{ (int) data_get($programmaticGrowthSummary, 'scheduled_publication_records', 0) }}</p></div>
            <div><p class="text-xs text-textSecondary">Blocked items</p><p class="mt-1 font-semibold text-textPrimary">{{ (int) data_get($programmaticGrowthSummary, 'blocked_items', 0) }}</p></div>
        </div>
    </div>

    <div class="mb-6 grid gap-4 md:grid-cols-4">
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">{{ __('app.runtime.Open opportunities') }}</p>
            <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ number_format((int) $summary['open']) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">{{ __('app.runtime.Signals') }}</p>
            <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ number_format((int) $summary['signals']) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">{{ __('app.runtime.Avg priority') }}</p>
            <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ number_format((float) $summary['avg_priority'], 1) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">{{ __('app.runtime.High confidence') }}</p>
            <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ number_format((int) $summary['high_confidence']) }}</p>
        </div>
    </div>

    <form method="GET" action="{{ route('app.agentic-marketing.intelligence.index') }}" class="mb-6 flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 md:flex-row">
        <select name="category" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">{{ __('app.runtime.All categories') }}</option>
            @foreach ($categories as $category)
                <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ str_replace('_', ' ', ucfirst($category)) }}</option>
            @endforeach
        </select>
        <select name="status" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">{{ __('app.runtime.Open by default') }}</option>
            @foreach (['open', 'reviewing', 'planned', 'actioned', 'dismissed', 'archived'] as $status)
                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <button class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">{{ __('app.runtime.Apply') }}</button>
    </form>

    <div class="mb-6 grid gap-6 xl:grid-cols-[1.5fr_1fr]">
        <section class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-sm font-semibold text-textPrimary">{{ __('app.runtime.Recommended Actions') }}</h2>
            </div>
            <div class="divide-y divide-border">
                @forelse ($opportunities as $opportunity)
                    <article class="p-5">
                        @php
                            $signalIntelligenceSignals = $opportunity->signals
                                ->filter(fn ($signal) => ($signal->source?->value ?? $signal->source) === 'signal_intelligence' && filled(data_get($signal->metadata, 'signal_detection_id')));
                        @endphp
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap gap-2">
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $opportunity->category?->value ?? $opportunity->category) }}</span>
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ __('app.runtime.Priority') }} {{ number_format((float) $opportunity->priority_score, 1) }}</span>
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ __('app.runtime.Confidence') }} {{ number_format((float) $opportunity->confidence_score, 1) }}</span>
                                    @if ($signalIntelligenceSignals->isNotEmpty())
                                        <span class="rounded-full border border-primary/25 bg-primarySoftBg px-2.5 py-1 text-xs font-medium text-primary">{{ __('app.runtime.Signal Intelligence') }}</span>
                                    @endif
                                </div>
                                <a href="{{ route('app.opportunity-intelligence.opportunities.show', $opportunity) }}" class="mt-2 block font-semibold text-textPrimary hover:text-primary">{{ $opportunity->title }}</a>
                                <p class="mt-1 text-sm text-textSecondary">{{ $opportunity->summary }}</p>
                            </div>
                        </div>
                        @if ($signalIntelligenceSignals->isNotEmpty())
                            <div class="mt-4 grid gap-3 md:grid-cols-2">
                                @foreach ($signalIntelligenceSignals as $signal)
                                    @php
                                        $detectionId = (string) data_get($signal->metadata, 'signal_detection_id');
                                        $priority = data_get($signal->metadata, 'signal_priority_score');
                                        $evidenceSummary = data_get($signal->evidence, 'evidence_summary', []);
                                    @endphp
                                    <div class="rounded-md border border-primary/20 bg-primarySoftBg/40 p-3">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-sm font-medium text-textPrimary">{{ __('app.runtime.Signal Intelligence evidence') }}</p>
                                            @if ($priority !== null)
                                                <span class="text-xs font-medium text-primary">{{ __('app.runtime.Signal priority') }} {{ number_format((float) $priority, 1) }}</span>
                                            @endif
                                        </div>
                                        <p class="mt-1 text-xs text-textSecondary">{{ data_get($signal->metadata, 'summary') ?: data_get($signal->evidence, 'summary', $rt('Promoted detection evidence is attached to this opportunity.')) }}</p>
                                        @if (! empty($evidenceSummary))
                                            <pre class="mt-2 max-h-28 overflow-auto whitespace-pre-wrap rounded-md border border-border/70 bg-surface px-2 py-1 text-[11px] text-textSecondary">{{ json_encode($evidenceSummary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                        @endif
                                        <a href="{{ route('app.signal-intelligence.detections.show', $detectionId) }}" class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                                            <i data-lucide="arrow-up-right" class="h-3 w-3"></i>
                                            {{ __('app.runtime.View linked detection') }}
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            @foreach ((array) $opportunity->recommended_actions as $action)
                                <div class="rounded-md border border-border bg-background p-3">
                                    <p class="text-sm font-medium text-textPrimary">{{ $action['label'] ?? str_replace('_', ' ', (string) ($action['type'] ?? __('app.runtime.Action'))) }}</p>
                                    <p class="mt-1 text-xs text-textSecondary">{{ $action['rationale'] ?? $rt('Recommended from stored evidence.') }}</p>
                                </div>
                            @endforeach
                        </div>
                        <details class="mt-4 rounded-md border border-border bg-background px-3 py-2">
                            <summary class="cursor-pointer text-xs font-medium text-textPrimary">{{ __('app.runtime.Score explanation') }}</summary>
                            <pre class="mt-2 whitespace-pre-wrap text-xs text-textSecondary">{{ json_encode($opportunity->score_breakdown, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                    </article>
                @empty
                    <div class="p-5">
                        @if (isset($emptyStateGuide))
                            <x-empty-state-guide :state="$emptyStateGuide" />
                            @if (($canRunOpportunityEngine ?? false) && (int) ($promotedSignalCount ?? 0) > 0)
                                <div class="mt-4 rounded-md border border-primary/20 bg-primarySoftBg/50 p-4">
                                    <p class="text-sm font-semibold text-textPrimary">{{ __('app.runtime.Promoted signals are ready') }}</p>
                                    <p class="mt-1 text-sm text-textSecondary">{{ $rt('Run the engine to cluster :count promoted signal(s) into opportunities.', ['count' => number_format((int) $promotedSignalCount)]) }}</p>
                                </div>
                            @endif
                        @else
                            <p class="text-sm text-textSecondary">{{ $rt('No opportunities yet. Ingest signals, then refresh intelligence.') }}</p>
                        @endif
                    </div>
                @endforelse
            </div>
            <div class="border-t border-border px-5 py-4">{{ $opportunities->links() }}</div>
        </section>

        <section class="space-y-6">
            <div class="rounded-lg border border-border bg-surface p-4">
                <h2 class="text-sm font-semibold text-textPrimary">{{ __('app.runtime.Signal Feed') }}</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($signals as $signal)
                        @php
                            $isSignalIntelligence = ($signal->source?->value ?? $signal->source) === 'signal_intelligence' && filled(data_get($signal->metadata, 'signal_detection_id'));
                        @endphp
                        <div class="rounded-md border border-border bg-background p-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-medium text-textPrimary">{{ $isSignalIntelligence ? __('app.runtime.Signal Intelligence') : str_replace('_', ' ', $signal->source?->value ?? $signal->source) }}</span>
                                <span class="text-xs text-textSecondary">{{ $signal->observed_at?->diffForHumans() }}</span>
                            </div>
                            <p class="mt-1 text-xs text-textSecondary">{{ $signal->topic ?: $signal->entity ?: __('app.runtime.General signal') }} · {{ __('app.runtime.Strength') }} {{ number_format((float) $signal->signal_strength, 1) }}</p>
                            @if ($isSignalIntelligence)
                                <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                    <span class="rounded-full border border-primary/25 bg-primarySoftBg px-2 py-0.5 font-medium text-primary">{{ __('app.runtime.Promoted detection') }}</span>
                                    <span class="text-textSecondary">{{ __('app.runtime.Priority') }} {{ number_format((float) data_get($signal->metadata, 'signal_priority_score', 0), 1) }}</span>
                                    <a href="{{ route('app.signal-intelligence.detections.show', data_get($signal->metadata, 'signal_detection_id')) }}" class="font-medium text-primary hover:underline">{{ __('app.runtime.View detection') }}</a>
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-textSecondary">{{ $rt('No signals stored yet.') }}</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-4">
                <h2 class="text-sm font-semibold text-textPrimary">{{ __('app.runtime.Opportunity Timeline') }}</h2>
                <div class="mt-4 space-y-4">
                    @forelse ($timeline as $date => $items)
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-textFaint">{{ $date }}</p>
                            <div class="mt-2 space-y-2 border-l border-border pl-3">
                                @foreach ($items as $item)
                                    <div class="rounded-md border border-border bg-background p-3">
                                        <p class="text-sm font-medium text-textPrimary">{{ $item->title }}</p>
                                        <p class="mt-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $item->category?->value ?? $item->category) }} · {{ number_format((float) $item->priority_score, 1) }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-textSecondary">{{ $rt('No timeline yet.') }}</p>
                    @endforelse
                </div>
            </div>
        </section>
    </div>
@endsection
