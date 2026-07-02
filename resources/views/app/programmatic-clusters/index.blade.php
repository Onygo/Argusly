@extends('layouts.app', ['title' => 'Programmatic Clusters'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Programmatic Clusters</x-slot:title>
        <x-slot:description>Preview clusters for scalable programmatic assets.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">

        <form method="GET" action="{{ route('app.programmatic-clusters.index') }}" class="grid gap-3 rounded-lg border border-border bg-surface p-4 md:grid-cols-4">
            <select name="workspace_id" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
                @foreach ($workspaces as $item)
                    <option value="{{ $item->id }}" @selected((string) $workspace->id === (string) $item->id)>{{ $item->display_name ?: $item->name }}</option>
                @endforeach
            </select>
            <select name="pattern_type" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
                <option value="">All patterns</option>
                @foreach ($patternTypes as $type)
                    <option value="{{ $type->value }}" @selected(($filters['pattern_type'] ?? '') === $type->value)>{{ $type->label() }}</option>
                @endforeach
            </select>
            <select name="status" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ str($status)->headline() }}</option>
                @endforeach
            </select>
            <button class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white">Filter</button>
        </form>

        <section class="space-y-3">
            @forelse ($clusters as $cluster)
                @php($pattern = $cluster->pattern_type instanceof \App\Enums\ProgrammaticPatternType ? $cluster->pattern_type : \App\Enums\ProgrammaticPatternType::tryFrom((string) $cluster->pattern_type))
                <a href="{{ route('app.programmatic-clusters.show', $cluster) }}" class="block rounded-lg border border-border bg-surface p-4 hover:bg-surfaceMuted">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">{{ $pattern?->label() ?? $cluster->pattern_type }}</p>
                            <h2 class="mt-1 text-base font-semibold text-textPrimary">{{ $cluster->name }}</h2>
                            <p class="mt-1 text-sm text-textSecondary">{{ $cluster->items_count }} items · {{ str($cluster->status)->headline() }}</p>
                        </div>
                        <div class="text-right text-xs text-textSecondary">
                            <p>Reach {{ $cluster->estimated_reach === null ? 'n/a' : number_format((float) $cluster->estimated_reach, 0) }}</p>
                            <p>AI {{ $cluster->estimated_ai_visibility === null ? 'n/a' : number_format((float) $cluster->estimated_ai_visibility, 1) }}</p>
                        </div>
                    </div>
                </a>
            @empty
                <div class="rounded-lg border border-dashed border-border bg-surface p-8 text-center text-sm text-textSecondary">No programmatic cluster previews yet.</div>
            @endforelse
        </section>

        {{ $clusters->links() }}
    </div>
@endsection
