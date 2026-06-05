@extends('layouts.app', ['title' => $cluster->name])

@php
    $map = (array) $cluster->visual_map;
    $nodes = (array) data_get($map, 'topic_relationships.nodes', []);
    $edges = (array) data_get($map, 'topic_relationships.edges', []);
    $materializedObjectiveId = session('agentic_marketing_objective_id') ?: collect($cluster->items)->map(fn ($item) => data_get($item->payload, 'agentic_marketing.objective_id'))->filter()->first();
@endphp

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('app.agentic-marketing.campaign-clusters.index', ['workspace_id' => $cluster->workspace_id, 'client_site_id' => $cluster->client_site_id]) }}" class="text-sm text-textSecondary hover:text-primary">Campaign clusters</a>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-textPrimary">{{ $cluster->name }}</h1>
                <p class="mt-1 max-w-3xl text-textSecondary">{{ $cluster->authority_strategy }}</p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-3">
                @if ($materializedObjectiveId)
                    <a href="{{ route('app.agentic-marketing.index', ['objective' => $materializedObjectiveId]) }}" class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary hover:bg-surfaceMuted">Open actions</a>
                @endif
                <form method="POST" action="{{ route('app.agentic-marketing.campaign-clusters.actions.materialize', $cluster) }}">
                    @csrf
                    <button class="rounded-md bg-primary px-3 py-2 text-sm font-medium text-white hover:bg-primary/90">Create generation actions</button>
                </form>
                <div class="rounded-lg border border-border bg-surface p-4 text-right">
                    <p class="text-xs text-textSecondary">Completeness</p>
                    <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ number_format((float) $cluster->completeness_score, 1) }}</p>
                </div>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-5">
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Authority</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((float) $cluster->authority_score, 1) }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Topical coverage</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((float) $cluster->topical_coverage_score, 1) }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Funnel</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((float) $cluster->funnel_coverage_score, 1) }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">AI visibility</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((float) $cluster->ai_visibility_score, 1) }}</p>
            </div>
            <div class="rounded-lg border border-border bg-surface p-4">
                <p class="text-xs text-textSecondary">Refresh</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ ucfirst($cluster->refresh_cadence) }}</p>
            </div>
        </div>

        <section class="rounded-lg border border-border bg-surface p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Topic Relationship Map</h2>
                    <p class="mt-1 text-sm text-textSecondary">Nodes show planned assets; lines show internal links and sequencing dependencies.</p>
                </div>
                <span class="rounded-full border border-border px-3 py-1 text-xs text-textSecondary">{{ count($nodes) }} nodes · {{ count($edges) }} links</span>
            </div>
            <div class="mt-5 overflow-x-auto">
                <div class="min-w-[720px]">
                    <div class="grid grid-cols-6 gap-3">
                        @foreach ($nodes as $node)
                            <div class="rounded-lg border border-border bg-background p-3">
                                <p class="text-xs text-textSecondary">{{ str_replace('_', ' ', (string) ($node['type'] ?? 'item')) }}</p>
                                <p class="mt-1 text-sm font-medium text-textPrimary">{{ $node['label'] ?? 'Cluster item' }}</p>
                                <p class="mt-2 text-xs text-textSecondary">{{ $node['stage'] ?? 'stage' }}</p>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4 grid gap-2 text-xs text-textSecondary md:grid-cols-2">
                        @foreach ($edges as $edge)
                            <div class="rounded-md border border-border bg-background px-3 py-2">
                                {{ $edge['from'] ?? 'source' }} → {{ $edge['to'] ?? 'target' }} · {{ $edge['label'] ?? $edge['type'] ?? 'link' }}
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-3">
            <section class="space-y-4 xl:col-span-2">
                <div class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-base font-semibold text-textPrimary">Publishing Sequence</h2>
                    <div class="mt-4 divide-y divide-border">
                        @foreach ($cluster->items as $item)
                            <div class="grid gap-3 py-4 md:grid-cols-[auto_minmax(0,1fr)_auto] md:items-center">
                                <div class="flex h-8 w-8 items-center justify-center rounded-full border border-border text-xs text-textSecondary">{{ $item->sequence_order }}</div>
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-medium text-textPrimary">{{ $item->title }}</p>
                                        @if (data_get($item->payload, 'agentic_marketing.action_ids'))
                                            <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700">Action ready</span>
                                        @endif
                                    </div>
                                    <p class="mt-1 text-xs text-textSecondary">{{ str_replace('_', ' ', $item->type) }} · {{ $item->funnel_stage }} · {{ $item->search_intent }}</p>
                                </div>
                                <div class="text-xs text-textSecondary">{{ optional($item->planned_publish_date)->format('Y-m-d') }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-base font-semibold text-textPrimary">Internal Link Architecture</h2>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        @foreach ($cluster->dependencies as $dependency)
                            <div class="rounded-md border border-border bg-background p-3 text-xs text-textSecondary">
                                <p class="font-medium text-textPrimary">{{ $dependency->sourceItem?->title }} → {{ $dependency->targetItem?->title }}</p>
                                <p class="mt-1">{{ $dependency->reason }}</p>
                                <p class="mt-1">Anchor: <span class="text-textPrimary">{{ $dependency->anchor_text }}</span></p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </section>

            <aside class="space-y-4">
                <div class="rounded-lg border border-border bg-surface p-4">
                    <h2 class="text-sm font-semibold text-textPrimary">CTA Strategy</h2>
                    <p class="mt-2 text-sm text-textSecondary">{{ $cluster->cta_strategy }}</p>
                </div>
                <div class="rounded-lg border border-border bg-surface p-4">
                    <h2 class="text-sm font-semibold text-textPrimary">Localization</h2>
                    <p class="mt-2 text-sm text-textSecondary">{{ data_get($cluster->localization_strategy, 'sequence') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @foreach ((array) data_get($cluster->localization_strategy, 'priority_locales', []) as $locale)
                            <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">{{ strtoupper($locale) }}</span>
                        @endforeach
                    </div>
                </div>
                <div class="rounded-lg border border-border bg-surface p-4">
                    <h2 class="text-sm font-semibold text-textPrimary">Missing Coverage</h2>
                    <div class="mt-3 space-y-2 text-xs text-textSecondary">
                        @forelse ((array) $cluster->missing_coverage as $gap)
                            <div class="rounded-md border border-border bg-background px-3 py-2">{{ str_replace('_', ' ', (string) ($gap['value'] ?? 'gap')) }} · {{ $gap['severity'] ?? 'medium' }}</div>
                        @empty
                            <p>No major gaps detected.</p>
                        @endforelse
                    </div>
                </div>
                <div class="rounded-lg border border-border bg-surface p-4">
                    <h2 class="text-sm font-semibold text-textPrimary">Authority Gaps</h2>
                    <div class="mt-3 space-y-2 text-xs text-textSecondary">
                        @forelse ((array) $cluster->authority_gaps as $gap)
                            <div class="rounded-md border border-border bg-background px-3 py-2">{{ $gap }}</div>
                        @empty
                            <p>Authority structure is strong.</p>
                        @endforelse
                    </div>
                </div>
            </aside>
        </div>
    </div>
@endsection
