@extends('layouts.admin', ['title' => 'Agent Runs'])

@section('pageHeader')
    <x-page-header title="Agent Runs">
        <x-slot:description>Internal observability for agent execution, scope, outcomes, and payload traces.</x-slot:description>
    </x-page-header>
@endsection

@section('filterBar')
    <form method="GET" action="{{ route('admin.agent-runs.index') }}" class="grid gap-3 md:grid-cols-5">
        <input type="text" name="agent_key" value="{{ $filters['agent_key'] }}" list="agent-key-options" class="rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Agent key">
        <datalist id="agent-key-options">
            @foreach ($agentKeys as $agentKey)
                <option value="{{ $agentKey }}"></option>
            @endforeach
        </datalist>

        <select name="status" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">All status</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>

        <select name="trigger_type" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">All triggers</option>
            @foreach ($triggerTypes as $triggerType)
                <option value="{{ $triggerType }}" @selected($filters['trigger_type'] === $triggerType)>{{ $triggerType }}</option>
            @endforeach
        </select>

        <input type="text" name="workspace_id" value="{{ $filters['workspace_id'] }}" class="rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Workspace ID">
        <input type="text" name="site_id" value="{{ $filters['site_id'] }}" class="rounded-md border border-border bg-background px-3 py-2 text-sm" placeholder="Site ID">

        <div class="md:col-span-5 flex gap-2">
            <button class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Apply filters</button>
            <a href="{{ route('admin.agent-runs.index') }}" class="inline-flex items-center rounded-md border border-border px-4 py-2 text-sm font-medium">Reset</a>
        </div>
    </form>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Total runs" :value="number_format($stats['total'])" />
        <x-metric-card label="Success" :value="number_format($stats['success'])" tone="success" />
        <x-metric-card label="Skipped" :value="number_format($stats['skipped'])" />
        <x-metric-card label="Warning" :value="number_format($stats['warning'])" tone="warning" />
        <x-metric-card label="Failed" :value="number_format($stats['failed'])" tone="danger" />
    </x-metric-section>
@endsection

@section('content')
    <x-data-table label="Agent runs" description="Agent execution runs with start time, trigger, scope, status, summary, and duration." density="compact">
            <x-data-table.header>
            <x-data-table.row>
                <x-data-table.cell heading>Started</x-data-table.cell>
                <x-data-table.cell heading>Agent</x-data-table.cell>
                <x-data-table.cell heading>Trigger</x-data-table.cell>
                <x-data-table.cell heading>Scope</x-data-table.cell>
                <x-data-table.cell heading>Status</x-data-table.cell>
                <x-data-table.cell heading>Summary</x-data-table.cell>
                <x-data-table.cell heading>Duration</x-data-table.cell>
            </x-data-table.row>
            </x-data-table.header>
            <tbody>
            @forelse ($rows as $row)
                <x-data-table.row>
                    <x-data-table.cell label="Started" class="text-textSecondary">{{ $row->started_at?->format('Y-m-d H:i:s') ?? '-' }}</x-data-table.cell>
                    <x-data-table.cell label="Agent" class="text-textPrimary">
                        <div>{{ $row->agent_key }}</div>
                        @if ($row->trigger_source)
                            <div class="text-xs text-textSecondary">{{ $row->trigger_source }}</div>
                        @endif
                    </x-data-table.cell>
                    <x-data-table.cell label="Trigger" class="text-textSecondary">{{ $row->trigger_type }}</x-data-table.cell>
                    <x-data-table.cell label="Scope" class="text-xs text-textSecondary">
                        <div>Org: {{ $row->organization?->name ?? ($row->organization_id ?? '-') }}</div>
                        <div>Workspace: {{ $row->workspace?->display_name ?? ($row->workspace_id ?? '-') }}</div>
                        <div>Site: {{ $row->site?->name ?? ($row->site_id ?? '-') }}</div>
                        <div>Content: {{ $row->content?->title ?? ($row->content_id ?? '-') }}</div>
                        <div>Draft: {{ $row->draft?->title ?? ($row->draft_id ?? '-') }}</div>
                    </x-data-table.cell>
                    <x-data-table.cell label="Status">
                        @php($status = $row->status instanceof \App\Agents\Support\AgentRunStatus ? $row->status->value : (string) $row->status)
                        <x-data-table.badge :tone="$status === 'success' ? 'success' : ($status === 'warning' ? 'warning' : ($status === 'failed' ? 'danger' : ($status === 'running' ? 'info' : 'neutral')))" :label="$status" />
                    </x-data-table.cell>
                    <x-data-table.cell label="Summary" class="text-textSecondary">{{ $row->summary ?: ($row->error_message ?: '-') }}</x-data-table.cell>
                    <x-data-table.cell label="Duration" class="text-textSecondary">
                        @if ($row->started_at && $row->finished_at)
                            {{ $row->finished_at->diffInMilliseconds($row->started_at) }} ms
                        @else
                            -
                        @endif
                    </x-data-table.cell>
                </x-data-table.row>
            @empty
                <x-data-table.empty colspan="7" title="No agent runs found" description="No agent runs match the current filters." />
            @endforelse
            </tbody>
        <x-slot:pagination>{{ $rows->links() }}</x-slot:pagination>
    </x-data-table>
@endsection
