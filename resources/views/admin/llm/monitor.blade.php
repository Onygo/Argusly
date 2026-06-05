@extends('layouts.admin', ['title' => 'LLM Monitor'])

@section('content')
    <div class="mb-6">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">LLM Monitor</h1>
        <p class="text-sm text-textSecondary mt-1">Observe provider usage, token flow, estimated euro costs, credits, errors, and latency.</p>
    </div>

    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <form method="GET" class="mb-6 grid gap-3 rounded-lg border border-border bg-surface p-4 md:grid-cols-4 xl:grid-cols-8">
        <input type="date" name="from" value="{{ $filters['from'] }}" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
        <input type="date" name="to" value="{{ $filters['to'] }}" class="rounded-md border border-border bg-background px-3 py-2 text-sm">

        <select name="workspace_id" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">All workspaces</option>
            @foreach ($workspaces as $workspace)
                <option value="{{ $workspace->id }}" @selected($filters['workspace_id'] === (string) $workspace->id)>{{ $workspace->display_name ?: $workspace->name }}</option>
            @endforeach
        </select>

        <select name="site_id" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">All sites</option>
            @foreach ($sites as $site)
                <option value="{{ $site->id }}" @selected($filters['site_id'] === (string) $site->id)>{{ $site->name }}</option>
            @endforeach
        </select>

        <select name="feature" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">All features</option>
            @foreach ($features as $feature)
                <option value="{{ $feature }}" @selected($filters['feature'] === $feature)>{{ $feature }}</option>
            @endforeach
        </select>

        <select name="provider" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">All providers</option>
            @foreach ($providers as $provider)
                <option value="{{ $provider }}" @selected($filters['provider'] === $provider)>{{ $provider }}</option>
            @endforeach
        </select>

        <select name="model" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">All models</option>
            @foreach ($models as $model)
                <option value="{{ $model }}" @selected($filters['model'] === $model)>{{ $model }}</option>
            @endforeach
        </select>

        <select name="status" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
            <option value="">All status</option>
            <option value="success" @selected($filters['status'] === 'success')>Success</option>
            <option value="error" @selected($filters['status'] === 'error')>Error</option>
        </select>

        <div class="md:col-span-4 xl:col-span-8 flex gap-2">
            <button class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-textInverse">Apply filters</button>
            <a href="{{ route('admin.llm.monitor') }}" class="inline-flex items-center rounded-md border border-border px-4 py-2 text-sm font-medium">Reset</a>
        </div>
    </form>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-8">
        <div class="rounded-lg border border-border bg-surface p-4"><p class="text-xs text-textSecondary">Requests</p><p class="text-2xl font-semibold">{{ number_format($stats['total_requests']) }}</p></div>
        <div class="rounded-lg border border-border bg-surface p-4"><p class="text-xs text-textSecondary">Input tokens</p><p class="text-2xl font-semibold">{{ number_format($stats['input_tokens']) }}</p></div>
        <div class="rounded-lg border border-border bg-surface p-4"><p class="text-xs text-textSecondary">Output tokens</p><p class="text-2xl font-semibold">{{ number_format($stats['output_tokens']) }}</p></div>
        <div class="rounded-lg border border-border bg-surface p-4"><p class="text-xs text-textSecondary">Total tokens</p><p class="text-2xl font-semibold">{{ number_format($stats['total_tokens']) }}</p></div>
        <div class="rounded-lg border border-border bg-surface p-4"><p class="text-xs text-textSecondary">Input cost</p><p class="text-2xl font-semibold">&euro;{{ number_format($stats['input_cost_eur'], 4) }}</p></div>
        <div class="rounded-lg border border-border bg-surface p-4"><p class="text-xs text-textSecondary">Output cost</p><p class="text-2xl font-semibold">&euro;{{ number_format($stats['output_cost_eur'], 4) }}</p></div>
        <div class="rounded-lg border border-border bg-surface p-4"><p class="text-xs text-textSecondary">Total cost</p><p class="text-2xl font-semibold">&euro;{{ number_format($stats['total_cost_eur'], 4) }}</p></div>
        <div class="rounded-lg border border-border bg-surface p-4"><p class="text-xs text-textSecondary">Credits consumed</p><p class="text-2xl font-semibold">{{ number_format($stats['credits_consumed'], 2) }}</p></div>
        <div class="rounded-lg border border-border bg-surface p-4"><p class="text-xs text-textSecondary">Error rate</p><p class="text-2xl font-semibold">{{ number_format($stats['error_rate_pct'], 2) }}%</p></div>
        <div class="rounded-lg border border-border bg-surface p-4"><p class="text-xs text-textSecondary">Avg latency</p><p class="text-2xl font-semibold">{{ number_format($stats['avg_latency_ms']) }} ms</p></div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-xs text-textSecondary">Top errors</p>
            <div class="mt-2 space-y-1 text-xs">
                @forelse($topErrors as $error)
                    <p class="truncate">{{ $error->error_type }}: {{ $error->total }}</p>
                @empty
                    <p class="text-textSecondary">No errors</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border bg-surface">
        <table class="w-full text-sm">
            <thead class="text-left text-textSecondary">
            <tr>
                <th class="px-3 py-2">Created</th>
                <th class="px-3 py-2">Workspace</th>
                <th class="px-3 py-2">Site</th>
                <th class="px-3 py-2">Feature</th>
                <th class="px-3 py-2">Provider/Model</th>
                <th class="px-3 py-2">Input</th>
                <th class="px-3 py-2">Output</th>
                <th class="px-3 py-2">Total</th>
                <th class="px-3 py-2">Cost</th>
                <th class="px-3 py-2">Credits</th>
                <th class="px-3 py-2">Latency</th>
                <th class="px-3 py-2">Status</th>
                <th class="px-3 py-2">Request ID</th>
                <th class="px-3 py-2">Error code</th>
                <th class="px-3 py-2"></th>
            </tr>
            </thead>
            <tbody class="divide-y divide-border">
            @forelse($rows as $row)
                <tr>
                    <td class="px-3 py-2">{{ $row->created_at?->format('Y-m-d H:i:s') }}</td>
                    <td class="px-3 py-2">{{ $row->workspace?->display_name ?: ($row->workspace?->name ?? '-') }}</td>
                    <td class="px-3 py-2">{{ $row->site?->name ?? '-' }}</td>
                    <td class="px-3 py-2">{{ $row->feature }}</td>
                    <td class="px-3 py-2">{{ $row->provider }}<br><span class="text-xs text-textSecondary">{{ $row->model ?: '-' }}</span></td>
                    <td class="px-3 py-2">{{ number_format($row->input_tokens) }}</td>
                    <td class="px-3 py-2">{{ number_format($row->output_tokens) }}</td>
                    <td class="px-3 py-2">{{ number_format($row->total_tokens) }}</td>
                    <td class="px-3 py-2">&euro;{{ number_format((float) $row->total_cost_eur, 4) }}</td>
                    <td class="px-3 py-2">{{ number_format((float) $row->credits_consumed, 2) }}</td>
                    <td class="px-3 py-2">{{ $row->latency_ms ? number_format($row->latency_ms).' ms' : '-' }}</td>
                    <td class="px-3 py-2">
                        <span class="rounded px-2 py-1 text-xs {{ $row->status === 'success' ? 'bg-emerald-500/10 text-emerald-700' : 'bg-rose-500/10 text-rose-700' }}">{{ $row->status }}</span>
                    </td>
                    <td class="px-3 py-2">{{ $row->request_id ?: '-' }}</td>
                    <td class="px-3 py-2">{{ $row->error_code ?: '-' }}</td>
                    <td class="px-3 py-2"><a href="{{ route('admin.llm.monitor.show', $row) }}" class="underline">Details</a></td>
                </tr>
            @empty
                <tr><td colspan="15" class="px-3 py-6 text-center text-textSecondary">No LLM requests found for current filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
