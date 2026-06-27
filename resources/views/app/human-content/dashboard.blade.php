@extends('layouts.app', ['title' => 'Human Content Dashboard'])

@php
    $averages = $dashboard['averages'] ?? [];
    $scoreCards = [
        ['label' => 'Human Content', 'key' => 'human_content_score'],
        ['label' => 'Editorial Quality', 'key' => 'editorial_quality_score'],
        ['label' => 'Originality', 'key' => 'originality_score'],
        ['label' => 'AI Fingerprint', 'key' => 'ai_fingerprint_score', 'lower' => true],
        ['label' => 'Narrative Flow', 'key' => 'narrative_flow_score'],
        ['label' => 'Human Voice', 'key' => 'human_voice_score'],
    ];
    $sampleSize = (int) ($dashboard['sample_size'] ?? 0);
@endphp

@section('content')
    <div class="space-y-6">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <a href="{{ route('app.insights.index') }}" class="inline-flex items-center gap-2 text-sm text-textSecondary hover:text-textPrimary">
                    <i data-lucide="arrow-left" class="h-4 w-4"></i>
                    Insights
                </a>
                <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Human Content Dashboard</h1>
                <p class="text-textSecondary">Workspace-level editorial health from stored Human Content scores.</p>
            </div>
            <div class="rounded-lg border border-border bg-surface px-4 py-3 text-sm text-textSecondary">
                {{ $sampleSize }} scored {{ Str::plural('draft', $sampleSize) }}
            </div>
        </header>

        <form method="GET" action="{{ route('app.insights.human-content.index') }}" class="rounded-lg border border-border bg-surface p-4">
            <div class="grid gap-3 md:grid-cols-5">
                <label class="space-y-1 text-sm">
                    <span class="text-xs font-medium text-textSecondary">Workspace</span>
                    <select name="workspace_id" class="w-full rounded-md border-border bg-background text-sm">
                        <option value="">All workspaces</option>
                        @foreach ($workspaces as $workspace)
                            <option value="{{ $workspace->id }}" @selected(($filters['workspace_id'] ?? '') === (string) $workspace->id)>{{ $workspace->display_name ?: $workspace->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-1 text-sm">
                    <span class="text-xs font-medium text-textSecondary">Site</span>
                    <select name="site_id" class="w-full rounded-md border-border bg-background text-sm">
                        <option value="">All sites</option>
                        @foreach ($sites as $site)
                            <option value="{{ $site->id }}" @selected(($filters['site_id'] ?? '') === (string) $site->id)>{{ $site->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-1 text-sm">
                    <span class="text-xs font-medium text-textSecondary">Locale</span>
                    <select name="locale" class="w-full rounded-md border-border bg-background text-sm">
                        <option value="">All locales</option>
                        @foreach ($locales as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['locale'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-1 text-sm">
                    <span class="text-xs font-medium text-textSecondary">Content type</span>
                    <select name="content_type" class="w-full rounded-md border-border bg-background text-sm">
                        <option value="">All types</option>
                        @foreach ($contentTypes as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['content_type'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="space-y-1 text-sm">
                    <span class="text-xs font-medium text-textSecondary">Period</span>
                    <select name="period" class="w-full rounded-md border-border bg-background text-sm">
                        @foreach ($periods as $value => $label)
                            <option value="{{ $value }}" @selected((int) ($filters['period'] ?? 30) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse hover:bg-primary/90">
                    <i data-lucide="filter" class="h-4 w-4"></i>
                    Apply filters
                </button>
                <a href="{{ route('app.insights.human-content.index') }}" class="inline-flex items-center rounded-md border border-border px-4 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Reset</a>
            </div>
        </form>

        @if ($sampleSize === 0)
            <x-settings.empty-state
                title="No Human Content scores yet"
                description="Generated or translated drafts will appear here after the Human Content scoring pipeline stores their score payload."
            />
        @else
            <section class="grid gap-4 md:grid-cols-3 xl:grid-cols-6">
                @foreach ($scoreCards as $card)
                    @php
                        $value = $averages[$card['key']] ?? null;
                        $lower = (bool) ($card['lower'] ?? false);
                        $tone = $value === null
                            ? 'text-textSecondary'
                            : ($lower ? ($value <= 35 ? 'text-emerald-700' : ($value <= 55 ? 'text-amber-700' : 'text-rose-700')) : ($value >= 75 ? 'text-emerald-700' : ($value >= 60 ? 'text-amber-700' : 'text-rose-700')));
                    @endphp
                    <div class="rounded-lg border border-border bg-surface p-4">
                        <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">{{ $card['label'] }}</p>
                        <p class="mt-2 text-3xl font-semibold {{ $tone }}">{{ $value ?? 'n/a' }}</p>
                    </div>
                @endforeach
            </section>

            <section class="grid gap-6 xl:grid-cols-3">
                <div class="rounded-lg border border-border bg-surface p-5 xl:col-span-2">
                    <h2 class="text-sm font-semibold text-textPrimary">Trend Over Time</h2>
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-border text-sm">
                            <thead class="text-left text-xs uppercase text-textSecondary">
                                <tr>
                                    <th class="py-2 pr-4">Date</th>
                                    <th class="py-2 pr-4">Human</th>
                                    <th class="py-2 pr-4">Editorial</th>
                                    <th class="py-2 pr-4">AI Fingerprint</th>
                                    <th class="py-2 pr-4">Drafts</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach (($dashboard['trend'] ?? []) as $point)
                                    <tr>
                                        <td class="py-2 pr-4 text-textPrimary">{{ $point['date'] }}</td>
                                        <td class="py-2 pr-4">{{ $point['human_content_score'] ?? 'n/a' }}</td>
                                        <td class="py-2 pr-4">{{ $point['editorial_quality_score'] ?? 'n/a' }}</td>
                                        <td class="py-2 pr-4">{{ $point['ai_fingerprint_score'] ?? 'n/a' }}</td>
                                        <td class="py-2 pr-4">{{ $point['count'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Common AI Fingerprints</h2>
                    <div class="mt-4 space-y-3">
                        @forelse (($dashboard['common_fingerprints'] ?? []) as $finding)
                            <div class="rounded-md border border-border bg-background p-3">
                                <div class="flex items-center justify-between gap-3">
                                    <p class="text-sm font-medium text-textPrimary">{{ $finding['label'] }}</p>
                                    <span class="rounded bg-surfaceMuted px-2 py-1 text-xs text-textSecondary">{{ $finding['count'] }}</span>
                                </div>
                                @if (! empty($finding['examples']))
                                    <p class="mt-2 text-xs text-textSecondary">{{ implode(' ', array_slice($finding['examples'], 0, 2)) }}</p>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-textSecondary">No recurring fingerprint findings in this period.</p>
                        @endforelse
                    </div>
                </div>
            </section>

            <section class="grid gap-6 xl:grid-cols-3">
                @include('app.human-content.partials.article-list', [
                    'title' => 'Most Repetitive Articles',
                    'items' => $dashboard['most_repetitive'] ?? [],
                    'metric' => 'corpus_diversity_risk_score',
                    'metricLabel' => 'Corpus risk',
                ])
                @include('app.human-content.partials.article-list', [
                    'title' => 'Most Original Articles',
                    'items' => $dashboard['most_original'] ?? [],
                    'metric' => 'originality_score',
                    'metricLabel' => 'Originality',
                ])
                @include('app.human-content.partials.article-list', [
                    'title' => 'Most Human Articles',
                    'items' => $dashboard['most_human'] ?? [],
                    'metric' => 'human_content_score',
                    'metricLabel' => 'Human score',
                ])
            </section>

            <section class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Blocked By Human Content Gate</h2>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full divide-y divide-border text-sm">
                        <thead class="text-left text-xs uppercase text-textSecondary">
                            <tr>
                                <th class="py-2 pr-4">Article</th>
                                <th class="py-2 pr-4">Score</th>
                                <th class="py-2 pr-4">Status</th>
                                <th class="py-2 pr-4">Reason</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @forelse (($dashboard['blocked_articles'] ?? []) as $item)
                                <tr>
                                    <td class="py-3 pr-4">
                                        <a href="{{ route('app.drafts.show', ['draft' => $item['id'], 'tab' => 'intelligence']) }}" class="font-medium text-primary hover:underline">{{ $item['title'] }}</a>
                                        <p class="text-xs text-textSecondary">{{ $item['workspace'] }} · {{ $item['locale'] }}</p>
                                    </td>
                                    <td class="py-3 pr-4">{{ $item['human_content_score'] ?? 'n/a' }}</td>
                                    <td class="py-3 pr-4">{{ str_replace('_', ' ', $item['gate_status'] ?: 'needs review') }}</td>
                                    <td class="py-3 pr-4 text-textSecondary">{{ implode(' ', array_slice($item['gate_reasons'] ?? [], 0, 2)) ?: 'Human Content Gate blocked publication.' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-4 text-sm text-textSecondary">No articles are blocked by the Human Content Gate in this period.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
