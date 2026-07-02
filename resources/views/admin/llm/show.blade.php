@extends('layouts.admin', ['title' => 'LLM Request Detail'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>LLM Request Detail</x-slot:title>
        <x-slot:description>Safe request diagnostics with scoped debug data.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
        <a href="{{ route('admin.llm.monitor') }}" class="inline-flex items-center rounded-md border border-border px-4 py-2 text-sm">Back to monitor</a>
@endsection

@section('content')

    <div class="rounded-lg border border-border bg-surface p-5 space-y-5">
        <dl class="grid gap-3 md:grid-cols-2 xl:grid-cols-4 text-sm">
            <div><dt class="text-textSecondary">Created</dt><dd>{{ $entry->created_at?->format('Y-m-d H:i:s') }}</dd></div>
            <div><dt class="text-textSecondary">Feature</dt><dd>{{ $entry->feature }}</dd></div>
            <div><dt class="text-textSecondary">Provider</dt><dd>{{ $entry->provider }}</dd></div>
            <div><dt class="text-textSecondary">Model</dt><dd>{{ $entry->model ?: '-' }}</dd></div>
            <div><dt class="text-textSecondary">Workspace</dt><dd>{{ $entry->workspace?->name ?? '-' }}</dd></div>
            <div><dt class="text-textSecondary">Site</dt><dd>{{ $entry->site?->name ?? '-' }}</dd></div>
            <div><dt class="text-textSecondary">Status</dt><dd>{{ $entry->status }}</dd></div>
            <div><dt class="text-textSecondary">Latency</dt><dd>{{ $entry->latency_ms ? $entry->latency_ms.' ms' : '-' }}</dd></div>
            <div><dt class="text-textSecondary">Input tokens</dt><dd>{{ number_format($entry->input_tokens) }}</dd></div>
            <div><dt class="text-textSecondary">Output tokens</dt><dd>{{ number_format($entry->output_tokens) }}</dd></div>
            <div><dt class="text-textSecondary">Total tokens</dt><dd>{{ number_format($entry->total_tokens) }}</dd></div>
            <div><dt class="text-textSecondary">Input cost</dt><dd>&euro;{{ number_format((float) $entry->input_cost_eur, 6) }}</dd></div>
            <div><dt class="text-textSecondary">Output cost</dt><dd>&euro;{{ number_format((float) $entry->output_cost_eur, 6) }}</dd></div>
            <div><dt class="text-textSecondary">Total cost</dt><dd>&euro;{{ number_format((float) $entry->total_cost_eur, 6) }}</dd></div>
            <div><dt class="text-textSecondary">Credits</dt><dd>{{ number_format((float) $entry->credits_consumed, 2) }}</dd></div>
            <div><dt class="text-textSecondary">Request ID</dt><dd>{{ $entry->request_id ?: '-' }}</dd></div>
            <div><dt class="text-textSecondary">Job ID</dt><dd>{{ $entry->job_id ?: '-' }}</dd></div>
            <div><dt class="text-textSecondary">Retry count</dt><dd>{{ $entry->retry_count }}</dd></div>
            <div><dt class="text-textSecondary">Error code</dt><dd>{{ $entry->error_code ?: '-' }}</dd></div>
        </dl>

        <div>
            <h2 class="text-sm font-semibold">Safe excerpts</h2>
            <p class="text-xs text-textSecondary mt-1">Prompt content is hidden by design. Only prompt/message lengths are shown.</p>
            <pre class="mt-2 overflow-auto rounded-md border border-border bg-background p-3 text-xs">{{ json_encode([
    'message_count' => data_get($entry->metadata, 'message_count'),
    'prompt_chars_total' => data_get($entry->metadata, 'prompt_chars_total'),
    'meta' => data_get($entry->metadata, 'meta'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>

        @if ($canViewDebug)
            <div>
                <h2 class="text-sm font-semibold">Debug provider response (redacted)</h2>
                <pre class="mt-2 max-h-[420px] overflow-auto rounded-md border border-border bg-background p-3 text-xs">{{ json_encode(data_get($entry->metadata, 'provider_raw'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </div>
        @endif

        @if ($entry->error_message)
            <div>
                <h2 class="text-sm font-semibold">Error</h2>
                <p class="mt-2 rounded-md border border-rose-400/30 bg-rose-500/5 p-3 text-sm text-rose-700">{{ $entry->error_message }}</p>
            </div>
        @endif
    </div>
@endsection
