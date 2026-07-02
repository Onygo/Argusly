@extends('layouts.app', ['title' => 'Compare AI Drafts'])

@php
    $comparisonTitle = (string) ($comparison->title ?: 'Compare AI Drafts');
    $selectedModelCount = (int) ($comparison->requested_model_count ?: $comparison->items_total);
    $createdBy = $comparison->creator?->name ?: 'System';
    $createdAt = optional($comparison->created_at)->format('M j, Y \a\t g:i A') ?: 'n/a';
    $status = (string) $comparison->status;
    $statusBadgeClass = match ($status) {
        'completed' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700',
        'partially_failed' => 'border-amber-500/30 bg-amber-500/10 text-amber-700',
        'failed', 'cancelled' => 'border-rose-500/30 bg-rose-500/10 text-rose-700',
        'processing' => 'border-sky-500/30 bg-sky-500/10 text-sky-700',
        'queued', 'pending' => 'border-amber-500/30 bg-amber-500/10 text-amber-700',
        default => 'border-border bg-background text-textSecondary',
    };
    $statusLabel = match ($status) {
        'completed' => 'Completed',
        'partially_failed' => 'Partially completed',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'processing' => 'Generating...',
        'queued' => 'Queued',
        'pending' => 'Pending',
        default => ucfirst($status),
    };

    $suggestedWinner = data_get($comparisonRecommendation, 'suggested_winner') ?? data_get($comparisonInsights, 'best_overall');
        $bestForSeo = data_get($comparisonRecommendation, 'best_for_seo') ?? data_get($comparisonInsights, 'best_for_seo');
        $bestForBrand = data_get($comparisonRecommendation, 'best_for_brand_voice') ?? data_get($comparisonInsights, 'best_brand_voice_fit');
        $bestForConversion = data_get($comparisonRecommendation, 'best_conversion_focused_option') ?? data_get($comparisonInsights, 'best_for_conversion');
        $trustSignals = is_array($comparisonTrustSignals ?? null) ? $comparisonTrustSignals : [];
        $trustUsage = is_array(data_get($trustSignals, 'usage_summary')) ? data_get($trustSignals, 'usage_summary') : [];
        $promptConsistency = is_array(data_get($trustSignals, 'prompt_consistency')) ? data_get($trustSignals, 'prompt_consistency') : [];
        $recommendationWhy = trim((string) data_get($comparisonRecommendation, 'why_it_won', data_get($trustSignals, 'recommendation_explanation', '')));

        $hasPartialFailures = !empty($failedVariantRows) && !empty($successfulVariantRows);
        $hasNoSuccessfulVariants = empty($successfulVariantRows);
        $hybridEligibility = is_array($hybridEligibility ?? null) ? $hybridEligibility : [];
        $hybridReasonMessage = trim((string) data_get($hybridEligibility, 'reason_message', ''));
        $hybridEstimatedCredits = (int) data_get($hybridEligibility, 'estimated_credit_cost', 0);
        $hybridAvailableCredits = (int) data_get($hybridEligibility, 'available_credits', 0);

        $resolveVariantLabel = function ($item) {
            if (!is_array($item)) {
                return null;
            }

            $provider = \Illuminate\Support\Str::headline((string) data_get($item, 'provider', ''));
            $model = (string) data_get($item, 'model', '');

            $label = trim($provider . ($model !== '' ? ' · ' . $model : ''));
            if ($label === '') {
                $label = (string) data_get($item, 'display_name', '');
            }

            return $label ?: null;
        };

        $resolveVariantScore = function ($item) {
            if (!is_array($item)) {
                return null;
            }
            $score = data_get($item, 'total_weighted_score', data_get($item, 'score'));
            return is_numeric($score) ? number_format((float) $score, 1) : null;
        };
@endphp

@section('pageHeader')
    <x-page-header :title="$comparisonTitle" icon="bar-chart-3">
        <x-slot:description>{{ $selectedModelCount }} model{{ $selectedModelCount === 1 ? '' : 's' }} · {{ $createdAt }} · {{ $createdBy }}</x-slot:description>
    </x-page-header>
@endsection

@section('primaryActions')
    <span class="rounded-full border px-2.5 py-1 text-xs font-medium {{ $statusBadgeClass }}" data-compare-status-badge>{{ $statusLabel }}</span>
    <a href="{{ route('app.content.workspace.show', $brief) }}" class="pl-btn-secondary">Back to content workspace</a>
@endsection

@section('content')

    <div
        class="space-y-6"
        data-compare-page
        data-status-endpoint="{{ route('app.content.workspace.compare.status', [$brief, $comparison]) }}"
        data-comparison-status="{{ $comparison->status }}"
        data-is-terminal="{{ $isTerminal ? '1' : '0' }}"
        data-items-done="{{ (int) $comparison->items_done }}"
        data-items-failed="{{ (int) $comparison->items_failed }}"
    >
        {{-- Header --}}
        <div class="rounded-lg border border-border bg-surface p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-4">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-primary/20 to-primary/10 text-primary">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                    </div>
                    <div>
                        <div class="flex flex-wrap items-center gap-2 mb-1">
                            <h2 class="text-xl font-semibold tracking-tight text-textPrimary">{{ $comparisonTitle }}</h2>
                            <span class="rounded-full border px-2.5 py-1 text-xs font-medium {{ $statusBadgeClass }}" data-compare-status-badge>{{ $statusLabel }}</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-textSecondary">
                            <a class="text-link hover:underline flex items-center gap-1" href="{{ route('app.content.workspace.show', $brief) }}">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                                {{ \Illuminate\Support\Str::limit($brief->title, 40) }}
                            </a>
                            <span class="flex items-center gap-1 text-textFaint">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" /></svg>
                                {{ $selectedModelCount }} model{{ $selectedModelCount === 1 ? '' : 's' }}
                            </span>
                            <span class="flex items-center gap-1 text-textFaint">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                {{ $createdAt }}
                            </span>
                            <span class="flex items-center gap-1 text-textFaint">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                                {{ $createdBy }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if (in_array($status, ['pending', 'queued'], true))
                        <form method="POST" action="{{ route('app.content.workspace.compare.start', [$brief, $comparison]) }}">
                            @csrf
                            <button class="rounded-lg bg-gradient-to-r from-primary to-primary/90 px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-primary/20 hover:shadow-xl hover:shadow-primary/30 transition-all flex items-center gap-2">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" /><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                Start comparison
                            </button>
                        </form>
                    @endif

                    @if ($comparison->winnerDraft)
                        <a href="{{ route('app.drafts.show', $comparison->winnerDraft) }}" class="rounded-lg border-2 border-emerald-500/30 bg-emerald-500/10 px-4 py-2.5 text-sm font-medium text-emerald-700 hover:bg-emerald-500/20 hover:border-emerald-500/50 transition-all">
                            <span class="flex items-center gap-1.5">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                View winner
                            </span>
                        </a>
                    @endif
                    @if ($comparison->hybridDraft)
                        <a href="{{ route('app.drafts.show', $comparison->hybridDraft) }}" class="rounded-lg border-2 border-primary/30 bg-primary/10 px-4 py-2.5 text-sm font-medium text-primary hover:bg-primary/20 hover:border-primary/50 transition-all">
                            <span class="flex items-center gap-1.5">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" /></svg>
                                View hybrid
                            </span>
                        </a>
                    @endif
                    <a href="{{ route('app.content.workspace.compare.setup', $brief) }}" class="rounded-lg border border-border px-4 py-2.5 text-sm hover:bg-surfaceSubtle transition-colors flex items-center gap-1.5">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                        Start comparison
                    </a>
                    <a href="{{ route('app.content.workspace.show', $brief) }}" class="rounded-lg border border-border px-4 py-2.5 text-sm hover:bg-surfaceSubtle transition-colors flex items-center gap-1.5">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                        Back to content workspace
                    </a>
                </div>
            </div>
        </div>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if ($errors->has('draft_compare'))
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-800">{{ $errors->first('draft_compare') }}</div>
        @endif

        {{-- Progress section (shown while processing or for reference) --}}
        @if (!$isTerminal || $status === 'processing')
            <div class="rounded-lg border-2 {{ $status === 'processing' ? 'border-primary/30 bg-gradient-to-br from-primary/5 to-surface' : 'border-border bg-surface' }} p-6">
                <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
                    <div class="flex items-center gap-4">
                        @if ($status === 'processing')
                            <div class="flex h-14 w-14 items-center justify-center rounded-lg border border-primary/20 bg-primary/10 text-primary">
                                <div class="flex h-7 items-end gap-1.5" aria-hidden="true">
                                    <span class="w-1.5 rounded-full bg-current/50 animate-pulse" style="height: 14px;"></span>
                                    <span class="w-1.5 rounded-full bg-current animate-pulse" style="height: 22px; animation-delay: 120ms;"></span>
                                    <span class="w-1.5 rounded-full bg-current/70 animate-pulse" style="height: 18px; animation-delay: 240ms;"></span>
                                </div>
                            </div>
                        @else
                            <div class="flex h-14 w-14 items-center justify-center rounded-lg bg-primary/10">
                                <svg class="h-6 w-6 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                            </div>
                        @endif
                        <div>
                            <h2 class="text-lg font-semibold text-textPrimary">
                                @if ($status === 'processing')
                                    Generating drafts from {{ $selectedModelCount }} model{{ $selectedModelCount === 1 ? '' : 's' }}
                                @elseif ($status === 'queued' || $status === 'pending')
                                    Comparison queued
                                @else
                                    Comparison {{ $statusLabel }}
                                @endif
                            </h2>
                            <p class="text-sm text-textSecondary mt-0.5">
                                @if ($status === 'processing')
                                    {{ (int) $comparison->items_done }} of {{ $selectedModelCount }} completed &middot; Please wait while AI models generate content
                                @elseif ($status === 'queued' || $status === 'pending')
                                    Waiting for available capacity to start generation
                                @else
                                    {{ (int) $comparison->items_done }} of {{ $selectedModelCount }} completed
                                @endif
                            </p>
                        </div>
                    </div>
                    @if ($status === 'processing')
                        <div class="shrink-0 inline-flex items-center gap-2 rounded-full bg-primary/10 px-4 py-2 text-sm font-medium text-primary">
                            <span class="relative flex h-2 w-2">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-2 w-2 bg-primary"></span>
                            </span>
                            Running
                        </div>
                    @endif
                </div>

                <div class="mb-5">
                    <div class="flex items-center justify-between text-xs text-textSecondary mb-2">
                        <span>Progress</span>
                        <span class="font-medium text-textPrimary" data-compare-progress-label>{{ $progressPercent }}%</span>
                    </div>
                    <div class="h-3 w-full overflow-hidden rounded-full bg-background border border-border/50">
                        <div class="h-full bg-gradient-to-r from-primary to-primary/80 transition-all duration-500 rounded-full" style="width: {{ $progressPercent }}%" data-compare-progress-bar></div>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-4">
                    <div class="rounded-lg border border-emerald-500/20 bg-gradient-to-br from-emerald-500/10 to-emerald-500/5 p-4 text-center">
                        <p class="text-3xl font-bold text-emerald-700" data-compare-items-done>{{ (int) $comparison->items_done }}</p>
                        <p class="text-xs font-medium text-emerald-600 mt-1">Completed</p>
                    </div>
                    <div class="rounded-lg border {{ (int) $comparison->items_failed > 0 ? 'border-rose-500/20 bg-gradient-to-br from-rose-500/10 to-rose-500/5' : 'border-border bg-background' }} p-4 text-center">
                        <p class="text-3xl font-bold {{ (int) $comparison->items_failed > 0 ? 'text-rose-700' : 'text-textPrimary' }}" data-compare-items-failed>{{ (int) $comparison->items_failed }}</p>
                        <p class="text-xs font-medium {{ (int) $comparison->items_failed > 0 ? 'text-rose-600' : 'text-textSecondary' }} mt-1">Failed</p>
                    </div>
                    <div class="rounded-lg border border-border bg-background p-4 text-center">
                        <p class="text-3xl font-bold text-textPrimary">{{ (int) ($comparison->final_credit_cost ?: $comparison->credits_used) }}</p>
                        <p class="text-xs font-medium text-textSecondary mt-1">Credits</p>
                    </div>
                    <div class="rounded-lg border border-sky-500/20 bg-gradient-to-br from-sky-500/10 to-sky-500/5 p-4 text-center">
                        <p class="text-3xl font-bold text-sky-700">{{ max(0, $selectedModelCount - (int) $comparison->items_done - (int) $comparison->items_failed) }}</p>
                        <p class="text-xs font-medium text-sky-600 mt-1">In progress</p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Alert messages --}}
        @if ($hasPartialFailures)
            <div class="flex items-start gap-4 rounded-lg border border-amber-500/30 bg-gradient-to-r from-amber-500/10 to-amber-500/5 px-5 py-4">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-500/20">
                    <svg class="h-5 w-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-amber-800">Some models did not complete successfully</p>
                    <p class="text-sm text-amber-700 mt-0.5">Don't worry — successful drafts are still available for comparison below. You can also run a new comparison to try again.</p>
                </div>
            </div>
        @endif

        @if ($isTerminal && $hasNoSuccessfulVariants)
            <div class="flex items-start gap-4 rounded-lg border border-rose-500/30 bg-gradient-to-r from-rose-500/10 to-rose-500/5 px-5 py-4">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-rose-500/20">
                    <svg class="h-5 w-5 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-rose-800">No drafts could be generated</p>
                    <p class="text-sm text-rose-700 mt-0.5">All selected models encountered errors. This can happen due to temporary service issues. Try running a new comparison with different models.</p>
                    <a href="{{ route('app.content.workspace.compare.setup', $brief) }}" class="mt-3 inline-flex items-center gap-1.5 rounded-lg bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700 transition-colors">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                        Try again
                    </a>
                </div>
            </div>
        @endif

        {{-- Recommendations strip --}}
        @if ($isTerminal && !$hasNoSuccessfulVariants)
            <div class="rounded-lg border border-border bg-surface p-5">
                <div class="flex items-center gap-3 mb-5">
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-br from-emerald-500/20 to-emerald-500/10 text-emerald-600">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" /></svg>
                    </div>
                    <div>
                        <h2 class="text-base font-semibold text-textPrimary">AI Recommendations</h2>
                        <p class="text-sm text-textSecondary">Based on quality scoring across multiple dimensions</p>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {{-- Suggested winner --}}
                    <div class="relative rounded-lg border-2 border-emerald-500/40 bg-gradient-to-br from-emerald-500/10 via-emerald-500/5 to-transparent p-4 overflow-hidden">
                        <div class="absolute top-0 right-0 w-16 h-16 bg-gradient-to-bl from-emerald-500/20 to-transparent rounded-bl-3xl"></div>
                        <div class="flex items-center gap-2 mb-3">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/20">
                                <svg class="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" /></svg>
                            </div>
                            <p class="text-[11px] font-bold text-emerald-700 uppercase tracking-wider">Suggested winner</p>
                        </div>
                        @if ($resolveVariantLabel($suggestedWinner))
                            <p class="text-base font-bold text-textPrimary leading-tight">{{ $resolveVariantLabel($suggestedWinner) }}</p>
                            @if ($resolveVariantScore($suggestedWinner))
                                <div class="mt-2 inline-flex items-center gap-1 rounded-full bg-emerald-500/20 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                                    <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                                    {{ $resolveVariantScore($suggestedWinner) }} points
                                </div>
                            @endif
                        @else
                            <div class="flex items-center gap-2 text-sm text-textSecondary">
                                <svg class="h-4 w-4 animate-pulse" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                Calculating...
                            </div>
                        @endif
                    </div>

                    {{-- Best for SEO --}}
                    <div class="rounded-lg border border-sky-500/20 bg-gradient-to-br from-sky-500/5 to-transparent p-4">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-sky-500/15">
                                <svg class="h-4 w-4 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                            </div>
                            <p class="text-[11px] font-bold text-sky-700 uppercase tracking-wider">Best for SEO</p>
                        </div>
                        @if ($resolveVariantLabel($bestForSeo))
                            <p class="text-sm font-semibold text-textPrimary leading-tight">{{ $resolveVariantLabel($bestForSeo) }}</p>
                            @if ($resolveVariantScore($bestForSeo))
                                <p class="text-xs text-textSecondary mt-1.5">Score: {{ $resolveVariantScore($bestForSeo) }}</p>
                            @endif
                        @else
                            <p class="text-sm text-textSecondary">Calculating...</p>
                        @endif
                    </div>

                    {{-- Best for brand --}}
                    <div class="rounded-lg border border-violet-500/20 bg-gradient-to-br from-violet-500/5 to-transparent p-4">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-500/15">
                                <svg class="h-4 w-4 text-violet-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" /></svg>
                            </div>
                            <p class="text-[11px] font-bold text-violet-700 uppercase tracking-wider">Best brand voice</p>
                        </div>
                        @if ($resolveVariantLabel($bestForBrand))
                            <p class="text-sm font-semibold text-textPrimary leading-tight">{{ $resolveVariantLabel($bestForBrand) }}</p>
                            @if ($resolveVariantScore($bestForBrand))
                                <p class="text-xs text-textSecondary mt-1.5">Score: {{ $resolveVariantScore($bestForBrand) }}</p>
                            @endif
                        @else
                            <p class="text-sm text-textSecondary">Calculating...</p>
                        @endif
                    </div>

                    {{-- Best for conversion --}}
                    <div class="rounded-lg border border-amber-500/20 bg-gradient-to-br from-amber-500/5 to-transparent p-4">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/15">
                                <svg class="h-4 w-4 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" /></svg>
                            </div>
                            <p class="text-[11px] font-bold text-amber-700 uppercase tracking-wider">Best conversion</p>
                        </div>
                        @if ($resolveVariantLabel($bestForConversion))
                            <p class="text-sm font-semibold text-textPrimary leading-tight">{{ $resolveVariantLabel($bestForConversion) }}</p>
                            @if ($resolveVariantScore($bestForConversion))
                                <p class="text-xs text-textSecondary mt-1.5">Score: {{ $resolveVariantScore($bestForConversion) }}</p>
                            @endif
                        @else
                            <p class="text-sm text-textSecondary">Calculating...</p>
                        @endif
                    </div>
                </div>

                {{-- Recommendation explanation --}}
                @if ($recommendationWhy !== '')
                    <div class="mt-5 pt-5 border-t border-border/50">
                        <div class="flex items-start gap-3 rounded-lg bg-gradient-to-r from-primary/5 to-transparent p-4">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" /></svg>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-textPrimary mb-1">Why this recommendation?</h3>
                                <p class="text-sm text-textSecondary leading-relaxed">{{ $recommendationWhy }}</p>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        {{-- Hybrid Draft Section --}}
        @if ($isTerminal && !$hasNoSuccessfulVariants)
            <div class="rounded-lg border-2 {{ $comparison->hybridDraft ? 'border-primary/40 bg-gradient-to-br from-primary/10 via-primary/5 to-surface' : (in_array((string) $comparison->hybrid_status, ['queued', 'generating'], true) ? 'border-primary/30 bg-gradient-to-br from-primary/5 to-surface' : ($canGenerateHybrid ? 'border-primary/20 bg-gradient-to-br from-primary/5 to-surface' : 'border-border bg-surface')) }} p-6 relative overflow-hidden">
                {{-- Premium badge background --}}
                @if ($comparison->hybridDraft || $canGenerateHybrid)
                    <div class="absolute top-0 right-0 w-32 h-32 bg-gradient-to-bl from-primary/10 to-transparent rounded-bl-[100px]"></div>
                @endif

                <div class="relative">
                    <div class="flex flex-wrap items-start justify-between gap-5">
                        <div class="flex items-start gap-4">
                            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg {{ $comparison->hybridDraft ? 'bg-gradient-to-br from-primary to-primary/80 text-white shadow-lg shadow-primary/30' : (in_array((string) $comparison->hybrid_status, ['queued', 'generating'], true) ? 'bg-primary/20 text-primary' : 'bg-gradient-to-br from-primary/20 to-primary/10 text-primary') }}">
                                @if (in_array((string) $comparison->hybrid_status, ['queued', 'generating'], true))
                                    <svg class="h-6 w-6 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                @else
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" /></svg>
                                @endif
                            </div>
                            <div>
                                <div class="flex items-center gap-2 mb-1">
                                    <h3 class="text-lg font-semibold text-textPrimary">Hybrid Draft</h3>
                                    @if ($comparison->hybridDraft)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/20 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 uppercase tracking-wide">
                                            <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                                            Ready
                                        </span>
                                    @elseif (in_array((string) $comparison->hybrid_status, ['queued', 'generating'], true))
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-primary/20 px-2.5 py-1 text-[11px] font-semibold text-primary uppercase tracking-wide">
                                            <span class="relative flex h-2 w-2">
                                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-primary opacity-75"></span>
                                                <span class="relative inline-flex rounded-full h-2 w-2 bg-primary"></span>
                                            </span>
                                            {{ (string) $comparison->hybrid_status === 'generating' ? 'Generating' : 'Queued' }}
                                        </span>
                                    @elseif ((string) $comparison->hybrid_status === 'failed')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-rose-500/20 px-2.5 py-1 text-[11px] font-semibold text-rose-700 uppercase tracking-wide">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                            Failed
                                        </span>
                                    @endif
                                </div>
                                @if ($comparison->hybridDraft)
                                    <p class="text-sm text-textSecondary leading-relaxed">Your hybrid draft is ready. It combines the best parts from all {{ count($successfulVariantRows) }} successful variants into one polished final version.</p>
                                @elseif (in_array((string) $comparison->hybrid_status, ['queued', 'generating'], true))
                                    <p class="text-sm text-textSecondary leading-relaxed">
                                        @if ((string) $comparison->hybrid_status === 'generating')
                                            AI is analyzing all variants and combining the best parts into one final draft. This usually takes 30-60 seconds.
                                        @else
                                            Hybrid draft is queued and will start generating shortly.
                                        @endif
                                    </p>
                                @elseif ((string) $comparison->hybrid_status === 'failed')
                                    <p class="text-sm text-rose-600 leading-relaxed">Hybrid generation encountered an error. You can try again — this is usually temporary.</p>
                                @elseif (! $hybridFeatureEnabled)
                                    <p class="text-sm text-textSecondary leading-relaxed">Combine the best parts of multiple AI drafts into one polished final version. This premium feature uses AI to analyze all variants and merge the strongest elements.</p>
                                @elseif ($canGenerateHybrid)
                                    <p class="text-sm text-textSecondary leading-relaxed">Combine the best parts of your {{ count($successfulVariantRows) }} AI drafts into one polished final version. AI will analyze structure, tone, and quality to create the optimal output.</p>
                                @elseif ($hybridReasonMessage !== '')
                                    <p class="text-sm text-textSecondary leading-relaxed">{{ $hybridReasonMessage }}</p>
                                @else
                                    <p class="text-sm text-textSecondary leading-relaxed">Hybrid draft requires at least 2 successful variants to combine and compare.</p>
                                @endif
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @if ($comparison->hybridDraft)
                                <a href="{{ route('app.drafts.show', $comparison->hybridDraft) }}" class="rounded-lg bg-gradient-to-r from-primary to-primary/90 px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-primary/20 hover:shadow-xl hover:shadow-primary/30 transition-all flex items-center gap-2">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" /></svg>
                                    Open hybrid draft
                                </a>
                                <a href="{{ route('app.drafts.edit', $comparison->hybridDraft) }}" class="rounded-lg border border-border px-4 py-3 text-sm font-medium hover:bg-surfaceSubtle transition-colors flex items-center gap-2">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                    Edit draft
                                </a>
                            @elseif (in_array((string) $comparison->hybrid_status, ['queued', 'generating'], true))
                                <div class="flex items-center gap-3 rounded-lg border-2 border-primary/30 bg-primary/10 px-5 py-3 text-sm font-medium text-primary">
                                    <svg class="h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                    <span>Generating hybrid...</span>
                                </div>
                            @elseif ((string) $comparison->hybrid_status === 'failed')
                                <form method="POST" action="{{ route('app.content.workspace.compare.hybrid', [$brief, $comparison]) }}">
                                    @csrf
                                    <button class="rounded-lg bg-rose-600 px-5 py-3 text-sm font-semibold text-white hover:bg-rose-700 transition-colors flex items-center gap-2">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
                                        Retry generation
                                    </button>
                                </form>
                            @elseif (! $hybridFeatureEnabled)
                                <a href="{{ route('app.billing.index', ['tab' => 'subscriptions']) }}" class="rounded-lg border-2 border-amber-500/30 bg-gradient-to-r from-amber-500/10 to-amber-500/5 px-5 py-3 text-sm font-semibold text-amber-700 hover:bg-amber-500/20 hover:border-amber-500/50 transition-all flex items-center gap-2">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                                    Upgrade to unlock
                                </a>
                            @elseif ($canGenerateHybrid)
                                <form method="POST" action="{{ route('app.content.workspace.compare.hybrid', [$brief, $comparison]) }}">
                                    @csrf
                                    <button class="rounded-lg bg-gradient-to-r from-primary to-primary/90 px-6 py-3 text-sm font-semibold text-white shadow-lg shadow-primary/20 hover:shadow-xl hover:shadow-primary/30 transition-all flex items-center gap-2">
                                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" /></svg>
                                        Generate Hybrid Draft
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>

                    @if (! $hybridFeatureEnabled)
                        <div class="mt-5 pt-5 border-t border-amber-500/20">
                            <div class="flex items-start gap-3 rounded-lg bg-amber-500/10 p-4">
                                <svg class="h-5 w-5 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" /></svg>
                                <div>
                                    <p class="text-sm font-medium text-amber-800">Hybrid drafts are not available on your current plan</p>
                                    <p class="text-sm text-amber-700 mt-0.5">Upgrade to combine the best parts of multiple AI drafts into one polished final version.</p>
                                </div>
                            </div>
                        </div>
                    @elseif (! $comparison->hybridDraft && ! in_array((string) $comparison->hybrid_status, ['queued', 'generating'], true) && $hybridEstimatedCredits > 0)
                        <div class="mt-5 pt-5 border-t border-border/50 flex flex-wrap items-center gap-4 text-sm">
                            <div class="flex items-center gap-2 text-textSecondary">
                                <svg class="h-4 w-4 text-textFaint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                <span>Estimated: <span class="font-semibold text-textPrimary">{{ $hybridEstimatedCredits }} credits</span></span>
                            </div>
                            <div class="flex items-center gap-2 text-textSecondary">
                                <svg class="h-4 w-4 text-textFaint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
                                <span>Available: <span class="font-semibold {{ $hybridAvailableCredits >= $hybridEstimatedCredits ? 'text-emerald-600' : 'text-rose-600' }}">{{ $hybridAvailableCredits }} credits</span></span>
                            </div>
                            @if ($hybridAvailableCredits < $hybridEstimatedCredits)
                                <span class="text-xs text-rose-600 font-medium">Insufficient credits</span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Trust signals --}}
        @if ($isTerminal && !$hasNoSuccessfulVariants)
            <div class="rounded-lg border border-border/50 bg-surfaceSubtle/50 px-5 py-3">
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-xs">
                    <span class="flex items-center gap-1.5 {{ data_get($promptConsistency, 'hash_consistent') ? 'text-emerald-700' : 'text-textSecondary' }}">
                        <svg class="h-4 w-4 {{ data_get($promptConsistency, 'hash_consistent') ? 'text-emerald-600' : 'text-textFaint' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                        <span class="font-medium">{{ data_get($promptConsistency, 'hash_consistent') ? 'Consistent prompts' : 'Prompt hashes vary' }}</span>
                    </span>
                    <span class="flex items-center gap-1.5 text-textSecondary">
                        <svg class="h-4 w-4 text-textFaint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                        <span><span class="font-medium text-textPrimary">{{ number_format((int) data_get($trustUsage, 'input_tokens', 0)) }}</span> input · <span class="font-medium text-textPrimary">{{ number_format((int) data_get($trustUsage, 'output_tokens', 0)) }}</span> output tokens</span>
                    </span>
                    <span class="flex items-center gap-1.5 text-textSecondary">
                        <svg class="h-4 w-4 text-textFaint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <span><span class="font-medium text-textPrimary">{{ (int) data_get($trustUsage, 'credit_cost', 0) }}</span> credits total</span>
                    </span>
                    <span class="flex items-center gap-1.5 text-textSecondary">
                        <svg class="h-4 w-4 text-textFaint" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" /></svg>
                        <span><span class="font-medium text-textPrimary">{{ count($successfulVariantRows) }}</span> of <span class="font-medium text-textPrimary">{{ $selectedModelCount }}</span> variants successful</span>
                    </span>
                </div>
            </div>
        @endif

        <div class="space-y-4">
            @forelse ($variantRows as $variant)
                @include('app.briefs.partials.draft-compare.variant-card', [
                    'variant' => $variant,
                    'brief' => $brief,
                    'comparison' => $comparison,
                    'isWinner' => (string) $comparison->winner_draft_id !== '' && (string) $comparison->winner_draft_id === (string) ($variant['draft_id'] ?? ''),
                ])
            @empty
                <div class="rounded border border-border bg-surface p-4 text-sm text-textSecondary">
                    No variants found for this comparison yet.
                </div>
            @endforelse
        </div>

        @include('app.briefs.partials.draft-compare.score-matrix', [
            'successfulVariantRows' => $successfulVariantRows,
            'scoreMatrixMetrics' => $scoreMatrixMetrics,
            'scoreMatrixRows' => $scoreMatrixRows ?? [],
            'scoreContextProfile' => $scoreContextProfile ?? [],
        ])

        @include('app.briefs.partials.draft-compare.full-text-compare', [
            'successfulVariantRows' => $successfulVariantRows,
        ])
    </div>

    <script>
        (() => {
            const page = document.querySelector('[data-compare-page]');
            if (!page) return;

            const isTerminal = page.getAttribute('data-is-terminal') === '1';
            const endpoint = page.getAttribute('data-status-endpoint');

            if (!isTerminal && endpoint) {
                let baselineStatus = page.getAttribute('data-comparison-status') || '';
                let baselineDone = Number(page.getAttribute('data-items-done') || 0);
                let baselineFailed = Number(page.getAttribute('data-items-failed') || 0);

                window.setInterval(() => {
                    fetch(endpoint, {
                        headers: {
                            Accept: 'application/json',
                        },
                    })
                        .then((response) => response.json())
                        .then((payload) => {
                            const status = String(payload?.data?.status || '');
                            const done = Number(payload?.data?.items_done || 0);
                            const failed = Number(payload?.data?.items_failed || 0);

                            if (status !== baselineStatus || done !== baselineDone || failed !== baselineFailed) {
                                window.location.reload();
                            }
                        })
                        .catch(() => {
                            // keep page stable on transient polling errors
                        });
                }, 5000);
            }

            const tabContainer = document.querySelector('[data-fulltext-tabs]');
            if (tabContainer) {
                const tabButtons = Array.from(tabContainer.querySelectorAll('[data-fulltext-tab]'));
                const panels = Array.from(tabContainer.querySelectorAll('[data-fulltext-panel]'));

                tabButtons.forEach((button) => {
                    button.addEventListener('click', () => {
                        const target = button.getAttribute('data-target');

                        tabButtons.forEach((tab) => {
                            tab.classList.remove('bg-background', 'text-textPrimary');
                            tab.classList.add('text-textSecondary');
                        });

                        button.classList.add('bg-background', 'text-textPrimary');
                        button.classList.remove('text-textSecondary');

                        panels.forEach((panel) => {
                            panel.classList.toggle('hidden', panel.getAttribute('data-fulltext-panel') !== target);
                        });
                    });
                });
            }
        })();
    </script>
@endsection
