@extends('layouts.admin', ['title' => 'System Health'])

@section('pageHeader')
    <x-page-header title="System Health">
        <x-slot:description>Read-only operational status for core platform services.</x-slot:description>
    </x-page-header>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Environment" :value="$checks['app_environment']" />
        <x-metric-card label="Queue" :value="$checks['queue_connection']" :tone="$checks['queue_configured'] ? 'success' : 'danger'">
            {{ $checks['queue_configured'] ? 'Configured' : 'Missing configuration' }}
        </x-metric-card>
        <x-metric-card label="Cache Driver" :value="$checks['cache_driver']" />
        <x-metric-card label="Database" :value="$checks['db_connection']" :tone="$checks['db_status'] === 'ok' ? 'success' : 'danger'">
            {{ $checks['db_status'] === 'ok' ? 'Connected' : 'Connection check failed' }}
        </x-metric-card>
        <x-metric-card label="Storage" :value="$checks['storage_disk']" :tone="in_array($checks['storage_status'], ['ok', 'configured'], true) ? 'success' : 'danger'">
            Driver: {{ $checks['storage_driver'] }} · {{ $checks['storage_status'] }}
        </x-metric-card>
        <x-metric-card label="Webhook Queue" :value="$checks['webhook_queue']">
            @if ($checks['failed_jobs_count'] !== null)
                Failed jobs (24h): {{ $checks['failed_jobs_count'] }}
            @endif
            @if (($checks['failed_jobs_total_count'] ?? null) !== null)
                All-time failed jobs on queue: {{ $checks['failed_jobs_total_count'] }}
            @endif
        </x-metric-card>
    </x-metric-section>
@endsection

@section('content')
    <div class="rounded-lg border border-border bg-surface p-4">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-sm font-semibold text-textPrimary">Queue summary</h2>
                <p class="mt-1 text-sm text-textSecondary">Pending backlog and stuck queue signal across database queues.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('admin.mos-providers.index') }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">MOS providers</a>
                <a href="{{ route('admin.queues.index') }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Open queues</a>
            </div>
        </div>

        <x-data-table label="Queue summary" description="Pending backlog and stuck queue signal across database queues." density="compact" class="mt-4 border-0 shadow-none">
            <x-data-table.header>
                <x-data-table.row>
                    <x-data-table.cell heading>Queue</x-data-table.cell>
                    <x-data-table.cell heading>Pending</x-data-table.cell>
                    <x-data-table.cell heading>Oldest</x-data-table.cell>
                    <x-data-table.cell heading>Status</x-data-table.cell>
                    <x-data-table.cell heading>Link</x-data-table.cell>
                </x-data-table.row>
            </x-data-table.header>
            <tbody class="divide-y divide-border">
            @foreach (($queue_summary['queues'] ?? []) as $queue)
                <x-data-table.row>
                    <x-data-table.cell label="Queue" class="font-medium text-textPrimary">{{ $queue['name'] }}</x-data-table.cell>
                    <x-data-table.cell label="Pending" class="text-textSecondary">{{ $queue['pending_count'] }}</x-data-table.cell>
                    <x-data-table.cell label="Oldest" class="text-textSecondary">
                        @if ($queue['oldest_job_at'])
                            {{ $queue['oldest_job_at']->diffForHumans() }}
                        @else
                            No pending jobs
                        @endif
                    </x-data-table.cell>
                    <x-data-table.cell label="Status">
                        <x-data-table.badge :tone="$queue['is_stuck'] ? 'warning' : 'success'" :label="$queue['is_stuck'] ? 'Stuck' : 'Healthy'" />
                    </x-data-table.cell>
                    <x-data-table.cell label="Link">
                        <a href="{{ route('admin.queues.index', ['pending_queue' => $queue['name']]) }}" class="text-sm text-link hover:underline">View queue</a>
                    </x-data-table.cell>
                </x-data-table.row>
            @endforeach
            </tbody>
        </x-data-table>
    </div>
@endsection
