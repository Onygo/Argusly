@extends('layouts.app', ['title' => 'Programmatic Opportunity'])

@section('pageHeader')
    <x-page-header :title="$opportunity->base_topic" eyebrow="Programmatic Opportunity">
        <x-slot:description>{{ str($opportunity->status)->headline() }}</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @php
        $pattern = $opportunity->pattern_type instanceof \App\Enums\ProgrammaticPatternType ? $opportunity->pattern_type : \App\Enums\ProgrammaticPatternType::tryFrom((string) $opportunity->pattern_type);
        $source = $opportunity->source;
        $sourceUrl = match (true) {
            $source instanceof \App\Models\Opportunity => route('app.opportunity-intelligence.opportunities.show', $source),
            default => null,
        };
    @endphp

    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('app.programmatic-opportunities.index', ['workspace_id' => $workspace->id]) }}" class="text-sm font-medium text-textSecondary hover:text-textPrimary">Programmatic Opportunities</a>
                <h2 class="mt-2 text-2xl font-semibold tracking-tight text-textPrimary">{{ $opportunity->base_topic }}</h2>
                <p class="mt-1 text-sm text-textSecondary">{{ $pattern?->label() ?? $opportunity->pattern_type }} · {{ str($opportunity->status)->headline() }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('approve', $opportunity)
                    <form method="POST" action="{{ route('app.programmatic-opportunities.validate', $opportunity) }}">@csrf<button class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white">Validate</button></form>
                    <form method="POST" action="{{ route('app.programmatic-opportunities.reject', $opportunity) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Reject</button></form>
                    <form method="POST" action="{{ route('app.programmatic-opportunities.growth-program', $opportunity) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Create Growth Program</button></form>
                @endcan
            </div>
        </div>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Detected Pattern</h2>
                    <dl class="mt-4 grid gap-4 md:grid-cols-2 text-sm">
                        <div><dt class="text-textSecondary">Pattern type</dt><dd class="mt-1 font-medium text-textPrimary">{{ $pattern?->label() }}</dd></div>
                        <div><dt class="text-textSecondary">Variable axis</dt><dd class="mt-1 font-medium text-textPrimary">{{ $opportunity->variable_axis ?: 'n/a' }}</dd></div>
                        <div><dt class="text-textSecondary">Estimated variants</dt><dd class="mt-1 font-medium text-textPrimary">{{ $opportunity->estimated_variants_count ?? 'n/a' }}</dd></div>
                        <div><dt class="text-textSecondary">Source</dt><dd class="mt-1 font-medium text-textPrimary">@if($sourceUrl)<a href="{{ $sourceUrl }}" class="text-primary hover:underline">{{ $source->title ?? class_basename($opportunity->source_type) }}</a>@else{{ $source->title ?? class_basename($opportunity->source_type) }}@endif</dd></div>
                    </dl>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Example Variables</h2>
                    <div class="mt-4 flex flex-wrap gap-2">
                        @foreach ((array) $opportunity->example_variables as $variable)
                            <span class="rounded-md border border-border bg-background px-2.5 py-1 text-xs text-textSecondary">{{ $variable }}</span>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Explanation</h2>
                    <pre class="mt-4 max-h-72 overflow-auto whitespace-pre-wrap rounded-md border border-border bg-background p-3 text-xs text-textSecondary">{{ json_encode($opportunity->explanation ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-lg border border-border bg-surface p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-sm font-semibold text-textPrimary">Cluster Preview</h2>
                            @if ($cluster)
                                <p class="mt-2 text-sm text-textSecondary">{{ $cluster->items_count }} items · impact {{ $cluster->estimated_business_impact === null ? 'n/a' : number_format((float) $cluster->estimated_business_impact, 1) }}</p>
                                <a href="{{ route('app.programmatic-clusters.show', $cluster) }}" class="mt-3 inline-flex text-sm font-medium text-primary hover:underline">Open cluster preview</a>
                            @else
                                <p class="mt-2 text-sm text-textSecondary">No cluster preview built yet.</p>
                            @endif
                        </div>
                        @can('update', $opportunity)
                            <form method="POST" action="{{ route('app.programmatic-clusters.build', $opportunity) }}">
                                @csrf
                                <button class="rounded-md border border-border bg-surfaceSubtle px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">Build</button>
                            </form>
                        @endcan
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Scores</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        @foreach ([
                            'scale_score' => 'Scale',
                            'business_value_score' => 'Business value',
                            'seo_opportunity_score' => 'SEO opportunity',
                            'ai_visibility_score' => 'AI visibility',
                            'competition_score' => 'Competition',
                            'confidence_score' => 'Confidence',
                        ] as $key => $label)
                            <div class="flex justify-between gap-3"><dt class="text-textSecondary">{{ $label }}</dt><dd class="font-medium text-textPrimary">{{ $opportunity->{$key} === null ? 'n/a' : number_format((float) $opportunity->{$key}, 1) }}</dd></div>
                        @endforeach
                    </dl>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Growth Program</h2>
                    @if ($opportunity->growthProgram)
                        <a href="{{ route('app.growth-programs.show', $opportunity->growthProgram) }}" class="mt-3 block text-sm font-medium text-primary hover:underline">{{ $opportunity->growthProgram->name }}</a>
                    @else
                        @can('update', $opportunity)
                        <form method="POST" action="{{ route('app.programmatic-opportunities.attach', $opportunity) }}" class="mt-4 space-y-2">
                            @csrf
                            <select name="growth_program_id" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                @foreach ($growthPrograms as $program)
                                    <option value="{{ $program->id }}">{{ $program->name }}</option>
                                @endforeach
                            </select>
                            <button class="w-full rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white">Attach</button>
                        </form>
                        @endcan
                    @endif
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Recommended Next Action</h2>
                    <p class="mt-3 text-sm leading-6 text-textSecondary">{{ $opportunity->growth_program_id ? 'Review this pattern inside the linked Growth Program.' : 'Validate the opportunity, then attach or create a Growth Program.' }}</p>
                </section>
            </aside>
        </div>
    </div>
@endsection
