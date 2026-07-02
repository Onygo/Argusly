@extends('layouts.app', ['title' => 'Opportunity Intelligence'])

@section('pageHeader')
    <x-page-header :title="$opportunity->title">
        <x-slot:description>{{ $opportunity->summary }}</x-slot:description>
    </x-page-header>
@endsection

@section('content')
@php
    $formatLabel = fn (?string $value): string => $value ? str($value)->replace(['_', '-'], ' ')->headline()->toString() : 'n/a';
    $statusTone = fn (?string $status): string => match ($status) {
        'approved', 'planned', 'actioned', 'resolved' => 'green',
        'reviewing' => 'amber',
        'dismissed', 'archived' => 'slate',
        default => 'blue',
    };
    $scoreRows = [
        'Priority' => $opportunity->priority_score,
        'Confidence' => $opportunity->confidence_score,
        'Impact' => $opportunity->impact_score,
        'Urgency' => $opportunity->urgency_score,
        'Opportunity' => $opportunity->source_signal_summary['average_strength'] ?? $opportunity->priority_score,
    ];
    $programmaticPotential = \App\Models\ProgrammaticOpportunity::query()
        ->where('source_type', $opportunity->getMorphClass())
        ->where('source_id', (string) $opportunity->id)
        ->latest()
        ->first();
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('app.agentic-marketing.intelligence.index', ['workspace_id' => $workspace->id]) }}" class="inline-flex items-center gap-2 text-sm font-medium text-textSecondary hover:text-textPrimary">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>
                Opportunity Intelligence
            </a>
            <h2 class="mt-3 text-2xl font-semibold tracking-tight text-textPrimary">{{ $opportunity->title }}</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-textSecondary">{{ $opportunity->summary }}</p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <x-status-badge :status="$opportunity->status?->value ?? $opportunity->status" :color="$statusTone($opportunity->status?->value ?? $opportunity->status)" />
                <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1 text-xs text-textSecondary">{{ $formatLabel($opportunity->category?->value ?? $opportunity->category) }}</span>
                <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1 text-xs text-textSecondary">Workspace: {{ $workspace->display_name ?: $workspace->name }}</span>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('app.growth-programs.from-opportunity', $opportunity) }}">
                @csrf
                <button class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                    <i data-lucide="network" class="h-4 w-4"></i>
                    Create Growth Program
                </button>
            </form>
            @if ($canCreateExecutionPlan)
                <form method="POST" action="{{ route('app.opportunity-intelligence.opportunities.execution-plans.store', $opportunity) }}">@csrf<button class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover"><i data-lucide="clipboard-list" class="h-4 w-4"></i>Create Execution Plan</button></form>
            @elseif ($opportunity->activeExecutionPlans->isNotEmpty())
                <a href="{{ route('app.opportunity-intelligence.execution-plans.show', $opportunity->activeExecutionPlans->first()) }}" class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted"><i data-lucide="clipboard-list" class="h-4 w-4"></i>View Execution Plan</a>
            @endif
            <form method="POST" action="{{ route('app.opportunity-intelligence.opportunities.review', $opportunity) }}">@csrf<button class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted"><i data-lucide="eye" class="h-4 w-4"></i>Mark reviewing</button></form>
            <form method="POST" action="{{ route('app.opportunity-intelligence.opportunities.approve', $opportunity) }}">@csrf<button class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover"><i data-lucide="check" class="h-4 w-4"></i>Approve</button></form>
            <x-action-menu>
                <form method="POST" action="{{ route('app.opportunity-intelligence.opportunities.dismiss', $opportunity) }}">@csrf<button class="block w-full rounded px-3 py-2 text-left text-sm text-textSecondary hover:bg-surfaceMuted">Dismiss</button></form>
                <form method="POST" action="{{ route('app.opportunity-intelligence.opportunities.resolve', $opportunity) }}">@csrf<button class="block w-full rounded px-3 py-2 text-left text-sm text-textSecondary hover:bg-surfaceMuted">Resolve</button></form>
                <form method="POST" action="{{ route('app.opportunity-intelligence.opportunities.archive', $opportunity) }}">@csrf<button class="block w-full rounded px-3 py-2 text-left text-sm text-textSecondary hover:bg-surfaceMuted">Archive</button></form>
            </x-action-menu>
        </div>
    </div>

    @if (session('status'))
        <x-alert class="md:items-center" :icon="true">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->any())
        <x-alert class="border-danger/30 bg-danger/5 text-danger" :icon="true">{{ $errors->first() }}</x-alert>
    @endif

    <x-first-value-celebrations :items="$firstValueCelebrations ?? collect()" />

    @if (! empty($firstOpportunityCard))
        <x-first-value-card :card="$firstOpportunityCard" />
    @endif

    @if (! $canCreateExecutionPlan && $opportunity->activeExecutionPlans->isEmpty())
        <div class="rounded-md border border-border bg-surface p-4">
            <div class="flex items-start gap-3">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-amber-50 text-amber-700">
                    <i data-lucide="circle-alert" class="h-4 w-4"></i>
                </span>
                <div>
                    <p class="text-sm font-semibold text-textPrimary">Execution plan is not available yet</p>
                    <p class="mt-1 text-sm leading-6 text-textSecondary">Approve this opportunity or mark it as reviewing before creating an execution plan.</p>
                </div>
            </div>
        </div>
    @endif

    <section class="grid gap-4 md:grid-cols-5">
        @foreach ($scoreRows as $label => $score)
            <x-llm-tracking.metric-card :label="$label" :value="number_format((float) $score, 1)" />
        @endforeach
    </section>

    <section class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Recommended Actions</h2>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    @forelse ((array) $opportunity->recommended_actions as $action)
                        <div class="rounded-md border border-border bg-surfaceSubtle p-3">
                            <p class="text-sm font-medium text-textPrimary">{{ $action['label'] ?? $formatLabel($action['type'] ?? 'Action') }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ $action['rationale'] ?? 'Recommended from stored evidence.' }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-textMuted">No recommended actions stored.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Signal Lineage</h2>
                <div class="mt-4 space-y-4">
                    @forelse ($opportunity->signals as $signal)
                        @php
                            $detectionId = (string) data_get($signal->metadata, 'signal_detection_id', '');
                            $detection = $detectionId !== '' ? $signalDetections->get($detectionId) : null;
                        @endphp
                        <div class="rounded-md border border-border bg-surfaceSubtle p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-textPrimary">OpportunitySignal: {{ $formatLabel($signal->source?->value ?? $signal->source) }}</p>
                                    <p class="mt-1 text-xs text-textSecondary">{{ $signal->topic ?: $signal->entity ?: 'General signal' }} · Strength {{ number_format((float) $signal->signal_strength, 1) }} · Confidence {{ number_format((float) $signal->confidence, 1) }}</p>
                                </div>
                                @if ($detection)
                                    <a href="{{ route('app.signal-intelligence.detections.show', $detection) }}" class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                                        <i data-lucide="arrow-up-right" class="h-3 w-3"></i>
                                        View SignalDetection
                                    </a>
                                @endif
                            </div>
                            @if ($detection)
                                <div class="mt-4 rounded-md border border-primary/20 bg-primarySoftBg/40 p-3">
                                    <p class="text-sm font-medium text-textPrimary">{{ $detection->title }}</p>
                                    <p class="mt-1 text-xs text-textSecondary">{{ $detection->summary }}</p>
                                    @if (! empty($detection->evidence_summary))
                                        <pre class="mt-2 max-h-32 overflow-auto whitespace-pre-wrap rounded-md border border-border bg-surface px-2 py-1 text-[11px] text-textSecondary">{{ json_encode($detection->evidence_summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    @endif
                                    <div class="mt-3 space-y-2">
                                        @forelse ($detection->events as $event)
                                            <div class="rounded-md border border-border bg-surface p-3">
                                                <p class="text-xs font-medium text-textPrimary">Signal evidence: {{ $formatLabel($event->type?->value ?? $event->type) }}</p>
                                                <p class="mt-1 text-xs text-textSecondary">{{ $event->topic ?: 'No topic' }} · {{ $event->entity_name ?: 'No entity' }} · {{ $event->observed_at?->format('Y-m-d H:i') }}</p>
                                                <p class="mt-1 text-xs text-textSecondary">Source: {{ $event->signalSource?->name ?? 'Unknown' }}</p>
                                                @if ($event->signalMention)
                                                    <p class="mt-1 text-xs text-textSecondary">Mention: {{ $event->signalMention->entity_name }} · {{ $event->signalMention->context }}</p>
                                                @endif
                                                @if ($event->signalFeedItem)
                                                    <p class="mt-1 text-xs text-textSecondary">Feed item: {{ $event->signalFeedItem->title ?: $event->signalFeedItem->url }}</p>
                                                @endif
                                            </div>
                                        @empty
                                            <p class="text-xs text-textMuted">No linked signal events.</p>
                                        @endforelse
                                    </div>
                                </div>
                            @else
                                <pre class="mt-3 max-h-40 overflow-auto whitespace-pre-wrap rounded-md border border-border bg-surface px-2 py-1 text-[11px] text-textSecondary">{{ json_encode($signal->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-textMuted">No linked opportunity signals.</p>
                    @endforelse
                </div>
            </div>
        </div>
        <aside class="space-y-6">
            @include('app.growth-programs._connection', [
                'subject' => $opportunity,
                'workspaceId' => $workspace->id,
                'createRoute' => route('app.growth-programs.from-opportunity', $opportunity),
                'attachRoute' => route('app.growth-programs.attach.opportunity', $opportunity),
            ])
            <div class="rounded-md border border-border bg-surface p-5">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-semibold text-textPrimary">Programmatic Potential</h2>
                        @if ($programmaticPotential)
                            @php($pattern = $programmaticPotential->pattern_type instanceof \App\Enums\ProgrammaticPatternType ? $programmaticPotential->pattern_type : \App\Enums\ProgrammaticPatternType::tryFrom((string) $programmaticPotential->pattern_type))
                            <p class="mt-1 text-sm text-textSecondary">{{ $pattern?->label() }} · Scale {{ number_format((float) $programmaticPotential->scale_score, 1) }} · {{ $programmaticPotential->estimated_variants_count ?? 'n/a' }} variants</p>
                            <a href="{{ route('app.programmatic-opportunities.show', $programmaticPotential) }}" class="mt-3 inline-flex text-sm font-medium text-primary hover:underline">Open programmatic opportunity</a>
                        @else
                            <p class="mt-1 text-sm text-textSecondary">Not detected yet.</p>
                        @endif
                    </div>
                    <form method="POST" action="{{ route('app.programmatic-opportunities.detect.opportunity', $opportunity) }}">
                        @csrf
                        <button class="rounded-md border border-border bg-surfaceSubtle px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">Detect</button>
                    </form>
                </div>
            </div>
            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Source Lineage</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-textMuted">Signal count</dt><dd class="font-medium text-textPrimary">{{ $opportunity->signals->count() }}</dd></div>
                    <div><dt class="text-textMuted">Sources</dt><dd class="font-medium text-textPrimary">{{ collect($opportunity->source_signal_summary['sources'] ?? [])->map($formatLabel)->implode(', ') ?: 'n/a' }}</dd></div>
                    <div><dt class="text-textMuted">Signal Intelligence detections</dt><dd class="font-medium text-textPrimary">{{ count($opportunity->metadata['signal_detection_ids'] ?? []) }}</dd></div>
                    <div><dt class="text-textMuted">First seen</dt><dd class="font-medium text-textPrimary">{{ $opportunity->first_seen_at?->format('Y-m-d H:i') ?: 'n/a' }}</dd></div>
                    <div><dt class="text-textMuted">Last seen</dt><dd class="font-medium text-textPrimary">{{ $opportunity->last_seen_at?->format('Y-m-d H:i') ?: 'n/a' }}</dd></div>
                    <div><dt class="text-textMuted">Created</dt><dd class="font-medium text-textPrimary">{{ $opportunity->created_at?->format('Y-m-d H:i') }}</dd></div>
                    <div><dt class="text-textMuted">Updated</dt><dd class="font-medium text-textPrimary">{{ $opportunity->updated_at?->format('Y-m-d H:i') }}</dd></div>
                </dl>
            </div>

            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Governance Audit</h2>
                <pre class="mt-4 max-h-72 overflow-auto whitespace-pre-wrap rounded-md border border-border bg-surfaceSubtle px-3 py-2 text-xs text-textSecondary">{{ json_encode($opportunity->metadata ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>

            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Score Explanation</h2>
                <pre class="mt-4 max-h-72 overflow-auto whitespace-pre-wrap rounded-md border border-border bg-surfaceSubtle px-3 py-2 text-xs text-textSecondary">{{ json_encode($opportunity->score_breakdown ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        </aside>
    </section>
</div>
@endsection
