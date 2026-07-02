@extends('layouts.app', ['title' => 'Share of AI Attention'])

@php
    $summary = $indexSummary ?? [];
        $trend = $siteTrend ?? [];
        $filters = $filters ?? [];
        $querySets = $querySets ?? collect();
        $queryPerformanceRows = $queryPerformanceRows ?? [];
        $latestResponseRows = $latestResponseRows ?? [];
        $providerOptions = $providerOptions ?? [];
        $modelOptions = $modelOptions ?? [];
        $localeOptions = $localeOptions ?? [];
        $frequencyLabel = static fn (?string $frequency): string => match ((string) $frequency) {
            'weekly' => 'Weekly',
            default => 'Daily',
        };
        $contextLabel = static fn (?string $label): string => match ((string) $label) {
            'positive' => 'Positive',
            'negative' => 'Negative',
            'neutral' => 'Neutral',
            'not_present' => 'Not present',
            default => 'Unknown',
        };
        $positionLabel = static fn ($score): string => match (true) {
            ! is_numeric($score) => 'Not ranked',
            (float) $score >= 1 => 'Primary mention',
            (float) $score >= 0.75 => 'Early mention',
            (float) $score >= 0.5 => 'Later mention',
            (float) $score > 0 => 'Buried mention',
            default => 'Not ranked',
        };
        $summaryCards = [
            [
                'label' => 'Presence Rate',
                'value' => is_numeric(data_get($summary, 'presence_rate')) ? number_format((float) data_get($summary, 'presence_rate'), 1) . '%' : '-',
                'helper' => 'Target brand mentioned',
            ],
            [
                'label' => 'Earned Visibility',
                'value' => is_numeric(data_get($summary, 'earned_visibility_score')) ? number_format((float) data_get($summary, 'earned_visibility_score'), 1) : '-',
                'helper' => 'Third-party authority',
            ],
            [
                'label' => 'Competitor Pressure',
                'value' => is_numeric(data_get($summary, 'competitor_pressure_score')) ? number_format((float) data_get($summary, 'competitor_pressure_score'), 1) : '-',
                'helper' => 'Higher means more pressure',
            ],
            [
                'label' => 'Authority Gap',
                'value' => is_numeric(data_get($summary, 'real_world_gap_score')) ? number_format((float) data_get($summary, 'real_world_gap_score'), 1) : '-',
                'helper' => 'Provider/citation gap risk',
            ],
            [
                'label' => 'Runs In Filter',
                'value' => (int) data_get($summary, 'queries_with_runs', 0),
                'helper' => (int) data_get($summary, 'active_queries', 0) . ' active queries',
            ],
    ];
@endphp

@section('pageHeader')
    <x-page-header title="Share of AI Attention" />
@endsection

@section('pageDescription')
    <x-page-description>AI Visibility Score for tracked prompts, providers, citations, brand mentions, and competitor pressure.</x-page-description>
@endsection

@section('primaryActions')
    <a href="{{ route('app.insights.index') }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">All sites</a>
    <a href="{{ route('app.sites.show', $site) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Site setup</a>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="AI Visibility Score" :value="is_numeric(data_get($summary, 'ai_visibility_score')) ? number_format((float) data_get($summary, 'ai_visibility_score'), 1) : '-'" helper="Average across latest filtered runs" />
        @foreach ($summaryCards as $card)
            <x-metric-card :label="$card['label']" :value="$card['value']" :helper="$card['helper']" />
        @endforeach
    </x-metric-section>
@endsection

@section('content')

    <div class="space-y-5">
        <x-app.insights-header
            :site="$site"
            title="Share of AI Attention"
            description="AI Visibility Score for tracked prompts, providers, citations, brand mentions, and competitor pressure."
            active="llm"
            :show-heading="false"
        >
        </x-app.insights-header>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>
        @endif

        <x-first-value-celebrations :items="$firstValueCelebrations ?? collect()" />

        @if ((int) ($totalQueryCount ?? 0) === 0)
            <section class="rounded-lg border border-primary/25 bg-primarySoftBg/70 p-6">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="max-w-3xl">
                        <p class="text-xs font-semibold uppercase tracking-wide text-primary">AI Visibility Setup</p>
                        <h2 class="mt-2 text-xl font-semibold text-textPrimary">Start with your first AI Visibility queries</h2>
                        <p class="mt-2 text-sm leading-6 text-textSecondary">
                            AI Visibility laat zien hoe zichtbaar jouw merk is binnen AI-systemen zoals ChatGPT, Claude en Gemini.
                            Queries zijn de vragen die Argusly volgt. Runs leveren antwoorddata op, en die data kan daarna Signal Events en Detections voeden.
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        <a href="{{ route('app.sites.llm-tracking.starter.preview', $site) }}" class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                            <i data-lucide="sparkles" class="h-4 w-4"></i>
                            Generate Starter Queries
                        </a>
                        <a href="#create-query-manually" class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                            <i data-lucide="square-pen" class="h-4 w-4"></i>
                            Create Query Manually
                        </a>
                    </div>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-3">
                    <div class="rounded-md border border-border bg-white/80 p-4">
                        <h3 class="text-sm font-semibold text-textPrimary">Why queries matter</h3>
                        <p class="mt-1 text-xs leading-5 text-textSecondary">Each query represents a buyer, category, competitor, or authority question you want AI systems to answer well.</p>
                    </div>
                    <div class="rounded-md border border-border bg-white/80 p-4">
                        <h3 class="text-sm font-semibold text-textPrimary">Recommended starter set</h3>
                        <p class="mt-1 text-xs leading-5 text-textSecondary">Start with up to 10 prompts: brand, competitors, buyer intent, authority, and category leadership.</p>
                    </div>
                    <div class="rounded-md border border-border bg-white/80 p-4">
                        <h3 class="text-sm font-semibold text-textPrimary">How signals appear</h3>
                        <p class="mt-1 text-xs leading-5 text-textSecondary">After a run, AI answer evidence can be normalized into Signal Events for Signal Intelligence.</p>
                    </div>
                </div>
            </section>
        @elseif ((int) ($runCount ?? 0) === 0 && !empty($firstRunnableQuery))
            <section class="rounded-lg border border-emerald-200 bg-emerald-50/80 p-6">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Your AI Visibility workspace is ready</p>
                        <h2 class="mt-2 text-xl font-semibold text-textPrimary">Run your first visibility check</h2>
                        <p class="mt-2 text-sm leading-6 text-textSecondary">You have {{ number_format((int) ($totalQueryCount ?? 0)) }} tracking {{ (int) ($totalQueryCount ?? 0) === 1 ? 'query' : 'queries' }} configured. Start with one manual run to create the first AI Visibility evidence.</p>
                    </div>
                    <form method="POST" action="{{ route('app.sites.llm-tracking.run-now', [$site, $firstRunnableQuery]) }}" class="shrink-0">
                        @csrf
                        <button class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                            <i data-lucide="play" class="h-4 w-4"></i>
                            Run First Visibility Check
                        </button>
                    </form>
                </div>
                <div class="mt-5 grid gap-3 md:grid-cols-3">
                    <div class="rounded-md border border-emerald-200 bg-white/80 p-4">
                        <p class="text-xs text-textSecondary">Queries configured</p>
                        <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((int) ($totalQueryCount ?? 0)) }}</p>
                    </div>
                    <div class="rounded-md border border-emerald-200 bg-white/80 p-4">
                        <p class="text-xs text-textSecondary">Estimated credits</p>
                        <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((int) ($estimatedFirstRunCredits ?? 1)) }}</p>
                        <p class="mt-1 text-xs text-textSecondary">Same-day identical reruns may be cached at 0 credits.</p>
                    </div>
                    <div class="rounded-md border border-emerald-200 bg-white/80 p-4">
                        <p class="text-xs text-textSecondary">Expected duration</p>
                        <p class="mt-1 text-xl font-semibold text-textPrimary">1-3 min</p>
                    </div>
                </div>
            </section>
        @endif

        <div class="grid gap-4 lg:grid-cols-4">
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-textMuted">Brand visibility</p>
                <div class="mt-3 space-y-2 text-sm text-textSecondary">
                    <p>Mention rate: <span class="font-medium text-textPrimary">{{ is_numeric(data_get($summary, 'presence_rate')) ? number_format((float) data_get($summary, 'presence_rate'), 1) . '%' : '-' }}</span></p>
                    <p>Placement: <span class="font-medium text-textPrimary">{{ is_numeric(data_get($summary, 'average_position_score')) ? number_format((float) data_get($summary, 'average_position_score'), 1) : '-' }}</span></p>
                    <p>Owned citations: <span class="font-medium text-textPrimary">{{ is_numeric(data_get($summary, 'owned_citation_rate')) ? number_format((float) data_get($summary, 'owned_citation_rate'), 1) . '%' : '-' }}</span></p>
                    <p>Earned citations: <span class="font-medium text-textPrimary">{{ is_numeric(data_get($summary, 'earned_citation_rate')) ? number_format((float) data_get($summary, 'earned_citation_rate'), 1) . '%' : '-' }}</span></p>
                </div>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-textMuted">Competitor pressure</p>
                <div class="mt-3 space-y-2 text-sm text-textSecondary">
                    @forelse ((array) data_get($summary, 'top_competitors', []) as $row)
                        <p>{{ $row['term'] ?? '' }} <span class="text-xs">({{ $row['mentions'] ?? 0 }} mentions)</span></p>
                    @empty
                        <p>No tracked or detected entities dominate the latest runs.</p>
                    @endforelse
                </div>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-textMuted">Authority gap</p>
                <div class="mt-3 space-y-2 text-sm text-textSecondary">
                    <p>Owned visibility: <span class="font-medium text-textPrimary">{{ is_numeric(data_get($summary, 'owned_visibility_score')) ? number_format((float) data_get($summary, 'owned_visibility_score'), 1) : '-' }}</span></p>
                    <p>Citation diversity: <span class="font-medium text-textPrimary">{{ is_numeric(data_get($summary, 'citation_diversity_score')) ? number_format((float) data_get($summary, 'citation_diversity_score'), 1) : '-' }}</span></p>
                    <p>Gap risk: <span class="font-medium text-textPrimary">{{ is_numeric(data_get($summary, 'real_world_gap_score')) ? number_format((float) data_get($summary, 'real_world_gap_score'), 1) : '-' }}</span></p>
                </div>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs font-semibold uppercase tracking-[0.12em] text-textMuted">Provider breakdown</p>
                <div class="mt-3 space-y-2 text-sm text-textSecondary">
                    @forelse ((array) data_get($summary, 'provider_breakdown', []) as $row)
                        <p>{{ ucfirst($row['provider'] ?? 'unknown') }}: <span class="font-medium text-textPrimary">{{ is_numeric($row['avg_visibility_score'] ?? null) ? number_format((float) $row['avg_visibility_score'], 1) : '-' }}</span> visibility, {{ $row['mention_rate'] ?? '-' }}% mentions</p>
                    @empty
                        <p>No provider-specific runs yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-textPrimary">Dashboard Filters</h2>
                    <p class="mt-1 text-xs text-textSecondary">Filter AI Visibility Score by query set, period, provider, model, locale, brand, or competitor.</p>
                </div>
                <div class="text-xs text-textSecondary">
                    @if (data_get($summary, 'latest_run_at'))
                        Latest run {{ data_get($summary, 'latest_run_at')->diffForHumans() }}
                    @endif
                </div>
            </div>

            <form method="GET" action="{{ route('app.sites.llm-tracking.index', $site) }}" class="mt-4 grid gap-3 md:grid-cols-4 xl:grid-cols-7">
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-textSecondary">Query set</label>
                    <select name="query_set_id" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        <option value="">All</option>
                        @foreach ($querySets as $querySet)
                            <option value="{{ $querySet->id }}" @selected((string) ($filters['query_set_id'] ?? '') === (string) $querySet->id)>{{ $querySet->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-textSecondary">Period</label>
                    <select name="period" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        @foreach (['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days'] as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['period'] ?? '30d') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-textSecondary">Provider</label>
                    <select name="provider" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        <option value="">All</option>
                        @foreach ($providerOptions as $provider)
                            <option value="{{ $provider }}" @selected(($filters['provider'] ?? '') === $provider)>{{ ucfirst($provider) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-textSecondary">Model</label>
                    <select name="model" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        <option value="">All</option>
                        @foreach ($modelOptions as $model)
                            <option value="{{ $model }}" @selected(($filters['model'] ?? '') === $model)>{{ $model }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-textSecondary">Locale</label>
                    <select name="locale" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        <option value="">All</option>
                        @foreach ($localeOptions as $locale)
                            <option value="{{ $locale }}" @selected(($filters['locale'] ?? '') === $locale)>{{ $locale }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-textSecondary">Brand</label>
                    <input name="brand" value="{{ $filters['brand'] ?? '' }}" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-medium text-textSecondary">Competitor</label>
                    <input name="competitor" value="{{ $filters['competitor'] ?? '' }}" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                </div>
                <div class="flex items-end gap-2 md:col-span-4 xl:col-span-7">
                    <button class="rounded-md border border-transparent bg-textPrimary px-3 py-2 text-sm text-white">Apply filters</button>
                    <a href="{{ route('app.sites.llm-tracking.index', $site) }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary">Reset</a>
                </div>
            </form>
        </div>

        <div class="grid gap-5 lg:grid-cols-3">
            <div class="rounded-lg border border-border bg-surface lg:col-span-2">
                <div class="flex items-start justify-between gap-4">
                    <div class="p-5">
                        <h2 class="text-sm font-semibold text-textPrimary">Query Sets</h2>
                        <p class="mt-1 text-xs text-textSecondary">Group monitoring prompts into SEO Focus, AI / GEO Focus, Brand Monitoring, or competitor comparison sets.</p>
                    </div>
                    <div class="p-5 text-xs text-textSecondary">{{ $querySets->count() }} configured</div>
                </div>

                <div class="border-t border-border/70 p-4">
                    @forelse ($querySets as $querySet)
                        <form method="POST" action="{{ route('app.sites.llm-tracking.query-sets.update', [$site, $querySet]) }}" class="grid gap-3 border-b border-border/60 py-3 last:border-0 md:grid-cols-[minmax(0,1fr)_80px_96px_auto]">
                            @csrf
                            <div>
                                <input name="name" value="{{ $querySet->name }}" class="w-full rounded border border-transparent bg-background px-2 py-2 text-sm font-medium text-textPrimary focus:border-border">
                                <textarea name="description" rows="2" class="mt-1 w-full rounded border border-transparent bg-background px-2 py-2 text-xs text-textSecondary focus:border-border">{{ $querySet->description }}</textarea>
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] text-textSecondary">Locale</label>
                                <input name="locale" value="{{ $querySet->locale }}" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-[11px] text-textSecondary">Status</label>
                                <label class="inline-flex items-center gap-2 text-xs text-textPrimary">
                                    <input type="checkbox" name="is_active" value="1" @checked($querySet->is_active)>
                                    Active
                                </label>
                            </div>
                            <div class="flex items-end gap-2">
                                <button class="rounded border border-border px-3 py-2 text-xs">Save</button>
                                <button
                                    type="submit"
                                    formmethod="POST"
                                    formaction="{{ route('app.sites.llm-tracking.query-sets.toggle', [$site, $querySet]) }}"
                                    class="rounded border border-border px-3 py-2 text-xs"
                                >
                                    {{ $querySet->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </div>
                        </form>
                    @empty
                        <p class="text-sm text-textSecondary">No query sets yet.</p>
                    @endforelse
                </div>
            </div>

            <details id="create-query-manually" class="self-start rounded-lg border border-border bg-surface p-5">
                <summary class="cursor-pointer list-none text-sm font-semibold text-textPrimary">
                    Create Query Set
                    <span class="mt-1 block text-xs font-normal text-textSecondary">Add a new monitoring group when the existing sets are not enough.</span>
                </summary>

                <form method="POST" action="{{ route('app.sites.llm-tracking.query-sets.store', $site) }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Name</label>
                        <input name="name" value="{{ old('name') }}" required maxlength="120" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Description</label>
                        <textarea name="description" rows="3" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">{{ old('description') }}</textarea>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Locale</label>
                            <input name="locale" value="{{ old('locale', 'en') }}" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        </div>
                        <div class="flex items-end">
                            <label class="inline-flex items-center gap-2 text-sm text-textPrimary">
                                <input type="checkbox" name="is_active" value="1" checked>
                                Active
                            </label>
                        </div>
                    </div>
                    <button class="rounded border border-border px-3 py-2 text-sm">Create query set</button>
                </form>
            </details>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div class="rounded-lg border border-border bg-surface lg:col-span-2">
                <div class="flex items-start justify-between gap-4 p-5">
                    <div>
                        <h2 class="text-sm font-semibold text-textPrimary">Tracking Queries</h2>
                        <p class="mt-1 text-xs text-textSecondary">LLM tracking query management, grouped by query set and ready for provider-level filtering.</p>
                    </div>
                    <div class="text-right text-xs text-textSecondary">
                        <div>{{ (int) data_get($summary, 'queries_with_runs', 0) }} with data</div>
                        <div>{{ (int) data_get($summary, 'opportunities_total', 0) }} strategy suggestions</div>
                    </div>
                </div>

                <x-data-table label="Tracking Queries" description="LLM tracking query management with prompt, target, cadence, run metrics, and row actions." sticky max-height="24rem" table-class="min-w-[1320px]" class="border-x-0 border-b-0 rounded-none">
                        <x-data-table.header sticky>
                            <x-data-table.row>
                                <x-data-table.cell heading class="w-56">Query</x-data-table.cell>
                                <x-data-table.cell heading class="w-72">Prompt</x-data-table.cell>
                                <x-data-table.cell heading class="w-44">Target</x-data-table.cell>
                                <x-data-table.cell heading class="w-40">Set</x-data-table.cell>
                                <x-data-table.cell heading class="w-24">Cadence</x-data-table.cell>
                                <x-data-table.cell heading class="w-24">Status</x-data-table.cell>
                                <x-data-table.cell heading class="w-32">Last run</x-data-table.cell>
                                <x-data-table.cell heading class="w-24">AI visibility</x-data-table.cell>
                                <x-data-table.cell heading class="w-32">Presence</x-data-table.cell>
                                <x-data-table.cell heading class="w-24">Citation</x-data-table.cell>
                                <x-data-table.cell heading class="w-28">Context</x-data-table.cell>
                                <x-data-table.cell heading class="w-36">Actions</x-data-table.cell>
                            </x-data-table.row>
                        </x-data-table.header>
                        <tbody>
                            @forelse ($queries as $query)
                                @php($latestRun = $query->runs->first())
                                <x-data-table.row>
                                    <x-data-table.cell label="Query">
                                        <div class="font-medium leading-5">{{ $query->name }}</div>
                                    </x-data-table.cell>
                                    <x-data-table.cell label="Prompt">
                                        <div class="text-xs leading-5 text-textSecondary">{{ \Illuminate\Support\Str::limit($query->query_text, 140) }}</div>
                                    </x-data-table.cell>
                                    <x-data-table.cell label="Target" class="text-xs leading-5">
                                        <div class="font-medium text-textPrimary">{{ $query->target_brand ?: 'Not set' }}</div>
                                        <div class="mt-1 text-textSecondary">{{ $query->target_domain ?: 'No domain' }}</div>
                                    </x-data-table.cell>
                                    <x-data-table.cell label="Set" class="text-xs">{{ $query->querySet?->name ?: 'Unassigned' }}</x-data-table.cell>
                                    <x-data-table.cell label="Cadence" class="text-xs">{{ $frequencyLabel($query->frequency) }}</x-data-table.cell>
                                    <x-data-table.cell label="Status" class="text-xs">
                                        <x-data-table.badge :tone="$query->is_active ? 'success' : 'neutral'" :label="$query->is_active ? 'Active' : 'Inactive'" />
                                    </x-data-table.cell>
                                    <x-data-table.cell label="Last run" class="text-xs">{{ optional($latestRun?->run_at)->toDateTimeString() ?? 'Never' }}</x-data-table.cell>
                                    <x-data-table.cell label="AI visibility" class="text-xs font-semibold text-textPrimary">{{ is_numeric($latestRun?->ai_visibility_score) ? number_format(((float) $latestRun->ai_visibility_score) * 100, 1) : '-' }}</x-data-table.cell>
                                    <x-data-table.cell label="Presence" class="text-xs">{{ $latestRun ? ($latestRun->brand_mentioned ? 'Argusly present' : 'Missing') : 'No run yet' }}</x-data-table.cell>
                                    <x-data-table.cell label="Citation" class="text-xs">{{ $latestRun && (((float) ($latestRun->citation_score ?? 0)) > 0 || $latestRun->urls_cited) ? 'Present' : 'Missing' }}</x-data-table.cell>
                                    <x-data-table.cell label="Context" class="text-xs">{{ $contextLabel($latestRun?->context_label ?? $latestRun?->sentiment_label) }}</x-data-table.cell>
                                    <x-data-table.cell label="Actions">
                                        <x-data-table.actions align="start">
                                            <a href="{{ route('app.sites.llm-tracking.show', [$site, $query]) }}" class="rounded border border-border px-2 py-1 text-xs">Open</a>
                                            <form method="POST" action="{{ route('app.sites.llm-tracking.toggle', [$site, $query]) }}">@csrf<button class="rounded border border-border px-2 py-1 text-xs">{{ $query->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                                            <form method="POST" action="{{ route('app.sites.llm-tracking.run-now', [$site, $query]) }}">@csrf<button class="rounded border border-border px-2 py-1 text-xs">Run now</button></form>
                                        </x-data-table.actions>
                                    </x-data-table.cell>
                                </x-data-table.row>
                            @empty
                                <x-data-table.empty colspan="12" title="No tracking queries configured yet" />
                            @endforelse
                        </tbody>
                </x-data-table>
            </div>

            <details class="self-start rounded-lg border border-border bg-surface p-5">
                <summary class="cursor-pointer list-none text-sm font-semibold text-textPrimary">
                    Create Tracking Query
                    <span class="mt-1 block text-xs font-normal text-textSecondary">Add prompts without pushing the data table down the page.</span>
                </summary>

                <form method="POST" action="{{ route('app.sites.llm-tracking.store', $site) }}" class="mt-4 space-y-4">
                    @csrf
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Query set</label>
                        <select name="llm_tracking_query_set_id" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                            <option value="">No query set</option>
                            @foreach ($querySets as $querySet)
                                <option value="{{ $querySet->id }}" @selected(old('llm_tracking_query_set_id') == $querySet->id)>{{ $querySet->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Name</label>
                        <input name="name" value="{{ old('name') }}" required maxlength="120" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Query text</label>
                        <textarea name="query_text" rows="4" required class="w-full rounded border border-border bg-background px-2 py-2 text-sm">{{ old('query_text') }}</textarea>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Query variants</label>
                        <textarea name="query_variants" rows="4" class="w-full rounded border border-border bg-background px-2 py-2 text-sm" placeholder="Optional. One buyer, comparison, category, or problem-intent variant per line.">{{ old('query_variants') }}</textarea>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Target brand</label>
                            <input name="target_brand" value="{{ old('target_brand') }}" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Target domain</label>
                            <input name="target_domain" value="{{ old('target_domain') }}" class="w-full rounded border border-border bg-background px-2 py-2 text-sm" placeholder="example.com">
                        </div>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Brand aliases</label>
                            <textarea name="brand_terms" rows="3" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">{{ old('brand_terms') }}</textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Competitors / aliases</label>
                            <textarea name="competitor_terms" rows="3" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">{{ old('competitor_terms') }}</textarea>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Target URLs</label>
                        <textarea name="target_urls" rows="2" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">{{ old('target_urls') }}</textarea>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Tags</label>
                        <textarea name="tags" rows="2" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">{{ old('tags') }}</textarea>
                    </div>
                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Locale</label>
                            <input name="locale" value="{{ old('locale', 'en') }}" maxlength="16" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Cadence</label>
                            <select name="frequency" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                                <option value="daily" @selected(old('frequency', 'daily') === 'daily')>Daily</option>
                                <option value="weekly" @selected(old('frequency') === 'weekly')>Weekly</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs text-textSecondary">Priority</label>
                            <input name="priority" type="number" min="1" max="100" value="{{ old('priority', 50) }}" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        </div>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-textPrimary">
                        <input type="checkbox" name="is_active" value="1" checked>
                        Active
                    </label>
                    <button class="rounded border border-border px-3 py-2 text-sm">Create query</button>
                </form>
            </details>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <div class="rounded-lg border border-border bg-surface">
                <div class="p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Query Performance</h2>
                </div>
                <x-data-table label="Query Performance" description="Latest and average AI visibility performance, presence, citation, competitor, and trend metrics by query." sticky max-height="24rem" class="border-x-0 border-b-0 rounded-none">
                        <x-data-table.header sticky>
                            <x-data-table.row>
                                <x-data-table.cell heading>Query</x-data-table.cell>
                                <x-data-table.cell heading>Latest</x-data-table.cell>
                                <x-data-table.cell heading>Average</x-data-table.cell>
                                <x-data-table.cell heading>Presence</x-data-table.cell>
                                <x-data-table.cell heading>Citation</x-data-table.cell>
                                <x-data-table.cell heading>Top competitor</x-data-table.cell>
                                <x-data-table.cell heading>Trend</x-data-table.cell>
                            </x-data-table.row>
                        </x-data-table.header>
                        <tbody>
                            @forelse ($queryPerformanceRows as $row)
                                <x-data-table.row>
                                    <x-data-table.cell label="Query">{{ data_get($row, 'query.name') }}</x-data-table.cell>
                                    <x-data-table.cell label="Latest" class="font-semibold">{{ is_numeric(data_get($row, 'latest_score')) ? number_format((float) data_get($row, 'latest_score'), 1) : '-' }}</x-data-table.cell>
                                    <x-data-table.cell label="Average">{{ is_numeric(data_get($row, 'average_score')) ? number_format((float) data_get($row, 'average_score'), 1) : '-' }}</x-data-table.cell>
                                    <x-data-table.cell label="Presence">{{ number_format((float) data_get($row, 'presence_percentage', 0), 1) }}%</x-data-table.cell>
                                    <x-data-table.cell label="Citation">{{ number_format((float) data_get($row, 'citation_percentage', 0), 1) }}%</x-data-table.cell>
                                    <x-data-table.cell label="Top competitor">{{ data_get($row, 'top_competitor', '-') ?: '-' }}</x-data-table.cell>
                                    <x-data-table.cell label="Trend">{{ is_numeric(data_get($row, 'trend')) ? number_format((float) data_get($row, 'trend'), 1) : '-' }}</x-data-table.cell>
                                </x-data-table.row>
                            @empty
                                <x-data-table.empty colspan="7" title="No query performance data yet" />
                            @endforelse
                        </tbody>
                </x-data-table>
            </div>

            <div class="rounded-lg border border-border bg-surface">
                <div class="p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Latest Answers</h2>
                </div>
                <x-data-table label="Latest Answers" description="Latest tracked answer runs with provider, model, query, score, brand presence, citation, context, and detail link." sticky max-height="24rem" class="border-x-0 border-b-0 rounded-none">
                        <x-data-table.header sticky>
                            <x-data-table.row>
                                <x-data-table.cell heading>Date</x-data-table.cell>
                                <x-data-table.cell heading>Provider</x-data-table.cell>
                                <x-data-table.cell heading>Model</x-data-table.cell>
                                <x-data-table.cell heading>Query</x-data-table.cell>
                                <x-data-table.cell heading>Score</x-data-table.cell>
                                <x-data-table.cell heading>Brand</x-data-table.cell>
                                <x-data-table.cell heading>Citation</x-data-table.cell>
                                <x-data-table.cell heading>Context</x-data-table.cell>
                                <x-data-table.cell heading>Detail</x-data-table.cell>
                            </x-data-table.row>
                        </x-data-table.header>
                        <tbody>
                            @forelse ($latestResponseRows as $row)
                                @php($run = $row['run'])
                                @php($query = $row['query'])
                                <x-data-table.row>
                                    <x-data-table.cell label="Date" class="text-xs">{{ optional($run->run_at)->toDateTimeString() }}</x-data-table.cell>
                                    <x-data-table.cell label="Provider" class="text-xs">{{ $run->provider ?: '-' }}</x-data-table.cell>
                                    <x-data-table.cell label="Model" class="text-xs">{{ $run->model ?: '-' }}</x-data-table.cell>
                                    <x-data-table.cell label="Query" class="text-xs">{{ $query->name }}</x-data-table.cell>
                                    <x-data-table.cell label="Score" class="text-xs font-semibold">{{ is_numeric($row['score']) ? number_format((float) $row['score'], 1) : '-' }}</x-data-table.cell>
                                    <x-data-table.cell label="Brand" class="text-xs">{{ $row['brand_mentioned'] ? 'Yes' : 'No' }}</x-data-table.cell>
                                    <x-data-table.cell label="Citation" class="text-xs">{{ $row['citation_present'] ? 'Yes' : 'No' }}</x-data-table.cell>
                                    <x-data-table.cell label="Context" class="text-xs">{{ $contextLabel($row['context_label']) }}</x-data-table.cell>
                                    <x-data-table.cell label="Detail"><a href="{{ route('app.sites.llm-tracking.show', [$site, $query]) }}" class="rounded border border-border px-2 py-1 text-xs">Open</a></x-data-table.cell>
                                </x-data-table.row>
                            @empty
                                <x-data-table.empty colspan="9" title="No tracked answers yet" />
                            @endforelse
                        </tbody>
                </x-data-table>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-amber-300/40 bg-amber-50/70 p-6">
                <h2 class="text-sm font-semibold text-amber-950">Missing Visibility Opportunities</h2>
                <p class="mt-1 text-xs text-amber-900/80">Queries where Argusly did not appear in the latest tracked run.</p>

                @forelse ((array) data_get($summary, 'missing_visibility', []) as $entry)
                    @php($query = $entry['query'])
                    @php($latestRun = $entry['latest_run'])
                    @php($primarySuggestion = collect((array) ($entry['suggestions'] ?? []))->first())
                    <div class="mt-4 rounded-lg border border-amber-300/40 bg-white/80 p-4">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm font-medium text-textPrimary">{{ $query->name }}</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ $query->query_text }}</p>
                            </div>
                            <div class="text-right text-xs text-textSecondary">
                                <div>{{ optional($latestRun?->run_at)->toDateTimeString() ?? 'No run' }}</div>
                                <div>{{ $frequencyLabel($query->frequency) }}</div>
                            </div>
                        </div>

                        @if ($primarySuggestion)
                            <div class="mt-3 grid gap-3 md:grid-cols-3">
                                <div>
                                    <p class="text-xs font-semibold text-textPrimary">Content topics</p>
                                    <p class="mt-1 text-xs text-textSecondary">{{ implode(', ', (array) ($primarySuggestion['content_topics'] ?? [])) ?: 'Add topic coverage for this query cluster.' }}</p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-textPrimary">Landing pages</p>
                                    @foreach ((array) ($primarySuggestion['landing_pages'] ?? []) as $page)
                                        <p class="mt-1 text-xs text-textSecondary">{{ data_get($page, 'title', 'Page') }} @if (data_get($page, 'slug'))<span class="font-mono text-[11px]">/{{ data_get($page, 'slug') }}</span>@endif</p>
                                    @endforeach
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-textPrimary">SEO / GEO improvements</p>
                                    @foreach ((array) ($primarySuggestion['seo_geo_improvements'] ?? []) as $improvement)
                                        <p class="mt-1 text-xs text-textSecondary">{{ $improvement }}</p>
                                    @endforeach
                                </div>
                            </div>
                        @else
                            <p class="mt-3 text-xs text-textSecondary">No structured strategy suggestions yet for this gap.</p>
                        @endif
                    </div>
                @empty
                    <p class="mt-3 text-sm text-textSecondary">No missing visibility opportunities in the latest tracked runs.</p>
                @endforelse
            </div>

            <div class="space-y-6">
                <div class="rounded-lg border border-border bg-surface p-6">
                    <h2 class="text-sm font-semibold text-textPrimary">Top Competitors By Frequency</h2>
                    <p class="mt-1 text-xs text-textSecondary">Compared against the latest run for each active query.</p>

                    @forelse ((array) data_get($summary, 'top_competitors', []) as $competitor)
                        <div class="mt-3 flex items-center justify-between rounded-lg border border-border/70 bg-background px-3 py-2 text-sm">
                            <div>
                                <p class="font-medium text-textPrimary">{{ data_get($competitor, 'term') }}</p>
                                <p class="text-xs text-textSecondary">{{ (int) data_get($competitor, 'queries', 0) }} queries</p>
                            </div>
                            <div class="text-right">
                                <p class="font-medium text-textPrimary">{{ (int) data_get($competitor, 'mentions', 0) }}</p>
                                <p class="text-xs text-textSecondary">mentions</p>
                            </div>
                        </div>
                    @empty
                        <p class="mt-3 text-sm text-textSecondary">No competitors detected yet.</p>
                    @endforelse
                </div>

                <div class="rounded-lg border border-border bg-surface p-6">
                    <h2 class="text-sm font-semibold text-textPrimary">Trend Over Time</h2>
                    <x-data-table label="Trend Over Time" description="Weekly AI visibility, presence, citation, context, position, and run count trend." density="compact" class="mt-3 border-0 rounded-none" table-class="text-xs">
                            <x-data-table.header>
                                <x-data-table.row>
                                    <x-data-table.cell heading>Week</x-data-table.cell>
                                    <x-data-table.cell heading>AI visibility</x-data-table.cell>
                                    <x-data-table.cell heading>Presence</x-data-table.cell>
                                    <x-data-table.cell heading>Citation</x-data-table.cell>
                                    <x-data-table.cell heading>Positive context</x-data-table.cell>
                                    <x-data-table.cell heading>Position</x-data-table.cell>
                                    <x-data-table.cell heading>Runs</x-data-table.cell>
                                </x-data-table.row>
                            </x-data-table.header>
                            <tbody>
                                @forelse ($trend as $row)
                                    <x-data-table.row>
                                        <x-data-table.cell label="Week" class="text-textPrimary">{{ optional(data_get($row, 'period_start'))->format('Y-m-d') }}</x-data-table.cell>
                                        <x-data-table.cell label="AI visibility">{{ is_numeric(data_get($row, 'ai_visibility_score')) ? number_format(((float) data_get($row, 'ai_visibility_score')) * 100, 1) : '-' }}</x-data-table.cell>
                                        <x-data-table.cell label="Presence">{{ is_numeric(data_get($row, 'presence_rate')) ? number_format(((float) data_get($row, 'presence_rate')) * 100, 1) . '%' : '-' }}</x-data-table.cell>
                                        <x-data-table.cell label="Citation">{{ is_numeric(data_get($row, 'citation_rate')) ? number_format(((float) data_get($row, 'citation_rate')) * 100, 1) . '%' : '-' }}</x-data-table.cell>
                                        <x-data-table.cell label="Positive context">{{ is_numeric(data_get($row, 'positive_context_rate')) ? number_format(((float) data_get($row, 'positive_context_rate')) * 100, 1) . '%' : '-' }}</x-data-table.cell>
                                        <x-data-table.cell label="Position">{{ is_numeric(data_get($row, 'average_position_score')) ? number_format(((float) data_get($row, 'average_position_score')) * 100, 1) : '-' }}</x-data-table.cell>
                                        <x-data-table.cell label="Runs">{{ (int) data_get($row, 'run_count', 0) }}</x-data-table.cell>
                                    </x-data-table.row>
                                @empty
                                    <x-data-table.empty colspan="7" title="No aggregate trend data yet" />
                                @endforelse
                            </tbody>
                    </x-data-table>
                </div>
            </div>
        </div>
    </div>
@endsection
