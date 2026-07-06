@extends('layouts.app', ['title' => 'Page Intelligence'])

@php
    $tabs = [
        'market-packs' => ['label' => 'Market Packs', 'route' => route('app.page-intelligence.index', array_merge(request()->except('page'), ['tab' => 'market-packs']))],
        'pages' => ['label' => 'Monitored Pages', 'route' => route('app.page-intelligence.index', array_merge(request()->except('page'), ['tab' => 'pages']))],
        'competitors' => ['label' => 'Competitors', 'route' => route('app.page-intelligence.index', array_merge(request()->except('page'), ['tab' => 'competitors']))],
        'themes' => ['label' => 'Themes', 'route' => route('app.page-intelligence.index', array_merge(request()->except('page'), ['tab' => 'themes']))],
        'sources' => ['label' => 'Sources', 'route' => route('app.page-intelligence.index', array_merge(request()->except('page'), ['tab' => 'sources']))],
        'alerts' => ['label' => 'Page Alerts', 'route' => route('app.page-intelligence.index', array_merge(request()->except('page'), ['tab' => 'alerts']))],
        'pr-value' => ['label' => 'PR Value', 'route' => route('app.page-intelligence.index', array_merge(request()->except('page'), ['tab' => 'pr-value']))],
        'intelligence' => ['label' => 'Intelligence', 'route' => route('app.page-intelligence.index', array_merge(request()->except('page'), ['tab' => 'intelligence']))],
        'serp' => ['label' => 'SERP', 'route' => route('app.page-intelligence.index', array_merge(request()->except('page'), ['tab' => 'serp']))],
        'geo' => ['label' => 'GEO', 'route' => route('app.page-intelligence.index', array_merge(request()->except('page'), ['tab' => 'geo']))],
    ];
@endphp

@section('pageHeader')
    <x-page-header title="Page Intelligence">
        <x-slot:description>Monitor canonical external page assets, source discovery, alerts, PR value, search visibility and GEO citations.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <a href="{{ route('app.page-intelligence.scheduled-briefings.index', ['workspace' => $workspace->id]) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Scheduled Briefings</a>
    <a href="{{ route('app.page-intelligence.reports.index', ['workspace' => $workspace->id]) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Reports</a>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Monitored pages" :value="number_format($metrics['pages'])" icon="file-search" />
        <x-metric-card label="Sources" :value="number_format($metrics['sources'])" icon="rss" />
        <x-metric-card label="Open alerts" :value="number_format($metrics['openAlerts'])" icon="bell" tone="warning" />
        <x-metric-card label="Avg PR value" :value="number_format($metrics['avgPrValue'], 1)" icon="badge-euro" />
        <x-metric-card label="Avg Intelligence" :value="number_format($metrics['avgIntelligenceScore'], 1)" icon="brain-circuit" />
        <x-metric-card label="Avg SERP" :value="number_format($metrics['avgSerp'], 1)" icon="search" />
        <x-metric-card label="Avg GEO" :value="number_format($metrics['avgGeo'], 1)" icon="bot" />
    </x-metric-section>
@endsection

@section('content')
    <div class="space-y-6">
        <form method="GET" action="{{ route('app.page-intelligence.index') }}" class="rounded-lg border border-border bg-surface p-4">
            <input type="hidden" name="tab" value="{{ $activeTab }}">
            <div class="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                <label class="block">
                    <span class="text-xs text-textSecondary">Workspace</span>
                    <select name="workspace" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        @foreach ($workspaces as $option)
                            <option value="{{ $option->id }}" @selected((string) $option->id === (string) $workspace->id)>{{ $option->display_name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs text-textSecondary">Source type</span>
                    <select name="source_type" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        <option value="">Any</option>
                        @foreach ($filterOptions['sourceTypes'] as $sourceType)
                            <option value="{{ $sourceType }}" @selected($filters['source_type'] === $sourceType)>{{ str($sourceType)->headline() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs text-textSecondary">Domain</span>
                    <select name="domain" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        <option value="">Any</option>
                        @foreach ($filterOptions['domains'] as $domain)
                            <option value="{{ $domain }}" @selected($filters['domain'] === $domain)>{{ $domain }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs text-textSecondary">Market pack</span>
                    <select name="market_pack" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        <option value="">Any</option>
                        @foreach ($filterOptions['marketPacks'] as $pack)
                            <option value="{{ $pack->key }}" @selected($filters['market_pack'] === $pack->key)>{{ $pack->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs text-textSecondary">Sentiment</span>
                    <select name="sentiment" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        <option value="">Any</option>
                        @foreach ($filterOptions['sentiments'] as $sentiment)
                            <option value="{{ $sentiment }}" @selected($filters['sentiment'] === $sentiment)>{{ str($sentiment)->headline() }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs text-textSecondary">Competitor</span>
                    <select name="competitor" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        <option value="">Any</option>
                        @foreach ($filterOptions['competitors'] as $competitor)
                            <option value="{{ $competitor->id }}" @selected($filters['competitor'] === (string) $competitor->id)>{{ $competitor->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs text-textSecondary">Campaign</span>
                    <select name="campaign" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                        <option value="">Any</option>
                        @foreach ($filterOptions['campaigns'] as $campaign)
                            <option value="{{ $campaign->id }}" @selected($filters['campaign'] === (string) $campaign->id)>{{ $campaign->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="block">
                    <span class="text-xs text-textSecondary">PR value min</span>
                    <input name="pr_value" type="number" min="0" step="1" value="{{ $filters['pr_value'] }}" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-xs text-textSecondary">SERP min</span>
                    <input name="serp_score" type="number" min="0" step="1" value="{{ $filters['serp_score'] }}" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-xs text-textSecondary">GEO min</span>
                    <input name="geo_score" type="number" min="0" step="1" value="{{ $filters['geo_score'] }}" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-xs text-textSecondary">From</span>
                    <input name="date_from" type="date" value="{{ $filters['date_from'] }}" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                </label>
                <label class="block">
                    <span class="text-xs text-textSecondary">To</span>
                    <input name="date_to" type="date" value="{{ $filters['date_to'] }}" class="mt-1 w-full rounded border border-border bg-background px-2 py-2 text-sm">
                </label>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <button class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Apply filters</button>
                <a href="{{ route('app.page-intelligence.index', ['workspace' => $workspace->id, 'tab' => $activeTab]) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textSecondary hover:bg-surfaceSubtle">Reset</a>
            </div>
        </form>

        <nav class="flex flex-wrap gap-2" aria-label="Page Intelligence sections">
            @foreach ($tabs as $key => $tab)
                <a href="{{ $tab['route'] }}" class="rounded-md border px-3 py-2 text-sm {{ $activeTab === $key ? 'border-textPrimary bg-textPrimary text-white' : 'border-border text-textPrimary hover:bg-surfaceSubtle' }}">
                    {{ $tab['label'] }}
                </a>
            @endforeach
        </nav>

        <section class="rounded-lg border border-border bg-surface">
            <div class="border-b border-border px-4 py-3">
                <h2 class="text-sm font-semibold text-textPrimary">Latest Signals</h2>
            </div>
            <div class="divide-y divide-border">
                @forelse ($latestSignals as $signal)
                    <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-textPrimary">{{ $signal['title'] }}</p>
                            <p class="text-xs text-textSecondary">{{ $signal['type'] }} · {{ $signal['detail'] }}</p>
                        </div>
                        <span class="text-xs text-textSecondary">{{ $signal['time']?->diffForHumans() ?: '-' }}</span>
                    </div>
                @empty
                    <p class="px-4 py-4 text-sm text-textSecondary">No market pack signals yet.</p>
                @endforelse
            </div>
        </section>

        @php
            $showMarketPacks = $activeTab === 'market-packs';
            $showPages = $activeTab === 'pages';
            $showCompetitors = $activeTab === 'competitors';
            $showThemes = $activeTab === 'themes';
            $showSources = $activeTab === 'sources';
            $showAlerts = $activeTab === 'alerts';
            $showPrValue = $activeTab === 'pr-value';
            $showIntelligence = $activeTab === 'intelligence';
            $showSerp = $activeTab === 'serp';
            $showGeo = $activeTab === 'geo';
        @endphp

        @if ($showMarketPacks)
            <x-data-table label="Market pack overview" description="Installed operational packs with sources, competitors, themes, alert rules and matched pages." density="compact">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Pack</x-data-table.cell>
                        <x-data-table.cell heading>Category</x-data-table.cell>
                        <x-data-table.cell heading>Sources</x-data-table.cell>
                        <x-data-table.cell heading>Competitors</x-data-table.cell>
                        <x-data-table.cell heading>Themes</x-data-table.cell>
                        <x-data-table.cell heading>Alert rules</x-data-table.cell>
                        <x-data-table.cell heading>Matched pages</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                    @forelse ($marketPacks as $row)
                        @php($pack = $row['pack'])
                        <x-data-table.row>
                            <x-data-table.cell label="Pack">
                                <p class="font-medium text-textPrimary">{{ $pack?->name }}</p>
                                <p class="text-xs text-textSecondary">{{ $pack?->description }}</p>
                            </x-data-table.cell>
                            <x-data-table.cell label="Category">{{ str($pack?->market_category)->headline() }}</x-data-table.cell>
                            <x-data-table.cell label="Sources">{{ $row['sources_count'] }}</x-data-table.cell>
                            <x-data-table.cell label="Competitors">{{ $pack?->competitors->count() ?? 0 }}</x-data-table.cell>
                            <x-data-table.cell label="Themes">{{ $pack?->themes->count() ?? 0 }}</x-data-table.cell>
                            <x-data-table.cell label="Alert rules">{{ $row['alert_rules_count'] }}</x-data-table.cell>
                            <x-data-table.cell label="Matched pages">{{ $row['matched_pages_count'] }}</x-data-table.cell>
                        </x-data-table.row>
                    @empty
                        <x-data-table.empty colspan="7" title="No market packs installed" description="Install a market pack to start market-specific monitoring." />
                    @endforelse
                </tbody>
            </x-data-table>
        @endif

        @includeWhen($showPages, 'app.page-intelligence.partials.monitored-pages-table')

        @if ($showCompetitors)
            <x-data-table label="Market pack competitors" description="Competitor definitions supplied by installed market packs and available to Page Intelligence matching." density="compact">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Competitor</x-data-table.cell>
                        <x-data-table.cell heading>Market pack</x-data-table.cell>
                        <x-data-table.cell heading>Domain</x-data-table.cell>
                        <x-data-table.cell heading>Aliases</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                    @forelse ($packCompetitors as $row)
                        <x-data-table.row>
                            <x-data-table.cell label="Competitor"><p class="font-medium text-textPrimary">{{ $row['competitor']->name }}</p></x-data-table.cell>
                            <x-data-table.cell label="Market pack">{{ $row['pack']->name }}</x-data-table.cell>
                            <x-data-table.cell label="Domain">{{ $row['competitor']->domain ?: '-' }}</x-data-table.cell>
                            <x-data-table.cell label="Aliases">{{ $row['aliases'] ?: '-' }}</x-data-table.cell>
                        </x-data-table.row>
                    @empty
                        <x-data-table.empty colspan="4" title="No pack competitors yet" />
                    @endforelse
                </tbody>
            </x-data-table>
        @endif

        @if ($showThemes)
            <x-data-table label="Market pack themes" description="Pack themes and keywords used for PageTopic and market pack matching." density="compact">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Theme</x-data-table.cell>
                        <x-data-table.cell heading>Market pack</x-data-table.cell>
                        <x-data-table.cell heading>Weight</x-data-table.cell>
                        <x-data-table.cell heading>Keywords</x-data-table.cell>
                        <x-data-table.cell heading>Classified pages</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                    @forelse ($packThemes as $row)
                        <x-data-table.row>
                            <x-data-table.cell label="Theme"><p class="font-medium text-textPrimary">{{ $row['theme']->name }}</p></x-data-table.cell>
                            <x-data-table.cell label="Market pack">{{ $row['pack']->name }}</x-data-table.cell>
                            <x-data-table.cell label="Weight">{{ number_format((float) $row['theme']->weight, 2) }}</x-data-table.cell>
                            <x-data-table.cell label="Keywords">{{ $row['keywords']->pluck('keyword')->take(4)->implode(', ') ?: '-' }}</x-data-table.cell>
                            <x-data-table.cell label="Classified pages">{{ $row['classified_pages_count'] }}</x-data-table.cell>
                        </x-data-table.row>
                    @empty
                        <x-data-table.empty colspan="5" title="No pack themes yet" />
                    @endforelse
                </tbody>
            </x-data-table>
        @endif

        @if ($showSources)
            <x-data-table label="Monitored sources" description="Workspace sources feeding Page Intelligence discovery." density="compact">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Source</x-data-table.cell>
                        <x-data-table.cell heading>Type</x-data-table.cell>
                        <x-data-table.cell heading>Status</x-data-table.cell>
                        <x-data-table.cell heading>Pages</x-data-table.cell>
                        <x-data-table.cell heading>Last run</x-data-table.cell>
                        <x-data-table.cell heading>Diagnostics</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                    @forelse ($sources as $source)
                        <x-data-table.row>
                            <x-data-table.cell label="Source">
                                <p class="font-medium text-textPrimary">{{ $source->name }}</p>
                                <p class="break-all text-xs text-textSecondary">{{ $source->base_url ?: $source->domain }}</p>
                            </x-data-table.cell>
                            <x-data-table.cell label="Type">{{ str($source->source_type)->headline() }}</x-data-table.cell>
                            <x-data-table.cell label="Status"><x-data-table.badge :tone="$source->status === 'failed' ? 'danger' : 'neutral'" :label="str($source->status)->headline()" /></x-data-table.cell>
                            <x-data-table.cell label="Pages">{{ $source->pages_count }}</x-data-table.cell>
                            <x-data-table.cell label="Last run">{{ $source->last_discovered_at?->diffForHumans() ?: '-' }}</x-data-table.cell>
                            <x-data-table.cell label="Diagnostics">{{ $source->last_error ?: 'Healthy' }}</x-data-table.cell>
                        </x-data-table.row>
                    @empty
                        <x-data-table.empty colspan="6" title="No monitored sources yet" />
                    @endforelse
                </tbody>
            </x-data-table>
        @endif

        @if ($showAlerts)
            <x-data-table label="Page alerts" description="Alerts created from Page Intelligence rules and findings." density="compact">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Alert</x-data-table.cell>
                        <x-data-table.cell heading>Page</x-data-table.cell>
                        <x-data-table.cell heading>Severity</x-data-table.cell>
                        <x-data-table.cell heading>Status</x-data-table.cell>
                        <x-data-table.cell heading>Recommended action</x-data-table.cell>
                        <x-data-table.cell heading>Fired</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                    @forelse ($alerts as $alert)
                        <x-data-table.row>
                            <x-data-table.cell label="Alert"><p class="font-medium text-textPrimary">{{ $alert->title }}</p><p class="text-xs text-textSecondary">{{ $alert->summary }}</p></x-data-table.cell>
                            <x-data-table.cell label="Page">{{ $alert->page?->title_current ?: $alert->page?->domain ?: '-' }}</x-data-table.cell>
                            <x-data-table.cell label="Severity"><x-data-table.badge :tone="$alert->severity === 'high' ? 'danger' : ($alert->severity === 'medium' ? 'warning' : 'neutral')" :label="str($alert->severity)->headline()" /></x-data-table.cell>
                            <x-data-table.cell label="Status">{{ str($alert->status)->headline() }}</x-data-table.cell>
                            <x-data-table.cell label="Recommended action">{{ $alert->recommendedAction?->title ?: '-' }}</x-data-table.cell>
                            <x-data-table.cell label="Fired">{{ $alert->fired_at?->diffForHumans() ?: '-' }}</x-data-table.cell>
                        </x-data-table.row>
                    @empty
                        <x-data-table.empty colspan="6" title="No page alerts yet" />
                    @endforelse
                </tbody>
            </x-data-table>
        @endif

        @if ($showPrValue)
            <x-data-table label="PR value overview" description="Explainable PR value model outputs for monitored pages." density="compact">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Page</x-data-table.cell>
                        <x-data-table.cell heading>Model</x-data-table.cell>
                        <x-data-table.cell heading>Score</x-data-table.cell>
                        <x-data-table.cell heading>Estimated value</x-data-table.cell>
                        <x-data-table.cell heading>Breakdown</x-data-table.cell>
                        <x-data-table.cell heading>Calculated</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                    @forelse ($prValues as $value)
                        <x-data-table.row>
                            <x-data-table.cell label="Page">{{ $value->page?->title_current ?: $value->page?->domain ?: '-' }}</x-data-table.cell>
                            <x-data-table.cell label="Model">{{ $value->model_key }} v{{ $value->model_version }}</x-data-table.cell>
                            <x-data-table.cell label="Score">{{ number_format((float) $value->score, 1) }}</x-data-table.cell>
                            <x-data-table.cell label="Estimated value">{{ $value->currency }} {{ number_format((float) $value->estimated_value_amount, 0) }}</x-data-table.cell>
                            <x-data-table.cell label="Breakdown">{{ collect((array) $value->breakdown_json)->take(3)->keys()->map(fn ($key) => str($key)->headline())->implode(', ') ?: '-' }}</x-data-table.cell>
                            <x-data-table.cell label="Calculated">{{ $value->calculated_at?->diffForHumans() ?: '-' }}</x-data-table.cell>
                        </x-data-table.row>
                    @empty
                        <x-data-table.empty colspan="6" title="No PR value records yet" />
                    @endforelse
                </tbody>
            </x-data-table>
        @endif

        @if ($showIntelligence)
            <div class="grid gap-6 xl:grid-cols-2">
                <x-data-table label="Top opportunities by score" description="Highest Argusly Intelligence Scores across monitored pages." density="compact">
                    <x-data-table.header>
                        <x-data-table.row>
                            <x-data-table.cell heading>Page</x-data-table.cell>
                            <x-data-table.cell heading>Adjusted score</x-data-table.cell>
                            <x-data-table.cell heading>Confidence</x-data-table.cell>
                            <x-data-table.cell heading>Missing inputs</x-data-table.cell>
                        </x-data-table.row>
                    </x-data-table.header>
                    <tbody>
                        @forelse ($topOpportunities as $score)
                            <x-data-table.row>
                                <x-data-table.cell label="Page"><p class="font-medium text-textPrimary">{{ $score->page?->title_current ?: $score->page?->domain ?: '-' }}</p><p class="break-all text-xs text-textSecondary">{{ $score->page?->canonical_url }}</p></x-data-table.cell>
                                <x-data-table.cell label="Adjusted score">
                                    {{ number_format((float) data_get($score->metadata_json, 'confidence_adjusted_score', $score->score), 1) }}
                                    <p class="text-xs text-textSecondary">Raw {{ number_format((float) data_get($score->metadata_json, 'raw_score', $score->score), 1) }}</p>
                                </x-data-table.cell>
                                <x-data-table.cell label="Confidence">{{ number_format((float) data_get($score->metadata_json, 'confidence', 0), 1) }}%</x-data-table.cell>
                                <x-data-table.cell label="Missing inputs">{{ collect((array) data_get($score->metadata_json, 'missing_inputs', []))->map(fn ($input) => str($input)->headline())->implode(', ') ?: 'None' }}</x-data-table.cell>
                            </x-data-table.row>
                        @empty
                            <x-data-table.empty colspan="4" title="No Intelligence Scores yet" />
                        @endforelse
                    </tbody>
                </x-data-table>

                <x-data-table label="Risk pages" description="Negative sentiment on high-authority sources." density="compact">
                    <x-data-table.header>
                        <x-data-table.row>
                            <x-data-table.cell heading>Page</x-data-table.cell>
                            <x-data-table.cell heading>Sentiment</x-data-table.cell>
                            <x-data-table.cell heading>Authority</x-data-table.cell>
                            <x-data-table.cell heading>Risk</x-data-table.cell>
                        </x-data-table.row>
                    </x-data-table.header>
                    <tbody>
                        @forelse ($riskPages as $row)
                            <x-data-table.row>
                                <x-data-table.cell label="Page"><p class="font-medium text-textPrimary">{{ $row['page']?->title_current ?: $row['page']?->domain ?: '-' }}</p></x-data-table.cell>
                                <x-data-table.cell label="Sentiment"><x-data-table.badge tone="danger" :label="str($row['sentiment']->label)->headline()" /></x-data-table.cell>
                                <x-data-table.cell label="Authority">{{ number_format((float) $row['source_authority'], 1) }}</x-data-table.cell>
                                <x-data-table.cell label="Risk">{{ number_format((float) $row['risk_score'], 1) }}</x-data-table.cell>
                            </x-data-table.row>
                        @empty
                            <x-data-table.empty colspan="4" title="No high-risk negative pages" />
                        @endforelse
                    </tbody>
                </x-data-table>
            </div>

            <x-data-table label="Competitor pressure overview" description="Pages where competitor evidence contributes to the Intelligence Score." density="compact">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Page</x-data-table.cell>
                        <x-data-table.cell heading>Competitor pressure</x-data-table.cell>
                        <x-data-table.cell heading>Mentions</x-data-table.cell>
                        <x-data-table.cell heading>Total score</x-data-table.cell>
                        <x-data-table.cell heading>Computed</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                    @forelse ($competitorPressure as $row)
                        <x-data-table.row>
                            <x-data-table.cell label="Page"><p class="font-medium text-textPrimary">{{ $row['page']?->title_current ?: $row['page']?->domain ?: '-' }}</p><p class="break-all text-xs text-textSecondary">{{ $row['page']?->canonical_url }}</p></x-data-table.cell>
                            <x-data-table.cell label="Competitor pressure">{{ number_format((float) $row['competitor_pressure'], 1) }}</x-data-table.cell>
                            <x-data-table.cell label="Mentions">{{ $row['competitor_mentions'] }}</x-data-table.cell>
                            <x-data-table.cell label="Total score">{{ number_format((float) $row['score']->score, 1) }}</x-data-table.cell>
                            <x-data-table.cell label="Computed">{{ $row['score']->computed_at?->diffForHumans() ?: '-' }}</x-data-table.cell>
                        </x-data-table.row>
                    @empty
                        <x-data-table.empty colspan="5" title="No competitor pressure signals yet" />
                    @endforelse
                </tbody>
            </x-data-table>
        @endif

        @if ($showSerp)
            <div class="grid gap-6 xl:grid-cols-2">
                <x-data-table label="SERP query sets" description="Durable query groups for imported or provider-backed search visibility tracking." density="compact">
                    <x-data-table.header>
                        <x-data-table.row>
                            <x-data-table.cell heading>Query set</x-data-table.cell>
                            <x-data-table.cell heading>Scope</x-data-table.cell>
                            <x-data-table.cell heading>Queries</x-data-table.cell>
                            <x-data-table.cell heading>Avg score</x-data-table.cell>
                            <x-data-table.cell heading>Observed</x-data-table.cell>
                        </x-data-table.row>
                    </x-data-table.header>
                    <tbody>
                        @forelse ($serpQuerySets as $row)
                            @php($querySet = $row['query_set'])
                            <x-data-table.row>
                                <x-data-table.cell label="Query set">
                                    <a href="{{ route('app.page-intelligence.index', array_merge(request()->except('page'), ['tab' => 'serp', 'serp_query_set' => $querySet->id])) }}" class="font-medium text-textPrimary hover:underline">{{ $querySet->name }}</a>
                                    <p class="text-xs text-textSecondary">{{ str($querySet->provider_key)->headline() }} · {{ str($querySet->status)->headline() }}</p>
                                </x-data-table.cell>
                                <x-data-table.cell label="Scope">{{ strtoupper((string) $querySet->country) ?: '-' }} · {{ $querySet->device }}</x-data-table.cell>
                                <x-data-table.cell label="Queries">{{ $row['queries_count'] }}</x-data-table.cell>
                                <x-data-table.cell label="Avg score">{{ $row['avg_visibility_score'] === null ? '-' : number_format((float) $row['avg_visibility_score'], 1) }}</x-data-table.cell>
                                <x-data-table.cell label="Observed">{{ $row['last_observed_at'] ? \Illuminate\Support\Carbon::parse($row['last_observed_at'])->diffForHumans() : '-' }}</x-data-table.cell>
                            </x-data-table.row>
                        @empty
                            <x-data-table.empty colspan="5" title="No SERP query sets yet" description="Import a manual query set to start tracking search visibility." />
                        @endforelse
                    </tbody>
                </x-data-table>

                <x-data-table label="Query ranking history" description="{{ $selectedSerpQuerySet ? $selectedSerpQuerySet->name : 'Select a query set to inspect query-level ranking history.' }}" density="compact">
                    <x-data-table.header>
                        <x-data-table.row>
                            <x-data-table.cell heading>Query</x-data-table.cell>
                            <x-data-table.cell heading>Latest page</x-data-table.cell>
                            <x-data-table.cell heading>Latest</x-data-table.cell>
                            <x-data-table.cell heading>Best</x-data-table.cell>
                            <x-data-table.cell heading>History</x-data-table.cell>
                        </x-data-table.row>
                    </x-data-table.header>
                    <tbody>
                        @forelse ($serpQueryHistory as $row)
                            @php($latest = $row['latest'])
                            <x-data-table.row>
                                <x-data-table.cell label="Query"><p class="font-medium text-textPrimary">{{ $row['query']->query }}</p><p class="text-xs text-textSecondary">{{ $row['query']->search_engine }} · {{ $row['query']->keyword_intent ?: 'intent unknown' }}</p></x-data-table.cell>
                                <x-data-table.cell label="Latest page">{{ $latest?->page?->title_current ?: $latest?->domain ?: '-' }}</x-data-table.cell>
                                <x-data-table.cell label="Latest">{{ $latest?->absolute_position ? '#'.$latest->absolute_position : '-' }}</x-data-table.cell>
                                <x-data-table.cell label="Best">{{ $row['best_position'] ? '#'.$row['best_position'] : '-' }}</x-data-table.cell>
                                <x-data-table.cell label="History">{{ $row['observations']->map(fn ($observation) => '#'.($observation->absolute_position ?: '-'))->implode(' -> ') ?: '-' }}</x-data-table.cell>
                            </x-data-table.row>
                        @empty
                            <x-data-table.empty colspan="5" title="No query history selected" />
                        @endforelse
                    </tbody>
                </x-data-table>
            </div>

            <x-data-table label="SERP observations" description="Search result visibility observations linked to monitored pages." density="compact">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Query</x-data-table.cell>
                        <x-data-table.cell heading>Set</x-data-table.cell>
                        <x-data-table.cell heading>Page</x-data-table.cell>
                        <x-data-table.cell heading>Engine</x-data-table.cell>
                        <x-data-table.cell heading>Position</x-data-table.cell>
                        <x-data-table.cell heading>Score</x-data-table.cell>
                        <x-data-table.cell heading>Observed</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                    @forelse ($serpObservations as $observation)
                        <x-data-table.row>
                            <x-data-table.cell label="Query"><p class="font-medium text-textPrimary">{{ $observation->query }}</p><p class="text-xs text-textSecondary">{{ $observation->country }} · {{ $observation->device }}</p></x-data-table.cell>
                            <x-data-table.cell label="Set">{{ $observation->querySet?->name ?: '-' }}</x-data-table.cell>
                            <x-data-table.cell label="Page">{{ $observation->page?->title_current ?: $observation->domain }}</x-data-table.cell>
                            <x-data-table.cell label="Engine">{{ str($observation->search_engine)->headline() }}</x-data-table.cell>
                            <x-data-table.cell label="Position">{{ $observation->position ?: '-' }}</x-data-table.cell>
                            <x-data-table.cell label="Score">{{ number_format((float) $observation->visibility_score, 1) }}</x-data-table.cell>
                            <x-data-table.cell label="Observed">{{ $observation->observed_at?->diffForHumans() ?: '-' }}</x-data-table.cell>
                        </x-data-table.row>
                    @empty
                        <x-data-table.empty colspan="7" title="No SERP observations yet" />
                    @endforelse
                </tbody>
            </x-data-table>
        @endif

        @if ($showGeo)
            <x-data-table label="GEO observations" description="Answer engine citations and visibility observations linked to monitored pages." density="compact">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Query</x-data-table.cell>
                        <x-data-table.cell heading>Page</x-data-table.cell>
                        <x-data-table.cell heading>Engine</x-data-table.cell>
                        <x-data-table.cell heading>Citation</x-data-table.cell>
                        <x-data-table.cell heading>Score</x-data-table.cell>
                        <x-data-table.cell heading>Observed</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                    @forelse ($geoObservations as $observation)
                        <x-data-table.row>
                            <x-data-table.cell label="Query"><p class="font-medium text-textPrimary">{{ $observation->query }}</p><p class="text-xs text-textSecondary">{{ $observation->provider }} · {{ $observation->model }}</p></x-data-table.cell>
                            <x-data-table.cell label="Page">{{ $observation->page?->title_current ?: $observation->cited_domain }}</x-data-table.cell>
                            <x-data-table.cell label="Engine">{{ str($observation->answer_engine)->headline() }}</x-data-table.cell>
                            <x-data-table.cell label="Citation">
                                <p>{{ $observation->client_cited ? 'Client cited' : ($observation->competitors_cited ? 'Competitor cited' : 'Observed') }}</p>
                                <p class="text-xs text-textSecondary">
                                    {{ collect($observation->mentioned_brands_json ?? [])->pluck('term')->filter()->take(2)->implode(', ') ?: 'No brand' }}
                                    ·
                                    {{ collect($observation->mentioned_competitors_json ?? [])->pluck('term')->filter()->take(2)->implode(', ') ?: 'No competitor' }}
                                </p>
                            </x-data-table.cell>
                            <x-data-table.cell label="Score">{{ number_format((float) $observation->geo_visibility_score, 1) }}</x-data-table.cell>
                            <x-data-table.cell label="Observed">{{ $observation->observed_at?->diffForHumans() ?: '-' }}</x-data-table.cell>
                        </x-data-table.row>
                    @empty
                        <x-data-table.empty colspan="6" title="No GEO observations yet" />
                    @endforelse
                </tbody>
            </x-data-table>
        @endif
    </div>
@endsection

@section('detailDrawer')
    <x-drawer.drawer
        :open="(bool) $selectedDrawer"
        :drawer="$selectedDrawer ? array_replace_recursive($selectedDrawer, [
            'state' => [
                'mode' => 'inspect',
                'open' => true,
                'loading' => false,
                'empty' => false,
                'error' => false,
                'message' => null,
                'interactive' => true,
                'can_edit' => false,
            ],
        ]) : [
            'key' => 'monitored-page.inspect',
            'mode' => 'inspect',
            'modal' => false,
            'width' => 'xl',
            'title' => 'Page inspection',
            'subtitle' => 'Monitored page',
            'description' => 'Select Inspect on a monitored page row to inspect page evidence.',
            'tabs' => [],
            'sections' => [],
            'footer_actions' => [],
            'empty_state' => [
                'title' => 'No monitored page selected',
                'description' => 'Choose a page from the table to inspect extraction, sentiment, PR value, SERP, GEO, and related evidence.',
            ],
            'state' => [
                'mode' => 'inspect',
                'open' => false,
                'loading' => false,
                'empty' => true,
                'error' => false,
                'message' => null,
                'interactive' => false,
                'can_edit' => false,
            ],
        ]"
    />
@endsection
