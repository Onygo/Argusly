<x-app.layout title="Agent Runs | Argusly">
    <div class="w-full">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="eyebrow">Agentic Marketing</p>
                <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Agent runs</h1>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Run monitoring for guarded agent workflows in {{ $account->name }}{{ $brand ? ' and '.$brand->name : '' }}.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-ui.button href="{{ route('app.agents') }}" variant="secondary">Agents</x-ui.button>
                <x-ui.button href="{{ route('app.agents.tasks') }}" variant="secondary">Tasks</x-ui.button>
            </div>
        </div>

        @if (session('status'))
            <div class="mt-6 rounded-md border border-line bg-white p-4 text-sm font-medium text-ink">{{ session('status') }}</div>
        @endif

        <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <x-dashboard.info-card label="Latest runs" :value="$runs->total()" />
            <x-dashboard.info-card label="Queued or running" :value="$workflow['stats']['running_or_queued_runs']" />
            <x-dashboard.info-card label="Open tasks" :value="$workflow['stats']['open_agent_tasks']" />
            <x-dashboard.info-card label="Audit events" :value="$workflow['stats']['audit_events']" />
        </div>

        <div class="mt-6">
            <x-dashboard.section title="Run monitoring" description="Status, timing, tenant scope and result metadata for agent runs.">
                <div class="space-y-3">
                    @forelse ($runs as $run)
                        <div class="rounded-md border border-line bg-panel p-4">
                            <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="text-sm font-semibold text-ink">{{ $run->agent->name }}</p>
                                        <x-ui.badge>{{ str($run->status)->headline() }}</x-ui.badge>
                                    </div>
                                    <p class="mt-2 text-xs text-muted">{{ $run->brand?->name ?? 'Account-wide' }} · Started {{ $run->started_at?->format('M j, H:i') ?? 'not started' }}</p>
                                    @if ($run->completed_at)
                                        <p class="mt-1 text-xs text-muted">Completed {{ $run->completed_at->format('M j, H:i') }}</p>
                                    @endif
                                </div>
                                <div class="min-w-0 lg:w-1/2">
                                    <p class="text-xs font-semibold uppercase tracking-[0.1em] text-muted">Result</p>
                                    <p class="mt-2 line-clamp-3 text-xs leading-5 text-muted">{{ $run->result['message'] ?? 'No result message yet.' }}</p>
                                    <p class="mt-2 text-xs text-muted">{{ $run->tasks->count() }} linked tasks</p>
                                </div>
                            </div>
                        </div>
                    @empty
                        <x-dashboard.empty-state title="No runs" message="Agent runs will appear here after approved work is queued and executed." />
                    @endforelse
                </div>

                <div class="mt-6">
                    {{ $runs->links() }}
                </div>
            </x-dashboard.section>
        </div>
    </div>
</x-app.layout>
