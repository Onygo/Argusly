@extends('layouts.app', ['title' => $blueprint->title])

@section('content')
    @php($type = $blueprint->growth_asset_type instanceof \App\Enums\GrowthAssetType ? $blueprint->growth_asset_type : \App\Enums\GrowthAssetType::tryFrom((string) $blueprint->growth_asset_type))
    @php($linkedBrief = $blueprint->linkedBrief())
    @php($draftRequest = $blueprint->draftRequest)

    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('app.programmatic-brief-blueprints.index', ['workspace_id' => $workspace->id]) }}" class="text-sm font-medium text-textSecondary hover:text-textPrimary">Brief Blueprints</a>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-textPrimary">{{ $blueprint->title }}</h1>
                <p class="mt-1 text-sm text-textSecondary">{{ $type?->label() ?? $blueprint->growth_asset_type }} · {{ str($blueprint->status)->headline() }} · {{ $blueprint->readinessPercentage() }}% readiness</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('prepare', $blueprint)
                    <form method="POST" action="{{ route('app.programmatic-brief-blueprints.review', $blueprint) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Review</button></form>
                @endcan
                @can('approve', $blueprint)
                    <form method="POST" action="{{ route('app.programmatic-brief-blueprints.approve', $blueprint) }}">@csrf<button class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white">Approve</button></form>
                    <form method="POST" action="{{ route('app.programmatic-brief-blueprints.reject', $blueprint) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Reject</button></form>
                @endcan
                @if (in_array($blueprint->status, [\App\Models\ProgrammaticBriefBlueprint::STATUS_APPROVED, \App\Models\ProgrammaticBriefBlueprint::STATUS_CONVERTED], true))
                    @can('convert', $blueprint)
                        <form method="POST" action="{{ route('app.programmatic-brief-blueprints.convert', $blueprint) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Convert to Brief</button></form>
                    @endcan
                @endif
                @if ($blueprint->status === \App\Models\ProgrammaticBriefBlueprint::STATUS_CONVERTED)
                    @can('prepare', $blueprint)
                        <form method="POST" action="{{ route('app.programmatic-draft-requests.prepare.blueprint', $blueprint) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Prepare Draft Request</button></form>
                    @endcan
                @endif
            </div>
        </div>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif
        @if ($errors->any())
            <x-alert type="error">{{ $errors->first() }}</x-alert>
        @endif

        <div class="grid gap-6 xl:grid-cols-3">
            <div class="space-y-6 xl:col-span-2">
                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Blueprint Summary</h2>
                    <dl class="mt-4 grid gap-4 md:grid-cols-2 text-sm">
                        <div><dt class="text-textSecondary">Linked cluster item</dt><dd class="mt-1 font-medium text-textPrimary">{{ $blueprint->item?->title ?: 'n/a' }}</dd></div>
                        <div><dt class="text-textSecondary">Cluster</dt><dd class="mt-1 font-medium text-textPrimary">@if ($blueprint->cluster)<a href="{{ route('app.programmatic-clusters.show', $blueprint->cluster) }}" class="text-primary hover:underline">{{ $blueprint->cluster->name }}</a>@else n/a @endif</dd></div>
                        <div><dt class="text-textSecondary">Intent</dt><dd class="mt-1 font-medium text-textPrimary">{{ $blueprint->intent ?: 'n/a' }}</dd></div>
                        <div><dt class="text-textSecondary">Audience</dt><dd class="mt-1 font-medium text-textPrimary">{{ $blueprint->audience ?: 'n/a' }}</dd></div>
                        <div><dt class="text-textSecondary">Primary keyword</dt><dd class="mt-1 font-medium text-textPrimary">{{ $blueprint->primary_keyword ?: 'n/a' }}</dd></div>
                        <div><dt class="text-textSecondary">CTA recommendation</dt><dd class="mt-1 font-medium text-textPrimary">{{ $blueprint->cta_recommendation ?: 'n/a' }}</dd></div>
                        <div><dt class="text-textSecondary">Linked Brief</dt><dd class="mt-1 font-medium text-textPrimary">@if ($linkedBrief)<a href="{{ route('app.content.workspace.show', $linkedBrief) }}" class="text-primary hover:underline">{{ $linkedBrief->title }}</a>@else n/a @endif</dd></div>
                        <div><dt class="text-textSecondary">Draft Request</dt><dd class="mt-1 font-medium text-textPrimary">@if ($draftRequest)<a href="{{ route('app.programmatic-draft-requests.show', $draftRequest) }}" class="text-primary hover:underline">{{ str($draftRequest->status)->headline() }}</a>@else n/a @endif</dd></div>
                    </dl>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Outline</h2>
                    <div class="mt-4 space-y-2">
                        @foreach ($blueprint->outline ?? [] as $section)
                            <div class="rounded-md border border-border bg-background p-3">
                                <p class="text-sm font-medium text-textPrimary">{{ $section['heading'] ?? 'Section' }}</p>
                                <p class="mt-1 text-xs text-textSecondary">{{ $section['level'] ?? 'h2' }} · {{ $section['purpose'] ?? 'n/a' }}</p>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Internal Linking Plan</h2>
                    <dl class="mt-4 grid gap-3 md:grid-cols-2 text-sm">
                        @foreach ($blueprint->internal_linking_plan ?? [] as $key => $value)
                            <div>
                                <dt class="text-textSecondary">{{ str($key)->headline() }}</dt>
                                <dd class="mt-1 font-medium text-textPrimary">{{ is_bool($value) ? ($value ? 'Yes' : 'No') : ($value ?: 'n/a') }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            </div>

            <aside class="space-y-6">
                @foreach ([
                    'Required Sections' => $blueprint->required_sections ?? [],
                    'FAQ Questions' => $blueprint->faq_questions ?? [],
                    'Schema Recommendations' => $blueprint->schema_recommendations ?? [],
                    'SEO Requirements' => $blueprint->seo_requirements ?? [],
                    'AI Visibility Requirements' => $blueprint->ai_visibility_requirements ?? [],
                    'Quality Requirements' => $blueprint->quality_requirements ?? [],
                ] as $label => $items)
                    <section class="rounded-lg border border-border bg-surface p-5">
                        <h2 class="text-sm font-semibold text-textPrimary">{{ $label }}</h2>
                        <ul class="mt-4 space-y-2 text-sm text-textSecondary">
                            @forelse ($items as $item)
                                <li class="rounded-md border border-border bg-background px-3 py-2">{{ $item }}</li>
                            @empty
                                <li class="text-textMuted">No entries.</li>
                            @endforelse
                        </ul>
                    </section>
                @endforeach
            </aside>
        </div>
    </div>
@endsection
