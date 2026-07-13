@extends('layouts.app')

@section('pageHeader')
    <x-page-header :title="$detection->title">
        <x-slot:description>{{ $detection->summary ?: 'Review detected signal intelligence and decide the next action.' }}</x-slot:description>
    </x-page-header>
@endsection

@section('content')
@php
    $formatLabel = fn (?string $value): string => $value ? str($value)->replace(['_', '-'], ' ')->headline()->toString() : 'n/a';
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
    $scoreRows = [
        'Priority' => $detection->priority_score,
        'Confidence' => $detection->confidence_score,
        'Impact' => $detection->impact_score,
        'Urgency' => $detection->urgency_score,
        'Risk' => $detection->risk_score,
        'Opportunity' => $detection->opportunity_score,
    ];
    $linkedMentions = $detection->events->pluck('signalMention')->filter()->unique('id')->values();
    $linkedFeedItems = $detection->events->pluck('signalFeedItem')->filter()->unique('id')->values();
    $sourceHistory = $detection->events->pluck('signalSource')->filter()->unique('id')->values();
    $detectionStatus = $detection->status?->value ?? (string) $detection->status;
    $canPromote = in_array($detectionStatus, ['new', 'detected', 'reviewing'], true);
    $canReview = $detection->canTransitionTo(\App\Enums\SignalStatus::REVIEWING);
    $canDismiss = $detection->canTransitionTo(\App\Enums\SignalStatus::DISMISSED);
    $canResolve = $detection->canTransitionTo(\App\Enums\SignalStatus::RESOLVED);
    $renderList = function (mixed $items): array {
        if ($items instanceof \Illuminate\Support\Collection) {
            return $items->all();
        }

        if (is_array($items)) {
            return $items;
        }

        return $items ? [$items] : [];
    };
    $impactAnalysis = $impactAnalysis ?? [];
    $impactActions = $renderList(data_get($impactAnalysis, 'suggested_actions', []));
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('app.signal-intelligence.index', ['workspace' => $workspace->id]) }}" class="inline-flex items-center gap-2 text-sm font-medium text-textSecondary hover:text-textPrimary">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>
                Signal Intelligence
            </a>
            <h2 class="mt-3 text-2xl font-semibold tracking-tight text-textPrimary">{{ $detection->title }}</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-textSecondary">{{ $detection->summary }}</p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <x-status-badge :status="$detection->status?->value ?? $detection->status" :color="$statusTone($detection->status?->value ?? $detection->status)" />
                <x-status-badge :status="$detection->severity?->value ?? $detection->severity" :color="$severityTone($detection->severity?->value ?? $detection->severity)" />
                <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1 text-xs text-textSecondary">{{ $formatLabel($detection->category) }}</span>
                <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1 text-xs text-textSecondary">{{ $formatLabel($detection->type) }}</span>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @can('update', $detection)
                @if ($canPromote)
                    <form method="POST" action="{{ route('app.signal-intelligence.detections.promote', $detection) }}">@csrf<button type="submit" class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover"><i data-lucide="send" class="h-4 w-4"></i>Promote to Opportunity</button></form>
                @endif
            @endcan
            @if ($canReview)
                <form method="POST" action="{{ route('app.signal-intelligence.detections.review', $detection) }}">@csrf<button type="submit" class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted"><i data-lucide="eye" class="h-4 w-4"></i>Mark reviewing</button></form>
            @endif
            @if ($canDismiss)
                <form method="POST" action="{{ route('app.signal-intelligence.detections.dismiss', $detection) }}">@csrf<button type="submit" class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textSecondary hover:bg-surfaceMuted"><i data-lucide="x" class="h-4 w-4"></i>Dismiss</button></form>
            @endif
            @if ($canResolve)
                <form method="POST" action="{{ route('app.signal-intelligence.detections.resolve', $detection) }}">@csrf<button type="submit" class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover"><i data-lucide="check" class="h-4 w-4"></i>Resolve</button></form>
            @endif
        </div>
    </div>

    @if (session('status'))
        <x-alert class="md:items-center" :icon="true">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->any())
        <x-alert class="border-danger/30 bg-danger/5 text-danger" :icon="true">
            {{ $errors->first() }}
        </x-alert>
    @endif

    <x-first-value-celebrations :items="$firstValueCelebrations ?? collect()" />

    @if (! empty($firstDetectionCard))
        <x-first-value-card :card="$firstDetectionCard" />
    @endif

    <section class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
        @foreach ($scoreRows as $label => $score)
            <x-llm-tracking.metric-card :label="$label" :value="number_format((float) $score, 1)" />
        @endforeach
    </section>

    @if (! empty($impactAnalysis))
        <section class="rounded-md border border-border bg-surface p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Impact Analysis</h2>
                    <p class="mt-2 max-w-4xl text-sm leading-6 text-textSecondary">{{ data_get($impactAnalysis, 'why_this_matters') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="inline-flex items-center rounded-md border border-border bg-surfaceSubtle px-2.5 py-1 text-xs text-textSecondary">Urgency: {{ $formatLabel(data_get($impactAnalysis, 'urgency.label')) }}</span>
                    <span class="inline-flex items-center rounded-md border border-border bg-surfaceSubtle px-2.5 py-1 text-xs text-textSecondary">Confidence: {{ $formatLabel(data_get($impactAnalysis, 'confidence.label')) }}</span>
                </div>
            </div>
            <div class="mt-4 grid gap-4 lg:grid-cols-3">
                <div class="rounded-md border border-border bg-surfaceSubtle p-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-textMuted">Business impact</p>
                    <p class="mt-2 text-sm leading-6 text-textSecondary">{{ data_get($impactAnalysis, 'business_impact.summary') }}</p>
                </div>
                <div class="rounded-md border border-border bg-surfaceSubtle p-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-textMuted">Recommended next step</p>
                    <p class="mt-2 text-sm leading-6 text-textSecondary">{{ data_get($impactAnalysis, 'recommended_next_step') }}</p>
                </div>
                <div class="rounded-md border border-border bg-surfaceSubtle p-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-textMuted">Affected scope</p>
                    <p class="mt-2 text-sm text-textSecondary">{{ data_get($impactAnalysis, 'affected_scope.topic', 'n/a') }} · {{ data_get($impactAnalysis, 'affected_scope.entity', 'n/a') }}</p>
                    <p class="mt-1 text-xs text-textMuted">{{ (int) data_get($impactAnalysis, 'affected_scope.event_count', 0) }} linked events</p>
                </div>
            </div>
            @if ($impactActions !== [])
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($impactActions as $action)
                        <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1 text-xs text-textSecondary">{{ data_get($action, 'label') }}</span>
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    <section class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Score Breakdown</h2>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    @forelse ($renderList($detection->score_breakdown) as $key => $value)
                        <div class="rounded-md border border-border bg-surfaceSubtle p-3">
                            <p class="text-xs font-medium uppercase tracking-wide text-textMuted">{{ is_string($key) ? $formatLabel($key) : 'Score item' }}</p>
                            <p class="mt-1 text-sm text-textPrimary">{{ is_scalar($value) ? $value : json_encode($value, JSON_PRETTY_PRINT) }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-textMuted">No score breakdown stored.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Evidence Summary</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($renderList($detection->evidence_summary) as $key => $value)
                        <div class="rounded-md border border-border bg-surfaceSubtle p-3 text-sm text-textSecondary">
                            <span class="font-medium text-textPrimary">{{ is_string($key) ? $formatLabel($key) : 'Evidence' }}:</span>
                            <span>{{ is_scalar($value) ? $value : json_encode($value, JSON_PRETTY_PRINT) }}</span>
                        </div>
                    @empty
                        <p class="text-sm text-textMuted">No evidence summary stored.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Linked Signal Events</h2>
                <x-responsive-table class="mt-4">
                    <thead>
                        <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-textMuted">
                            <th class="px-4 py-3">Observed</th>
                            <th class="px-4 py-3">Signal</th>
                            <th class="px-4 py-3">Source</th>
                            <th class="px-4 py-3">Contribution</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($detection->events as $event)
                            <tr>
                                <td class="px-4 py-3 text-textSecondary">{{ $event->observed_at?->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3">
                                    <p class="font-medium text-textPrimary">{{ $formatLabel($event->type?->value ?? $event->type) }}</p>
                                    <p class="text-xs text-textSecondary">{{ $event->topic ?: 'No topic' }} · {{ $event->entity_name ?: 'No entity' }}</p>
                                </td>
                                <td class="px-4 py-3 text-textSecondary">{{ $event->signalSource?->name ?? 'Unknown source' }}</td>
                                <td class="px-4 py-3 text-textSecondary">{{ number_format((float) ($event->pivot?->weight ?? 0), 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-textMuted">No linked signal events.</td></tr>
                        @endforelse
                    </tbody>
                </x-responsive-table>
            </div>
        </div>

        <aside class="space-y-6">
            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Detection Details</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-textMuted">Workspace</dt><dd class="font-medium text-textPrimary">{{ $detection->workspace?->display_name ?: $detection->workspace?->name }}</dd></div>
                    <div><dt class="text-textMuted">Site</dt><dd class="font-medium text-textPrimary">{{ $detection->clientSite?->name ?? 'All sites' }}</dd></div>
                    <div><dt class="text-textMuted">Primary entity</dt><dd class="font-medium text-textPrimary">{{ $detection->primary_entity ?: 'n/a' }}</dd></div>
                    <div><dt class="text-textMuted">Primary topic</dt><dd class="font-medium text-textPrimary">{{ $detection->primary_topic ?: 'n/a' }}</dd></div>
                    <div><dt class="text-textMuted">First seen</dt><dd class="font-medium text-textPrimary">{{ $detection->first_seen_at?->format('Y-m-d H:i') ?: 'n/a' }}</dd></div>
                    <div><dt class="text-textMuted">Last seen</dt><dd class="font-medium text-textPrimary">{{ $detection->last_seen_at?->format('Y-m-d H:i') ?: 'n/a' }}</dd></div>
                    <div><dt class="text-textMuted">Resolved</dt><dd class="font-medium text-textPrimary">{{ $detection->resolved_at?->format('Y-m-d H:i') ?: 'n/a' }}</dd></div>
                    <div><dt class="text-textMuted">Created</dt><dd class="font-medium text-textPrimary">{{ $detection->created_at?->format('Y-m-d H:i') }}</dd></div>
                    <div><dt class="text-textMuted">Updated</dt><dd class="font-medium text-textPrimary">{{ $detection->updated_at?->format('Y-m-d H:i') }}</dd></div>
                </dl>
            </div>

            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Recommended Actions</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($renderList($detection->recommended_actions) as $action)
                        <div class="rounded-md border border-border bg-surfaceSubtle p-3 text-sm text-textSecondary">
                            {{ is_scalar($action) ? $action : (data_get($action, 'label') ?: json_encode($action, JSON_PRETTY_PRINT)) }}
                        </div>
                    @empty
                        <p class="text-sm text-textMuted">No recommended actions stored.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Linked Mentions</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($linkedMentions as $mention)
                        <div class="rounded-md border border-border bg-surfaceSubtle p-3">
                            <p class="text-sm font-medium text-textPrimary">{{ $mention->entity_name }}</p>
                            <p class="mt-1 line-clamp-3 text-xs text-textSecondary">{{ $mention->context ?: $mention->url }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-textMuted">No linked mentions.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Linked Feed Items</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($linkedFeedItems as $feedItem)
                        <div class="rounded-md border border-border bg-surfaceSubtle p-3">
                            <p class="text-sm font-medium text-textPrimary">{{ $feedItem->title ?: $feedItem->url }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ $feedItem->published_at?->format('Y-m-d') ?: 'No publish date' }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-textMuted">No linked feed items.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Source History</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($sourceHistory as $source)
                        <div class="rounded-md border border-border bg-surfaceSubtle p-3">
                            <p class="text-sm font-medium text-textPrimary">{{ $source->name }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ $formatLabel($source->type?->value ?? $source->type) }} · Last seen {{ $source->last_seen_at?->format('Y-m-d H:i') ?: 'n/a' }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-textMuted">No source history.</p>
                    @endforelse
                </div>
            </div>
        </aside>
    </section>
</div>
@endsection
