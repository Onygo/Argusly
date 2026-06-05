@extends('layouts.app', ['title' => 'Tracking Query'])

@section('content')
    @php
        $querySets = $querySets ?? collect();
        $detail = $detail ?? [];
        $filters = $filters ?? [];
    @endphp

    <div class="space-y-6" data-llm-tracking-detail data-llm-tracking-active-tab="{{ $activeTab }}">
        <x-app.insights-header
            :site="$site"
            :title="$query->name"
            description="Visibility analysis for one tracked query. Use this page to understand outcome, drivers, competitors, sources, and next actions."
            active="llm"
        >
            <a href="{{ route('app.sites.llm-tracking.index', $site) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Back to dashboard</a>
        </x-app.insights-header>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>
        @endif

        <div class="sticky top-4 z-20 rounded-lg border border-border bg-surface/95 shadow-sm backdrop-blur">
            <div class="grid gap-6 px-6 py-5 lg:grid-cols-[minmax(0,1.25fr),minmax(320px,0.75fr)]">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <x-llm-tracking.status-badge
                            :label="data_get($detail, 'header.status.label', 'Draft')"
                            :tone="data_get($detail, 'header.status.tone', 'slate')"
                            :icon="data_get($detail, 'header.status.icon', 'sparkles')"
                        />
                        <span class="text-sm text-textSecondary">Latest run: {{ data_get($detail, 'header.latest_run_label', 'No runs yet') }}</span>
                        <span class="text-sm text-textSecondary">Provider: {{ data_get($detail, 'header.provider_label', 'No provider yet') }}</span>
                        <span class="text-sm text-textSecondary">Model: {{ data_get($detail, 'header.model_label', 'No model yet') }}</span>
                    </div>

                    <div>
                        <h1 class="text-3xl font-semibold tracking-tight text-textPrimary">{{ data_get($detail, 'header.title', $query->name) }}</h1>
                        <p class="mt-2 max-w-3xl text-sm leading-6 text-textSecondary">{{ data_get($detail, 'header.description', '') }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap items-start justify-start gap-2 lg:justify-end">
                    <form method="POST" action="{{ route('app.sites.llm-tracking.run-now', [$site, $query]) }}">
                        @csrf
                        <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Run opnieuw</button>
                    </form>
                    <a href="#query-settings" class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">Bewerk query</a>
                    <a href="{{ route('app.sites.llm-tracking.show', ['site' => $site, 'query' => $query, 'tab' => 'history']) }}#history-compare" data-llm-tracking-tab-link="history" class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">Vergelijk vorige run</a>
                    <a href="{{ route('app.sites.llm-tracking.show', ['site' => $site, 'query' => $query, 'tab' => 'history']) }}#history-table" data-llm-tracking-tab-link="history" class="inline-flex items-center justify-center rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">Bekijk historie</a>
                </div>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ((array) data_get($detail, 'summary_metrics', []) as $metric)
                <x-llm-tracking.metric-card
                    :label="$metric['label'] ?? ''"
                    :value="$metric['value'] ?? '-'"
                    :context="$metric['context'] ?? null"
                    :helper="$metric['helper'] ?? null"
                    :tone="$metric['tone'] ?? 'slate'"
                />
            @endforeach
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(280px,0.72fr),minmax(0,1.48fr)]">
            <aside class="space-y-6">
                <div id="query-settings" class="sticky top-[15rem] space-y-6">
                    <x-llm-tracking.analysis-card
                        title="Query context"
                        description="Compact query snapshot so settings stay visible without dominating the analysis."
                        icon="settings-2"
                    >
                        <p class="text-sm leading-6 text-textSecondary">{{ data_get($detail, 'query_context.summary', '') }}</p>

                        <div class="mt-4 grid gap-3">
                            @foreach ((array) data_get($detail, 'query_context.primary_fields', []) as $field)
                                <div class="rounded-lg border border-border bg-background px-4 py-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-textMuted">{{ $field['label'] ?? '' }}</p>
                                    <p class="mt-2 text-sm font-medium text-textPrimary">{{ $field['value'] ?? '' }}</p>
                                </div>
                            @endforeach
                        </div>

                        <details class="group mt-4 rounded-lg border border-border bg-background">
                            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3">
                                <span class="text-sm font-medium text-textPrimary">Toon meer query instellingen</span>
                                <i data-lucide="chevron-down" class="h-4 w-4 text-textMuted transition group-open:rotate-180"></i>
                            </summary>
                            <div class="space-y-4 border-t border-border px-4 py-4">
                                <div class="grid gap-3">
                                    @foreach ((array) data_get($detail, 'query_context.secondary_fields', []) as $field)
                                        <div>
                                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-textMuted">{{ $field['label'] ?? '' }}</p>
                                            <p class="mt-1 text-sm text-textSecondary">{{ $field['value'] ?? '' }}</p>
                                        </div>
                                    @endforeach
                                </div>

                                @foreach ((array) data_get($detail, 'query_context.lists', []) as $list)
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-textMuted">{{ $list['label'] ?? '' }}</p>
                                        @if (! empty($list['items']))
                                            <ul class="mt-2 space-y-2 text-sm text-textSecondary">
                                                @foreach ((array) $list['items'] as $item)
                                                    <li class="flex gap-2">
                                                        <span class="mt-2 h-1.5 w-1.5 shrink-0 rounded-full bg-primary/70"></span>
                                                        <span>{{ $item }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <p class="mt-1 text-sm text-textMuted">Nothing configured.</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    </x-llm-tracking.analysis-card>

                    <x-llm-tracking.analysis-card
                        title="Edit query"
                        description="Update targeting, competitors, locale, and cadence without leaving the analysis page."
                        icon="square-pen"
                    >
                        <form method="POST" action="{{ route('app.sites.llm-tracking.update', [$site, $query]) }}" class="space-y-4">
                            @csrf
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">Query set</label>
                                <select name="llm_tracking_query_set_id" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                    <option value="">No query set</option>
                                    @foreach ($querySets as $querySet)
                                        <option value="{{ $querySet->id }}" @selected(old('llm_tracking_query_set_id', $query->llm_tracking_query_set_id) == $querySet->id)>{{ $querySet->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">Name</label>
                                <input name="name" value="{{ old('name', $query->name) }}" required maxlength="120" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">Query text</label>
                                <textarea name="query_text" rows="4" required class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('query_text', $query->query_text) }}</textarea>
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs text-textSecondary">Target brand</label>
                                    <input name="target_brand" value="{{ old('target_brand', $query->target_brand) }}" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-textSecondary">Target domain</label>
                                    <input name="target_domain" value="{{ old('target_domain', $query->target_domain) }}" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                </div>
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-xs text-textSecondary">Brand aliases</label>
                                    <textarea name="brand_terms" rows="3" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('brand_terms', implode(PHP_EOL, (array) $query->brand_terms)) }}</textarea>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-textSecondary">Competitor terms</label>
                                    <textarea name="competitor_terms" rows="3" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('competitor_terms', implode(PHP_EOL, (array) $query->competitor_terms)) }}</textarea>
                                </div>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">Target URLs</label>
                                <textarea name="target_urls" rows="2" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('target_urls', implode(PHP_EOL, (array) $query->target_urls)) }}</textarea>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs text-textSecondary">Tags</label>
                                <textarea name="tags" rows="2" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">{{ old('tags', implode(PHP_EOL, (array) $query->tags)) }}</textarea>
                            </div>
                            <div class="grid gap-4 md:grid-cols-3">
                                <div>
                                    <label class="mb-1 block text-xs text-textSecondary">Locale</label>
                                    <input name="locale" value="{{ old('locale', $query->locale) }}" maxlength="16" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-textSecondary">Cadence</label>
                                    <select name="frequency" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                        <option value="daily" @selected(old('frequency', $query->frequency) === 'daily')>Daily</option>
                                        <option value="weekly" @selected(old('frequency', $query->frequency) === 'weekly')>Weekly</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs text-textSecondary">Priority</label>
                                    <input name="priority" type="number" min="1" max="100" value="{{ old('priority', $query->priority ?? 50) }}" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                </div>
                            </div>
                            <label class="inline-flex items-center gap-2 text-sm text-textPrimary">
                                <input type="checkbox" name="is_active" value="1" @checked($query->is_active)>
                                Active
                            </label>
                            <div class="flex flex-wrap gap-2">
                                <button class="rounded-md border border-border px-4 py-2 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle">Save query</button>
                            </div>
                        </form>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('app.sites.llm-tracking.toggle', [$site, $query]) }}">
                                @csrf
                                <button class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary transition hover:bg-surfaceSubtle">{{ $query->is_active ? 'Deactivate' : 'Activate' }}</button>
                            </form>
                            <form method="POST" action="{{ route('app.sites.llm-tracking.rescore', [$site, $query]) }}">
                                @csrf
                                <button class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary transition hover:bg-surfaceSubtle">Rescore stored runs</button>
                            </form>
                        </div>
                    </x-llm-tracking.analysis-card>
                </div>
            </aside>

            <main class="space-y-6">
                <div class="rounded-lg border border-border bg-surface p-2">
                    <div class="flex flex-wrap gap-2" role="tablist" aria-label="Tracking query analysis sections">
                        @foreach ((array) data_get($detail, 'tabs', []) as $tab)
                            @php($isActive = $activeTab === $tab['id'])
                            <a
                                href="{{ route('app.sites.llm-tracking.show', ['site' => $site, 'query' => $query, 'tab' => $tab['id']]) }}"
                                role="tab"
                                tabindex="{{ $isActive ? '0' : '-1' }}"
                                aria-selected="{{ $isActive ? 'true' : 'false' }}"
                                data-llm-tracking-tab-trigger="{{ $tab['id'] }}"
                                class="{{ $isActive ? 'bg-background text-textPrimary shadow-sm' : 'text-textSecondary hover:bg-background/70 hover:text-textPrimary' }} inline-flex items-center rounded-lg px-4 py-3 text-sm font-medium transition"
                            >
                                {{ $tab['label'] }}
                            </a>
                        @endforeach
                    </div>
                </div>

                <section data-llm-tracking-tab-panel="overview" aria-hidden="{{ $activeTab === 'overview' ? 'false' : 'true' }}" @class([$activeTab === 'overview' ? 'block' : 'hidden'])>
                    @include('app.sites.llm-tracking.partials.overview')
                </section>

                <section data-llm-tracking-tab-panel="competitors" aria-hidden="{{ $activeTab === 'competitors' ? 'false' : 'true' }}" @class([$activeTab === 'competitors' ? 'block' : 'hidden'])>
                    @include('app.sites.llm-tracking.partials.competitors')
                </section>

                <section data-llm-tracking-tab-panel="sources" aria-hidden="{{ $activeTab === 'sources' ? 'false' : 'true' }}" @class([$activeTab === 'sources' ? 'block' : 'hidden'])>
                    @include('app.sites.llm-tracking.partials.sources')
                </section>

                <section data-llm-tracking-tab-panel="findings" aria-hidden="{{ $activeTab === 'findings' ? 'false' : 'true' }}" @class([$activeTab === 'findings' ? 'block' : 'hidden'])>
                    @include('app.sites.llm-tracking.partials.findings')
                </section>

                <section id="history-compare" data-llm-tracking-tab-panel="history" aria-hidden="{{ $activeTab === 'history' ? 'false' : 'true' }}" @class([$activeTab === 'history' ? 'block' : 'hidden'])>
                    <div id="history-table">
                        @include('app.sites.llm-tracking.partials.history')
                    </div>
                </section>

                <section data-llm-tracking-tab-panel="raw" aria-hidden="{{ $activeTab === 'raw' ? 'false' : 'true' }}" @class([$activeTab === 'raw' ? 'block' : 'hidden'])>
                    @include('app.sites.llm-tracking.partials.raw')
                </section>
            </main>
        </div>
    </div>
@endsection
