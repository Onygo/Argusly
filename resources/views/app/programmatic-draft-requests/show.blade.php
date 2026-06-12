@extends('layouts.app', ['title' => $draftRequest->title])

@section('content')
    @php($type = $draftRequest->growth_asset_type instanceof \App\Enums\GrowthAssetType ? $draftRequest->growth_asset_type : \App\Enums\GrowthAssetType::tryFrom((string) $draftRequest->growth_asset_type))
    @php($linkedDraft = $draftRequest->linkedDraft())
    @php($review = $draftRequest->review)

    @include('app.programmatic-growth._beta-banner', ['class' => 'mb-6'])

    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('app.programmatic-draft-requests.index', ['workspace_id' => $workspace->id]) }}" class="text-sm font-medium text-textSecondary hover:text-textPrimary">Draft Requests</a>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-textPrimary">{{ $draftRequest->title }}</h1>
                <p class="mt-1 text-sm text-textSecondary">{{ $type?->label() ?? $draftRequest->growth_asset_type }} · {{ str($draftRequest->status)->headline() }} · {{ str($draftRequest->generation_mode)->headline() }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @can('approve', $draftRequest)
                    <form method="POST" action="{{ route('app.programmatic-draft-requests.approve', $draftRequest) }}">@csrf<button class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white">Approve</button></form>
                    <form method="POST" action="{{ route('app.programmatic-draft-requests.reject', $draftRequest) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Reject</button></form>
                    <form method="POST" action="{{ route('app.programmatic-draft-requests.cancel', $draftRequest) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Cancel</button></form>
                @endcan
                @if ($draftRequest->status === \App\Models\ProgrammaticDraftRequest::STATUS_APPROVED)
                    @can('prepare', $draftRequest)
                        <form method="POST" action="{{ route('app.programmatic-draft-requests.generate', $draftRequest) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Generate Draft</button></form>
                    @endcan
                @endif
                @if ($draftRequest->status === \App\Models\ProgrammaticDraftRequest::STATUS_GENERATED)
                    @can('prepare', $draftRequest)
                        <form method="POST" action="{{ route('app.programmatic-draft-reviews.run.request', $draftRequest) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Run Review</button></form>
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
                    <h2 class="text-sm font-semibold text-textPrimary">Request Summary</h2>
                    <dl class="mt-4 grid gap-4 md:grid-cols-2 text-sm">
                        <div><dt class="text-textSecondary">Linked Brief</dt><dd class="mt-1 font-medium text-textPrimary">@if ($draftRequest->brief)<a href="{{ route('app.content.workspace.show', $draftRequest->brief) }}" class="text-primary hover:underline">{{ $draftRequest->brief->title }}</a>@else n/a @endif</dd></div>
                        <div><dt class="text-textSecondary">Linked Blueprint</dt><dd class="mt-1 font-medium text-textPrimary">@if ($draftRequest->blueprint)<a href="{{ route('app.programmatic-brief-blueprints.show', $draftRequest->blueprint) }}" class="text-primary hover:underline">Blueprint</a>@else n/a @endif</dd></div>
                        <div><dt class="text-textSecondary">Cluster Item</dt><dd class="mt-1 font-medium text-textPrimary">{{ $draftRequest->item?->title ?: 'n/a' }}</dd></div>
                        <div><dt class="text-textSecondary">Priority</dt><dd class="mt-1 font-medium text-textPrimary">{{ number_format((float) $draftRequest->priority_score, 1) }}</dd></div>
                        <div><dt class="text-textSecondary">Estimated tokens</dt><dd class="mt-1 font-medium text-textPrimary">{{ number_format((int) $draftRequest->estimated_tokens) }}</dd></div>
                        <div><dt class="text-textSecondary">Estimated cost</dt><dd class="mt-1 font-medium text-textPrimary">€{{ number_format((float) $draftRequest->estimated_cost, 4) }}</dd></div>
                        <div><dt class="text-textSecondary">Linked Draft</dt><dd class="mt-1 font-medium text-textPrimary">@if ($linkedDraft)<a href="{{ route('app.drafts.show', $linkedDraft) }}" class="text-primary hover:underline">{{ $linkedDraft->title }}</a>@else n/a @endif</dd></div>
                        <div><dt class="text-textSecondary">Generation status</dt><dd class="mt-1 font-medium text-textPrimary">{{ str($draftRequest->status)->headline() }}</dd></div>
                        <div><dt class="text-textSecondary">Review status</dt><dd class="mt-1 font-medium text-textPrimary">@if ($review)<a href="{{ route('app.programmatic-draft-reviews.show', $review) }}" class="text-primary hover:underline">{{ str($review->status)->headline() }}</a>@else not reviewed @endif</dd></div>
                    </dl>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Safety Metadata</h2>
                    <dl class="mt-4 grid gap-3 md:grid-cols-2 text-sm">
                        @foreach ($draftRequest->metadata ?? [] as $key => $value)
                            <div>
                                <dt class="text-textSecondary">{{ str($key)->headline() }}</dt>
                                <dd class="mt-1 font-medium text-textPrimary">{{ is_bool($value) ? ($value ? 'Yes' : 'No') : (is_array($value) ? json_encode($value) : ($value ?? 'n/a')) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            </div>

            <aside class="space-y-6">
                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Safety Limits</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-textSecondary">Manual approval</dt><dd class="font-medium text-textPrimary">{{ config('argusly_programmatic.require_manual_approval') ? 'Required' : 'Optional' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-textSecondary">Batch generation</dt><dd class="font-medium text-textPrimary">{{ config('argusly_programmatic.allow_batch_generation') ? 'Allowed' : 'Disabled' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-textSecondary">Cost warning</dt><dd class="font-medium text-textPrimary">€{{ number_format((float) config('argusly_programmatic.estimated_cost_warning_threshold'), 2) }}</dd></div>
                    </dl>
                </section>
            </aside>
        </div>
    </div>
@endsection
