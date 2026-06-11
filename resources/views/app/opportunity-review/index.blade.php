@extends('layouts.app', ['title' => 'Opportunity Review'])

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
@endphp

<div class="space-y-6">
    <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div>
            <a href="{{ route('app.signal-intelligence.index', ['workspace' => $workspace->id]) }}" class="inline-flex items-center gap-2 text-sm font-medium text-textSecondary hover:text-textPrimary">
                <i data-lucide="arrow-left" class="h-4 w-4"></i>
                Signal Intelligence
            </a>
            <h1 class="mt-3 text-2xl font-semibold tracking-tight text-textPrimary">Opportunity Review</h1>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-textSecondary">
                Review the first opportunity candidate before Opportunity Intelligence opens the planning workflow.
            </p>
        </div>
        <form method="GET" action="{{ route('app.opportunity-review.index') }}">
            <select name="workspace" class="pl-work-select" onchange="this.form.submit()">
                @foreach ($workspaces as $option)
                    <option value="{{ $option->id }}" @selected((string) $workspace->id === (string) $option->id)>{{ $option->display_name ?: $option->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    @if (session('status'))
        <x-alert class="md:items-center" :icon="true">{{ session('status') }}</x-alert>
    @endif

    @if (!empty($activation) && !data_get($activation, 'is_active'))
        <x-activation-banner :activation="$activation" compact />
    @endif

    <x-first-value-celebrations :items="$firstValueCelebrations ?? collect()" />

    @if ($first_candidate)
        <section class="rounded-lg border border-emerald-200 bg-emerald-50/80 p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Opportunity Review unlocked</p>
                    <h2 class="mt-2 text-xl font-semibold text-textPrimary">🎉 First Opportunity Candidate Detected</h2>
                    <p class="mt-1 text-sm leading-6 text-textSecondary">Argusly found a potential growth opportunity.</p>
                    <p class="mt-3 text-sm font-medium text-textPrimary">{{ $first_candidate->title }}</p>
                    <p class="mt-1 text-sm leading-6 text-textSecondary">{{ $first_candidate->summary }}</p>
                </div>
                <a href="{{ route('app.signal-intelligence.detections.show', $first_candidate) }}" class="inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                    <i data-lucide="eye" class="h-4 w-4"></i>
                    Review Opportunity
                </a>
            </div>
        </section>
    @else
        <section class="rounded-lg border border-amber-200 bg-amber-50/70 p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-wide text-amber-700">Waiting for first candidate</p>
                    <h2 class="mt-2 text-lg font-semibold text-textPrimary">No opportunity candidate yet</h2>
                    <p class="mt-1 text-sm leading-6 text-textSecondary">
                        Opportunity Review unlocks after Signal Intelligence creates a detection marked as an opportunity candidate.
                    </p>
                </div>
                <a href="{{ route('app.signal-intelligence.index', ['workspace' => $workspace->id]) }}" class="inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                    <i data-lucide="radar" class="h-4 w-4"></i>
                    Open Signal Intelligence
                </a>
            </div>
        </section>
    @endif

    <section class="grid gap-4 md:grid-cols-3">
        <x-llm-tracking.metric-card label="Candidates" :value="number_format((int) $candidate_count)" helper="Open review queue" tone="emerald" />
        <x-llm-tracking.metric-card label="High confidence" :value="number_format((int) $high_confidence_count)" helper="Confidence 75+" tone="blue" />
        <x-llm-tracking.metric-card label="Avg opportunity" :value="number_format((float) $avg_opportunity_score, 1)" helper="Candidate score" tone="amber" />
    </section>

    <section class="rounded-lg border border-border bg-surface p-5">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-base font-semibold text-textPrimary">Candidate queue</h2>
                <p class="mt-1 text-sm text-textSecondary">Open a candidate to inspect evidence, then promote, dismiss, or resolve it.</p>
            </div>
            <a href="{{ route('app.signal-intelligence.index', ['workspace' => $workspace->id]) }}#priority" class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                <i data-lucide="list-filter" class="h-4 w-4"></i>
                Signal candidates
            </a>
        </div>

        <x-responsive-table class="mt-4">
            <thead>
                <tr class="border-b border-border text-left text-xs uppercase tracking-wide text-textMuted">
                    <th class="px-4 py-3">Candidate</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Site</th>
                    <th class="px-4 py-3">Scores</th>
                    <th class="px-4 py-3 text-right">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($latest_candidates as $candidate)
                    <tr>
                        <td class="px-4 py-3">
                            <a href="{{ route('app.signal-intelligence.detections.show', $candidate) }}" class="font-medium text-primary hover:underline">{{ $candidate->title }}</a>
                            <p class="mt-1 line-clamp-2 text-xs text-textSecondary">{{ $candidate->primary_topic ?: $formatLabel($candidate->type) }}</p>
                        </td>
                        <td class="px-4 py-3"><x-status-badge :status="$candidate->status?->value ?? $candidate->status" :color="$statusTone($candidate->status?->value ?? $candidate->status)" /></td>
                        <td class="px-4 py-3 text-textSecondary">{{ $candidate->clientSite?->name ?? 'All sites' }}</td>
                        <td class="px-4 py-3 text-textSecondary">O {{ number_format((float) $candidate->opportunity_score, 0) }} · C {{ number_format((float) $candidate->confidence_score, 0) }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('app.signal-intelligence.detections.show', $candidate) }}" class="inline-flex h-8 items-center gap-2 rounded-md bg-primary px-3 text-xs font-semibold text-white hover:bg-primaryHover">
                                <i data-lucide="eye" class="h-3.5 w-3.5"></i>
                                Review
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-textMuted">No opportunity candidates are ready for review.</td></tr>
                @endforelse
            </tbody>
        </x-responsive-table>
    </section>
</div>
@endsection
