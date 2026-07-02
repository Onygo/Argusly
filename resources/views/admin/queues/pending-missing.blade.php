@extends('layouts.admin', ['title' => 'Pending Job Not Found'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Pending job not found</x-slot:title>
        <x-slot:description>Job #{{ $jobId }} is no longer present in the pending jobs table.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
        <a href="{{ route('admin.queues.index', request()->query()) }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Back to queues</a>
@endsection

@section('content')

    <div class="mb-6 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-900">
        This usually means the worker already picked up the job, the job completed, it was requeued with a different id, or it moved to failed jobs.
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-lg border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">Nearby pending jobs</h2>
            @if (empty($nearbyPendingJobs))
                <p class="mt-3 text-sm text-textSecondary">No nearby pending job ids are currently available.</p>
            @else
                <x-data-table label="Nearby pending jobs" description="Nearby pending job ids with queue, job name, and created time." density="compact" class="mt-3 border-0 shadow-none">
                    <x-data-table.header>
                        <x-data-table.row>
                            <x-data-table.cell heading>ID</x-data-table.cell>
                            <x-data-table.cell heading>Queue</x-data-table.cell>
                            <x-data-table.cell heading>Job</x-data-table.cell>
                            <x-data-table.cell heading>Created</x-data-table.cell>
                        </x-data-table.row>
                    </x-data-table.header>
                    <tbody class="divide-y divide-border">
                    @foreach ($nearbyPendingJobs as $nearby)
                        <x-data-table.row>
                            <x-data-table.cell label="ID"><a href="{{ route('admin.queues.pending.show', [$nearby['id']] + request()->query()) }}" class="text-link underline">{{ $nearby['id'] }}</a></x-data-table.cell>
                            <x-data-table.cell label="Queue" class="text-textSecondary">{{ $nearby['queue'] }}</x-data-table.cell>
                            <x-data-table.cell label="Job" class="text-textPrimary">{{ $nearby['job_name'] }}</x-data-table.cell>
                            <x-data-table.cell label="Created" class="text-textSecondary">{{ $nearby['created_at']?->format('Y-m-d H:i:s') ?? 'Unknown' }}</x-data-table.cell>
                        </x-data-table.row>
                    @endforeach
                    </tbody>
                </x-data-table>
            @endif
        </div>

        <div class="rounded-lg border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">Recent failed jobs</h2>
            @if (empty($recentFailedJobs))
                <p class="mt-3 text-sm text-textSecondary">No failed jobs are currently recorded.</p>
            @else
                <div class="mt-3 space-y-3">
                    @foreach ($recentFailedJobs as $failed)
                        <div class="rounded border border-border bg-background p-3 text-sm">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <a href="{{ route('admin.queues.show', $failed['id']) }}" class="font-medium text-link underline">Failed #{{ $failed['id'] }}</a>
                                <span class="text-xs text-textFaint">{{ $failed['failed_at'] }}</span>
                            </div>
                            <p class="mt-1 text-textPrimary">{{ $failed['job_name'] }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ $failed['error_summary'] }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
