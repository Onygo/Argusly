@extends('layouts.app', ['title' => 'Campaign Cluster Planning'])

@section('pageHeader')
    <x-page-header title="Campaign Cluster Planning">
        <x-slot:description>Plan strategic content ecosystems with authority maps, funnel coverage, internal linking, localization, and campaign timelines.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-2xl font-semibold tracking-tight text-textPrimary">Campaign Cluster Planning</h2>
                <p class="mt-1 max-w-3xl text-textSecondary">Plan strategic content ecosystems with authority maps, funnel coverage, internal linking, localization, and campaign timelines.</p>
            </div>
            <form method="POST" action="{{ route('app.agentic-marketing.campaign-clusters.run') }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <input type="hidden" name="workspace_id" value="{{ $workspace->id }}">
                @if ($siteId)
                    <input type="hidden" name="client_site_id" value="{{ $siteId }}">
                @endif
                <label class="inline-flex items-center gap-2 text-xs text-textSecondary">
                    <input type="checkbox" name="run_inline" value="1">
                    Run now
                </label>
                <button class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">Generate clusters</button>
            </form>
        </div>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        <div class="grid gap-4 md:grid-cols-4">
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Clusters</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ $summary['total'] }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Authority</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((float) $summary['avg_authority'], 1) }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Coverage</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((float) $summary['avg_coverage'], 1) }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Completeness</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((float) $summary['avg_completeness'], 1) }}</p>
            </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-4">
            <form method="GET" action="{{ route('app.agentic-marketing.campaign-clusters.index') }}" class="grid gap-3 md:grid-cols-4">
                <select name="workspace_id" class="pl-select bg-background" onchange="this.form.submit()">
                    @foreach ($workspaces as $item)
                        <option value="{{ $item->id }}" @selected((string) $item->id === (string) $workspace->id)>{{ $item->display_name }}</option>
                    @endforeach
                </select>
                <select name="client_site_id" class="pl-select bg-background">
                    <option value="">All sites</option>
                    @foreach ($workspace->clientSites as $site)
                        <option value="{{ $site->id }}" @selected((string) $site->id === (string) $siteId)>{{ $site->name }}</option>
                    @endforeach
                </select>
                <button class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">Filter</button>
                <a href="{{ route('app.agentic-marketing.content-opportunities.index', ['workspace_id' => $workspace->id, 'client_site_id' => $siteId]) }}" class="rounded-md border border-border bg-background px-3 py-2 text-center text-sm text-textPrimary">Open opportunities</a>
            </form>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-4 xl:col-span-2">
                @forelse ($clusters as $cluster)
                    <article class="rounded-lg border border-border bg-surface p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-xs text-textSecondary">{{ $cluster->primary_entity }} · {{ $cluster->refresh_cadence }} refresh</p>
                                <h2 class="mt-1 text-lg font-semibold text-textPrimary">
                                    <a href="{{ route('app.agentic-marketing.campaign-clusters.show', $cluster) }}" class="hover:text-primary">{{ $cluster->name }}</a>
                                </h2>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-textSecondary">Completeness</p>
                                <p class="text-xl font-semibold text-textPrimary">{{ number_format((float) $cluster->completeness_score, 1) }}</p>
                            </div>
                        </div>

                        <p class="mt-3 text-sm text-textSecondary">{{ $cluster->authority_strategy }}</p>
                        <div class="mt-4 grid gap-3 text-xs text-textSecondary md:grid-cols-4">
                            <div>Items: <span class="text-textPrimary">{{ $cluster->items_count }}</span></div>
                            <div>Dependencies: <span class="text-textPrimary">{{ $cluster->dependencies_count }}</span></div>
                            <div>Authority: <span class="text-textPrimary">{{ number_format((float) $cluster->authority_score, 1) }}</span></div>
                            <div>AI visibility: <span class="text-textPrimary">{{ number_format((float) $cluster->ai_visibility_score, 1) }}</span></div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach ((array) $cluster->missing_coverage as $gap)
                                <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ str_replace('_', ' ', (string) ($gap['value'] ?? 'gap')) }}</span>
                            @endforeach
                        </div>
                    </article>
                @empty
                    <div class="rounded-lg border border-border bg-surface p-6 text-sm text-textSecondary">No campaign clusters yet. Generate clusters after company intelligence and content opportunities are available.</div>
                @endforelse

                <div>{{ $clusters->links() }}</div>
            </div>

            <aside class="space-y-4">
                <div class="rounded-lg border border-border bg-surface p-4">
                    <h2 class="text-sm font-semibold text-textPrimary">Recent runs</h2>
                    <div class="mt-3 space-y-2 text-xs text-textSecondary">
                        @forelse ($runs as $run)
                            <div class="rounded-md border border-border bg-background p-3">
                                <div class="flex justify-between gap-2">
                                    <span>{{ strtoupper($run->status) }}</span>
                                    <span>{{ optional($run->created_at)->format('Y-m-d H:i') }}</span>
                                </div>
                                <p class="mt-1">{{ $run->created_count }} created, {{ $run->refreshed_count }} refreshed</p>
                            </div>
                        @empty
                            <p>No runs yet.</p>
                        @endforelse
                    </div>
                </div>
            </aside>
        </div>
    </div>
@endsection
