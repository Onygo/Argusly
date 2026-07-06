@extends('layouts.app', ['title' => $drawer['title'] ?? 'Monitored page'])

@section('pageHeader')
    <x-page-header :title="$drawer['title'] ?? 'Monitored page'">
        <x-slot:description>{{ $drawer['subtitle'] ?? $monitoredPage->canonical_url }}</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    @if ($monitoredPage->canonical_url)
        <a href="{{ $monitoredPage->canonical_url }}" target="_blank" rel="noopener noreferrer" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Open URL</a>
    @endif
@endsection

@section('content')
    <div class="space-y-6">
        <section class="rounded-lg border border-border bg-surface p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">Universal Resource</p>
                    <h2 class="mt-2 text-xl font-semibold text-textPrimary">{{ $resource['title'] ?? $drawer['title'] }}</h2>
                    <p class="mt-1 break-all text-sm text-textSecondary">{{ $resource['subtitle'] ?? $drawer['subtitle'] }}</p>
                </div>
                @if (! empty($resource['status']['label']))
                    <x-data-table.badge :label="$resource['status']['label']" />
                @endif
            </div>
        </section>

        <x-data-table label="Monitored page inspection metadata" description="Drawer-ready metadata for the canonical monitored page resource." density="compact">
            <x-data-table.header>
                <x-data-table.row>
                    <x-data-table.cell heading>Section</x-data-table.cell>
                    <x-data-table.cell heading>Field</x-data-table.cell>
                    <x-data-table.cell heading>Value</x-data-table.cell>
                </x-data-table.row>
            </x-data-table.header>
            <tbody>
                @foreach (($drawer['sections'] ?? []) as $section)
                    @foreach (($section['items'] ?? []) as $item)
                        <x-data-table.row>
                            <x-data-table.cell label="Section">{{ $section['title'] ?? $section['key'] ?? '-' }}</x-data-table.cell>
                            <x-data-table.cell label="Field">{{ $item['label'] ?? '-' }}</x-data-table.cell>
                            <x-data-table.cell label="Value">
                                <span class="break-words">{{ $item['value'] ?? '-' }}</span>
                            </x-data-table.cell>
                        </x-data-table.row>
                    @endforeach
                @endforeach
            </tbody>
        </x-data-table>
    </div>
@endsection
