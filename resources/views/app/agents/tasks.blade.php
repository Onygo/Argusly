<x-app.layout title="Agent Tasks | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Agentic Marketing</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Agent tasks</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Planning, approval and execution state for agent work in {{ $account->name }}{{ $brand ? ' and '.$brand->name : '' }}.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button href="{{ route('app.agents') }}" variant="secondary">Agents</x-ui.button>
                <x-ui.button href="{{ route('app.agents.runs') }}" variant="secondary">Runs</x-ui.button>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.info-card label="Open tasks" :value="$workflow['stats']['open_agent_tasks']" />
            <x-dashboard.info-card label="Pending approvals" :value="$workflow['stats']['pending_approvals']" />
            <x-dashboard.info-card label="Queued or running" :value="$workflow['stats']['running_or_queued_runs']" />
            <x-dashboard.info-card label="Audit events" :value="$workflow['stats']['audit_events']" />
        </div>

        <div class="mt-6">
            <x-dashboard.section title="Task queue" description="Agent tasks stay human-approved before guarded execution.">
                <div class="space-y-3">
                    @forelse ($tasks as $task)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-semibold text-ink">{{ $task->title }}</p>
                                        <x-ui.badge>{{ str($task->status)->headline() }}</x-ui.badge>
                                        <x-ui.badge variant="blue">{{ $task->agent->name }}</x-ui.badge>
                                    </div>
                                    <p class="mt-2 text-xs leading-5 text-muted">{{ $task->description ?: 'No task description.' }}</p>
                                    <p class="mt-2 text-xs text-muted">{{ $task->brand?->name ?? 'Account-wide' }} · Created {{ $task->created_at->format('M j, H:i') }}</p>
                                </div>
                                <div class="flex shrink-0 flex-wrap gap-2">
                                    @if (! in_array($task->status, ['approved', 'queued', 'running', 'completed', 'failed', 'cancelled'], true))
                                        <form method="POST" action="{{ route('app.agents.tasks.approval', $task) }}">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="secondary">Request approval</x-ui.button>
                                        </form>
                                    @endif
                                    @if ($task->status === 'approved')
                                        <form method="POST" action="{{ route('app.agents.tasks.queue', $task) }}">
                                            @csrf
                                            <x-ui.button type="submit" size="sm" variant="secondary">Queue</x-ui.button>
                                        </form>
                                    @endif
                                    @if (in_array($task->status, ['approved', 'queued', 'dispatched'], true))
                                        <form method="POST" action="{{ route('app.agents.tasks.run', $task) }}">
                                            @csrf
                                            <x-ui.button type="submit" size="sm">Run</x-ui.button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <x-dashboard.empty-state title="No agent tasks" message="Planned recommendation and briefing workflows will appear here." />
                    @endforelse
                </div>

                <div class="mt-6">
                    {{ $tasks->links() }}
                </div>
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
