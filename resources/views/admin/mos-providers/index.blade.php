@extends('layouts.admin', ['title' => 'MOS Providers'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>MOS Providers</x-slot:title>
        <x-slot:description>Read-only registry diagnostics for MOS provider consolidation.</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
        <a href="{{ route('admin.system-health.index') }}" class="rounded border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">System health</a>
@endsection

@section('content')

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

    <x-data-table label="MOS providers" description="Read-only MOS provider registry diagnostics with domain, capabilities, priority, and backing class." density="compact">
        <x-data-table.header>
            <x-data-table.row>
                <x-data-table.cell heading>Key</x-data-table.cell>
                <x-data-table.cell heading>Domain</x-data-table.cell>
                <x-data-table.cell heading>Capabilities</x-data-table.cell>
                <x-data-table.cell heading>Priority</x-data-table.cell>
                <x-data-table.cell heading>Class</x-data-table.cell>
            </x-data-table.row>
        </x-data-table.header>
        <tbody class="divide-y divide-border">
        @foreach ($providers as $provider)
            <x-data-table.row>
                <x-data-table.cell label="Key" class="font-medium text-textPrimary">{{ $provider['key'] }}</x-data-table.cell>
                <x-data-table.cell label="Domain" class="text-textSecondary">{{ $provider['domain'] }}</x-data-table.cell>
                <x-data-table.cell label="Capabilities" class="text-textSecondary">{{ $provider['capabilities_list'] }}</x-data-table.cell>
                <x-data-table.cell label="Priority" class="text-textSecondary">{{ $provider['priority'] }}</x-data-table.cell>
                <x-data-table.cell label="Class" class="font-mono text-xs text-textSecondary">{{ $provider['class'] }}</x-data-table.cell>
            </x-data-table.row>
        @endforeach
        </tbody>
    </x-data-table>

    @if ($opportunity_providers !== [])
        <x-data-table label="Opportunity providers" description="Opportunity provider consolidation diagnostics with legacy model, classification, readiness, canonical payload, signal, and risk." density="compact" class="mt-6">
            <x-data-table.header>
                <x-data-table.row>
                    <x-data-table.cell heading>Opportunity provider</x-data-table.cell>
                    <x-data-table.cell heading>Legacy model</x-data-table.cell>
                    <x-data-table.cell heading>Classification</x-data-table.cell>
                    <x-data-table.cell heading>Readiness</x-data-table.cell>
                    <x-data-table.cell heading>Canonical</x-data-table.cell>
                    <x-data-table.cell heading>Signal</x-data-table.cell>
                    <x-data-table.cell heading>Risk</x-data-table.cell>
                </x-data-table.row>
            </x-data-table.header>
            <tbody class="divide-y divide-border">
            @foreach ($opportunity_providers as $provider)
                <x-data-table.row>
                    <x-data-table.cell label="Opportunity provider" class="font-medium text-textPrimary">{{ $provider['provider_key'] }}</x-data-table.cell>
                    <x-data-table.cell label="Legacy model" class="font-mono text-xs text-textSecondary">{{ class_basename($provider['legacy_model']) }}</x-data-table.cell>
                    <x-data-table.cell label="Classification" class="text-textSecondary">{{ $provider['classification'] }}</x-data-table.cell>
                    <x-data-table.cell label="Readiness" class="text-textSecondary">{{ $provider['readiness'] }}</x-data-table.cell>
                    <x-data-table.cell label="Canonical" class="text-textSecondary">{{ $provider['can_emit_canonical_payload'] ? 'yes' : 'no' }}</x-data-table.cell>
                    <x-data-table.cell label="Signal" class="text-textSecondary">{{ $provider['can_emit_signal'] ? 'yes' : 'no' }}</x-data-table.cell>
                    <x-data-table.cell label="Risk" class="text-textSecondary">{{ $provider['risk_level'] }}</x-data-table.cell>
                </x-data-table.row>
            @endforeach
            </tbody>
        </x-data-table>
    @endif
@endsection
