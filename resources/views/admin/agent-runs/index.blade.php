@extends('layouts.admin', ['title' => 'Agent Runs'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Agent Runs</h1>
        <p class="mt-1 text-sm text-textSecondary">Internal observability for agent execution, scope, outcomes, and payload traces.</p>
    </div>

    <form method="GET" action="{{ route('admin.agent-runs.index') }}" class="mb-6 grid gap-3 rounded-lg border border-border bg-surface p-4 md:grid-cols-5">
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

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs text-textSecondary">Total runs</p>
            <p class="text-2xl font-semibold text-textPrimary">{{ number_format($stats['total']) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs text-textSecondary">Success</p>
            <p class="text-2xl font-semibold text-emerald-700">{{ number_format($stats['success']) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs text-textSecondary">Skipped</p>
            <p class="text-2xl font-semibold text-slate-700">{{ number_format($stats['skipped']) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs text-textSecondary">Warning</p>
            <p class="text-2xl font-semibold text-amber-700">{{ number_format($stats['warning']) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs text-textSecondary">Failed</p>
            <p class="text-2xl font-semibold text-rose-700">{{ number_format($stats['failed']) }}</p>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border bg-surface">
        <table class="w-full text-left text-sm">
            <thead class="text-textSecondary">
            <tr>
                <th class="px-3 py-2">Started</th>
                <th class="px-3 py-2">Agent</th>
                <th class="px-3 py-2">Trigger</th>
                <th class="px-3 py-2">Scope</th>
                <th class="px-3 py-2">Status</th>
                <th class="px-3 py-2">Summary</th>
                <th class="px-3 py-2">Duration</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-border">
            @forelse ($rows as $row)
                <tr>
                    <td class="px-3 py-2 text-textSecondary">{{ $row->started_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                    <td class="px-3 py-2 text-textPrimary">
                        <div>{{ $row->agent_key }}</div>
                        @if ($row->trigger_source)
                            <div class="text-xs text-textSecondary">{{ $row->trigger_source }}</div>
                        @endif
                    </td>
                    <td class="px-3 py-2 text-textSecondary">{{ $row->trigger_type }}</td>
                    <td class="px-3 py-2 text-xs text-textSecondary">
                        <div>Org: {{ $row->organization?->name ?? ($row->organization_id ?? '-') }}</div>
                        <div>Workspace: {{ $row->workspace?->display_name ?? ($row->workspace_id ?? '-') }}</div>
                        <div>Site: {{ $row->site?->name ?? ($row->site_id ?? '-') }}</div>
                        <div>Content: {{ $row->content?->title ?? ($row->content_id ?? '-') }}</div>
                        <div>Draft: {{ $row->draft?->title ?? ($row->draft_id ?? '-') }}</div>
                    </td>
                    <td class="px-3 py-2">
                        @php($status = $row->status instanceof \App\Agents\Support\AgentRunStatus ? $row->status->value : (string) $row->status)
                        <span class="rounded px-2 py-1 text-xs
                            {{ $status === 'success' ? 'bg-emerald-500/10 text-emerald-700' : '' }}
                            {{ $status === 'skipped' ? 'bg-slate-500/10 text-slate-700' : '' }}
                            {{ $status === 'warning' ? 'bg-amber-500/10 text-amber-700' : '' }}
                            {{ $status === 'failed' ? 'bg-rose-500/10 text-rose-700' : '' }}
                            {{ $status === 'running' ? 'bg-blue-500/10 text-blue-700' : '' }}">
                            {{ $status }}
                        </span>
                    </td>
                    <td class="px-3 py-2 text-textSecondary">{{ $row->summary ?: ($row->error_message ?: '-') }}</td>
                    <td class="px-3 py-2 text-textSecondary">
                        @if ($row->started_at && $row->finished_at)
                            {{ $row->finished_at->diffInMilliseconds($row->started_at) }} ms
                        @else
                            -
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-3 py-6 text-center text-textSecondary">No agent runs found for the current filters.</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
