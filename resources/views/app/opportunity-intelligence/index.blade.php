@extends('layouts.app', ['title' => 'Opportunity Intelligence'])

@section('content')
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Opportunity Intelligence</h1>
            <p class="mt-1 text-sm text-textSecondary">Explainable signals, ranked opportunities, and recommended actions across search, AI visibility, competitors, content decay, and engagement.</p>
        </div>
        <form method="POST" action="{{ route('app.agentic-marketing.intelligence.run', request()->query()) }}" class="flex flex-wrap gap-2">
            @csrf
            <button class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Refresh intelligence</button>
            <button name="run_inline" value="1" class="rounded-md border border-border px-4 py-2 text-sm text-textPrimary">Run inline</button>
        </form>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <div class="mb-6 grid gap-4 md:grid-cols-4">
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">Open opportunities</p>
            <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ number_format((int) $summary['open']) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">Signals</p>
            <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ number_format((int) $summary['signals']) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">Avg priority</p>
            <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ number_format((float) $summary['avg_priority'], 1) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">High confidence</p>
            <p class="mt-2 text-2xl font-semibold text-textPrimary">{{ number_format((int) $summary['high_confidence']) }}</p>
        </div>
    </div>

    <form method="GET" action="{{ route('app.agentic-marketing.intelligence.index') }}" class="mb-6 flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 md:flex-row">
        <select name="category" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">All categories</option>
            @foreach ($categories as $category)
                <option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ str_replace('_', ' ', ucfirst($category)) }}</option>
            @endforeach
        </select>
        <select name="status" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">Open by default</option>
            @foreach (['open', 'reviewing', 'planned', 'actioned', 'dismissed', 'archived'] as $status)
                <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
        <button class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Apply</button>
    </form>

    <div class="mb-6 grid gap-6 xl:grid-cols-[1.5fr_1fr]">
        <section class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-5 py-4">
                <h2 class="text-sm font-semibold text-textPrimary">Recommended Actions</h2>
            </div>
            <div class="divide-y divide-border">
                @forelse ($opportunities as $opportunity)
                    <article class="p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap gap-2">
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $opportunity->category?->value ?? $opportunity->category) }}</span>
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">Priority {{ number_format((float) $opportunity->priority_score, 1) }}</span>
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">Confidence {{ number_format((float) $opportunity->confidence_score, 1) }}</span>
                                </div>
                                <h3 class="mt-2 font-semibold text-textPrimary">{{ $opportunity->title }}</h3>
                                <p class="mt-1 text-sm text-textSecondary">{{ $opportunity->summary }}</p>
                            </div>
                        </div>
                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            @foreach ((array) $opportunity->recommended_actions as $action)
                                <div class="rounded-md border border-border bg-background p-3">
                                    <p class="text-sm font-medium text-textPrimary">{{ $action['label'] ?? str_replace('_', ' ', (string) ($action['type'] ?? 'Action')) }}</p>
                                    <p class="mt-1 text-xs text-textSecondary">{{ $action['rationale'] ?? 'Recommended from stored evidence.' }}</p>
                                </div>
                            @endforeach
                        </div>
                        <details class="mt-4 rounded-md border border-border bg-background px-3 py-2">
                            <summary class="cursor-pointer text-xs font-medium text-textPrimary">Score explanation</summary>
                            <pre class="mt-2 whitespace-pre-wrap text-xs text-textSecondary">{{ json_encode($opportunity->score_breakdown, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                    </article>
                @empty
                    <p class="p-6 text-sm text-textSecondary">No opportunities yet. Ingest signals, then refresh intelligence.</p>
                @endforelse
            </div>
            <div class="border-t border-border px-5 py-4">{{ $opportunities->links() }}</div>
        </section>

        <section class="space-y-6">
            <div class="rounded-lg border border-border bg-surface p-4">
                <h2 class="text-sm font-semibold text-textPrimary">Signal Feed</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($signals as $signal)
                        <div class="rounded-md border border-border bg-background p-3">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-medium text-textPrimary">{{ str_replace('_', ' ', $signal->source?->value ?? $signal->source) }}</span>
                                <span class="text-xs text-textSecondary">{{ $signal->observed_at?->diffForHumans() }}</span>
                            </div>
                            <p class="mt-1 text-xs text-textSecondary">{{ $signal->topic ?: $signal->entity ?: 'General signal' }} · Strength {{ number_format((float) $signal->signal_strength, 1) }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-textSecondary">No signals stored yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-4">
                <h2 class="text-sm font-semibold text-textPrimary">Opportunity Timeline</h2>
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
                        <p class="text-sm text-textSecondary">No timeline yet.</p>
                    @endforelse
                </div>
            </div>
        </section>
    </div>
@endsection
