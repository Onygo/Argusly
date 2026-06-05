@extends('layouts.app', ['title' => 'Share of AI Attention'])

@section('content')
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

    <div class="space-y-5">
        <x-app.insights-header
            :site="$site"
            title="Share of AI Attention"
            description="AI Visibility Score for tracked prompts, providers, citations, brand mentions, and competitor pressure."
            active="llm"
        >
            <a href="{{ route('app.insights.index') }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">All sites</a>
            <a href="{{ route('app.sites.show', $site) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Site setup</a>
        </x-app.insights-header>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>
        @endif

        <div class="grid grid-cols-1 gap-3 md:grid-cols-3 xl:grid-cols-7">
            <div class="rounded-lg border border-sky-200 bg-sky-50/70 p-5 md:col-span-3 xl:col-span-2">
                <p class="text-xs font-semibold uppercase tracking-widest text-sky-800">AI Visibility Score</p>
                <div class="mt-3 flex items-end justify-between gap-4">
                    <p class="text-4xl font-semibold leading-none text-textPrimary">{{ is_numeric(data_get($summary, 'ai_visibility_score')) ? number_format((float) data_get($summary, 'ai_visibility_score'), 1) : '-' }}</p>
                    <p class="max-w-40 text-right text-xs leading-5 text-sky-900/80">Average across latest filtered runs</p>
                </div>
            </div>
            @foreach ($summaryCards as $card)
                <div class="rounded-lg border border-border bg-surface p-4">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.12em] text-textMuted">{{ $card['label'] }}</p>
                    <p class="mt-2 text-2xl font-semibold leading-none text-textPrimary">{{ $card['value'] }}</p>
                    <p class="mt-2 text-xs text-textSecondary">{{ $card['helper'] }}</p>
                </div>
            @endforeach
        </div>

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

            <details class="self-start rounded-lg border border-border bg-surface p-5">
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

                <div class="max-h-96 overflow-auto border-t border-border/70">
                    <table class="min-w-full text-sm text-textPrimary">
                        <thead class="sticky top-0 z-10 bg-surface">
                            <tr class="text-left text-[11px] uppercase tracking-[0.08em] text-textSecondary">
                                <th class="px-4 py-3 font-medium">Query</th>
                                <th class="px-4 py-3 font-medium">Set</th>
                                <th class="px-4 py-3 font-medium">Cadence</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3 font-medium">Last run</th>
                                <th class="px-4 py-3 font-medium">AI visibility</th>
                                <th class="px-4 py-3 font-medium">Presence</th>
                                <th class="px-4 py-3 font-medium">Citation</th>
                                <th class="px-4 py-3 font-medium">Context</th>
                                <th class="px-4 py-3 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/70">
                            @forelse ($queries as $query)
                                @php($latestRun = $query->runs->first())
                                <tr class="align-top hover:bg-surfaceSubtle/60">
                                    <td class="px-4 py-3">
                                        <div class="font-medium">{{ $query->name }}</div>
                                        <div class="mt-1 max-w-md text-xs text-textSecondary">{{ \Illuminate\Support\Str::limit($query->query_text, 96) }}</div>
                                        <div class="mt-1 text-[11px] text-textSecondary">Target brand: {{ $query->target_brand ?: 'Not set' }} · Domain: {{ $query->target_domain ?: 'Not set' }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-xs">{{ $query->querySet?->name ?: 'Unassigned' }}</td>
                                    <td class="px-4 py-3 text-xs">{{ $frequencyLabel($query->frequency) }}</td>
                                    <td class="px-4 py-3 text-xs">
                                        <span class="inline-flex rounded-full px-2 py-1 {{ $query->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-surfaceSubtle text-textSecondary' }}">{{ $query->is_active ? 'Active' : 'Inactive' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs">{{ optional($latestRun?->run_at)->toDateTimeString() ?? 'Never' }}</td>
                                    <td class="px-4 py-3 text-xs font-semibold text-textPrimary">{{ is_numeric($latestRun?->ai_visibility_score) ? number_format(((float) $latestRun->ai_visibility_score) * 100, 1) : '-' }}</td>
                                    <td class="px-4 py-3 text-xs">{{ $latestRun ? ($latestRun->brand_mentioned ? 'PublishLayer present' : 'Missing') : 'No run yet' }}</td>
                                    <td class="px-4 py-3 text-xs">{{ $latestRun && (((float) ($latestRun->citation_score ?? 0)) > 0 || $latestRun->urls_cited) ? 'Present' : 'Missing' }}</td>
                                    <td class="px-4 py-3 text-xs">{{ $contextLabel($latestRun?->context_label ?? $latestRun?->sentiment_label) }}</td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap gap-2">
                                            <a href="{{ route('app.sites.llm-tracking.show', [$site, $query]) }}" class="rounded border border-border px-2 py-1 text-xs">Open</a>
                                            <form method="POST" action="{{ route('app.sites.llm-tracking.toggle', [$site, $query]) }}">@csrf<button class="rounded border border-border px-2 py-1 text-xs">{{ $query->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                                            <form method="POST" action="{{ route('app.sites.llm-tracking.run-now', [$site, $query]) }}">@csrf<button class="rounded border border-border px-2 py-1 text-xs">Run now</button></form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="py-3 text-textSecondary">No tracking queries configured yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
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
                <div class="max-h-96 overflow-auto border-t border-border/70">
                    <table class="min-w-full text-sm text-textPrimary">
                        <thead class="sticky top-0 z-10 bg-surface">
                            <tr class="text-left text-[11px] uppercase tracking-[0.08em] text-textSecondary">
                                <th class="px-4 py-3 font-medium">Query</th>
                                <th class="px-4 py-3 font-medium">Latest</th>
                                <th class="px-4 py-3 font-medium">Average</th>
                                <th class="px-4 py-3 font-medium">Presence</th>
                                <th class="px-4 py-3 font-medium">Citation</th>
                                <th class="px-4 py-3 font-medium">Top competitor</th>
                                <th class="px-4 py-3 font-medium">Trend</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/70">
                            @forelse ($queryPerformanceRows as $row)
                                <tr class="hover:bg-surfaceSubtle/60">
                                    <td class="px-4 py-3">{{ data_get($row, 'query.name') }}</td>
                                    <td class="px-4 py-3 font-semibold">{{ is_numeric(data_get($row, 'latest_score')) ? number_format((float) data_get($row, 'latest_score'), 1) : '-' }}</td>
                                    <td class="px-4 py-3">{{ is_numeric(data_get($row, 'average_score')) ? number_format((float) data_get($row, 'average_score'), 1) : '-' }}</td>
                                    <td class="px-4 py-3">{{ number_format((float) data_get($row, 'presence_percentage', 0), 1) }}%</td>
                                    <td class="px-4 py-3">{{ number_format((float) data_get($row, 'citation_percentage', 0), 1) }}%</td>
                                    <td class="px-4 py-3">{{ data_get($row, 'top_competitor', '-') ?: '-' }}</td>
                                    <td class="px-4 py-3">{{ is_numeric(data_get($row, 'trend')) ? number_format((float) data_get($row, 'trend'), 1) : '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-4 py-3 text-textSecondary">No query performance data yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface">
                <div class="p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Latest Answers</h2>
                </div>
                <div class="max-h-96 overflow-auto border-t border-border/70">
                    <table class="min-w-full text-sm text-textPrimary">
                        <thead class="sticky top-0 z-10 bg-surface">
                            <tr class="text-left text-[11px] uppercase tracking-[0.08em] text-textSecondary">
                                <th class="px-4 py-3 font-medium">Date</th>
                                <th class="px-4 py-3 font-medium">Provider</th>
                                <th class="px-4 py-3 font-medium">Model</th>
                                <th class="px-4 py-3 font-medium">Query</th>
                                <th class="px-4 py-3 font-medium">Score</th>
                                <th class="px-4 py-3 font-medium">Brand</th>
                                <th class="px-4 py-3 font-medium">Citation</th>
                                <th class="px-4 py-3 font-medium">Context</th>
                                <th class="px-4 py-3 font-medium">Detail</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/70">
                            @forelse ($latestResponseRows as $row)
                                @php($run = $row['run'])
                                @php($query = $row['query'])
                                <tr class="hover:bg-surfaceSubtle/60">
                                    <td class="px-4 py-3 text-xs">{{ optional($run->run_at)->toDateTimeString() }}</td>
                                    <td class="px-4 py-3 text-xs">{{ $run->provider ?: '-' }}</td>
                                    <td class="px-4 py-3 text-xs">{{ $run->model ?: '-' }}</td>
                                    <td class="px-4 py-3 text-xs">{{ $query->name }}</td>
                                    <td class="px-4 py-3 text-xs font-semibold">{{ is_numeric($row['score']) ? number_format((float) $row['score'], 1) : '-' }}</td>
                                    <td class="px-4 py-3 text-xs">{{ $row['brand_mentioned'] ? 'Yes' : 'No' }}</td>
                                    <td class="px-4 py-3 text-xs">{{ $row['citation_present'] ? 'Yes' : 'No' }}</td>
                                    <td class="px-4 py-3 text-xs">{{ $contextLabel($row['context_label']) }}</td>
                                    <td class="px-4 py-3"><a href="{{ route('app.sites.llm-tracking.show', [$site, $query]) }}" class="rounded border border-border px-2 py-1 text-xs">Open</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="px-4 py-3 text-textSecondary">No tracked answers yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-amber-300/40 bg-amber-50/70 p-6">
                <h2 class="text-sm font-semibold text-amber-950">Missing Visibility Opportunities</h2>
                <p class="mt-1 text-xs text-amber-900/80">Queries where PublishLayer did not appear in the latest tracked run.</p>

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
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-xs text-textSecondary">
                            <thead>
                                <tr class="text-left">
                                    <th class="pb-2 font-medium">Week</th>
                                    <th class="pb-2 font-medium">AI visibility</th>
                                    <th class="pb-2 font-medium">Presence</th>
                                    <th class="pb-2 font-medium">Citation</th>
                                    <th class="pb-2 font-medium">Positive context</th>
                                    <th class="pb-2 font-medium">Position</th>
                                    <th class="pb-2 font-medium">Runs</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($trend as $row)
                                    <tr class="border-t border-border/60">
                                        <td class="py-2 text-textPrimary">{{ optional(data_get($row, 'period_start'))->format('Y-m-d') }}</td>
                                        <td class="py-2">{{ is_numeric(data_get($row, 'ai_visibility_score')) ? number_format(((float) data_get($row, 'ai_visibility_score')) * 100, 1) : '-' }}</td>
                                        <td class="py-2">{{ is_numeric(data_get($row, 'presence_rate')) ? number_format(((float) data_get($row, 'presence_rate')) * 100, 1) . '%' : '-' }}</td>
                                        <td class="py-2">{{ is_numeric(data_get($row, 'citation_rate')) ? number_format(((float) data_get($row, 'citation_rate')) * 100, 1) . '%' : '-' }}</td>
                                        <td class="py-2">{{ is_numeric(data_get($row, 'positive_context_rate')) ? number_format(((float) data_get($row, 'positive_context_rate')) * 100, 1) . '%' : '-' }}</td>
                                        <td class="py-2">{{ is_numeric(data_get($row, 'average_position_score')) ? number_format(((float) data_get($row, 'average_position_score')) * 100, 1) : '-' }}</td>
                                        <td class="py-2">{{ (int) data_get($row, 'run_count', 0) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="py-3 text-textSecondary">No aggregate trend data yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
