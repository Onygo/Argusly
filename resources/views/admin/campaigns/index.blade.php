@extends('layouts.admin', ['title' => 'Campaigns'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Campaigns</x-slot:title>
        <x-slot:description>Agentic Marketing campaign orchestration across workspaces, content assets, approvals, and distribution plans.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
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

    <x-data-table label="Campaigns" description="Agentic marketing campaigns with workspace, status, schedule, assets, distribution, and update time." density="compact">
            <x-data-table.header>
            <x-data-table.row>
                <x-data-table.cell heading>Campaign</x-data-table.cell>
                <x-data-table.cell heading>Workspace</x-data-table.cell>
                <x-data-table.cell heading>Status</x-data-table.cell>
                <x-data-table.cell heading>Schedule</x-data-table.cell>
                <x-data-table.cell heading>Assets</x-data-table.cell>
                <x-data-table.cell heading>Distribution</x-data-table.cell>
                <x-data-table.cell heading>Updated</x-data-table.cell>
            </x-data-table.row>
            </x-data-table.header>
            <tbody>
            @forelse ($campaigns as $campaign)
                <x-data-table.row>
                    <x-data-table.cell label="Campaign">
                        <a href="{{ route('admin.campaigns.show', $campaign) }}" class="font-medium text-primary hover:underline">{{ $campaign->name }}</a>
                        <div class="text-xs text-textSecondary">{{ $campaign->objective ?: $campaign->slug }}</div>
                    </x-data-table.cell>
                    <x-data-table.cell label="Workspace" class="text-textSecondary">
                        <div>{{ $campaign->workspace?->organization?->name ?? '-' }}</div>
                        <div class="text-xs">{{ $campaign->workspace?->display_name ?? $campaign->workspace?->name ?? $campaign->workspace_id }}</div>
                    </x-data-table.cell>
                    <x-data-table.cell label="Status">
                        <x-data-table.badge :label="str_replace('_', ' ', $campaign->status?->value ?? $campaign->status)" />
                        <x-data-table.badge tone="info" :label="str_replace('_', ' ', $campaign->approval_status?->value ?? $campaign->approval_status)" class="ml-1" />
                    </x-data-table.cell>
                    <x-data-table.cell label="Schedule" class="text-textSecondary">
                        {{ $campaign->planned_start_date?->format('Y-m-d') ?? '-' }}
                        @if ($campaign->planned_end_date)
                            <span class="text-textFaint">to</span> {{ $campaign->planned_end_date->format('Y-m-d') }}
                        @endif
                    </x-data-table.cell>
                    <x-data-table.cell label="Assets" class="text-textSecondary">{{ number_format($campaign->contents_count) }}</x-data-table.cell>
                    <x-data-table.cell label="Distribution" class="text-textSecondary">{{ number_format($campaign->distribution_plans_count) }}</x-data-table.cell>
                    <x-data-table.cell label="Updated" class="text-textSecondary">{{ $campaign->updated_at?->format('Y-m-d H:i') }}</x-data-table.cell>
                </x-data-table.row>
            @empty
                <x-data-table.empty colspan="7" title="No campaigns found" />
            @endforelse
            </tbody>
        <x-slot:pagination>{{ $campaigns->links() }}</x-slot:pagination>
    </x-data-table>
@endsection
