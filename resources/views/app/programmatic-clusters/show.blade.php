@extends('layouts.app', ['title' => $cluster->name])

@section('content')
    @php($pattern = $cluster->pattern_type instanceof \App\Enums\ProgrammaticPatternType ? $cluster->pattern_type : \App\Enums\ProgrammaticPatternType::tryFrom((string) $cluster->pattern_type))

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('app.programmatic-clusters.index', ['workspace_id' => $workspace->id]) }}" class="text-sm font-medium text-textSecondary hover:text-textPrimary">Programmatic Clusters</a>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-textPrimary">{{ $cluster->name }}</h1>
                <p class="mt-1 text-sm text-textSecondary">{{ $pattern?->label() ?? $cluster->pattern_type }} · {{ str($cluster->status)->headline() }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('app.programmatic-clusters.validate', $cluster) }}">@csrf<button class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white">Validate</button></form>
                <form method="POST" action="{{ route('app.programmatic-clusters.reject', $cluster) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Reject</button></form>
                <form method="POST" action="{{ route('app.programmatic-clusters.brief-blueprints.build', $cluster) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Build Blueprints</button></form>
                <form method="POST" action="{{ route('app.programmatic-clusters.convert-approved-blueprints', $cluster) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Convert Approved</button></form>
                <form method="POST" action="{{ route('app.programmatic-clusters.prepare-draft-requests', $cluster) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Prepare Draft Requests</button></form>
                <form method="POST" action="{{ route('app.programmatic-clusters.generate-approved-requests', $cluster) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Generate Approved Requests</button></form>
                <form method="POST" action="{{ route('app.programmatic-clusters.review-generated-drafts', $cluster) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Review Generated Drafts</button></form>
                <form method="POST" action="{{ route('app.programmatic-clusters.convert-approved-reviews-to-content', $cluster) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Convert Approved Reviews</button></form>
                <form method="POST" action="{{ route('app.programmatic-clusters.publication-readiness', $cluster) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Run Publication Readiness</button></form>
                <form method="POST" action="{{ route('app.programmatic-clusters.publication-plans.create', $cluster) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Create Publication Plan</button></form>
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
                    <h2 class="text-sm font-semibold text-textPrimary">Cluster Summary</h2>
                    <dl class="mt-4 grid gap-4 md:grid-cols-2 text-sm">
                        <div><dt class="text-textSecondary">Linked opportunity</dt><dd class="mt-1 font-medium text-textPrimary"><a href="{{ route('app.programmatic-opportunities.show', $cluster->programmaticOpportunity) }}" class="text-primary hover:underline">{{ $cluster->programmaticOpportunity?->base_topic }}</a></dd></div>
                        <div><dt class="text-textSecondary">Variable axis</dt><dd class="mt-1 font-medium text-textPrimary">{{ $cluster->variable_axis ?: 'n/a' }}</dd></div>
                        <div><dt class="text-textSecondary">Estimated assets</dt><dd class="mt-1 font-medium text-textPrimary">{{ $cluster->estimated_assets_count }}</dd></div>
                        <div><dt class="text-textSecondary">Confidence</dt><dd class="mt-1 font-medium text-textPrimary">{{ $cluster->confidence_score === null ? 'n/a' : number_format((float) $cluster->confidence_score, 1) }}</dd></div>
                    </dl>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-sm font-semibold text-textPrimary">Item Preview</h2>
                        <form method="GET" action="{{ route('app.programmatic-clusters.show', $cluster) }}" class="flex flex-wrap items-center gap-2">
                            <select name="asset_type" class="rounded-md border border-border bg-background px-3 py-2 text-xs text-textPrimary">
                                <option value="">All asset types</option>
                                @foreach ($assetTypes as $assetType)
                                    <option value="{{ $assetType->value }}" @selected(($itemFilters['asset_type'] ?? '') === $assetType->value)>{{ $assetType->label() }}</option>
                                @endforeach
                            </select>
                            <select name="priority" class="rounded-md border border-border bg-background px-3 py-2 text-xs text-textPrimary">
                                <option value="">All priorities</option>
                                <option value="high" @selected(($itemFilters['priority'] ?? '') === 'high')>High</option>
                                <option value="medium" @selected(($itemFilters['priority'] ?? '') === 'medium')>Medium</option>
                                <option value="low" @selected(($itemFilters['priority'] ?? '') === 'low')>Low</option>
                            </select>
                            <select name="status" class="rounded-md border border-border bg-background px-3 py-2 text-xs text-textPrimary">
                                <option value="">All statuses</option>
                                @foreach ([\App\Models\ProgrammaticClusterItem::STATUS_PREVIEW, \App\Models\ProgrammaticClusterItem::STATUS_ACCEPTED, \App\Models\ProgrammaticClusterItem::STATUS_REJECTED, \App\Models\ProgrammaticClusterItem::STATUS_PLANNED] as $status)
                                    <option value="{{ $status }}" @selected(($itemFilters['status'] ?? '') === $status)>{{ str($status)->headline() }}</option>
                                @endforeach
                            </select>
                            <select name="duplicate_risk" class="rounded-md border border-border bg-background px-3 py-2 text-xs text-textPrimary">
                                <option value="">All duplicate risk</option>
                                <option value="high" @selected(($itemFilters['duplicate_risk'] ?? '') === 'high')>High</option>
                                <option value="medium" @selected(($itemFilters['duplicate_risk'] ?? '') === 'medium')>Medium</option>
                                <option value="low" @selected(($itemFilters['duplicate_risk'] ?? '') === 'low')>Low</option>
                            </select>
                            <button class="rounded-md bg-primary px-3 py-2 text-xs font-semibold text-white">Filter</button>
                        </form>
                    </div>
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-border text-sm">
                            <thead>
                                <tr class="text-left text-xs uppercase tracking-wide text-textSecondary">
                                    <th class="py-2 pr-4">Title</th>
                                    <th class="py-2 pr-4">Asset type</th>
                                    <th class="py-2 pr-4">Intent</th>
                                    <th class="py-2 pr-4">Word count</th>
                                    <th class="py-2 pr-4">Schema</th>
                                    <th class="py-2 pr-4">Internal role</th>
                                    <th class="py-2 pr-4">CTA</th>
                                    <th class="py-2 pr-4">SEO readiness</th>
                                    <th class="py-2 pr-4">AI readiness</th>
                                    <th class="py-2 pr-4">Blueprint</th>
                                    <th class="py-2 pr-4">Draft request</th>
                                    <th class="py-2 pr-4">Review</th>
                                    <th class="py-2 pr-4">Content</th>
                                    <th class="py-2 pr-4">Readiness</th>
                                    <th class="py-2 pr-4">Duplicate risk</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                @foreach ($cluster->items as $item)
                                    @php($growthAssetType = $item->growth_asset_type instanceof \App\Enums\GrowthAssetType ? $item->growth_asset_type : \App\Enums\GrowthAssetType::tryFrom((string) $item->growth_asset_type))
                                    <tr>
                                        <td class="py-2 pr-4 font-medium text-textPrimary">{{ $item->title }}<div class="text-xs text-textMuted">{{ $item->slug }}</div></td>
                                        <td class="py-2 pr-4 text-textSecondary">{{ $growthAssetType?->label() ?? ($item->asset_type ?: 'n/a') }}</td>
                                        <td class="py-2 pr-4 text-textSecondary">{{ $item->intent }}</td>
                                        <td class="py-2 pr-4 text-textSecondary">
                                            @if ($item->recommended_word_count_min !== null && $item->recommended_word_count_max !== null)
                                                {{ number_format($item->recommended_word_count_min) }}-{{ number_format($item->recommended_word_count_max) }}
                                            @else
                                                n/a
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-textSecondary">{{ collect($item->recommended_schema_types ?? [])->join(', ') ?: 'n/a' }}</td>
                                        <td class="py-2 pr-4 text-textSecondary">{{ $item->internal_linking_role ?: 'n/a' }}</td>
                                        <td class="py-2 pr-4 text-textSecondary">{{ $item->recommended_cta ?: 'n/a' }}</td>
                                        <td class="py-2 pr-4 text-textSecondary">{{ count($item->seo_requirements ?? []) }} checks · {{ number_format((float) $item->seo_score, 1) }}</td>
                                        <td class="py-2 pr-4 text-textSecondary">{{ count($item->ai_visibility_requirements ?? []) }} checks · {{ number_format((float) $item->ai_visibility_score, 1) }}</td>
                                        <td class="py-2 pr-4 text-textSecondary">
                                            @if ($item->briefBlueprint)
                                                @php($linkedBrief = $item->briefBlueprint->linkedBrief())
                                                <a href="{{ route('app.programmatic-brief-blueprints.show', $item->briefBlueprint) }}" class="font-medium text-primary hover:underline">{{ str($item->briefBlueprint->status)->headline() }}</a>
                                                @if ($linkedBrief)
                                                    <div class="mt-1 text-xs"><a href="{{ route('app.content.workspace.show', $linkedBrief) }}" class="text-primary hover:underline">Brief</a></div>
                                                @endif
                                            @else
                                                <form method="POST" action="{{ route('app.programmatic-brief-blueprints.build.item', $item) }}">
                                                    @csrf
                                                    <button class="text-xs font-medium text-primary hover:underline">Build</button>
                                                </form>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-textSecondary">
                                            @if ($item->briefBlueprint?->draftRequest)
                                                <a href="{{ route('app.programmatic-draft-requests.show', $item->briefBlueprint->draftRequest) }}" class="font-medium text-primary hover:underline">{{ str($item->briefBlueprint->draftRequest->status)->headline() }}</a>
                                                @if ($draft = $item->briefBlueprint->draftRequest->linkedDraft())
                                                    <div class="mt-1 text-xs"><a href="{{ route('app.drafts.show', $draft) }}" class="text-primary hover:underline">Draft</a></div>
                                                @endif
                                            @else
                                                not prepared
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-textSecondary">
                                            @if ($review = $item->briefBlueprint?->draftRequest?->review)
                                                <a href="{{ route('app.programmatic-draft-reviews.show', $review) }}" class="font-medium text-primary hover:underline">{{ str($review->status)->headline() }}</a>
                                            @else
                                                not reviewed
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-textSecondary">
                                            @if (($review = $item->briefBlueprint?->draftRequest?->review) && ($content = $review->linkedContent()))
                                                <a href="{{ route('app.content.show', $content) }}" class="font-medium text-primary hover:underline">{{ str($content->status)->headline() }}</a>
                                            @else
                                                not converted
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-textSecondary">
                                            @if (($readiness = $item->briefBlueprint?->draftRequest?->review?->publicationReadiness))
                                                <a href="{{ route('app.programmatic-publication-readiness.show', $readiness) }}" class="font-medium text-primary hover:underline">{{ str($readiness->status)->headline() }}</a>
                                                <div class="mt-1 text-xs">{{ number_format((float) $readiness->readiness_score, 1) }} readiness</div>
                                            @else
                                                not checked
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-textSecondary">{{ number_format((float) $item->duplicate_risk_score, 1) }}</td>
                                    </tr>
                                @endforeach
                                @if ($cluster->items->isEmpty())
                                    <tr>
                                        <td colspan="15" class="py-6 text-center text-sm text-textMuted">No items match these filters.</td>
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Scores</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-textSecondary">Reach</dt><dd class="font-medium text-textPrimary">{{ $cluster->estimated_reach === null ? 'n/a' : number_format((float) $cluster->estimated_reach, 0) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-textSecondary">AI visibility</dt><dd class="font-medium text-textPrimary">{{ $cluster->estimated_ai_visibility === null ? 'n/a' : number_format((float) $cluster->estimated_ai_visibility, 1) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-textSecondary">Business impact</dt><dd class="font-medium text-textPrimary">{{ $cluster->estimated_business_impact === null ? 'n/a' : number_format((float) $cluster->estimated_business_impact, 1) }}</dd></div>
                    </dl>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Growth Program</h2>
                    @if ($cluster->growthProgram)
                        <a href="{{ route('app.growth-programs.show', $cluster->growthProgram) }}" class="mt-3 block text-sm font-medium text-primary hover:underline">{{ $cluster->growthProgram->name }}</a>
                    @else
                        <form method="POST" action="{{ route('app.programmatic-clusters.attach', $cluster) }}" class="mt-4 space-y-2">
                            @csrf
                            <select name="growth_program_id" class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm">
                                @foreach ($growthPrograms as $program)
                                    <option value="{{ $program->id }}">{{ $program->name }}</option>
                                @endforeach
                            </select>
                            <button class="w-full rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white">Attach</button>
                        </form>
                    @endif
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Recommended Next Action</h2>
                    <p class="mt-3 text-sm leading-6 text-textSecondary">{{ $cluster->status === 'validated' ? 'Attach this cluster to a Growth Program and move it into planning when ready.' : 'Validate or reject this preview before planning.' }}</p>
                </section>
            </aside>
        </div>
    </div>
@endsection
