@extends('layouts.admin', ['title' => 'Pending Job'])

@section('pageHeader')
    <x-page-header title="Pending job detail">
        <x-slot:description>{{ $job['job_name'] }}</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <a href="{{ route('admin.queues.index', request()->query()) }}" class="pl-btn-secondary">Back to queues</a>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Queue" :value="$job['queue']" />
        <x-metric-card label="Attempts" :value="$job['attempts']" />
        <x-metric-card label="Created" :value="$job['created_at']?->format('Y-m-d H:i:s') ?? 'Unknown'" />
        <x-metric-card label="Org / Site" :value="$job['org_site']" />
    </x-metric-section>
@endsection

@section('content')
    @if (session('status'))
        <x-alert class="mb-4">{{ session('status') }}</x-alert>
    @endif
    @if ($errors->has('queues'))
        <div class="mb-4 rounded-md border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-800">{{ $errors->first('queues') }}</div>
    @endif

    <div class="mb-6 rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Context</h2>
        <div class="mt-3 grid gap-2 text-sm md:grid-cols-3">
            <div>Organization: <strong>{{ $job['context']['organization_id'] ?? 'Unknown' }}</strong></div>
            <div>Site: <strong>{{ $job['context']['site_id'] ?? 'Unknown' }}</strong></div>
            <div>Workspace: <strong>{{ $job['context']['workspace_id'] ?? 'Unknown' }}</strong></div>
            <div>Brief: <strong>{{ $job['context']['brief_id'] ?? 'Unknown' }}</strong></div>
            <div>Draft: <strong>{{ $job['context']['draft_id'] ?? 'Unknown' }}</strong></div>
            <div>Reserved: <strong>{{ $job['reserved_at'] ? $job['reserved_at']->format('Y-m-d H:i:s') : 'No' }}</strong></div>
        </div>
    </div>

    @if (! empty($job['payload_summary'] ?? []))
        <div class="mb-6 rounded-lg border border-border bg-surface p-4">
            <h2 class="text-sm font-semibold text-textPrimary">Job metadata</h2>
            <dl class="mt-3 grid gap-3 text-sm md:grid-cols-2">
                @foreach ($job['payload_summary'] as $label => $value)
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-textFaint">{{ str_replace('_', ' ', $label) }}</dt>
                        <dd class="mt-1 break-all font-mono text-xs text-textPrimary">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif

    <div class="mb-6 rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Payload JSON</h2>
        <pre class="mt-3 overflow-x-auto whitespace-pre-wrap rounded border border-border bg-background p-3 text-xs text-textSecondary">{{ $job['payload_json'] }}</pre>
    </div>

    <div class="flex flex-wrap gap-2">
        <form method="POST" action="{{ route('admin.queues.pending.destroy', [$job['id']] + request()->query()) }}" onsubmit="return confirm('Delete pending job {{ $job['id'] }}?');">
            @csrf
            @method('DELETE')
            <button type="submit" class="rounded border border-danger/30 px-3 py-2 text-sm text-danger hover:bg-danger/5">Delete job</button>
        </form>
    </div>
@endsection
