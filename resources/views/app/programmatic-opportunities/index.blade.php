@extends('layouts.app', ['title' => 'Programmatic Opportunities'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>Programmatic Opportunities</x-slot:title>
        <x-slot:description>Detected scalable opportunity patterns.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
        </div>

        <form method="GET" action="{{ route('app.programmatic-opportunities.index') }}" class="grid gap-3 rounded-lg border border-border bg-surface p-4 md:grid-cols-5">
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
            <select name="linked" class="rounded-md border border-border bg-background px-3 py-2 text-sm">
                <option value="">Linked and unlinked</option>
                <option value="linked" @selected(($filters['linked'] ?? '') === 'linked')>Linked</option>
                <option value="unlinked" @selected(($filters['linked'] ?? '') === 'unlinked')>Unlinked</option>
            </select>
            <button class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white">Filter</button>
        </form>

        <section class="space-y-3">
            @forelse ($opportunities as $opportunity)
                @php($pattern = $opportunity->pattern_type instanceof \App\Enums\ProgrammaticPatternType ? $opportunity->pattern_type : \App\Enums\ProgrammaticPatternType::tryFrom((string) $opportunity->pattern_type))
                <a href="{{ route('app.programmatic-opportunities.show', $opportunity) }}" class="block rounded-lg border border-border bg-surface p-4 hover:bg-surfaceMuted">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-textSecondary">{{ $pattern?->label() ?? $opportunity->pattern_type }}</p>
                            <h2 class="mt-1 text-base font-semibold text-textPrimary">{{ $opportunity->base_topic }}</h2>
                            <p class="mt-1 text-sm text-textSecondary">{{ $opportunity->variable_axis ?: 'No variable axis' }} · {{ $opportunity->estimated_variants_count ?? 'n/a' }} variants</p>
                        </div>
                        <div class="text-right text-sm">
                            <p class="font-semibold text-textPrimary">{{ number_format((float) ($opportunity->scale_score ?? 0), 1) }}</p>
                            <p class="text-xs text-textSecondary">{{ str($opportunity->status)->headline() }}</p>
                        </div>
                    </div>
                </a>
            @empty
                <div class="rounded-lg border border-dashed border-border bg-surface p-8 text-center text-sm text-textSecondary">No programmatic opportunities detected yet.</div>
            @endforelse
        </section>

        {{ $opportunities->links() }}
    </div>
@endsection
