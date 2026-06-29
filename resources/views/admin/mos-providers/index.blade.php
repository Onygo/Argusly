@extends('layouts.admin', ['title' => 'MOS Providers'])

@section('content')
    <div class="mb-6 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">MOS Providers</h1>
            <p class="mt-1 text-textSecondary">Read-only registry diagnostics for MOS provider consolidation.</p>
        </div>
        <a href="{{ route('admin.system-health.index') }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">System health</a>
    </div>

    <div class="mb-4 rounded-lg border border-border bg-surface p-4">
        <p class="text-xs uppercase tracking-wide text-textFaint">Duplicate warnings</p>
        @if ($duplicate_warnings === [])
            <p class="mt-2 text-sm font-medium text-success">None detected</p>
        @else
            <ul class="mt-2 space-y-1 text-sm text-danger">
                @foreach ($duplicate_warnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="overflow-x-auto rounded-lg border border-border bg-surface">
        <table class="min-w-full text-left text-sm">
            <thead>
            <tr class="border-b border-border text-xs uppercase tracking-wide text-textFaint">
                <th class="px-3 py-2">Key</th>
                <th class="px-3 py-2">Domain</th>
                <th class="px-3 py-2">Capabilities</th>
                <th class="px-3 py-2">Priority</th>
                <th class="px-3 py-2">Class</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($providers as $provider)
                <tr class="border-b border-border/60 align-top">
                    <td class="px-3 py-2 font-medium text-textPrimary">{{ $provider['key'] }}</td>
                    <td class="px-3 py-2 text-textSecondary">{{ $provider['domain'] }}</td>
                    <td class="px-3 py-2 text-textSecondary">{{ $provider['capabilities_list'] }}</td>
                    <td class="px-3 py-2 text-textSecondary">{{ $provider['priority'] }}</td>
                    <td class="px-3 py-2 font-mono text-xs text-textSecondary">{{ $provider['class'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if ($opportunity_providers !== [])
        <div class="mt-6 overflow-x-auto rounded-lg border border-border bg-surface">
            <table class="min-w-full text-left text-sm">
                <thead>
                <tr class="border-b border-border text-xs uppercase tracking-wide text-textFaint">
                    <th class="px-3 py-2">Opportunity provider</th>
                    <th class="px-3 py-2">Legacy model</th>
                    <th class="px-3 py-2">Classification</th>
                    <th class="px-3 py-2">Readiness</th>
                    <th class="px-3 py-2">Canonical</th>
                    <th class="px-3 py-2">Signal</th>
                    <th class="px-3 py-2">Risk</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($opportunity_providers as $provider)
                    <tr class="border-b border-border/60 align-top">
                        <td class="px-3 py-2 font-medium text-textPrimary">{{ $provider['provider_key'] }}</td>
                        <td class="px-3 py-2 font-mono text-xs text-textSecondary">{{ class_basename($provider['legacy_model']) }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ $provider['classification'] }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ $provider['readiness'] }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ $provider['can_emit_canonical_payload'] ? 'yes' : 'no' }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ $provider['can_emit_signal'] ? 'yes' : 'no' }}</td>
                        <td class="px-3 py-2 text-textSecondary">{{ $provider['risk_level'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
