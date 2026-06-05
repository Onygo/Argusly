<x-app.layout title="Platform Queues" :show-workspace-header="false">
    @include('admin._nav')

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm font-semibold uppercase tracking-[0.12em] text-muted">Platform</p>
            <h1 class="mt-1 text-3xl font-bold text-ink">Queues</h1>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-md border border-line bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-[0.08em] text-muted">Pending jobs</p>
            <p class="mt-3 text-3xl font-bold text-ink">{{ $queue['pending_count'] }}</p>
        </div>
        <div class="rounded-md border border-line bg-white p-4">
            <p class="text-xs font-semibold uppercase tracking-[0.08em] text-muted">Failed jobs</p>
            <p class="mt-3 text-3xl font-bold text-ink">{{ $queue['failed_count'] }}</p>
        </div>
    </div>

    <div class="mt-6 grid gap-4 xl:grid-cols-2">
        <div class="rounded-md border border-line bg-white p-4 xl:col-span-2">
            <h2 class="text-lg font-bold text-ink">Named Queues</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-line text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-[0.08em] text-muted">
                            <th class="py-2 pr-4">Queue</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4">Pending</th>
                            <th class="py-2 pr-4">Failed</th>
                            <th class="py-2 pr-4">Workers</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($queue['queue_matrix'] as $row)
                            <tr>
                                <td class="py-2 pr-4 font-semibold text-ink">{{ $row['name'] }}</td>
                                <td class="py-2 pr-4">@include('admin._status', ['value' => $row['status']])</td>
                                <td class="py-2 pr-4 text-muted">{{ $row['pending'] }}</td>
                                <td class="py-2 pr-4 text-muted">{{ $row['failed'] }}</td>
                                <td class="py-2 pr-4 text-muted">{{ $row['workers'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Pending</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full divide-y divide-line text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-[0.08em] text-muted">
                            <th class="py-2 pr-4">ID</th>
                            <th class="py-2 pr-4">Queue</th>
                            <th class="py-2 pr-4">Attempts</th>
                            <th class="py-2 pr-4">Available</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($queue['pending_jobs'] as $job)
                            <tr>
                                <td class="py-2 pr-4 font-semibold text-ink">{{ $job->id }}</td>
                                <td class="py-2 pr-4 text-muted">{{ $job->queue }}</td>
                                <td class="py-2 pr-4 text-muted">{{ $job->attempts }}</td>
                                <td class="py-2 pr-4 text-muted">{{ $job->available_at_human }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-6 text-muted">No pending jobs.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-md border border-line bg-white p-4">
            <h2 class="text-lg font-bold text-ink">Failed</h2>
            <div class="mt-4 space-y-3">
                @forelse ($queue['failed_jobs'] as $job)
                    <div class="rounded-md border border-line p-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-ink">#{{ $job->id }} · {{ $job->queue }}</p>
                                <p class="mt-1 text-xs text-muted">{{ $job->failed_at }}</p>
                            </div>
                            <form method="POST" action="{{ route('admin.platform.queues.retry', $job->id) }}">
                                @csrf
                                <button class="rounded-md border border-line px-3 py-2 text-sm font-semibold text-ink hover:bg-panel">Retry</button>
                            </form>
                        </div>
                        <pre class="mt-3 max-h-24 overflow-auto whitespace-pre-wrap rounded-md bg-panel p-2 text-xs text-muted">{{ str($job->exception)->limit(800) }}</pre>
                    </div>
                @empty
                    <p class="rounded-md border border-line p-4 text-sm text-muted">No failed jobs.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="mt-6 rounded-md border border-line bg-white p-4">
        <h2 class="text-lg font-bold text-ink">Worker Heartbeats</h2>
        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($queue['heartbeats'] as $heartbeat)
                <div class="rounded-md border border-line p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-ink">{{ $heartbeat->worker_name }}</p>
                            <p class="text-sm text-muted">{{ $heartbeat->queue ?? 'all queues' }} · {{ $heartbeat->last_seen_at?->format('Y-m-d H:i') }}</p>
                        </div>
                        @include('admin._status', ['value' => $heartbeat->status])
                    </div>
                </div>
            @empty
                <p class="text-sm text-muted">No worker heartbeats recorded yet.</p>
            @endforelse
        </div>
        @if ($queue['stale_heartbeats']->isNotEmpty())
            <div class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                {{ $queue['stale_heartbeats']->count() }} worker heartbeat(s) are stale.
            </div>
        @endif
    </div>
</x-app.layout>
