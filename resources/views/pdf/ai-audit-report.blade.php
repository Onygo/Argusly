<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Argusly AI Audit Report</title>
    <style>
        body { font-family: Arial, sans-serif; color: #111827; font-size: 12px; line-height: 1.45; }
        h1 { font-size: 24px; margin: 0 0 4px; }
        h2 { font-size: 15px; margin: 22px 0 8px; border-bottom: 1px solid #e5e7eb; padding-bottom: 5px; }
        h3 { font-size: 12px; margin: 12px 0 4px; }
        .muted { color: #6b7280; }
        .badge { display: inline-block; border: 1px solid #d1d5db; border-radius: 999px; padding: 3px 8px; font-weight: bold; }
        .grid { width: 100%; border-collapse: collapse; margin-top: 8px; }
        .grid th, .grid td { border: 1px solid #e5e7eb; padding: 7px; text-align: left; vertical-align: top; }
        .grid th { background: #f9fafb; font-size: 10px; text-transform: uppercase; color: #4b5563; }
        .mono { font-family: "Courier New", monospace; font-size: 10px; word-break: break-all; }
        .score { font-size: 34px; font-weight: bold; margin: 8px 0; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    <h1>Argusly AI Audit Report</h1>
    <div class="muted">Generated {{ $generatedAt->format('Y-m-d H:i') }}@if($generatedBy) by {{ $generatedBy->name }}@endif</div>

    <h2>Asset Summary</h2>
    <table class="grid">
        <tr><th>Title</th><td>{{ $record->content?->title ?? $payload['record']['title'] ?? 'Untitled' }}</td></tr>
        <tr><th>Asset ID</th><td class="mono">{{ $record->asset_id }}</td></tr>
        <tr><th>AI Badge</th><td><span class="badge">{{ $record->ai_badge }}</span></td></tr>
        <tr><th>Disclosure</th><td>{{ $record->disclosure_label }}</td></tr>
        <tr><th>Human Review</th><td>{{ str_replace('_', ' ', $record->human_review_status) }}</td></tr>
        <tr><th>Fact-check</th><td>{{ str_replace('_', ' ', $record->fact_check_status) }}</td></tr>
        <tr><th>Content Hash</th><td class="mono">{{ $record->content_hash }}</td></tr>
    </table>

    <h2>AI Trust Score</h2>
    <div class="score">{{ $record->trust_score }}/100</div>
    <table class="grid">
        <tr><th>Component</th><th>Points</th></tr>
        @foreach(($record->score_breakdown ?? []) as $label => $value)
            <tr><td>{{ str_replace('_', ' ', ucfirst($label)) }}</td><td>{{ $value }}</td></tr>
        @endforeach
    </table>

    <h2>Provenance Timeline</h2>
    <table class="grid">
        <tr><th>Time</th><th>Event</th><th>Summary</th><th>Output hash</th></tr>
        @forelse($payload['timeline'] as $event)
            <tr>
                <td>{{ $event['occurred_at'] }}</td>
                <td>{{ str_replace('_', ' ', $event['type']) }}</td>
                <td>{{ $event['summary'] }}</td>
                <td class="mono">{{ $event['output_hash'] }}</td>
            </tr>
        @empty
            <tr><td colspan="4">No provenance events recorded.</td></tr>
        @endforelse
    </table>

    <h2>Model History</h2>
    <table class="grid">
        <tr><th>Time</th><th>Provider</th><th>Model</th><th>Run ID</th></tr>
        @forelse($payload['model_history'] as $run)
            <tr>
                <td>{{ $run['ran_at'] }}</td>
                <td>{{ $run['provider'] }}</td>
                <td>{{ $run['model'] }} {{ $run['model_version'] }}</td>
                <td class="mono">{{ $run['run_id'] }}</td>
            </tr>
        @empty
            <tr><td colspan="4">No model runs recorded.</td></tr>
        @endforelse
    </table>

    <h2>Prompt History</h2>
    <table class="grid">
        <tr><th>Version</th><th>Type</th><th>Prompt hash</th><th>Summary</th></tr>
        @forelse($payload['prompt_history'] as $prompt)
            <tr>
                <td>{{ $prompt['version'] }}</td>
                <td>{{ $prompt['prompt_type'] }}</td>
                <td class="mono">{{ $prompt['prompt_hash'] }}</td>
                <td>{{ $prompt['summary'] }}</td>
            </tr>
        @empty
            <tr><td colspan="4">No prompt history recorded.</td></tr>
        @endforelse
    </table>

    <h2>Source Trace</h2>
    <table class="grid">
        <tr><th>Title</th><th>URL</th><th>Status</th><th>Reliability</th></tr>
        @forelse($payload['source_trace'] as $source)
            <tr>
                <td>{{ $source['title'] }}</td>
                <td class="mono">{{ $source['url'] }}</td>
                <td>{{ $source['retrieval_status'] }}</td>
                <td>{{ $source['reliability_score'] }}</td>
            </tr>
        @empty
            <tr><td colspan="4">No sources recorded.</td></tr>
        @endforelse
    </table>

    <h2>Machine-readable Metadata</h2>
    <pre class="mono">{{ json_encode($payload['record']['machine_metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
</body>
</html>
