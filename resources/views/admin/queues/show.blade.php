@extends('layouts.admin', ['title' => 'Failed Job'])

@section('pageHeader')
    <x-page-header title="Failed job detail">
        <x-slot:description>{{ $job['job_name'] }}</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <a href="{{ route('admin.queues.index', request()->query()) }}" class="pl-btn-secondary">Back to queues</a>
@endsection

@section('metricSection')
    <x-metric-section>
        <x-metric-card label="Failed at" :value="$job['failed_at']" />
        <x-metric-card label="Queue / connection" :value="$job['queue'].' / '.$job['connection']" />
        <x-metric-card label="Attempts" :value="$job['attempts']" />
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
            <div>UUID: <strong class="font-mono text-xs">{{ $job['uuid'] ?: 'n/a' }}</strong></div>
        </div>
    </div>

    <div class="mb-6 rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Error summary</h2>
        <p class="mt-2 text-sm text-rose-800">{{ $job['error_summary'] }}</p>
    </div>

    <div class="mb-6 rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Exception and stack trace</h2>
        <pre class="mt-3 overflow-x-auto whitespace-pre-wrap rounded border border-border bg-background p-3 text-xs text-textSecondary">{{ $job['exception'] }}</pre>
    </div>

    <div class="mb-6 rounded-lg border border-border bg-surface p-4">
        <h2 class="text-sm font-semibold text-textPrimary">Redacted payload JSON</h2>
        <pre class="mt-3 overflow-x-auto whitespace-pre-wrap rounded border border-border bg-background p-3 text-xs text-textSecondary">{{ $job['payload_json'] }}</pre>
    </div>

    <div class="flex flex-wrap gap-2">
        <form method="POST" action="{{ route('admin.queues.retry', $job['id']) }}">
            @csrf
            <button type="submit" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Retry job</button>
        </form>
        <form method="POST" action="{{ route('admin.queues.destroy', $job['id']) }}" onsubmit="return confirm('Delete this failed job record?');">
            @csrf
            @method('DELETE')
            <button type="submit" class="rounded border border-danger/30 px-3 py-2 text-sm text-danger hover:bg-danger/5">Delete record</button>
        </form>
    </div>
@endsection
