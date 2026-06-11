@extends('layouts.app', ['title' => 'Publication Readiness'])

@section('content')
    @php($type = $readiness->growth_asset_type instanceof \App\Enums\GrowthAssetType ? $readiness->growth_asset_type : \App\Enums\GrowthAssetType::tryFrom((string) $readiness->growth_asset_type))

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('app.programmatic-publication-readiness.index', ['workspace_id' => $workspace->id]) }}" class="text-sm font-medium text-textSecondary hover:text-textPrimary">Publication Readiness</a>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-textPrimary">{{ $readiness->content?->title }}</h1>
                <p class="mt-1 text-sm text-textSecondary">{{ str($readiness->status)->headline() }} · {{ $type?->label() ?? 'Programmatic asset' }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('app.programmatic-publication-readiness.approve', $readiness) }}">@csrf<button class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white">Approve</button></form>
                <form method="POST" action="{{ route('app.programmatic-publication-readiness.needs-work', $readiness) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Needs Work</button></form>
                <form method="POST" action="{{ route('app.programmatic-publication-readiness.block', $readiness) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Block</button></form>
                <form method="POST" action="{{ route('app.programmatic-publication-readiness.reject', $readiness) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Reject</button></form>
                @if ($readiness->status === \App\Models\ProgrammaticPublicationReadiness::STATUS_APPROVED)
                    <form method="POST" action="{{ route('app.programmatic-publication-plans.create.readiness', $readiness) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Create Plan from Content</button></form>
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
                    <h2 class="text-sm font-semibold text-textPrimary">Scores</h2>
                    <dl class="mt-4 grid gap-4 md:grid-cols-3 text-sm">
                        @foreach ([
                            'readiness_score' => 'Readiness',
                            'seo_score' => 'SEO',
                            'schema_score' => 'Schema',
                            'internal_linking_score' => 'Internal linking',
                            'destination_readiness_score' => 'Destination',
                            'publication_risk_score' => 'Publication risk',
                        ] as $key => $label)
                            <div><dt class="text-textSecondary">{{ $label }}</dt><dd class="mt-1 font-medium text-textPrimary">{{ number_format((float) $readiness->{$key}, 1) }}</dd></div>
                        @endforeach
                    </dl>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Checks</h2>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        @foreach ($readiness->checks ?? [] as $group => $checks)
                            <div class="rounded-md border border-border bg-background p-3">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-textSecondary">{{ str($group)->headline() }}</h3>
                                <ul class="mt-2 space-y-1 text-sm text-textSecondary">
                                    @foreach ($checks as $key => $passed)
                                        <li>{{ $passed ? 'Pass' : 'Fail' }} · {{ str($key)->headline() }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Recommendations</h2>
                    <ul class="mt-4 space-y-2 text-sm text-textSecondary">
                        @forelse ($readiness->recommendations ?? [] as $item)
                            <li class="rounded-md border border-border bg-background px-3 py-2">{{ $item }}</li>
                        @empty
                            <li class="text-textMuted">No recommendations.</li>
                        @endforelse
                    </ul>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Links</h2>
                    <div class="mt-4 space-y-2 text-sm">
                        @if ($readiness->content)<a href="{{ route('app.content.show', $readiness->content) }}" class="block text-primary hover:underline">Linked Content</a>@endif
                        @if ($readiness->review)<a href="{{ route('app.programmatic-draft-reviews.show', $readiness->review) }}" class="block text-primary hover:underline">Linked Review</a>@endif
                        @if ($readiness->request)<a href="{{ route('app.programmatic-draft-requests.show', $readiness->request) }}" class="block text-primary hover:underline">Linked Draft Request</a>@endif
                        @if ($readiness->cluster)<a href="{{ route('app.programmatic-clusters.show', $readiness->cluster) }}" class="block text-primary hover:underline">Linked Cluster</a>@endif
                        @if ($readiness->growthProgram)<a href="{{ route('app.growth-programs.show', $readiness->growthProgram) }}" class="block text-primary hover:underline">Linked Growth Program</a>@endif
                    </div>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Missing Requirements</h2>
                    <ul class="mt-4 space-y-2 text-sm text-textSecondary">
                        @forelse ($readiness->missing_requirements ?? [] as $item)
                            <li class="rounded-md border border-border bg-background px-3 py-2">{{ $item }}</li>
                        @empty
                            <li class="text-textMuted">No missing requirements.</li>
                        @endforelse
                    </ul>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Approval</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-textSecondary">Approved by</dt><dd class="font-medium text-textPrimary">{{ $readiness->approver?->name ?? 'n/a' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-textSecondary">Approved at</dt><dd class="font-medium text-textPrimary">{{ $readiness->approved_at?->format('Y-m-d H:i') ?? 'n/a' }}</dd></div>
                    </dl>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Publication Plans</h2>
                    <div class="mt-4 space-y-2 text-sm">
                        @forelse ($readiness->planItems as $planItem)
                            <a href="{{ route('app.programmatic-publication-plans.show', $planItem->plan) }}" class="block rounded-md border border-border bg-background px-3 py-2 text-primary hover:underline">
                                {{ $planItem->plan?->name }} · {{ $planItem->planned_publish_at?->format('Y-m-d H:i') ?? 'manual' }}
                            </a>
                        @empty
                            <p class="text-textMuted">No publication plan item yet.</p>
                        @endforelse
                    </div>
                    @if ($readiness->status === \App\Models\ProgrammaticPublicationReadiness::STATUS_APPROVED)
                        <form method="POST" action="{{ route('app.programmatic-publication-plans.create.readiness', $readiness) }}" class="mt-4 space-y-2">
                            @csrf
                            <select name="cadence" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                @foreach (\App\Models\ProgrammaticPublicationPlan::cadences() as $cadence)
                                    <option value="{{ $cadence }}" @selected($cadence === config('argusly_programmatic.default_publication_cadence'))>{{ str($cadence)->replace('_', ' ')->headline() }}</option>
                                @endforeach
                            </select>
                            <input type="datetime-local" name="planned_start_at" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                            <button class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm font-medium text-textPrimary">Add to Publication Plan</button>
                        </form>
                    @endif
                </section>
            </aside>
        </div>
    </div>
@endsection
