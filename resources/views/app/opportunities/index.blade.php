@extends('layouts.app', ['title' => $title ?? 'Opportunities'])

@section('pageHeader')
    <x-page-header title="Opportunities" eyebrow="Opportunities Workspace">
        <x-slot:description>Review what matters, decide the next step, and turn the best opportunities into execution.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @php
        $activeItems = $mode === 'decisions' ? $decisionItems : $inboxItems;
    @endphp

    <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Opportunities Workspace</p>
            <h2 class="mt-1 text-2xl font-semibold tracking-tight text-textPrimary">Opportunities</h2>
            <p class="mt-1 max-w-2xl text-sm text-textSecondary">Review what matters, decide the next step, and turn the best opportunities into execution.</p>
        </div>
        @if ($workspaces->count() > 1)
            <form method="GET" action="{{ route('app.opportunities.index') }}" class="flex items-center gap-2">
                <label class="text-xs font-medium text-textSecondary" for="workspace">Workspace</label>
                <select id="workspace" name="workspace" class="pl-select h-9 min-w-48" onchange="this.form.submit()">
                    @foreach ($workspaces as $option)
                        <option value="{{ $option->id }}" @selected((string) $option->id === (string) $workspace->id)>{{ $option->display_name ?: $option->name }}</option>
                    @endforeach
                </select>
            </form>
        @endif
    </div>

    <div class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-sm text-textSecondary">Opportunities</p>
            <p class="mt-2 text-3xl font-semibold text-textPrimary">{{ number_format((int) data_get($summary, 'opportunities', 0)) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-sm text-textSecondary">Decision Queue</p>
            <p class="mt-2 text-3xl font-semibold text-textPrimary">{{ number_format((int) data_get($summary, 'decisions', 0)) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-sm text-textSecondary">Execution Recommendations</p>
            <p class="mt-2 text-3xl font-semibold text-textPrimary">{{ number_format((int) data_get($summary, 'execution_recommendations', 0)) }}</p>
        </div>
        <div class="rounded-lg border border-border bg-surface p-4">
            <p class="text-sm text-textSecondary">High Impact</p>
            <p class="mt-2 text-3xl font-semibold text-textPrimary">{{ number_format((int) data_get($summary, 'high_impact', 0)) }}</p>
        </div>
    </div>

    <div class="mb-5 flex flex-wrap items-center gap-2">
        <a href="{{ route('app.opportunities.inbox', ['workspace' => $workspace->id]) }}" class="inline-flex h-9 items-center rounded-md px-3 text-sm font-medium {{ $mode === 'inbox' ? 'bg-primary text-white' : 'border border-border bg-surface text-textPrimary hover:bg-surfaceMuted' }}">Opportunity Inbox</a>
        <a href="{{ route('app.opportunities.decisions', ['workspace' => $workspace->id]) }}" class="inline-flex h-9 items-center rounded-md px-3 text-sm font-medium {{ $mode === 'decisions' ? 'bg-primary text-white' : 'border border-border bg-surface text-textPrimary hover:bg-surfaceMuted' }}">Decision Queue</a>
        <a href="{{ route('app.recommended-actions.index') }}" class="inline-flex h-9 items-center rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">Next Actions</a>
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <section class="rounded-lg border border-border bg-surface p-5" aria-label="{{ $mode === 'decisions' ? 'Decision Queue' : 'Opportunity Inbox' }}">
            <div class="mb-4">
                <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">{{ $mode === 'decisions' ? 'Decision Queue' : 'Opportunity Inbox' }}</p>
                <h2 class="mt-1 text-lg font-semibold text-textPrimary">{{ $mode === 'decisions' ? 'Choose what Argusly should do next' : 'Opportunities ready for review' }}</h2>
            </div>
            <div class="mb-3 hidden grid-cols-4 gap-3 rounded-md border border-border bg-background px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-textFaint md:grid">
                <span>Why it matters</span>
                <span>Recommended action</span>
                <span>Expected impact</span>
                <span>Next step</span>
            </div>

            <div class="space-y-3">
                @forelse ($activeItems as $item)
                    <a href="{{ data_get($item, 'url') }}" class="block rounded-md border border-border bg-background p-4 transition hover:border-primary/40 hover:bg-surfaceMuted">
                        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full border border-border bg-surface px-2 py-0.5 text-[11px] font-semibold text-textSecondary">{{ data_get($item, 'label') }}</span>
                                    <span class="rounded-full border border-border bg-surface px-2 py-0.5 text-[11px] font-semibold text-textSecondary">{{ data_get($item, 'status') }}</span>
                                    <span class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">{{ data_get($item, 'expected_impact') }} impact</span>
                                </div>
                                <h3 class="mt-2 truncate text-base font-semibold text-textPrimary">{{ data_get($item, 'title') }}</h3>
                                <p class="mt-1 line-clamp-2 text-sm text-textSecondary">{{ data_get($item, 'why_it_matters') }}</p>
                            </div>
                            <span class="inline-flex h-8 shrink-0 items-center gap-1 rounded-md border border-border bg-surface px-3 text-xs font-medium text-textPrimary">
                                Open <i data-lucide="arrow-right" class="h-3.5 w-3.5"></i>
                            </span>
                        </div>
                        <div class="mt-4 grid gap-3 md:grid-cols-3">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-textFaint">Recommended action</p>
                                <p class="mt-1 text-xs leading-5 text-textSecondary">{{ data_get($item, 'recommended_action') }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-textFaint">Expected impact</p>
                                <p class="mt-1 text-xs leading-5 text-textSecondary">{{ data_get($item, 'expected_impact') }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-textFaint">Next step</p>
                                <p class="mt-1 text-xs leading-5 text-textSecondary">{{ data_get($item, 'next_step') }}</p>
                            </div>
                        </div>
                    </a>
                @empty
                    <div class="rounded-md border border-border bg-background p-6 text-center">
                        <h3 class="text-base font-semibold text-textPrimary">No opportunities need attention</h3>
                        <p class="mx-auto mt-2 max-w-md text-sm text-textSecondary">Argusly will add opportunities here when there is something useful to review, decide, or execute.</p>
                    </div>
                @endforelse
            </div>
        </section>

        <aside class="space-y-4">
            <section class="rounded-lg border border-border bg-surface p-5" aria-label="Execution Recommendation">
                <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Execution Recommendation</p>
                <h2 class="mt-1 text-base font-semibold text-textPrimary">Ready to turn into work</h2>
                <div class="mt-4 space-y-3">
                    @forelse ($executionRecommendations as $item)
                        <a href="{{ data_get($item, 'url') }}" class="block rounded-md border border-border bg-background p-3 hover:bg-surfaceMuted">
                            <p class="truncate text-sm font-semibold text-textPrimary">{{ data_get($item, 'title') }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ data_get($item, 'recommended_action') }}</p>
                            <p class="mt-2 text-xs font-medium text-textPrimary">{{ data_get($item, 'expected_impact') }} impact</p>
                        </a>
                    @empty
                        <p class="rounded-md border border-border bg-background p-3 text-sm text-textSecondary">Approve an opportunity to create an execution recommendation.</p>
                    @endforelse
                </div>
            </section>

            @if (\App\Support\AdvancedMode::enabled(request()))
                <section class="rounded-lg border border-border bg-surface p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Specialist Tools</p>
                    <p class="mt-1 text-sm text-textSecondary">Use these when you need to inspect raw signals or legacy opportunity evidence.</p>
                    <div class="mt-4 space-y-2">
                        <a href="{{ route('app.signal-intelligence.index', ['workspace' => $workspace->id]) }}" class="block rounded-md border border-border bg-background px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">Signal intelligence</a>
                        <a href="{{ route('app.opportunity-review.index', ['workspace' => $workspace->id]) }}" class="block rounded-md border border-border bg-background px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">Signal candidates</a>
                    </div>
                </section>
            @endif
        </aside>
    </div>
@endsection
