@extends('layouts.admin', ['title' => 'System Health'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">System Health</h1>
        <p class="mt-1 text-textSecondary">Read-only operational status for core platform services.</p>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">Environment</p>
            <p class="mt-2 text-base font-semibold text-textPrimary">{{ $checks['app_environment'] }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">Queue</p>
            <p class="mt-2 text-base font-semibold text-textPrimary">{{ $checks['queue_connection'] }}</p>
            <p class="mt-1 text-xs {{ $checks['queue_configured'] ? 'text-success' : 'text-danger' }}">
                {{ $checks['queue_configured'] ? 'Configured' : 'Missing configuration' }}
            </p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">Cache Driver</p>
            <p class="mt-2 text-base font-semibold text-textPrimary">{{ $checks['cache_driver'] }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">Database</p>
            <p class="mt-2 text-base font-semibold text-textPrimary">{{ $checks['db_connection'] }}</p>
            <p class="mt-1 text-xs {{ $checks['db_status'] === 'ok' ? 'text-success' : 'text-danger' }}">
                {{ $checks['db_status'] === 'ok' ? 'Connected' : 'Connection check failed' }}
            </p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">Storage</p>
            <p class="mt-2 text-base font-semibold text-textPrimary">{{ $checks['storage_disk'] }}</p>
            <p class="mt-1 text-xs text-textSecondary">Driver: {{ $checks['storage_driver'] }}</p>
            <p class="mt-1 text-xs {{ in_array($checks['storage_status'], ['ok', 'configured'], true) ? 'text-success' : 'text-danger' }}">
                {{ $checks['storage_status'] }}
            </p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs uppercase tracking-wide text-textFaint">Webhook Queue</p>
            <p class="mt-2 text-base font-semibold text-textPrimary">{{ $checks['webhook_queue'] }}</p>
            @if ($checks['failed_jobs_count'] !== null)
                <p class="mt-1 text-xs text-textSecondary">Failed jobs (24h): {{ $checks['failed_jobs_count'] }}</p>
            @endif
            @if (($checks['failed_jobs_total_count'] ?? null) !== null)
                <p class="mt-1 text-xs text-textSecondary">All-time failed jobs on queue: {{ $checks['failed_jobs_total_count'] }}</p>
            @endif
        </div>
    </div>

    <div class="mt-6 rounded-lg border border-border bg-surface p-4">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-sm font-semibold text-textPrimary">Queue summary</h2>
                <p class="mt-1 text-sm text-textSecondary">Pending backlog and stuck queue signal across database queues.</p>
            </div>
            <a href="{{ route('admin.queues.index') }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Open queues</a>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead>
                <tr class="border-b border-border text-xs uppercase tracking-wide text-textFaint">
                    <th class="px-3 py-2">Queue</th>
                    <th class="px-3 py-2">Pending</th>
                    <th class="px-3 py-2">Oldest</th>
                    <th class="px-3 py-2">Status</th>
                    <th class="px-3 py-2">Link</th>
                </tr>
                </thead>
                <tbody>
                @foreach (($queue_summary['queues'] ?? []) as $queue)
                    <tr class="border-b border-border/60">
                        <td class="px-3 py-2 font-medium text-textPrimary">{{ $queue['name'] }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ $queue['pending_count'] }}</td>
                        <td class="px-3 py-2 text-textSecondary">
                            @if ($queue['oldest_job_at'])
                                {{ $queue['oldest_job_at']->diffForHumans() }}
                            @else
                                No pending jobs
                            @endif
                        </td>
                        <td class="px-3 py-2">
                            @if ($queue['is_stuck'])
                                <span class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-1 text-xs font-medium text-amber-800">Stuck</span>
                            @else
                                <span class="rounded-full border border-emerald-500/30 bg-emerald-500/10 px-2 py-1 text-xs font-medium text-emerald-800">Healthy</span>
                            @endif
                        </td>
                        <td class="px-3 py-2">
                            <a href="{{ route('admin.queues.index', ['pending_queue' => $queue['name']]) }}" class="text-sm text-link hover:underline">View queue</a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
