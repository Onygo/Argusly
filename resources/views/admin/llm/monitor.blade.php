@extends('layouts.admin', ['title' => 'LLM Monitor'])

@section('pageHeader')
    <x-page-header title="LLM Monitor" />
@endsection

@section('pageDescription')
    <x-page-description>Observe provider usage, token flow, estimated euro costs, credits, errors, and latency.</x-page-description>
@endsection

@section('filterBar')
    <form method="GET" class="grid gap-3 md:grid-cols-4 xl:grid-cols-8">
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
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Requests" :value="number_format($stats['total_requests'])" />
        <x-metric-card label="Input tokens" :value="number_format($stats['input_tokens'])" />
        <x-metric-card label="Output tokens" :value="number_format($stats['output_tokens'])" />
        <x-metric-card label="Total tokens" :value="number_format($stats['total_tokens'])" />
        <x-metric-card label="Input cost" :value="'€'.number_format($stats['input_cost_eur'], 4)" />
        <x-metric-card label="Output cost" :value="'€'.number_format($stats['output_cost_eur'], 4)" />
        <x-metric-card label="Total cost" :value="'€'.number_format($stats['total_cost_eur'], 4)" />
        <x-metric-card label="Credits consumed" :value="number_format($stats['credits_consumed'], 2)" />
        <x-metric-card label="Error rate" :value="number_format($stats['error_rate_pct'], 2).'%" />
        <x-metric-card label="Avg latency" :value="number_format($stats['avg_latency_ms']).' ms'" />
        <x-metric-card label="Top errors">
            <div class="space-y-1 text-xs">
                @forelse($topErrors as $error)
                    <p class="truncate">{{ $error->error_type }}: {{ $error->total }}</p>
                @empty
                    <p class="text-textSecondary">No errors</p>
                @endforelse
            </div>
        </x-metric-card>
    </x-metric-section>
@endsection

@section('content')
    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <x-data-table label="LLM request log" description="Filtered provider requests, costs, credits, latency, statuses, request IDs, and detail links." density="compact">
            <x-data-table.header>
            <x-data-table.row>
                <x-data-table.cell heading>Created</x-data-table.cell>
                <x-data-table.cell heading>Workspace</x-data-table.cell>
                <x-data-table.cell heading>Site</x-data-table.cell>
                <x-data-table.cell heading>Feature</x-data-table.cell>
                <x-data-table.cell heading>Provider/Model</x-data-table.cell>
                <x-data-table.cell heading>Input</x-data-table.cell>
                <x-data-table.cell heading>Output</x-data-table.cell>
                <x-data-table.cell heading>Total</x-data-table.cell>
                <x-data-table.cell heading>Cost</x-data-table.cell>
                <x-data-table.cell heading>Credits</x-data-table.cell>
                <x-data-table.cell heading>Latency</x-data-table.cell>
                <x-data-table.cell heading>Status</x-data-table.cell>
                <x-data-table.cell heading>Request ID</x-data-table.cell>
                <x-data-table.cell heading>Error code</x-data-table.cell>
                <x-data-table.cell heading><span class="sr-only">Actions</span></x-data-table.cell>
            </x-data-table.row>
            </x-data-table.header>
            <tbody>
            @forelse($rows as $row)
                <x-data-table.row>
                    <x-data-table.cell label="Created">{{ $row->created_at?->format('Y-m-d H:i:s') }}</x-data-table.cell>
                    <x-data-table.cell label="Workspace">{{ $row->workspace?->display_name ?: ($row->workspace?->name ?? '-') }}</x-data-table.cell>
                    <x-data-table.cell label="Site">{{ $row->site?->name ?? '-' }}</x-data-table.cell>
                    <x-data-table.cell label="Feature">{{ $row->feature }}</x-data-table.cell>
                    <x-data-table.cell label="Provider/Model">{{ $row->provider }}<br><span class="text-xs text-textSecondary">{{ $row->model ?: '-' }}</span></x-data-table.cell>
                    <x-data-table.cell label="Input">{{ number_format($row->input_tokens) }}</x-data-table.cell>
                    <x-data-table.cell label="Output">{{ number_format($row->output_tokens) }}</x-data-table.cell>
                    <x-data-table.cell label="Total">{{ number_format($row->total_tokens) }}</x-data-table.cell>
                    <x-data-table.cell label="Cost">&euro;{{ number_format((float) $row->total_cost_eur, 4) }}</x-data-table.cell>
                    <x-data-table.cell label="Credits">{{ number_format((float) $row->credits_consumed, 2) }}</x-data-table.cell>
                    <x-data-table.cell label="Latency">{{ $row->latency_ms ? number_format($row->latency_ms).' ms' : '-' }}</x-data-table.cell>
                    <x-data-table.cell label="Status">
                        <x-data-table.badge :tone="$row->status === 'success' ? 'success' : 'danger'" :label="$row->status" />
                    </x-data-table.cell>
                    <x-data-table.cell label="Request ID">{{ $row->request_id ?: '-' }}</x-data-table.cell>
                    <x-data-table.cell label="Error code">{{ $row->error_code ?: '-' }}</x-data-table.cell>
                    <x-data-table.cell label="Actions"><x-data-table.actions><a href="{{ route('admin.llm.monitor.show', $row) }}" class="underline">Details</a></x-data-table.actions></x-data-table.cell>
                </x-data-table.row>
            @empty
                <x-data-table.empty colspan="15" title="No LLM requests found" description="No LLM requests match the current filters." />
            @endforelse
            </tbody>
        <x-slot:pagination>{{ $rows->links() }}</x-slot:pagination>
    </x-data-table>

@endsection
