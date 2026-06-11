@extends('layouts.app', ['title' => 'Draft Review'])

@section('content')
    <div class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <a href="{{ route('app.programmatic-draft-reviews.index', ['workspace_id' => $workspace->id]) }}" class="text-sm font-medium text-textSecondary hover:text-textPrimary">Draft Reviews</a>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-textPrimary">{{ $review->draft?->title }}</h1>
                <p class="mt-1 text-sm text-textSecondary">{{ str($review->status)->headline() }} · overall {{ number_format((float) $review->overall_score, 1) }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('app.programmatic-draft-reviews.approve', $review) }}">@csrf<button class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white">Approve Review</button></form>
                @if (in_array($review->status, [\App\Models\ProgrammaticDraftReview::STATUS_APPROVED], true))
                    <form method="POST" action="{{ route('app.programmatic-draft-reviews.convert-to-content', $review) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Convert to Content</button></form>
                @endif
                @if ($content = $review->linkedContent())
                    <form method="POST" action="{{ route('app.programmatic-publication-readiness.run.content', $content) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Run Publication Readiness</button></form>
                @endif
                <form method="POST" action="{{ route('app.programmatic-draft-reviews.needs-work', $review) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Mark Needs Work</button></form>
                <form method="POST" action="{{ route('app.programmatic-draft-reviews.block', $review) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Block</button></form>
                <form method="POST" action="{{ route('app.programmatic-draft-reviews.reject', $review) }}">@csrf<button class="rounded-md border border-border bg-surface px-3 py-2 text-sm text-textPrimary">Reject</button></form>
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
                            'overall_score' => 'Overall',
                            'completeness_score' => 'Completeness',
                            'seo_score' => 'SEO',
                            'ai_visibility_score' => 'AI visibility',
                            'duplication_score' => 'Duplication',
                            'brand_fit_score' => 'Brand fit',
                            'schema_readiness_score' => 'Schema',
                            'internal_linking_score' => 'Internal linking',
                            'risk_score' => 'Risk',
                        ] as $key => $label)
                            <div><dt class="text-textSecondary">{{ $label }}</dt><dd class="mt-1 font-medium text-textPrimary">{{ number_format((float) $review->{$key}, 1) }}</dd></div>
                        @endforeach
                    </dl>
                </section>

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Checks</h2>
                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                        @foreach ($review->checks ?? [] as $group => $checks)
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
            </div>

            <aside class="space-y-6">
                @foreach (['Blocking Issues' => $review->blocking_issues ?? [], 'Recommendations' => $review->recommendations ?? []] as $label => $items)
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

                <section class="rounded-lg border border-border bg-surface p-5">
                    <h2 class="text-sm font-semibold text-textPrimary">Links</h2>
                    <div class="mt-4 space-y-2 text-sm">
                        @if ($content = $review->linkedContent())<a href="{{ route('app.content.show', $content) }}" class="block text-primary hover:underline">Linked Content</a>@endif
                        @if ($review->publicationReadiness)<a href="{{ route('app.programmatic-publication-readiness.show', $review->publicationReadiness) }}" class="block text-primary hover:underline">Publication Readiness</a>@endif
                        @if ($review->draft)<a href="{{ route('app.drafts.show', $review->draft) }}" class="block text-primary hover:underline">Linked Draft</a>@endif
                        @if ($review->request)<a href="{{ route('app.programmatic-draft-requests.show', $review->request) }}" class="block text-primary hover:underline">Linked Request</a>@endif
                        @if ($review->brief)<a href="{{ route('app.content.workspace.show', $review->brief) }}" class="block text-primary hover:underline">Linked Brief</a>@endif
                    </div>
                </section>
            </aside>
        </div>
    </div>
@endsection
