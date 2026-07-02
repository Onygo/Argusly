@extends('layouts.app', ['title' => 'Agent Orchestration Run'])

@php
    $result = (array) $run->normalized_result;
    $actions = collect((array) data_get($result, 'next_actions', data_get($result, 'actions', [])));
    $topActions = $actions->take(5);
    $recommendations = collect((array) data_get($result, 'recommendations', []));
    $context = (array) $run->shared_context;
    $hasUsefulContext = count((array) data_get($context, 'opportunities', [])) > 0
        || count((array) data_get($context, 'competitor_gaps', [])) > 0
        || count((array) data_get($context, 'campaign_clusters', [])) > 0
        || count((array) data_get($context, 'existing_content', [])) > 0;
@endphp

@section('pageHeader')
    <x-page-header title="What should the customer do next?" eyebrow="Agent orchestration">
        <x-slot:description>{{ strtoupper($run->status) }} · {{ $run->completed_tasks_count }}/{{ $run->tasks_count }} agents completed · confidence {{ number_format((float) $run->confidence_score, 1) }}</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="space-y-6">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('app.agentic-marketing.orchestration.index', ['workspace_id' => $run->workspace_id]) }}" class="text-sm text-textSecondary hover:text-primary">Agent orchestration</a>
                <h2 class="mt-2 text-2xl font-semibold tracking-tight text-textPrimary">What should the customer do next?</h2>
                <p class="mt-1 max-w-3xl text-textSecondary">
                    {{ strtoupper($run->status) }} · {{ $run->completed_tasks_count }}/{{ $run->tasks_count }} agents completed · confidence {{ number_format((float) $run->confidence_score, 1) }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('app.agentic-marketing.campaign-clusters.index', ['workspace_id' => $run->workspace_id, 'client_site_id' => $run->client_site_id]) }}" class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">Open clusters</a>
                <a href="{{ route('app.agentic-marketing.content-opportunities.index', ['workspace_id' => $run->workspace_id, 'client_site_id' => $run->client_site_id]) }}" class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">Open opportunities</a>
            </div>
        </header>

        @unless ($hasUsefulContext)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                This run had very little customer-specific intelligence. Generate company intelligence, content opportunities, competitor intelligence, or campaign clusters first for sharper actions.
            </div>
        @endunless

        <section class="rounded-lg border border-border bg-surface p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-textPrimary">Recommended Next Actions</h2>
                    <p class="mt-1 text-sm text-textSecondary">Use this as the customer-facing action plan. Agent details are summarized below.</p>
                </div>
                <span class="rounded-full border border-border px-3 py-1 text-xs text-textSecondary">{{ $actions->count() }} proposed actions</span>
            </div>

            <div class="mt-5 space-y-3">
                @forelse ($topActions as $index => $action)
                    <article class="rounded-lg border border-border bg-background p-4">
                        <div class="grid gap-4 lg:grid-cols-[auto_minmax(0,1fr)_auto] lg:items-start">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full border border-border text-sm font-semibold text-textPrimary">{{ $index + 1 }}</div>
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full bg-primarySoftBg px-2.5 py-1 text-xs font-medium text-primary">{{ ucfirst((string) ($action['priority'] ?? 'medium')) }}</span>
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">Impact {{ $action['impact'] ?? 'medium' }}</span>
                                    <span class="rounded-full border border-border px-2.5 py-1 text-xs text-textSecondary">Effort {{ $action['effort'] ?? 'medium' }}</span>
                                </div>
                                <h3 class="mt-3 text-sm font-semibold text-textPrimary">{{ $action['title'] ?? 'Proposed action' }}</h3>
                                <p class="mt-2 text-sm text-textSecondary">{{ $action['customer_value'] ?? 'This helps the customer move from analysis to execution.' }}</p>
                                <div class="mt-3 rounded-md border border-border bg-surface px-3 py-2">
                                    <p class="text-xs font-medium text-textPrimary">Next step</p>
                                    <p class="mt-1 text-sm text-textSecondary">{{ $action['next_step'] ?? 'Review and assign this action.' }}</p>
                                </div>
                            </div>
                            <div class="text-xs text-textSecondary lg:text-right">
                                <div>{{ str_replace('_', ' ', (string) ($action['owner_agent'] ?? 'agent')) }}</div>
                                <div class="mt-1">{{ str_replace('_', ' ', (string) ($action['type'] ?? 'action')) }}</div>
                            </div>
                        </div>
                    </article>
                @empty
                    <p class="rounded-md border border-border bg-background p-4 text-sm text-textSecondary">No actions were produced yet.</p>
                @endforelse
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-3">
            <section class="space-y-4 xl:col-span-2">
                <div class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-base font-semibold text-textPrimary">Strategic Notes</h2>
                    <div class="mt-4 space-y-3">
                        @forelse ($recommendations->take(6) as $recommendation)
                            <div class="rounded-md border border-border bg-background p-3 text-sm">
                                <p class="font-medium text-textPrimary">{{ $recommendation['recommendation'] ?? 'Recommendation' }}</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ str_replace('_', ' ', (string) ($recommendation['owner_agent'] ?? 'agent')) }} · {{ $recommendation['priority'] ?? 'medium' }}</p>
                            </div>
                        @empty
                            <p class="text-sm text-textSecondary">No strategic notes yet.</p>
                        @endforelse
                    </div>
                </div>

                <details class="rounded-lg border border-border bg-surface p-5">
                    <summary class="cursor-pointer text-base font-semibold text-textPrimary">Agent Details</summary>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        @foreach ($run->tasks as $task)
                            @php($agent = $agents->get($task->agent_key))
                            <div class="rounded-lg border border-border bg-background p-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="text-sm font-semibold text-textPrimary">{{ $agent['name'] ?? $task->agent_key }}</p>
                                        <p class="mt-1 text-xs text-textSecondary">{{ strtoupper($task->status) }} · confidence {{ number_format((float) $task->confidence_score, 1) }}</p>
                                    </div>
                                </div>
                                <p class="mt-3 text-sm text-textSecondary">{{ data_get($task->normalized_result, 'summary', $task->error_message ?: 'Waiting to run.') }}</p>
                            </div>
                        @endforeach
                    </div>
                </details>
            </section>

            <aside class="space-y-4">
                <div class="rounded-lg border border-border bg-surface p-4">
                    <h2 class="text-sm font-semibold text-textPrimary">Customer Context</h2>
                    <div class="mt-3 space-y-2 text-xs text-textSecondary">
                        <div class="rounded-md border border-border bg-background px-3 py-2">Focus: {{ data_get($context, 'focus.topic') ?: 'Not set' }}</div>
                        <div class="rounded-md border border-border bg-background px-3 py-2">Opportunities: {{ count((array) data_get($context, 'opportunities', [])) }}</div>
                        <div class="rounded-md border border-border bg-background px-3 py-2">Competitor gaps: {{ count((array) data_get($context, 'competitor_gaps', [])) }}</div>
                        <div class="rounded-md border border-border bg-background px-3 py-2">Clusters: {{ count((array) data_get($context, 'campaign_clusters', [])) }}</div>
                    </div>
                </div>

                <div class="rounded-lg border border-border bg-surface p-4">
                    <h2 class="text-sm font-semibold text-textPrimary">Readiness</h2>
                    <div class="mt-3 space-y-2 text-xs text-textSecondary">
                        <div class="rounded-md border border-border bg-background px-3 py-2">Agents: {{ $run->completed_tasks_count }}/{{ $run->tasks_count }} complete</div>
                        <div class="rounded-md border border-border bg-background px-3 py-2">Conflicts: {{ $run->conflicts_count }}</div>
                        <div class="rounded-md border border-border bg-background px-3 py-2">Memories used: {{ count((array) data_get($context, 'memories', [])) }}</div>
                    </div>
                </div>

                <details class="rounded-lg border border-border bg-surface p-4">
                    <summary class="cursor-pointer text-sm font-semibold text-textPrimary">Debug Trace</summary>
                    <div class="mt-3 max-h-80 space-y-2 overflow-y-auto text-xs text-textSecondary">
                        @foreach ($run->traces as $trace)
                            <div class="rounded-md border border-border bg-background px-3 py-2">
                                {{ optional($trace->occurred_at)->format('H:i:s') }} · {{ $trace->event }}
                            </div>
                        @endforeach
                    </div>
                </details>
            </aside>
        </div>
    </div>
@endsection
