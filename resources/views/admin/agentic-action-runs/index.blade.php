@extends('layouts.admin', ['title' => 'Agentic Action Runs'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Agentic Action Runs</x-slot:title>
        <x-slot:description>Recent Agentic Marketing action decisions and execution outcomes across customer workspaces.</x-slot:description>
    </x-page-header>
@endsection

@section('filterBar')
    <form method="GET" action="{{ route('admin.agentic-action-runs.index') }}" class="grid gap-3 md:grid-cols-5">
        <select name="status" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">All statuses</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>
            @endforeach
        </select>
        <select name="action_type" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">All action types</option>
            @foreach ($actionTypes as $type)
                <option value="{{ $type }}" @selected($filters['action_type'] === $type)>{{ str_replace('_', ' ', $type) }}</option>
            @endforeach
        </select>
        <select name="execution_mode" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">All execution modes</option>
            <option value="guided" @selected($filters['execution_mode'] === 'guided')>Guided</option>
            <option value="autonomous" @selected($filters['execution_mode'] === 'autonomous')>Autonomous</option>
        </select>
        <input type="text" name="workspace_id" value="{{ $filters['workspace_id'] }}" class="rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Workspace ID">
        <button class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Apply filters</button>
    </form>
@endsection

@section('content')
    <x-data-table label="Agentic action runs" description="Recent Agentic Marketing action decisions and execution outcomes across customer workspaces." density="compact">
        <x-data-table.header>
            <x-data-table.row>
                <x-data-table.cell heading>Updated</x-data-table.cell>
                <x-data-table.cell heading>Customer</x-data-table.cell>
                <x-data-table.cell heading>Action</x-data-table.cell>
                <x-data-table.cell heading>Mode</x-data-table.cell>
                <x-data-table.cell heading>Status</x-data-table.cell>
                <x-data-table.cell heading>Learning Signal</x-data-table.cell>
                <x-data-table.cell heading>Credits</x-data-table.cell>
                <x-data-table.cell heading>Reason</x-data-table.cell>
            </x-data-table.row>
        </x-data-table.header>
        <tbody class="divide-y divide-border">
        @forelse ($rows as $row)
            @php
                $learningSignal = (array) data_get($row->output_snapshot, 'learning_signal', []);
                $impactScore = data_get($learningSignal, 'impact_score');
            @endphp
            <x-data-table.row>
                <x-data-table.cell label="Updated" class="text-textSecondary">{{ $row->updated_at?->format('Y-m-d H:i:s') }}</x-data-table.cell>
                <x-data-table.cell label="Customer" class="text-xs text-textSecondary">
                    <div>{{ $row->workspace?->organization?->name ?? '-' }}</div>
                    <div>{{ $row->workspace?->display_name ?? $row->workspace?->name ?? $row->workspace_id }}</div>
                </x-data-table.cell>
                <x-data-table.cell label="Action" class="text-textPrimary">
                    <div class="font-medium">{{ str_replace('_', ' ', $row->action_type) }}</div>
                    <div class="text-xs text-textSecondary">{{ $row->goal?->name ?? $row->action_id }}</div>
                </x-data-table.cell>
                <x-data-table.cell label="Mode">
                    <x-data-table.badge :tone="$row->execution_mode_snapshot === 'autonomous' ? 'warning' : 'neutral'" :label="ucfirst($row->execution_mode_snapshot)" />
                    @if ($row->executed_by_agent)
                        <x-data-table.badge tone="info" label="Agent" class="ml-1" />
                    @endif
                </x-data-table.cell>
                <x-data-table.cell label="Status">
                    <x-data-table.badge :label="str_replace('_', ' ', $row->status)" />
                </x-data-table.cell>
                <x-data-table.cell label="Learning Signal" class="max-w-sm text-xs text-textSecondary">
                    @if ($learningSignal)
                        <div class="font-medium text-textPrimary">{{ data_get($learningSignal, 'summary', 'Learning signal recorded.') }}</div>
                        <div class="mt-1 flex flex-wrap gap-1">
                            @if (is_numeric($impactScore))
                                <x-data-table.badge tone="success">Impact {{ (int) $impactScore >= 0 ? '+' : '' }}{{ $impactScore }}</x-data-table.badge>
                            @endif
                            @if (data_get($learningSignal, 'classifiers.high_cost_low_impact'))
                                <x-data-table.badge tone="warning" label="High cost low impact" />
                            @endif
                            @if (data_get($learningSignal, 'classifiers.page_improved_after_refresh'))
                                <x-data-table.badge tone="info" label="Refresh improved" />
                            @endif
                        </div>
                    @else
                        -
                    @endif
                </x-data-table.cell>
                <x-data-table.cell label="Credits" class="text-textSecondary">{{ number_format((int) ($row->actual_credits ?? $row->estimated_credits ?? 0)) }}</x-data-table.cell>
                <x-data-table.cell label="Reason" class="max-w-lg text-textSecondary">{{ $row->error_message ?: $row->reason ?: '-' }}</x-data-table.cell>
            </x-data-table.row>
        @empty
            <x-data-table.empty colspan="8" title="No Agentic Action runs found" description="No Agentic Action runs match the current filters." />
        @endforelse
        </tbody>

        <x-slot:pagination>{{ $rows->links() }}</x-slot:pagination>
    </x-data-table>
@endsection
