@extends('layouts.admin', ['title' => 'Agentic Action Runs'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Agentic Action Runs</h1>
        <p class="mt-1 text-sm text-textSecondary">Recent Agentic Marketing action decisions and execution outcomes across customer workspaces.</p>
    </div>

    <form method="GET" action="{{ route('admin.agentic-action-runs.index') }}" class="mb-6 grid gap-3 rounded-lg border border-border bg-surface p-4 md:grid-cols-5">
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

    <div class="overflow-x-auto rounded-lg border border-border bg-surface">
        <table class="w-full text-left text-sm">
            <thead class="text-textSecondary">
            <tr>
                <th class="px-3 py-2">Updated</th>
                <th class="px-3 py-2">Customer</th>
                <th class="px-3 py-2">Action</th>
                <th class="px-3 py-2">Mode</th>
                <th class="px-3 py-2">Status</th>
                <th class="px-3 py-2">Learning Signal</th>
                <th class="px-3 py-2">Credits</th>
                <th class="px-3 py-2">Reason</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-border">
            @forelse ($rows as $row)
                @php
                    $learningSignal = (array) data_get($row->output_snapshot, 'learning_signal', []);
                    $impactScore = data_get($learningSignal, 'impact_score');
                @endphp
                <tr>
                    <td class="px-3 py-2 text-textSecondary">{{ $row->updated_at?->format('Y-m-d H:i:s') }}</td>
                    <td class="px-3 py-2 text-xs text-textSecondary">
                        <div>{{ $row->workspace?->organization?->name ?? '-' }}</div>
                        <div>{{ $row->workspace?->display_name ?? $row->workspace?->name ?? $row->workspace_id }}</div>
                    </td>
                    <td class="px-3 py-2 text-textPrimary">
                        <div class="font-medium">{{ str_replace('_', ' ', $row->action_type) }}</div>
                        <div class="text-xs text-textSecondary">{{ $row->goal?->name ?? $row->action_id }}</div>
                    </td>
                    <td class="px-3 py-2">
                        <span class="rounded px-2 py-1 text-xs {{ $row->execution_mode_snapshot === 'autonomous' ? 'bg-amber-500/10 text-amber-700' : 'bg-slate-500/10 text-slate-700' }}">{{ ucfirst($row->execution_mode_snapshot) }}</span>
                        @if ($row->executed_by_agent)
                            <span class="ml-1 rounded px-2 py-1 text-xs bg-blue-500/10 text-blue-700">Agent</span>
                        @endif
                    </td>
                    <td class="px-3 py-2">
                        <span class="rounded px-2 py-1 text-xs bg-slate-500/10 text-slate-700">{{ str_replace('_', ' ', $row->status) }}</span>
                    </td>
                    <td class="max-w-sm px-3 py-2 text-xs text-textSecondary">
                        @if ($learningSignal)
                            <div class="font-medium text-textPrimary">{{ data_get($learningSignal, 'summary', 'Learning signal recorded.') }}</div>
                            <div class="mt-1 flex flex-wrap gap-1">
                                @if (is_numeric($impactScore))
                                    <span class="rounded bg-emerald-500/10 px-2 py-0.5 text-emerald-700">Impact {{ (int) $impactScore >= 0 ? '+' : '' }}{{ $impactScore }}</span>
                                @endif
                                @if (data_get($learningSignal, 'classifiers.high_cost_low_impact'))
                                    <span class="rounded bg-amber-500/10 px-2 py-0.5 text-amber-700">High cost low impact</span>
                                @endif
                                @if (data_get($learningSignal, 'classifiers.page_improved_after_refresh'))
                                    <span class="rounded bg-blue-500/10 px-2 py-0.5 text-blue-700">Refresh improved</span>
                                @endif
                            </div>
                        @else
                            -
                        @endif
                    </td>
                    <td class="px-3 py-2 text-textSecondary">{{ number_format((int) ($row->actual_credits ?? $row->estimated_credits ?? 0)) }}</td>
                    <td class="max-w-lg px-3 py-2 text-textSecondary">{{ $row->error_message ?: $row->reason ?: '-' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-3 py-6 text-center text-textSecondary">No Agentic Action runs found for the current filters.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
