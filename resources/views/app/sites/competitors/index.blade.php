@extends('layouts.app', ['title' => 'Site Competitors'])

@section('pageHeader')
    <x-page-header title="Competitors">
        <x-slot:description>Manage the competitor domains and context signals used across insight workflows.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <a href="{{ route('app.insights.index') }}" class="pl-btn-secondary">All sites</a>
    <a href="{{ route('app.sites.show', $site) }}" class="pl-btn-secondary">Site setup</a>
@endsection

@section('content')
    <div class="space-y-6">
        <x-app.insights-header
            :site="$site"
            title="Competitors"
            description="Manage the competitor domains and context signals used across insight workflows."
            active="competitors"
            :show-heading="false"
        />

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if ($errors->has('competitors'))
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">{{ $errors->first('competitors') }}</div>
        @endif

        <div class="rounded-lg border border-border bg-surface p-6 text-sm">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs text-textSecondary">Competitor slots</p>
                <p class="mt-1 font-semibold text-textPrimary">
                    {{ $competitorUsed }}
                    @if ($competitorLimit >= 0)
                        / {{ $competitorLimit }}
                    @else
                        / Unlimited
                    @endif
                </p>
            </div>
            <form method="POST" action="{{ route('app.sites.competitors.context.update', $site) }}" class="inline-flex items-center gap-2">
                @csrf
                <input type="hidden" name="competitor_context_enabled" value="0">
                <label class="inline-flex items-center gap-2 text-xs text-textSecondary">
                    <input type="checkbox" name="competitor_context_enabled" value="1" @checked($competitorContextEnabled) onchange="this.form.submit()">
                    Inject competitors into generation context
                </label>
            </form>
        </div>
        </div>

        <div class="rounded-lg border border-border bg-surface p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-textPrimary">High-performing entities to consider</h2>
                    <p class="mt-1 text-xs text-textSecondary">Detected from AI visibility runs. Entities can be competitors, benchmarks, publishers, source authorities, ecosystem entities, or complementary platforms.</p>
                </div>
            </div>
            <x-data-table label="High-performing entities to consider" description="Detected authority candidates with category, mentions, rank, provider evidence, and actions." class="mt-4 border-x-0 border-b-0 rounded-none">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Entity</x-data-table.cell>
                        <x-data-table.cell heading>Category</x-data-table.cell>
                        <x-data-table.cell heading>Mentions</x-data-table.cell>
                        <x-data-table.cell heading>Rank</x-data-table.cell>
                        <x-data-table.cell heading>Providers</x-data-table.cell>
                        <x-data-table.cell heading>Evidence</x-data-table.cell>
                        <x-data-table.cell heading>Actions</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                    @forelse (($authorityCandidates ?? collect()) as $candidate)
                        <x-data-table.row class="align-top">
                            <x-data-table.cell label="Entity">
                                <p class="font-medium">{{ $candidate->brand_name }}</p>
                                <p class="text-xs text-textSecondary">{{ data_get($candidate->evidence, 'latest_reason', 'Detected in AI answer evidence.') }}</p>
                            </x-data-table.cell>
                            <x-data-table.cell label="Category">{{ str_replace('_', ' ', $candidate->entity_category) }}</x-data-table.cell>
                            <x-data-table.cell label="Mentions">{{ $candidate->mention_count }}</x-data-table.cell>
                            <x-data-table.cell label="Rank">
                                Latest {{ $candidate->latest_rank ?: '-' }}<br>
                                <span class="text-xs text-textSecondary">Avg {{ is_numeric($candidate->average_rank) ? number_format((float) $candidate->average_rank, 1) : '-' }}</span>
                            </x-data-table.cell>
                            <x-data-table.cell label="Providers" class="text-xs text-textSecondary">
                                {{ collect((array) $candidate->provider_breakdown)->keys()->implode(', ') ?: '-' }}
                            </x-data-table.cell>
                            <x-data-table.cell label="Evidence" class="text-xs text-textSecondary">
                                <details>
                                    <summary class="cursor-pointer text-textPrimary">View evidence</summary>
                                    <div class="mt-2 space-y-2">
                                        <p>{{ data_get($candidate->evidence, 'latest_query') }}</p>
                                        @foreach ((array) $candidate->source_urls as $url)
                                            <p class="break-all">{{ $url }}</p>
                                        @endforeach
                                        @foreach ((array) data_get($candidate->evidence, 'latest_context', []) as $context)
                                            <p>{{ $context }}</p>
                                        @endforeach
                                    </div>
                                </details>
                            </x-data-table.cell>
                            <x-data-table.cell label="Actions">
                                @if ($candidate->status === 'accepted')
                                    <x-data-table.badge tone="success" label="Accepted" />
                                @else
                                    <x-data-table.actions align="start">
                                        <form method="POST" action="{{ route('app.sites.competitors.candidates.accept', [$site, $candidate]) }}">
                                            @csrf
                                            <button class="rounded border border-border px-2 py-1 text-xs">Add as competitor</button>
                                        </form>
                                        <form method="POST" action="{{ route('app.sites.competitors.candidates.ignore', [$site, $candidate]) }}">
                                            @csrf
                                            <button class="rounded border border-border px-2 py-1 text-xs">Ignore</button>
                                        </form>
                                    </x-data-table.actions>
                                @endif
                            </x-data-table.cell>
                        </x-data-table.row>
                    @empty
                        <x-data-table.empty colspan="7" title="No high-performing entity candidates detected yet" />
                    @endforelse
                </tbody>
            </x-data-table>
        </div>

        <div class="rounded-lg border border-border bg-surface p-6">
            <h2 class="text-sm font-semibold text-textPrimary">Authority learnings</h2>
            <div class="mt-4 grid gap-3 lg:grid-cols-2">
                @forelse (($authorityLearnings ?? collect()) as $learning)
                    <div class="rounded border border-border bg-background p-4">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-semibold text-textPrimary">{{ $learning->title }}</p>
                            <span class="text-xs text-textSecondary">{{ str_replace('_', ' ', $learning->learning_type) }}</span>
                        </div>
                        <p class="mt-2 text-sm text-textSecondary">{{ $learning->summary }}</p>
                        @if ($learning->recommended_action)
                            <p class="mt-2 text-xs font-medium text-textPrimary">{{ $learning->recommended_action }}</p>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-textSecondary">No authority learnings extracted yet.</p>
                @endforelse
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-border bg-surface p-6">
            <h2 class="text-sm font-semibold text-textPrimary">Add competitor</h2>
            <form method="POST" action="{{ route('app.sites.competitors.store', $site) }}" class="mt-4 space-y-4">
                @csrf
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Name</label>
                    <input name="name" value="{{ old('name') }}" required maxlength="120" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Domain</label>
                    <input name="domain" value="{{ old('domain') }}" required maxlength="190" placeholder="competitor.com" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs text-textSecondary">Notes</label>
                    <textarea name="notes" rows="3" class="w-full rounded border border-border bg-background px-2 py-2 text-sm">{{ old('notes') }}</textarea>
                </div>
                <button class="rounded border border-border px-3 py-2 text-sm">Add competitor</button>
            </form>
            </div>

            <div class="rounded-lg border border-border bg-surface p-6">
            <h2 class="text-sm font-semibold text-textPrimary">Competitor list</h2>
            <x-data-table label="Competitor list" description="Configured site competitors with domain, status, and activation action." class="mt-4 border-x-0 border-b-0 rounded-none">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Name</x-data-table.cell>
                        <x-data-table.cell heading>Domain</x-data-table.cell>
                        <x-data-table.cell heading>Status</x-data-table.cell>
                        <x-data-table.cell heading>Action</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                    @forelse ($competitors as $competitor)
                        <x-data-table.row>
                            <x-data-table.cell label="Name">
                                <p>{{ $competitor->name }}</p>
                                @if ($competitor->notes)
                                    <p class="text-xs text-textSecondary">{{ $competitor->notes }}</p>
                                @endif
                            </x-data-table.cell>
                            <x-data-table.cell label="Domain">{{ $competitor->domain }}</x-data-table.cell>
                            <x-data-table.cell label="Status">
                                <x-data-table.badge :tone="$competitor->is_active ? 'success' : 'neutral'" :label="$competitor->is_active ? 'Active' : 'Inactive'" />
                            </x-data-table.cell>
                            <x-data-table.cell label="Action">
                                <x-data-table.actions align="start">
                                    <form method="POST" action="{{ route('app.sites.competitors.toggle', [$site, $competitor]) }}">
                                        @csrf
                                        <button class="rounded border border-border px-2 py-1 text-xs">{{ $competitor->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                </x-data-table.actions>
                            </x-data-table.cell>
                        </x-data-table.row>
                    @empty
                        <x-data-table.empty colspan="4" title="No competitors configured for this site" />
                    @endforelse
                </tbody>
            </x-data-table>
            </div>
        </div>
    </div>
@endsection
