@extends('layouts.app', ['title' => __('app.dashboard.title')])

@section('content')
    @php
        $localeBadgeClasses = [
            'source' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            'variant' => 'border-sky-200 bg-sky-50 text-sky-700',
        ];
    @endphp

    <div class="mb-8">
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">{{ __('app.dashboard.title') }}</h1>
        <p class="text-textSecondary mt-1">{{ __('app.dashboard.subtitle') }}</p>
    </div>

    <div class="mb-8 space-y-4">
        <x-dashboard.recommended-action-widget :action="data_get($actionFirstDashboard, 'recommended_action')" />

        <div class="grid gap-4 lg:grid-cols-2">
            <x-dashboard.open-opportunities-widget :summary="data_get($actionFirstDashboard, 'open_opportunities')" />
            <x-dashboard.risk-summary-widget :summary="data_get($actionFirstDashboard, 'risk_summary')" />
        </div>

        <x-dashboard.next-journey-step-widget :step="data_get($actionFirstDashboard, 'journey_step')" />
        <x-dashboard.intelligence-feed-widget :items="data_get($actionFirstDashboard, 'intelligence_feed', collect())" />
    </div>

    @if (!empty($activation) && !data_get($activation, 'is_active'))
        <x-activation-banner class="mb-8" :activation="$activation" />
    @elseif (!empty($isEmptyDashboard))
        <div class="mb-8 rounded-md border border-border bg-surface p-5">
            <h2 class="text-base font-semibold text-textPrimary">Start met First Value Activation</h2>
            <p class="mt-1 text-sm text-textSecondary">Dit dashboard wacht nog op data. Open Activation om je eerste AI Visibility run en Signal Intelligence output te bereiken.</p>
            <a href="{{ route('app.activation.index') }}" class="mt-4 inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                <i data-lucide="list-checks" class="h-4 w-4"></i>
                Open Activation
            </a>
        </div>
    @endif

    <div class="mb-4">
        <h2 class="text-lg font-semibold text-textPrimary">{{ __('app.dashboard_action_first.supporting_metrics') }}</h2>
        <p class="mt-1 text-sm text-textSecondary">{{ __('app.dashboard_action_first.supporting_metrics_hint') }}</p>
    </div>

    <div class="mb-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <div class="bg-surface rounded-lg border border-border p-5">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-sm text-textSecondary">{{ __('app.dashboard.content_created') }}</p>
                    <p class="text-3xl font-semibold text-textPrimary mt-1">{{ $briefCount }}</p>
                    <p class="text-sm text-textSecondary mt-1">{{ __('app.dashboard.total_briefs') }}</p>
                </div>
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-accentYellow-100">
                    <i data-lucide="file-text" class="h-5 w-5 text-accentYellow-900"></i>
                </div>
            </div>
        </div>
        <div class="bg-surface rounded-lg border border-border p-5">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-sm text-textSecondary">{{ __('app.dashboard.integrations') }}</p>
                    <p class="text-3xl font-semibold text-textPrimary mt-1">{{ $integrationsCount }}</p>
                    <p class="text-sm mt-2 font-medium text-success">{{ $connectedSitesCount }} {{ __('app.dashboard.connected') }}</p>
                </div>
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-accentYellow-100">
                    <i data-lucide="plug" class="h-5 w-5 text-accentYellow-900"></i>
                </div>
            </div>
        </div>
        <div class="bg-surface rounded-lg border border-border p-5">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-sm text-textSecondary">{{ __('app.dashboard.available_credits') }}</p>
                    <p class="text-3xl font-semibold text-textPrimary mt-1">{{ $totalAvailableCredits ?? 0 }}</p>
                    <a href="{{ route('app.billing.index') }}" class="text-sm mt-2 inline-block text-primary hover:underline">{{ __('app.dashboard.buy_more_credits') }}</a>
                </div>
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-accentYellow-100">
                    <i data-lucide="wallet" class="h-5 w-5 text-accentYellow-900"></i>
                </div>
            </div>
        </div>
        <div class="bg-surface rounded-lg border border-border p-5">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-sm text-textSecondary">Distribution</p>
                    <p class="text-3xl font-semibold text-textPrimary mt-1">{{ (int) data_get($distributionSummary, 'scheduled_posts', 0) }}</p>
                    <a href="{{ route('app.agentic-marketing.distribution.index') }}" class="text-sm mt-2 inline-block text-primary hover:underline">
                        {{ (int) data_get($distributionSummary, 'variants_pending', 0) }} variant{{ (int) data_get($distributionSummary, 'variants_pending', 0) === 1 ? '' : 's' }} pending
                    </a>
                </div>
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-accentYellow-100">
                    <i data-lucide="send" class="h-5 w-5 text-accentYellow-900"></i>
                </div>
            </div>
        </div>
        <div class="bg-surface rounded-lg border border-border p-5">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-sm text-textSecondary">Opportunities</p>
                    <p class="text-3xl font-semibold text-textPrimary mt-1">{{ (int) data_get($opportunityIntelligenceSummary, 'open', 0) }}</p>
                    <a href="{{ route('app.agentic-marketing.intelligence.index') }}" class="text-sm mt-2 inline-block text-primary hover:underline">
                        {{ (int) data_get($opportunityIntelligenceSummary, 'high_priority', 0) }} high priority
                    </a>
                </div>
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-accentYellow-100">
                    <i data-lucide="radar" class="h-5 w-5 text-accentYellow-900"></i>
                </div>
            </div>
        </div>
    </div>

    @php
        $metricCards = [
            'content_roi' => [
                'label' => 'Content ROI',
                'hint' => 'Engagement and conversion signals blended into one score.',
            ],
            'ai_visibility' => [
                'label' => 'AI Visibility',
                'hint' => 'Estimated visibility based on citation and mention signals.',
            ],
            'ai_seo_score' => [
                'label' => 'AI SEO Score',
                'hint' => 'Combined score using ROI and normalized AI visibility.',
            ],
        ];
    @endphp

    <div class="mb-8 rounded-lg border border-border bg-surface p-4">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3 class="font-semibold text-textPrimary">Performance Signals</h3>
                <p class="text-xs text-textSecondary">
                    Based on {{ (int) ($performanceSummary['tracked_content_count'] ?? 0) }} tracked pages in recent content.
                </p>
            </div>
            <a href="{{ route('app.content.index') }}" class="text-xs text-primary hover:underline">Open content analytics</a>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($metricCards as $metricKey => $metric)
                @php
                    $metricData = data_get($performanceSummary, $metricKey, []);
                    $metricValue = data_get($metricData, 'value');
                    $metricSamples = (int) data_get($metricData, 'samples', 0);
                    $metricUpdated = data_get($metricData, 'updated_at');
                @endphp
                <div class="rounded border border-border bg-background p-3">
                    <p class="text-xs text-textSecondary">{{ $metric['label'] }}</p>
                    @if (is_numeric($metricValue))
                        <p class="mt-1 text-2xl font-semibold text-textPrimary">{{ number_format((float) $metricValue, 1) }}</p>
                        <p class="mt-1 text-xs text-textSecondary">{{ $metricSamples }} page{{ $metricSamples === 1 ? '' : 's' }} with data</p>
                    @else
                        <p class="mt-2 text-sm font-medium text-textSecondary">Waiting for tracking data</p>
                    @endif
                    <p class="mt-2 text-xs text-textSecondary">{{ $metricUpdated ? 'Updated '.$metricUpdated->diffForHumans() : 'No refresh yet' }}</p>
                    <p class="mt-1 text-[11px] text-textSecondary">{{ $metric['hint'] }}</p>
                </div>
            @endforeach
        </div>
    </div>

    <div class="bg-surface rounded-lg border border-border p-4">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="font-semibold text-textPrimary">Latest Content</h3>
            <a href="{{ route('app.content.index') }}" class="inline-flex items-center gap-1 text-sm text-textSecondary hover:text-textPrimary">
                View all <i data-lucide="arrow-right" class="h-4 w-4 rounded-sm bg-accentYellow-100 p-0.5 text-accentYellow-900"></i>
            </a>
        </div>
        <x-mobile-card-list class="md:hidden">
            @forelse ($recentContentTree as $article)
                @php
                    $canonical = $article['canonical_content'];
                    $variants = collect($article['visible_variants'] ?? []);
                    $allVariants = collect($article['all_variants'] ?? []);
                    $hasChildren = $variants->count() > 0;
                    $articleInsight = $recentContentInsights[$canonical->id] ?? [];
                @endphp
                <article class="pl-mobile-card">
                    <div class="pl-mobile-card__header">
                        <div class="min-w-0">
                            <a class="pl-mobile-card__title pl-line-clamp-2" href="{{ route('app.content.show', $canonical) }}">{{ $article['title'] }}</a>
                            <div class="pl-mobile-card__badges">
                                <x-status-badge
                                    :label="data_get($article, 'summary.status_label', 'In progress')"
                                    :color="data_get($article, 'summary.status_color', 'slate')"
                                />
                                <span class="inline-flex items-center rounded-full border border-border bg-surfaceSubtle px-2 py-1 text-[11px] font-medium text-textSecondary">{{ $article['role_label'] }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="pl-mobile-card__badges">
                        @foreach ($allVariants as $variant)
                            <span class="inline-flex items-center gap-1 rounded-full border px-2 py-1 text-[11px] font-medium {{ ($variant['is_source'] ?? false) ? $localeBadgeClasses['source'] : $localeBadgeClasses['variant'] }}">
                                <span>{{ $variant['locale'] }}</span>
                                @if (! empty($variant['source_locale']))
                                    <span class="rounded-full bg-white/70 px-1 py-0.5 text-[9px] uppercase tracking-wide">SRC {{ $variant['source_locale'] }}</span>
                                @endif
                            </span>
                        @endforeach
                    </div>

                    <div class="pl-mobile-card__meta">
                        <div>
                            <span>Site</span>
                            <strong>{{ $article['site_label'] }}</strong>
                        </div>
                        <div>
                            <span>Publish</span>
                            <strong>{{ data_get($article, 'summary.publication_progress_text', 'No publications yet') }}</strong>
                        </div>
                        <div>
                            <span>Performance</span>
                            <strong>
                                @if (is_numeric(data_get($articleInsight, 'roi_score')))
                                    ROI {{ number_format((float) data_get($articleInsight, 'roi_score'), 1) }}
                                @else
                                    {{ data_get($article, 'performance.message', 'Not enough data yet.') }}
                                @endif
                            </strong>
                        </div>
                        <div>
                            <span>Updated</span>
                            <strong>{{ $canonical->updated_at?->format('Y-m-d H:i') ?? 'n/a' }}</strong>
                        </div>
                    </div>

                    @if ($hasChildren)
                        <details class="rounded-md border border-border bg-background px-3 py-2">
                            <summary class="cursor-pointer list-none text-xs font-medium text-textPrimary">Translations and variants</summary>
                            <div class="mt-3 space-y-2">
                                @foreach ($variants as $variant)
                                    @php
                                        $variantContent = $variant['content'];
                                        $variantPresenter = $variant['presenter'];
                                        $variantInsight = $recentContentInsights[$variantContent->id] ?? [];
                                    @endphp
                                    <div class="rounded-md border border-border bg-surface p-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="inline-flex items-center gap-1 rounded-full border px-2 py-1 text-[11px] font-medium {{ ($variant['is_source'] ?? false) ? $localeBadgeClasses['source'] : $localeBadgeClasses['variant'] }}">{{ $variant['locale'] }}</span>
                                            <x-status-badge
                                                :status="$variantPresenter->deliveryStatus()"
                                                :tooltip="$variantPresenter->lastErrorMessage()"
                                            />
                                        </div>
                                        <a class="mt-2 block text-sm font-medium text-textPrimary hover:underline" href="{{ route('app.content.show', $variantContent) }}">{{ $variantContent->title }}</a>
                                        <p class="mt-1 text-xs text-textSecondary">{{ $variant['site_label'] }} · {{ $variant['destination_label'] }}</p>
                                        <p class="mt-2 text-xs text-textSecondary">
                                            @if (is_numeric(data_get($variantInsight, 'roi_score')))
                                                ROI {{ number_format((float) data_get($variantInsight, 'roi_score'), 1) }}
                                            @else
                                                {{ (string) data_get($variantInsight, 'status_message', 'Waiting for data.') }}
                                            @endif
                                        </p>
                                    </div>
                                @endforeach
                            </div>
                        </details>
                    @endif

                    <div class="pl-mobile-card__actions">
                        <a href="{{ route('app.content.show', $canonical) }}" class="pl-btn-secondary">Open</a>
                    </div>
                </article>
            @empty
                <div class="pl-mobile-card text-sm text-textSecondary">Get started by connecting your website and creating your first piece of content.</div>
            @endforelse
        </x-mobile-card-list>

        <div class="hidden overflow-x-auto md:block">
            <table class="w-full min-w-[980px] text-sm">
                <thead>
                    <tr class="text-left text-textSecondary">
                        <th class="w-14 pb-2 pr-4 font-medium whitespace-nowrap">Item</th>
                        <th class="pb-2 font-medium">Title</th>
                        <th class="pb-2 font-medium">Locales</th>
                        <th class="pb-2 font-medium">Site</th>
                        <th class="pb-2 font-medium">Status</th>
                        <th class="pb-2 font-medium">Publish</th>
                        <th class="pb-2 font-medium text-right">Performance</th>
                        <th class="pb-2 font-medium">Updated</th>
                        <th class="pb-2 font-medium">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($recentContentTree as $article)
                        @php
                            $canonical = $article['canonical_content'];
                            $variants = collect($article['visible_variants'] ?? []);
                            $allVariants = collect($article['all_variants'] ?? []);
                            $hasChildren = $variants->count() > 0;
                            $articleInsight = $recentContentInsights[$canonical->id] ?? [];
                        @endphp
                        <tr
                            class="dashboard-content-tree-parent-row transition-colors hover:bg-background/70"
                            @if ($hasChildren)
                                data-dashboard-content-tree-row
                                data-target="{{ $article['key'] }}"
                                data-expanded="false"
                                tabindex="0"
                                role="button"
                                aria-expanded="false"
                            @endif
                        >
                            <td class="py-3 pr-4">
                                @if ($hasChildren)
                                    <button
                                        type="button"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded border border-border text-textSecondary transition hover:bg-surfaceSubtle hover:text-textPrimary"
                                        data-dashboard-content-tree-toggle
                                        data-target="{{ $article['key'] }}"
                                        aria-expanded="false"
                                        aria-controls="dashboard-content-tree-children-{{ $article['key'] }}"
                                        data-no-row-toggle
                                    >
                                        <i data-lucide="chevron-right" class="h-4 w-4 transition-transform duration-150 ease-out"></i>
                                    </button>
                                @endif
                            </td>
                            <td class="py-3">
                                <a href="{{ route('app.content.show', $canonical) }}" class="font-medium text-textPrimary hover:underline">{{ $article['title'] }}</a>
                                <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-textSecondary">
                                    <span>{{ $article['role_label'] }}</span>
                                    <span>{{ data_get($article, 'summary.translation_progress_text') }}</span>
                                </div>
                            </td>
                            <td class="py-3">
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($allVariants as $variant)
                                        <span class="inline-flex items-center gap-1 rounded-full border px-2 py-1 text-[11px] font-medium {{ ($variant['is_source'] ?? false) ? $localeBadgeClasses['source'] : $localeBadgeClasses['variant'] }}">
                                            <span>{{ $variant['locale'] }}</span>
                                            @if (! empty($variant['source_locale']))
                                                <span class="rounded-full bg-white/70 px-1 py-0.5 text-[9px] uppercase tracking-wide">SRC {{ $variant['source_locale'] }}</span>
                                            @endif
                                        </span>
                                    @endforeach
                                </div>
                            </td>
                            <td class="py-3">{{ $article['site_label'] }}</td>
                            <td class="py-3">
                                <x-status-badge
                                    :label="data_get($article, 'summary.status_label', 'In progress')"
                                    :color="data_get($article, 'summary.status_color', 'slate')"
                                />
                            </td>
                            <td class="py-3">
                                <div class="text-xs text-textPrimary">{{ data_get($article, 'summary.publication_progress_text', 'No publications yet') }}</div>
                                @if (data_get($article, 'summary.failed_deliveries', 0) > 0)
                                    <div class="mt-1 text-xs text-amber-600">Needs attention in {{ data_get($article, 'summary.failed_deliveries') }} locale(s)</div>
                                @endif
                            </td>
                            <td class="py-3 text-right">
                                <div class="text-xs text-textSecondary">{{ data_get($article, 'performance.message', 'Not enough data yet.') }}</div>
                                @if (is_numeric(data_get($articleInsight, 'roi_score')))
                                    <div class="mt-1 text-xs text-textPrimary">ROI {{ number_format((float) data_get($articleInsight, 'roi_score'), 1) }}</div>
                                @endif
                            </td>
                            <td class="py-3">{{ $canonical->updated_at?->format('Y-m-d H:i') }}</td>
                            <td class="py-3">
                                <a href="{{ route('app.content.show', $canonical) }}" class="text-link hover:text-linkHover underline">Open</a>
                            </td>
                        </tr>
                        <tr
                            id="dashboard-content-tree-children-{{ $article['key'] }}"
                            data-dashboard-content-tree-children="{{ $article['key'] }}"
                            class="hidden"
                        >
                            <td colspan="9" class="pb-4 pl-8 pr-0">
                                <div
                                    data-dashboard-content-tree-panel
                                    class="overflow-hidden rounded-md border border-border bg-background/80 px-3 py-3 opacity-0 transition-all duration-150 ease-out"
                                    style="max-height: 0; transform: translateY(-4px);"
                                    aria-hidden="true"
                                >
                                    <div class="space-y-2">
                                        @foreach ($variants as $variant)
                                            @php
                                                $variantContent = $variant['content'];
                                                $variantPresenter = $variant['presenter'];
                                                $variantInsight = $recentContentInsights[$variantContent->id] ?? [];
                                            @endphp
                                            <div class="grid gap-3 rounded-md border border-border/70 bg-surface px-3 py-3 md:ml-3 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]">
                                                <div class="min-w-0">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <span class="inline-flex items-center gap-1 rounded-full border px-2 py-1 text-[11px] font-medium {{ ($variant['is_source'] ?? false) ? $localeBadgeClasses['source'] : $localeBadgeClasses['variant'] }}">
                                                            <span>{{ $variant['locale'] }}</span>
                                                        </span>
                                                        @if (! empty($variant['source_locale']))
                                                            <span class="inline-flex items-center rounded-full border border-border bg-surfaceSubtle px-2 py-1 text-[11px] font-medium text-textSecondary">SRC {{ $variant['source_locale'] }}</span>
                                                        @endif
                                                        @if ($variant['is_source'] ?? false)
                                                            <span class="inline-flex items-center rounded-full border border-border bg-surfaceSubtle px-2 py-1 text-[11px] font-medium text-textSecondary">Source</span>
                                                        @endif
                                                    </div>
                                                    <a class="mt-2 block min-w-0 truncate text-sm font-medium text-textPrimary hover:underline" href="{{ route('app.content.show', $variantContent) }}">
                                                        {{ $variantContent->title }}
                                                    </a>
                                                    <div class="mt-1 text-xs text-textSecondary">{{ $variant['site_label'] }} · {{ $variant['destination_label'] }}</div>
                                                </div>
                                                <div>
                                                    <div class="text-[11px] uppercase tracking-wide text-textSecondary">Draft</div>
                                                    <div class="mt-1"><x-content-status :content="$variantContent" :show-remote="false" /></div>
                                                </div>
                                                <div>
                                                    <div class="text-[11px] uppercase tracking-wide text-textSecondary">Publish</div>
                                                    <div class="mt-1">
                                                        <x-status-badge
                                                            :status="$variantPresenter->deliveryStatus()"
                                                            :tooltip="$variantPresenter->lastErrorMessage()"
                                                        />
                                                    </div>
                                                </div>
                                                <div>
                                                    <div class="text-[11px] uppercase tracking-wide text-textSecondary">Performance</div>
                                                    <div class="mt-1 text-xs text-textPrimary">
                                                        @if (is_numeric(data_get($variantInsight, 'roi_score')))
                                                            ROI {{ number_format((float) data_get($variantInsight, 'roi_score'), 1) }}
                                                        @else
                                                            {{ (string) data_get($variantInsight, 'status_message', 'Waiting for data.') }}
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="text-xs text-textSecondary">
                                                    <div>{{ $variantContent->updated_at?->format('Y-m-d H:i') ?? 'n/a' }}</div>
                                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                                        <a class="text-link underline hover:text-linkHover" href="{{ route('app.content.show', $variantContent) }}">Open</a>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9">
                                <div class="py-10 text-center">
                                    <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-primary/10">
                                        <i data-lucide="rocket" class="h-7 w-7 text-primary"></i>
                                    </div>
                                    <h3 class="text-base font-semibold text-textPrimary">Welcome to Argusly!</h3>
                                    <p class="mt-2 max-w-sm mx-auto text-sm text-textSecondary">Get started by connecting your website and creating your first piece of content.</p>
                                    <div class="mt-5 flex flex-wrap items-center justify-center gap-3">
                                        <a href="{{ route('app.sites') }}" class="inline-flex items-center gap-2 rounded bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90">
                                            <i data-lucide="globe" class="h-4 w-4"></i>
                                            Connect website
                                        </a>
                                        <a href="{{ route('app.briefs.create') }}" class="inline-flex items-center gap-2 rounded border border-border px-4 py-2 text-sm font-medium text-textPrimary hover:bg-surfaceSubtle">
                                            <i data-lucide="plus" class="h-4 w-4"></i>
                                            Create brief
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const storageKey = 'argusly.dashboard.latest-content.expanded.v1';
            const interactiveSelector = 'a, button, input, select, textarea, label, summary, [data-no-row-toggle]';

            const readExpandedKeys = () => {
                try {
                    const value = window.localStorage.getItem(storageKey);
                    const parsed = value ? JSON.parse(value) : [];

                    return Array.isArray(parsed) ? new Set(parsed) : new Set();
                } catch (error) {
                    return new Set();
                }
            };

            const persistExpandedKeys = (expandedKeys) => {
                try {
                    window.localStorage.setItem(storageKey, JSON.stringify(Array.from(expandedKeys)));
                } catch (error) {
                    // Ignore storage failures.
                }
            };

            const expandedKeys = readExpandedKeys();

            const setExpanded = (target, shouldExpand, options = {}) => {
                const row = document.querySelector(`[data-dashboard-content-tree-row][data-target="${target}"]`);
                const button = document.querySelector(`[data-dashboard-content-tree-toggle][data-target="${target}"]`);
                const childRow = document.querySelector(`[data-dashboard-content-tree-children="${target}"]`);
                const panel = childRow?.querySelector('[data-dashboard-content-tree-panel]');
                const icon = button?.querySelector('[data-lucide]');

                if (!row || !button || !childRow || !panel) {
                    return;
                }

                const immediate = options.immediate === true;
                const closeAfterTransition = () => {
                    childRow.classList.add('hidden');
                    panel.removeEventListener('transitionend', closeAfterTransition);
                };

                row.setAttribute('aria-expanded', shouldExpand ? 'true' : 'false');
                row.dataset.expanded = shouldExpand ? 'true' : 'false';
                button.setAttribute('aria-expanded', shouldExpand ? 'true' : 'false');
                panel.setAttribute('aria-hidden', shouldExpand ? 'false' : 'true');

                if (icon) {
                    icon.setAttribute('data-lucide', shouldExpand ? 'chevron-down' : 'chevron-right');
                }

                panel.removeEventListener('transitionend', closeAfterTransition);

                if (shouldExpand) {
                    expandedKeys.add(target);
                    childRow.classList.remove('hidden');

                    if (immediate) {
                        panel.style.maxHeight = 'none';
                        panel.style.opacity = '1';
                        panel.style.transform = 'translateY(0)';
                    } else {
                        panel.style.maxHeight = '0px';
                        panel.style.opacity = '0';
                        panel.style.transform = 'translateY(-4px)';

                        requestAnimationFrame(() => {
                            panel.style.maxHeight = `${panel.scrollHeight}px`;
                            panel.style.opacity = '1';
                            panel.style.transform = 'translateY(0)';
                        });
                    }
                } else {
                    expandedKeys.delete(target);

                    if (immediate) {
                        panel.style.maxHeight = '0px';
                        panel.style.opacity = '0';
                        panel.style.transform = 'translateY(-4px)';
                        childRow.classList.add('hidden');
                    } else {
                        panel.style.maxHeight = `${panel.scrollHeight}px`;
                        panel.style.opacity = '1';
                        panel.style.transform = 'translateY(0)';

                        requestAnimationFrame(() => {
                            panel.style.maxHeight = '0px';
                            panel.style.opacity = '0';
                            panel.style.transform = 'translateY(-4px)';
                        });

                        panel.addEventListener('transitionend', closeAfterTransition);
                    }
                }

                persistExpandedKeys(expandedKeys);

                if (window.lucide?.createIcons) {
                    window.lucide.createIcons();
                }
            };

            const toggleExpanded = (target) => {
                const row = document.querySelector(`[data-dashboard-content-tree-row][data-target="${target}"]`);
                const isExpanded = row?.getAttribute('aria-expanded') === 'true';

                setExpanded(target, !isExpanded);
            };

            document.querySelectorAll('[data-dashboard-content-tree-toggle]').forEach((button) => {
                const target = button.getAttribute('data-target');

                if (!target) {
                    return;
                }

                button.addEventListener('click', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    toggleExpanded(target);
                });
            });

            document.querySelectorAll('[data-dashboard-content-tree-row]').forEach((row) => {
                const target = row.getAttribute('data-target');

                if (!target) {
                    return;
                }

                row.addEventListener('click', (event) => {
                    if (event.target instanceof Element && event.target.closest(interactiveSelector)) {
                        return;
                    }

                    toggleExpanded(target);
                });

                row.addEventListener('keydown', (event) => {
                    if ((event.key !== 'Enter' && event.key !== ' ') || event.target instanceof Element && event.target.closest(interactiveSelector)) {
                        return;
                    }

                    event.preventDefault();
                    toggleExpanded(target);
                });

                setExpanded(target, expandedKeys.has(target), { immediate: true });
            });
        });
    </script>
@endsection
