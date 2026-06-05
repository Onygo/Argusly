@extends('layouts.app', ['title' => 'Site Competitors'])

@section('content')
    <div class="space-y-6">
        <x-app.insights-header
            :site="$site"
            title="Competitors"
            description="Manage the competitor domains and context signals used across insight workflows."
            active="competitors"
        >
            <a href="{{ route('app.insights.index') }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">All sites</a>
            <a href="{{ route('app.sites.show', $site) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Site setup</a>
        </x-app.insights-header>

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
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm text-textPrimary">
                    <thead>
                        <tr class="text-left text-xs text-textSecondary">
                            <th class="pb-2 font-medium">Entity</th>
                            <th class="pb-2 font-medium">Category</th>
                            <th class="pb-2 font-medium">Mentions</th>
                            <th class="pb-2 font-medium">Rank</th>
                            <th class="pb-2 font-medium">Providers</th>
                            <th class="pb-2 font-medium">Evidence</th>
                            <th class="pb-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse (($authorityCandidates ?? collect()) as $candidate)
                            <tr class="border-t border-border/70 align-top">
                                <td class="py-3 pr-4">
                                    <p class="font-medium">{{ $candidate->brand_name }}</p>
                                    <p class="text-xs text-textSecondary">{{ data_get($candidate->evidence, 'latest_reason', 'Detected in AI answer evidence.') }}</p>
                                </td>
                                <td class="py-3 pr-4">{{ str_replace('_', ' ', $candidate->entity_category) }}</td>
                                <td class="py-3 pr-4">{{ $candidate->mention_count }}</td>
                                <td class="py-3 pr-4">
                                    Latest {{ $candidate->latest_rank ?: '-' }}<br>
                                    <span class="text-xs text-textSecondary">Avg {{ is_numeric($candidate->average_rank) ? number_format((float) $candidate->average_rank, 1) : '-' }}</span>
                                </td>
                                <td class="py-3 pr-4 text-xs text-textSecondary">
                                    {{ collect((array) $candidate->provider_breakdown)->keys()->implode(', ') ?: '-' }}
                                </td>
                                <td class="py-3 pr-4 text-xs text-textSecondary">
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
                                </td>
                                <td class="py-3">
                                    @if ($candidate->status === 'accepted')
                                        <span class="rounded border border-emerald-500/30 bg-emerald-500/10 px-2 py-1 text-xs text-emerald-800">Accepted</span>
                                    @else
                                        <div class="flex flex-wrap gap-2">
                                            <form method="POST" action="{{ route('app.sites.competitors.candidates.accept', [$site, $candidate]) }}">
                                                @csrf
                                                <button class="rounded border border-border px-2 py-1 text-xs">Add as competitor</button>
                                            </form>
                                            <form method="POST" action="{{ route('app.sites.competitors.candidates.ignore', [$site, $candidate]) }}">
                                                @csrf
                                                <button class="rounded border border-border px-2 py-1 text-xs">Ignore</button>
                                            </form>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-3 text-textSecondary">No high-performing entity candidates detected yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
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
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm text-textPrimary">
                    <thead>
                        <tr class="text-left text-xs text-textSecondary">
                            <th class="pb-2 font-medium">Name</th>
                            <th class="pb-2 font-medium">Domain</th>
                            <th class="pb-2 font-medium">Status</th>
                            <th class="pb-2 font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($competitors as $competitor)
                            <tr class="border-t border-border/70">
                                <td class="py-2">
                                    <p>{{ $competitor->name }}</p>
                                    @if ($competitor->notes)
                                        <p class="text-xs text-textSecondary">{{ $competitor->notes }}</p>
                                    @endif
                                </td>
                                <td class="py-2">{{ $competitor->domain }}</td>
                                <td class="py-2">{{ $competitor->is_active ? 'Active' : 'Inactive' }}</td>
                                <td class="py-2">
                                    <form method="POST" action="{{ route('app.sites.competitors.toggle', [$site, $competitor]) }}">
                                        @csrf
                                        <button class="rounded border border-border px-2 py-1 text-xs">{{ $competitor->is_active ? 'Deactivate' : 'Activate' }}</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="py-3 text-textSecondary">No competitors configured for this site.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            </div>
        </div>
    </div>
@endsection
