@extends('layouts.app', ['title' => 'Starter Queries'])

@section('pageHeader')
    <x-page-header>
        <x-slot:title>{{ count($suggestions) }} starter {{ count($suggestions) === 1 ? 'query' : 'queries' }} available</x-slot:title>
        <x-slot:description>These prompts use your brand, website, Company Intelligence and competitors. Creating them does not start runs and does not use credits.</x-slot:description>
    </x-page-header>
@endsection

@section('content')
    @php
        $categoryLabels = [
            'brand_visibility' => 'Brand Visibility',
            'competitor_comparison' => 'Competitor Comparison',
            'buyer_intent' => 'Buyer Intent',
            'authority' => 'Authority',
            'category_leadership' => 'Category Leadership',
        ];
    @endphp

    <div class="space-y-6">
        <x-app.insights-header
            :site="$site"
            title="AI Visibility Starter Queries"
            description="Preview deterministic starter prompts, select the ones you want, then create them without running anything yet."
            active="llm"
        >
            <a href="{{ route('app.sites.llm-tracking.index', $site) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Back to AI Visibility</a>
        </x-app.insights-header>

        @if ($errors->any())
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">{{ $errors->first() }}</div>
        @endif

        <section class="rounded-lg border border-border bg-surface p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Preview</p>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-textSecondary">
                        These prompts use your brand, website, Company Intelligence and competitors. Creating them does not start runs and does not use credits.
                    </p>
                </div>
                <div class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textSecondary">
                    Existing queries: <span class="font-semibold text-textPrimary">{{ number_format((int) $existingQueryCount) }}</span>
                </div>
            </div>
        </section>

        @if (count($suggestions) > 0)
            <form method="POST" action="{{ route('app.sites.llm-tracking.starter.store', $site) }}" class="space-y-5">
                @csrf
                <section class="grid gap-4 lg:grid-cols-2">
                    @foreach ($suggestions as $suggestion)
                        <label class="block cursor-pointer rounded-lg border border-border bg-surface p-4 transition hover:border-primary/40 hover:bg-primarySoftBg/30">
                            <div class="flex items-start gap-3">
                                <input type="checkbox" name="selected[]" value="{{ $suggestion->key }}" checked class="mt-1">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full border border-border bg-background px-2 py-1 text-[11px] font-medium text-textSecondary">{{ $categoryLabels[$suggestion->category] ?? str($suggestion->category)->headline() }}</span>
                                        <span class="rounded-full border border-border bg-background px-2 py-1 text-[11px] font-medium text-textSecondary">{{ str($suggestion->intent)->replace('_', ' ')->headline() }}</span>
                                        <span class="rounded-full border border-primary/20 bg-primarySoftBg px-2 py-1 text-[11px] font-semibold text-primary">{{ $suggestion->confidenceScore }}%</span>
                                    </div>
                                    <p class="mt-3 text-sm font-semibold text-textPrimary">{{ $suggestion->queryText }}</p>
                                    <p class="mt-2 text-xs leading-5 text-textSecondary">{{ $suggestion->explanation }}</p>
                                </div>
                            </div>
                        </label>
                    @endforeach
                </section>

                <div class="rounded-lg border border-border bg-surface p-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 class="text-sm font-semibold text-textPrimary">Create selected queries</h2>
                            <p class="mt-1 text-xs text-textSecondary">No runs will start automatically. You can run the first visibility check from the AI Visibility dashboard after creation.</p>
                        </div>
                        <button class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                            <i data-lucide="plus" class="h-4 w-4"></i>
                            Create selected queries
                        </button>
                    </div>
                </div>
            </form>
        @else
            <section class="rounded-lg border border-border bg-surface p-6 text-sm text-textSecondary">
                No new starter queries are available. Existing queries already cover the deterministic starter set.
                <a href="{{ route('app.sites.llm-tracking.index', $site) }}" class="font-medium text-primary hover:underline">Return to AI Visibility</a>.
            </section>
        @endif
    </div>
@endsection
