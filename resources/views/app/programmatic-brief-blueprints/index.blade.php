@extends('layouts.app', ['title' => 'Programmatic Brief Blueprints'])

@section('content')
    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">Programmatic Brief Blueprints</h1>
                <p class="mt-1 text-sm text-textSecondary">Reusable briefing preparation for programmatic cluster items.</p>
            </div>
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

        <section class="rounded-lg border border-border bg-surface p-5">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-border text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-textSecondary">
                            <th class="py-2 pr-4">Title</th>
                            <th class="py-2 pr-4">Asset type</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4">Cluster</th>
                            <th class="py-2 pr-4">Intent</th>
                            <th class="py-2 pr-4">Primary keyword</th>
                            <th class="py-2 pr-4">Readiness</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($blueprints as $blueprint)
                            @php($type = $blueprint->growth_asset_type instanceof \App\Enums\GrowthAssetType ? $blueprint->growth_asset_type : \App\Enums\GrowthAssetType::tryFrom((string) $blueprint->growth_asset_type))
                            <tr>
                                <td class="py-2 pr-4 font-medium text-textPrimary"><a href="{{ route('app.programmatic-brief-blueprints.show', $blueprint) }}" class="hover:text-primary">{{ $blueprint->title }}</a></td>
                                <td class="py-2 pr-4 text-textSecondary">{{ $type?->label() ?? $blueprint->growth_asset_type }}</td>
                                <td class="py-2 pr-4 text-textSecondary">{{ str($blueprint->status)->headline() }}</td>
                                <td class="py-2 pr-4 text-textSecondary">{{ $blueprint->cluster?->name ?: 'n/a' }}</td>
                                <td class="py-2 pr-4 text-textSecondary">{{ $blueprint->intent ?: 'n/a' }}</td>
                                <td class="py-2 pr-4 text-textSecondary">{{ $blueprint->primary_keyword ?: 'n/a' }}</td>
                                <td class="py-2 pr-4 text-textSecondary">{{ $blueprint->readinessPercentage() }}%</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-6 text-center text-sm text-textMuted">No brief blueprints prepared yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $blueprints->links() }}</div>
        </section>
    </div>
@endsection
