@extends('layouts.app', ['title' => 'Opportunity Execution Plan'])

@section('content')
@php
    $formatLabel = fn (?string $value): string => $value ? str($value)->replace(['_', '-'], ' ')->headline()->toString() : 'n/a';
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('app.opportunity-intelligence.opportunities.show', $plan->opportunity) }}" class="inline-flex items-center gap-2 text-sm font-medium text-textSecondary hover:text-textPrimary">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>
                Linked opportunity
            </a>
            <h1 class="mt-3 text-2xl font-semibold tracking-tight text-textPrimary">{{ $plan->title }}</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-textSecondary">{{ $plan->summary }}</p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <x-status-badge :status="$plan->status" :color="match($plan->status) { 'approved', 'planned' => 'green', 'reviewing' => 'amber', 'archived' => 'slate', default => 'blue' }" />
                <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1 text-xs text-textSecondary">{{ $formatLabel($plan->recommended_channel) }}</span>
                <span class="inline-flex items-center rounded-md border border-border bg-surface px-2.5 py-1 text-xs text-textSecondary">{{ $formatLabel($plan->recommended_format) }}</span>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form method="POST" action="{{ route('app.opportunity-intelligence.execution-plans.review', $plan) }}">@csrf<button class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted"><i data-lucide="eye" class="h-4 w-4"></i>Review</button></form>
            <form method="POST" action="{{ route('app.opportunity-intelligence.execution-plans.approve', $plan) }}">@csrf<button class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover"><i data-lucide="check" class="h-4 w-4"></i>Approve</button></form>
            <form method="POST" action="{{ route('app.opportunity-intelligence.execution-plans.planned', $plan) }}">@csrf<button class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted"><i data-lucide="calendar-check" class="h-4 w-4"></i>Mark planned</button></form>
            <form method="POST" action="{{ route('app.opportunity-intelligence.execution-plans.archive', $plan) }}">@csrf<button class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textSecondary hover:bg-surfaceMuted"><i data-lucide="archive" class="h-4 w-4"></i>Archive</button></form>
        </div>
    </div>

    @if (session('status'))
        <x-alert class="md:items-center" :icon="true">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->has('brief'))
        <x-alert variant="error" class="md:items-center" :icon="true">{{ $errors->first('brief') }}</x-alert>
    @endif

    <x-first-value-celebrations :items="$firstValueCelebrations ?? collect()" />

    <section class="grid gap-4 md:grid-cols-3">
        <x-llm-tracking.metric-card label="Priority" :value="number_format((float) $plan->priority_score, 1)" />
        <x-llm-tracking.metric-card label="Estimated effort" :value="number_format((float) $plan->estimated_effort, 1)" />
        <x-llm-tracking.metric-card label="Expected impact" :value="number_format((float) $plan->expected_impact, 1)" />
    </section>

    <section class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Objective</h2>
                <p class="mt-3 text-sm leading-6 text-textSecondary">{{ $plan->objective }}</p>
            </div>

            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Planned Steps</h2>
                <div class="mt-4 space-y-3">
                    @foreach ((array) $plan->planned_steps as $step)
                        <div class="rounded-md border border-border bg-surfaceSubtle p-3">
                            <p class="text-sm font-medium text-textPrimary">{{ $step['position'] ?? '' }}. {{ $step['title'] ?? 'Step' }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ $step['description'] ?? '' }}</p>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Source Evidence</h2>
                <div class="mt-4 space-y-3">
                    @foreach ((array) data_get($plan->source_evidence, 'signals', []) as $signal)
                        @php($detectionId = (string) ($signal['signal_detection_id'] ?? ''))
                        <div class="rounded-md border border-border bg-surfaceSubtle p-3">
                            <p class="text-sm font-medium text-textPrimary">{{ $formatLabel($signal['source'] ?? null) }} · {{ $signal['topic'] ?? 'General signal' }}</p>
                            <p class="mt-1 text-xs text-textSecondary">Strength {{ number_format((float) ($signal['signal_strength'] ?? 0), 1) }} · Confidence {{ number_format((float) ($signal['confidence'] ?? 0), 1) }}</p>
                            @if ($detectionId !== '' && $signalDetections->has($detectionId))
                                <a href="{{ route('app.signal-intelligence.detections.show', $detectionId) }}" class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">
                                    <i data-lucide="arrow-up-right" class="h-3 w-3"></i>
                                    {{ $signalDetections->get($detectionId)->title }}
                                </a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

        <aside class="space-y-6">
            @include('app.growth-programs._connection', [
                'subject' => $plan,
                'workspaceId' => $plan->workspace_id,
                'createRoute' => route('app.growth-programs.from-execution-plan', $plan),
                'attachRoute' => route('app.growth-programs.attach.execution-plan', $plan),
            ])

            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Prepared Links</h2>
                <div class="mt-4 space-y-2">
                    @if ($canCreateBrief)
                        <form method="POST" action="{{ route('app.opportunity-intelligence.execution-plans.create-brief', $plan) }}">
                            @csrf
                            <button class="flex w-full items-center justify-between rounded-md border border-primary/30 bg-primary px-3 py-2 text-sm font-semibold text-white hover:bg-primaryHover">
                                Create content brief
                                <i data-lucide="file-plus-2" class="h-4 w-4"></i>
                            </button>
                        </form>
                    @endif
                    @foreach ($preparedLinks as $link)
                        @if ($link['enabled'])
                            <a href="{{ $link['url'] }}" class="flex items-center justify-between rounded-md border border-border bg-surfaceSubtle px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                                {{ $link['label'] === 'Create content brief' ? 'Open content brief' : $link['label'] }}
                                <i data-lucide="arrow-up-right" class="h-4 w-4"></i>
                            </a>
                        @elseif ($link['label'] !== 'Create content brief')
                            <span class="flex items-center justify-between rounded-md border border-border bg-surfaceSubtle px-3 py-2 text-sm text-textMuted">{{ $link['label'] }} <span>coming next</span></span>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Linked Opportunity</h2>
                <a href="{{ route('app.opportunity-intelligence.opportunities.show', $plan->opportunity) }}" class="mt-3 block text-sm font-medium text-primary hover:underline">{{ $plan->opportunity?->title }}</a>
            </div>

            <div class="rounded-md border border-border bg-surface p-5">
                <h2 class="text-base font-semibold text-textPrimary">Governance</h2>
                <dl class="mt-4 space-y-3 text-sm">
                    <div><dt class="text-textMuted">Created by</dt><dd class="font-medium text-textPrimary">{{ $plan->creator?->name ?? 'n/a' }}</dd></div>
                    <div><dt class="text-textMuted">Approved by</dt><dd class="font-medium text-textPrimary">{{ $plan->approver?->name ?? 'n/a' }}</dd></div>
                    <div><dt class="text-textMuted">Approved at</dt><dd class="font-medium text-textPrimary">{{ $plan->approved_at?->format('Y-m-d H:i') ?? 'n/a' }}</dd></div>
                    <div><dt class="text-textMuted">Created</dt><dd class="font-medium text-textPrimary">{{ $plan->created_at?->format('Y-m-d H:i') }}</dd></div>
                </dl>
            </div>
        </aside>
    </section>
</div>
@endsection
