<x-app.layout title="Agents | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Agent framework</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Agents</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Execution architecture for {{ $account->name }}{{ $brand ? ' and '.$brand->name : '' }}. Agents coordinate briefings, recommendations, approvals and guarded workflow runs.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button href="{{ route('app.agents.tasks') }}" variant="secondary">Tasks</x-ui.button>
                <x-ui.button href="{{ route('app.agents.runs') }}" variant="secondary">Runs</x-ui.button>
                <x-ui.badge variant="blue">{{ $agents->count() }} agents</x-ui.badge>
            </div>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.info-card label="Agents" :value="$agents->count()" />
            <x-dashboard.info-card label="Open tasks" :value="$workflow['stats']['open_agent_tasks']" />
            <x-dashboard.info-card label="Planning queue" :value="$workflow['stats']['planning_queue']" />
            <x-dashboard.info-card label="Pending approvals" :value="$workflow['stats']['pending_approvals']" />
        </div>

        <div class="mt-6">
            <x-dashboard.section title="Workflow engine" description="Briefings, research context, planning, approvals and runs in the current tenant scope.">
                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                    @foreach ($workflow['workflowStages'] as $stage)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex items-start justify-between gap-3">
                                <p class="text-sm font-semibold text-ink">{{ $stage['label'] }}</p>
                                <x-ui.badge>{{ $stage['count'] }}</x-ui.badge>
                            </div>
                            <p class="mt-2 text-xs leading-5 text-muted">{{ $stage['description'] }}</p>
                        </div>
                    @endforeach
                </div>
            </x-dashboard.section>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <x-dashboard.section title="Agent roster" description="Default system agents and their planned capabilities.">
                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach ($agents as $agent)
                        <x-agents.card :agent="$agent" />
                    @endforeach
                </div>
            </x-dashboard.section>

            <div class="space-y-6">
                <x-dashboard.section title="Latest runs">
                    @if ($latestRuns->isEmpty())
                        <x-dashboard.empty-state title="No runs yet" message="Agent runs will appear here once tasks are dispatched or placeholder runs are created." />
                    @else
                        <div class="space-y-3">
                            @foreach ($latestRuns as $run)
                                <div class="rounded-md border border-line bg-white p-4">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-ink">{{ $run->agent->name }}</p>
                                            <p class="mt-1 text-xs text-muted">{{ str($run->status)->headline() }}{{ $run->brand ? ' · '.$run->brand->name : '' }}</p>
                                            @if ($run->tasks->isNotEmpty())
                                                <p class="mt-2 truncate text-xs text-muted">{{ $run->tasks->pluck('title')->join(', ') }}</p>
                                            @endif
                                        </div>
                                        <time class="shrink-0 text-xs text-muted" datetime="{{ $run->started_at?->toIso8601String() }}">
                                            {{ $run->started_at?->diffForHumans() }}
                                        </time>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section>

                <x-dashboard.section title="Planning engine">
                    @if ($workflow['planningQueue']->isEmpty())
                        <x-dashboard.empty-state title="No planning items" message="Actionable recommendations without an agent task will appear here." />
                    @else
                        <div class="space-y-3">
                            @foreach ($workflow['planningQueue'] as $recommendation)
                                <div class="rounded-md border border-line bg-panel p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-semibold text-ink">{{ $recommendation->title }}</p>
                                            <p class="mt-1 text-xs text-muted">{{ str($recommendation->action_type)->replace('_', ' ')->headline() }} · {{ $recommendation->brand?->name ?? 'Account-wide' }}</p>
                                        </div>
                                        <form method="POST" action="{{ route('app.agents.recommendations.plan', $recommendation) }}">
                                            @csrf
                                            <x-ui.button type="submit" size="sm">Plan</x-ui.button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-dashboard.section>
            </div>
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-3">
            <x-dashboard.section title="Briefing management" description="Approved and in-review briefings that can seed agent work.">
                <div class="space-y-3">
                    @forelse ($workflow['latestBriefings'] as $briefing)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-sm font-semibold text-ink">{{ $briefing->title }}</p>
                                    <p class="mt-1 text-xs text-muted">{{ str($briefing->status)->headline() }} · {{ $briefing->campaign?->name ?? $briefing->brand?->name ?? 'Account-wide' }}</p>
                                </div>
                                <form method="POST" action="{{ route('app.agents.briefings.plan', $briefing) }}">
                                    @csrf
                                    <x-ui.button type="submit" size="sm" variant="secondary">Plan</x-ui.button>
                                </form>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">No briefings in this scope.</p>
                    @endforelse
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Research workspace" description="Actionable context available for planning.">
                <div class="space-y-3">
                    <x-dashboard.info-card label="Actionable recommendations" :value="$workflow['researchWorkspace']['actionable_recommendations']" />
                    <x-dashboard.info-card label="Accepted recommendations" :value="$workflow['researchWorkspace']['accepted_recommendations']" />
                    <x-dashboard.info-card label="Unplanned recommendations" :value="$workflow['researchWorkspace']['unplanned_recommendations']" />
                </div>
            </x-dashboard.section>

            <x-dashboard.section title="Audit trail" description="Recent agentic workflow events.">
                <div class="space-y-3">
                    @forelse ($workflow['auditTrail'] as $event)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <p class="text-sm font-semibold text-ink">{{ str($event->event_type)->headline() }}</p>
                            <p class="mt-1 text-xs text-muted">{{ $event->occurred_at->format('M j, H:i') }} · {{ $event->actor?->name ?? 'System' }}</p>
                        </div>
                    @empty
                        <p class="text-sm text-muted">No agentic audit events yet.</p>
                    @endforelse
                </div>
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
