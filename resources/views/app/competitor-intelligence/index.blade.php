@extends('layouts.app', ['title' => 'Competitor Intelligence'])

@php
    $missingTopics = $topicSignals->where('coverage_status', 'missing');
    $weakTopics = $topicSignals->where('coverage_status', 'weak');
    $attackableAngles = $opportunities->whereNotNull('attackable_angle')->take(8);
@endphp

@section('pageHeader')
    <x-page-header title="Competitor Intelligence" />
@endsection

@section('pageDescription')
    <x-page-description>Normalize imported competitor content into topics, intents, coverage gaps, and attackable content opportunities.</x-page-description>
@endsection

@section('primaryActions')
    <a href="{{ route('app.sites.competitors.index', $site) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Manage competitors</a>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Competitors" :value="$competitors->count()" />
        <x-metric-card label="Imported pages" :value="$contentItems->count()" />
        <x-metric-card label="Missing topics" :value="$missingTopics->count()" />
        <x-metric-card label="Open opportunities" :value="$opportunities->count()" />
    </x-metric-section>
@endsection

@section('content')

    <div class="space-y-6">
        <x-app.insights-header
            :site="$site"
            title="Competitor Intelligence"
            description="Normalize imported competitor content into topics, intents, coverage gaps, and attackable content opportunities."
            active="competitor-intelligence"
            :show-heading="false"
        >
        </x-app.insights-header>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if ($errors->any())
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-lg border border-border bg-surface p-5 lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-textPrimary">Competitor overview</h2>
                        <p class="mt-1 text-xs text-textSecondary">Choose a competitor, import representative URLs/content, then run the internal analyzer.</p>
                    </div>
                    <form method="GET" action="{{ route('app.sites.competitor-intelligence.index', $site) }}">
                        <select name="competitor_id" class="pl-select bg-background" onchange="this.form.submit()">
                            @foreach ($competitors as $competitor)
                                <option value="{{ $competitor->id }}" @selected($selectedCompetitor && $competitor->id === $selectedCompetitor->id)>{{ $competitor->name }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>

                <x-data-table label="Competitor overview" description="Competitor domains, imported pages, topic signals, and generated opportunities." density="compact" class="mt-4 border-0 rounded-none">
                        <x-data-table.header>
                            <x-data-table.row>
                                <x-data-table.cell heading>Competitor</x-data-table.cell>
                                <x-data-table.cell heading>Domain</x-data-table.cell>
                                <x-data-table.cell heading>Pages</x-data-table.cell>
                                <x-data-table.cell heading>Topics</x-data-table.cell>
                                <x-data-table.cell heading>Opportunities</x-data-table.cell>
                            </x-data-table.row>
                        </x-data-table.header>
                        <tbody>
                            @forelse ($competitors as $competitor)
                                <x-data-table.row>
                                    <x-data-table.cell label="Competitor" class="text-textPrimary">{{ $competitor->name }}</x-data-table.cell>
                                    <x-data-table.cell label="Domain" class="text-textSecondary">{{ $competitor->domain }}</x-data-table.cell>
                                    <x-data-table.cell label="Pages" class="text-textPrimary">{{ $competitor->content_items_count }}</x-data-table.cell>
                                    <x-data-table.cell label="Topics" class="text-textPrimary">{{ $competitor->topic_signals_count }}</x-data-table.cell>
                                    <x-data-table.cell label="Opportunities" class="text-textPrimary">{{ $competitor->content_opportunities_count }}</x-data-table.cell>
                                </x-data-table.row>
                            @empty
                                <x-data-table.empty colspan="5" title="No competitors yet" description="Add competitors before importing intelligence." />
                            @endforelse
                        </tbody>
                </x-data-table>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Run analysis</h2>
                <form method="POST" action="{{ route('app.sites.competitor-intelligence.analyze', $site) }}" class="mt-4 space-y-3">
                    @csrf
                    <select name="site_competitor_id" class="pl-select w-full bg-background">
                        <option value="">All competitors</option>
                        @foreach ($competitors as $competitor)
                            <option value="{{ $competitor->id }}" @selected($selectedCompetitor && $competitor->id === $selectedCompetitor->id)>{{ $competitor->name }}</option>
                        @endforeach
                    </select>
                    <label class="flex items-center gap-2 text-xs text-textSecondary">
                        <input type="checkbox" name="run_inline" value="1">
                        Run immediately in this request
                    </label>
                    <button class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">Analyze</button>
                </form>

                <div class="mt-5">
                    <p class="text-xs font-medium text-textPrimary">Recent runs</p>
                    <div class="mt-2 space-y-2 text-xs text-textSecondary">
                        @forelse ($runs as $run)
                            <div class="rounded-md border border-border bg-background px-3 py-2">
                                <div class="flex justify-between gap-2">
                                    <span>{{ strtoupper($run->status) }}</span>
                                    <span>{{ optional($run->created_at)->format('Y-m-d H:i') }}</span>
                                </div>
                                <p class="mt-1">{{ $run->topics_count }} topics, {{ $run->opportunities_count }} opportunities</p>
                            </div>
                        @empty
                            <p>No runs yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-5">
            <h2 class="text-sm font-semibold text-textPrimary">Import competitor content</h2>
            <form method="POST" action="{{ route('app.sites.competitor-intelligence.import', $site) }}" class="mt-4 grid gap-4 lg:grid-cols-2">
                @csrf
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Competitor</label>
                        <select name="site_competitor_id" required class="pl-select w-full bg-background">
                            @foreach ($competitors as $competitor)
                                <option value="{{ $competitor->id }}" @selected($selectedCompetitor && $competitor->id === $selectedCompetitor->id)>{{ $competitor->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">URL</label>
                        <input name="url" value="{{ old('url') }}" class="w-full rounded border border-border bg-background px-2 py-2 text-sm" placeholder="https://competitor.com/use-cases/topic">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Title</label>
                        <input name="title" value="{{ old('title') }}" required maxlength="255" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Meta description</label>
                        <textarea name="meta_description" rows="2" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">{{ old('meta_description') }}</textarea>
                    </div>
                </div>
                <div class="space-y-3">
                    <div>
                        <label class="mb-1 block text-xs text-textSecondary">Content excerpt</label>
                        <textarea name="content_excerpt" rows="9" required class="w-full rounded border border-border bg-background px-2 py-2 text-sm">{{ old('content_excerpt') }}</textarea>
                    </div>
                    <button class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">Import and normalize</button>
                </div>
            </form>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Topic overlap</h2>
                <x-data-table label="Topic overlap" description="Detected competitor topics, coverage status, competitor page counts, and opportunity score." density="compact" class="mt-4 border-0 rounded-none">
                        <x-data-table.header>
                            <x-data-table.row>
                                <x-data-table.cell heading>Topic</x-data-table.cell>
                                <x-data-table.cell heading>Coverage</x-data-table.cell>
                                <x-data-table.cell heading>Competitor pages</x-data-table.cell>
                                <x-data-table.cell heading>Score</x-data-table.cell>
                            </x-data-table.row>
                        </x-data-table.header>
                        <tbody>
                            @forelse ($topicSignals as $signal)
                                <x-data-table.row>
                                    <x-data-table.cell label="Topic" class="text-textPrimary">{{ $signal->topic }}</x-data-table.cell>
                                    <x-data-table.cell label="Coverage" class="text-textSecondary">{{ str_replace('_', ' ', $signal->coverage_status) }}</x-data-table.cell>
                                    <x-data-table.cell label="Competitor pages" class="text-textPrimary">{{ $signal->competitor_content_count }}</x-data-table.cell>
                                    <x-data-table.cell label="Score" class="text-textPrimary">{{ number_format((float) $signal->opportunity_score, 1) }}</x-data-table.cell>
                                </x-data-table.row>
                            @empty
                                <x-data-table.empty colspan="4" title="No topic signals yet" />
                            @endforelse
                        </tbody>
                </x-data-table>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Missing and weak coverage</h2>
                <div class="mt-4 grid gap-4 md:grid-cols-2">
                    <div>
                        <p class="text-xs font-medium text-textPrimary">Missing topics</p>
                        <ul class="mt-2 space-y-2 text-xs text-textSecondary">
                            @forelse ($missingTopics->take(10) as $signal)
                                <li>{{ $signal->topic }} <span class="text-textPrimary">({{ number_format((float) $signal->opportunity_score, 0) }})</span></li>
                            @empty
                                <li>No missing topics detected.</li>
                            @endforelse
                        </ul>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-textPrimary">Weak coverage</p>
                        <ul class="mt-2 space-y-2 text-xs text-textSecondary">
                            @forelse ($weakTopics->take(10) as $signal)
                                <li>{{ $signal->topic }} <span class="text-textPrimary">({{ number_format((float) $signal->opportunity_score, 0) }})</span></li>
                            @empty
                                <li>No weak topics detected.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-6 xl:grid-cols-2">
            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Attackable angles</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($attackableAngles as $opportunity)
                        <div class="rounded-md border border-border bg-background p-3">
                            <p class="text-sm font-medium text-textPrimary">{{ $opportunity->title }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ $opportunity->attackable_angle }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-textSecondary">No attackable angles yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="rounded-lg border border-border bg-surface p-5">
                <h2 class="text-sm font-semibold text-textPrimary">Opportunity output</h2>
                <x-data-table label="Opportunity output" description="Generated competitor intelligence opportunities by type, title, and priority." density="compact" class="mt-4 border-0 rounded-none">
                        <x-data-table.header>
                            <x-data-table.row>
                                <x-data-table.cell heading>Type</x-data-table.cell>
                                <x-data-table.cell heading>Title</x-data-table.cell>
                                <x-data-table.cell heading>Priority</x-data-table.cell>
                            </x-data-table.row>
                        </x-data-table.header>
                        <tbody>
                            @forelse ($opportunities as $opportunity)
                                <x-data-table.row>
                                    <x-data-table.cell label="Type" class="text-textSecondary">{{ str_replace('_', ' ', $opportunity->type) }}</x-data-table.cell>
                                    <x-data-table.cell label="Title" class="text-textPrimary">{{ $opportunity->title }}</x-data-table.cell>
                                    <x-data-table.cell label="Priority" class="text-textPrimary">{{ number_format((float) $opportunity->priority_score, 1) }}</x-data-table.cell>
                                </x-data-table.row>
                            @empty
                                <x-data-table.empty colspan="3" title="No opportunities yet" description="Run analysis to generate comparison pages, BOFU pages, answer blocks, implementation guides, and use cases." />
                            @endforelse
                        </tbody>
                </x-data-table>
            </div>
        </div>
    </div>
@endsection
