@extends('layouts.admin', ['title' => 'Pending Job Not Found'])

@section('content')
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Pending job not found</h1>
            <p class="mt-1 text-textSecondary">Job #{{ $jobId }} is no longer present in the pending jobs table.</p>
        </div>
        <a href="{{ route('admin.queues.index', request()->query()) }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Back to queues</a>
    </div>

    <div class="mb-6 rounded-lg border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-900">
        This usually means the worker already picked up the job, the job completed, it was requeued with a different id, or it moved to failed jobs.
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-lg border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">Nearby pending jobs</h2>
            @if (empty($nearbyPendingJobs))
                <p class="mt-3 text-sm text-textSecondary">No nearby pending job ids are currently available.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                        <tr class="border-b border-border text-xs uppercase tracking-wide text-textFaint">
                            <th class="px-2 py-2">ID</th>
                            <th class="px-2 py-2">Queue</th>
                            <th class="px-2 py-2">Job</th>
                            <th class="px-2 py-2">Created</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($nearbyPendingJobs as $nearby)
                            <tr class="border-b border-border/60">
                                <td class="px-2 py-2"><a href="{{ route('admin.queues.pending.show', [$nearby['id']] + request()->query()) }}" class="text-link underline">{{ $nearby['id'] }}</a></td>
                                <td class="px-2 py-2 text-textSecondary">{{ $nearby['queue'] }}</td>
                                <td class="px-2 py-2 text-textPrimary">{{ $nearby['job_name'] }}</td>
                                <td class="px-2 py-2 text-textSecondary">{{ $nearby['created_at']?->format('Y-m-d H:i:s') ?? 'Unknown' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
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
