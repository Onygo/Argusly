@extends('layouts.app', ['title' => 'Programmatic Brief Blueprints'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Programmatic Brief Blueprints</x-slot:title>
        <x-slot:description>Reusable briefing preparation for programmatic cluster items.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <form method="GET" action="{{ route('app.programmatic-brief-blueprints.index') }}" class="flex flex-wrap items-center gap-2">
                <input type="hidden" name="workspace_id" value="{{ $workspace->id }}">
                <select name="asset_type" class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">
                    <option value="">All asset types</option>
                    @foreach ($assetTypes as $assetType)
                        <option value="{{ $assetType->value }}" @selected(($filters['asset_type'] ?? '') === $assetType->value)>{{ $assetType->label() }}</option>
                    @endforeach
                </select>
                <select name="status" class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textPrimary">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->headline() }}</option>
                    @endforeach
                </select>
                <button class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white">Filter</button>
            </form>
        </div>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        <x-data-table label="Programmatic brief blueprints" description="Brief blueprints with asset type, status, cluster, intent, primary keyword, and readiness." density="compact">
                <x-data-table.header>
                    <x-data-table.row>
                        <x-data-table.cell heading>Title</x-data-table.cell>
                        <x-data-table.cell heading>Asset type</x-data-table.cell>
                        <x-data-table.cell heading>Status</x-data-table.cell>
                        <x-data-table.cell heading>Cluster</x-data-table.cell>
                        <x-data-table.cell heading>Intent</x-data-table.cell>
                        <x-data-table.cell heading>Primary keyword</x-data-table.cell>
                        <x-data-table.cell heading>Readiness</x-data-table.cell>
                    </x-data-table.row>
                </x-data-table.header>
                <tbody>
                        @forelse ($blueprints as $blueprint)
                            @php($type = $blueprint->growth_asset_type instanceof \App\Enums\GrowthAssetType ? $blueprint->growth_asset_type : \App\Enums\GrowthAssetType::tryFrom((string) $blueprint->growth_asset_type))
                            <x-data-table.row>
                                <x-data-table.cell label="Title" class="font-medium text-textPrimary"><a href="{{ route('app.programmatic-brief-blueprints.show', $blueprint) }}" class="hover:text-primary">{{ $blueprint->title }}</a></x-data-table.cell>
                                <x-data-table.cell label="Asset type" class="text-textSecondary">{{ $type?->label() ?? $blueprint->growth_asset_type }}</x-data-table.cell>
                                <x-data-table.cell label="Status">
                                    <x-data-table.badge :label="str($blueprint->status)->headline()" />
                                </x-data-table.cell>
                                <x-data-table.cell label="Cluster" class="text-textSecondary">{{ $blueprint->cluster?->name ?: 'n/a' }}</x-data-table.cell>
                                <x-data-table.cell label="Intent" class="text-textSecondary">{{ $blueprint->intent ?: 'n/a' }}</x-data-table.cell>
                                <x-data-table.cell label="Primary keyword" class="text-textSecondary">{{ $blueprint->primary_keyword ?: 'n/a' }}</x-data-table.cell>
                                <x-data-table.cell label="Readiness" class="text-textSecondary">{{ $blueprint->readinessPercentage() }}%</x-data-table.cell>
                            </x-data-table.row>
                        @empty
                            <x-data-table.empty colspan="7" title="No brief blueprints prepared yet" />
                        @endforelse
                </tbody>
            <x-slot:pagination>{{ $blueprints->links() }}</x-slot:pagination>
        </x-data-table>
    </div>
@endsection
