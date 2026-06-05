@extends('layouts.admin', ['title' => 'Campaigns'])

@section('content')
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Campaigns</h1>
            <p class="mt-1 text-sm text-textSecondary">Agentic Marketing campaign orchestration across workspaces, content assets, approvals, and distribution plans.</p>
        </div>
        <div class="rounded border border-border px-3 py-2 text-sm text-textSecondary">
            {{ number_format($channelCount) }} distribution channels configured
        </div>
    </div>

    <form method="GET" action="{{ route('admin.campaigns.index') }}" class="mb-6 flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 md:flex-row">
        <select name="status" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">All statuses</option>
            @foreach (['draft', 'planning', 'pending_approval', 'approved', 'scheduled', 'active', 'paused', 'completed', 'archived'] as $option)
                <option value="{{ $option }}" @selected($status === $option)>{{ str_replace('_', ' ', ucfirst($option)) }}</option>
            @endforeach
        </select>
        <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Apply</button>
    </form>

    <div class="overflow-x-auto rounded-lg border border-border bg-surface">
        <table class="w-full text-left text-sm">
            <thead class="text-textSecondary">
            <tr>
                <th class="px-3 py-2">Campaign</th>
                <th class="px-3 py-2">Workspace</th>
                <th class="px-3 py-2">Status</th>
                <th class="px-3 py-2">Schedule</th>
                <th class="px-3 py-2">Assets</th>
                <th class="px-3 py-2">Distribution</th>
                <th class="px-3 py-2">Updated</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-border">
            @forelse ($campaigns as $campaign)
                <tr>
                    <td class="px-3 py-2">
                        <a href="{{ route('admin.campaigns.show', $campaign) }}" class="font-medium text-primary hover:underline">{{ $campaign->name }}</a>
                        <div class="text-xs text-textSecondary">{{ $campaign->objective ?: $campaign->slug }}</div>
                    </td>
                    <td class="px-3 py-2 text-textSecondary">
                        <div>{{ $campaign->workspace?->organization?->name ?? '-' }}</div>
                        <div class="text-xs">{{ $campaign->workspace?->display_name ?? $campaign->workspace?->name ?? $campaign->workspace_id }}</div>
                    </td>
                    <td class="px-3 py-2">
                        <span class="rounded bg-slate-500/10 px-2 py-1 text-xs text-slate-700">{{ str_replace('_', ' ', $campaign->status?->value ?? $campaign->status) }}</span>
                        <span class="ml-1 rounded bg-blue-500/10 px-2 py-1 text-xs text-blue-700">{{ str_replace('_', ' ', $campaign->approval_status?->value ?? $campaign->approval_status) }}</span>
                    </td>
                    <td class="px-3 py-2 text-textSecondary">
                        {{ $campaign->planned_start_date?->format('Y-m-d') ?? '-' }}
                        @if ($campaign->planned_end_date)
                            <span class="text-textFaint">to</span> {{ $campaign->planned_end_date->format('Y-m-d') }}
                        @endif
                    </td>
                    <td class="px-3 py-2 text-textSecondary">{{ number_format($campaign->contents_count) }}</td>
                    <td class="px-3 py-2 text-textSecondary">{{ number_format($campaign->distribution_plans_count) }}</td>
                    <td class="px-3 py-2 text-textSecondary">{{ $campaign->updated_at?->format('Y-m-d H:i') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-3 py-6 text-center text-textSecondary">No campaigns found.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $campaigns->links() }}</div>
@endsection
