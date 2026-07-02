@extends('layouts.app', ['title' => $title ?? 'Opportunity Detail'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>{{ $opportunity->title }}</x-slot:title>
        <x-slot:description>{{ $opportunityCard['why_it_matters'] }}</x-slot:description>
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
                <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Opportunity Detail</p>
                <p class="mt-2 max-w-3xl text-sm leading-6 text-textSecondary">{{ $opportunityCard['why_it_matters'] }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($canCreateExecutionPlan)
                    <form method="POST" action="{{ route('app.opportunity-intelligence.opportunities.execution-plans.store', $opportunity) }}">
                        @csrf
                        <button class="inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                            <i data-lucide="clipboard-list" class="h-4 w-4"></i>
                            Create execution recommendation
                        </button>
                    </form>
                @endif
                <form method="POST" action="{{ route('app.opportunity-intelligence.opportunities.approve', $opportunity) }}">
                    @csrf
                    <button class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">
                        <i data-lucide="check" class="h-4 w-4"></i>
                        Approve
                    </button>
                </form>
                <form method="POST" action="{{ route('app.opportunity-intelligence.opportunities.dismiss', $opportunity) }}">
                    @csrf
                    <button class="inline-flex h-9 items-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textSecondary hover:bg-surfaceMuted">
                        <i data-lucide="x" class="h-4 w-4"></i>
                        Dismiss
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <section class="rounded-lg border border-border bg-surface p-5">
            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-md border border-border bg-background p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Why it matters</p>
                    <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $opportunityCard['why_it_matters'] }}</p>
                </div>
                <div class="rounded-md border border-border bg-background p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Recommended action</p>
                    <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $opportunityCard['recommended_action'] }}</p>
                </div>
                <div class="rounded-md border border-border bg-background p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Expected impact</p>
                    <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $opportunityCard['expected_impact'] }} impact</p>
                </div>
                <div class="rounded-md border border-border bg-background p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Next step</p>
                    <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $opportunityCard['next_step'] }}</p>
                </div>
            </div>
        </section>

        <aside class="space-y-4">
            <section class="rounded-lg border border-border bg-surface p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Execution Recommendation</p>
                <div class="mt-4 space-y-3">
                    @forelse ($executionRecommendations as $item)
                        <a href="{{ data_get($item, 'url') }}" class="block rounded-md border border-border bg-background p-3 hover:bg-surfaceMuted">
                            <p class="truncate text-sm font-semibold text-textPrimary">{{ data_get($item, 'title') }}</p>
                            <p class="mt-1 text-xs text-textSecondary">{{ data_get($item, 'next_step') }}</p>
                        </a>
                    @empty
                        <p class="rounded-md border border-border bg-background p-3 text-sm text-textSecondary">No execution recommendation yet.</p>
                    @endforelse
                </div>
            </section>

            <section class="rounded-lg border border-border bg-surface p-5">
                <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Advanced Compatibility</p>
                <a href="{{ $opportunityCard['legacy_url'] }}" class="mt-3 block rounded-md border border-border bg-background px-3 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceMuted">Open advanced detail</a>
            </section>
        </aside>
    </div>
@endsection
