@extends('layouts.app', ['title' => $title ?? 'Execution Recommendation'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>{{ $plan->title }}</x-slot:title>
        <x-slot:description>{{ $planCard['why_it_matters'] }}</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    <div class="mb-6">
        <a href="{{ route('app.opportunities.index', ['workspace' => $workspace->id]) }}" class="inline-flex items-center gap-2 text-sm font-medium text-textSecondary hover:text-textPrimary">
            <i data-lucide="arrow-left" class="h-4 w-4"></i>
            Opportunity Inbox
        </a>
        <div class="mt-4 flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Execution Recommendation</p>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-textSecondary">{{ $planCard['why_it_matters'] }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="POST" action="{{ route('app.opportunity-intelligence.execution-plans.approve', $plan) }}">
                    @csrf
                    <button class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                        <i data-lucide="check" class="h-4 w-4"></i>
                        Approve
                    </button>
                </form>
                <form method="POST" action="{{ route('app.opportunity-intelligence.execution-plans.planned', $plan) }}">
                    @csrf
                    <button class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                        <i data-lucide="calendar-check" class="h-4 w-4"></i>
                        Mark planned
                    </button>
                </form>
                @if ($canCreateBrief)
                    <form method="POST" action="{{ route('app.opportunity-intelligence.execution-plans.create-brief', $plan) }}">
                        @csrf
                        <button class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                            <i data-lucide="file-plus" class="h-4 w-4"></i>
                            Create brief
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <section class="rounded-lg border border-border bg-surface p-5">
            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-md border border-border bg-background p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Why it matters</p>
                    <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $planCard['why_it_matters'] }}</p>
                </div>
                <div class="rounded-md border border-border bg-background p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Recommended action</p>
                    <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $planCard['recommended_action'] }}</p>
                </div>
                <div class="rounded-md border border-border bg-background p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Expected impact</p>
                    <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $planCard['expected_impact'] }} impact</p>
                </div>
                <div class="rounded-md border border-border bg-background p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Next step</p>
                    <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $planCard['next_step'] }}</p>
                </div>
            </div>

            @if (! empty($plan->planned_steps))
                <div class="mt-5 rounded-md border border-border bg-background p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Execution steps</p>
                    <ol class="mt-3 space-y-2">
                        @foreach ($plan->planned_steps as $step)
                            <li class="flex gap-3 text-sm text-textSecondary">
                                <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-primary"></span>
                                <span>{{ is_array($step) ? (string) ($step['title'] ?? $step['description'] ?? json_encode($step)) : (string) $step }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endif
        </section>

        <aside class="space-y-4">
            @if ($plan->opportunity)
                <section class="rounded-lg border border-border bg-surface p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Related Opportunity</p>
                    <a href="{{ route('app.opportunities.show', $plan->opportunity) }}" class="mt-3 block rounded-md border border-border bg-background p-3 hover:bg-surfaceMuted">
                        <p class="text-sm font-semibold text-textPrimary">{{ $plan->opportunity->title }}</p>
                        <p class="mt-1 text-xs text-textSecondary">{{ $plan->opportunity->summary ?: $plan->opportunity->topic }}</p>
                    </a>
                </section>
            @endif

            <section class="rounded-lg border border-border bg-surface p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Advanced Compatibility</p>
                <a href="{{ $planCard['legacy_url'] }}" class="mt-3 block rounded-md border border-border bg-background px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">Open advanced detail</a>
            </section>
        </aside>
    </div>
@endsection
