@extends('layouts.app', ['title' => __('app.dashboard.title')])

@section('content')
    @php
        $localeBadgeClasses = [
            'source' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            'variant' => 'border-sky-200 bg-sky-50 text-sky-700',
        ];
        $openOpportunityCount = (int) data_get($actionFirstDashboard, 'open_opportunities.count', 0);
        $recommendedActionCount = $openOpportunityCount + (int) data_get($opportunityIntelligenceSummary, 'high_priority', 0);
        $urgentDecisionCount = (int) data_get($actionFirstDashboard, 'risk_summary.count', 0) + (int) data_get($distributionSummary, 'failed_posts', 0) + (int) data_get($programmaticGrowthSummary, 'blocked_items', 0);
        $progressScore = (int) data_get($actionFirstDashboard, 'journey_step.progress', 0);
        $trackedContentCount = (int) data_get($performanceSummary, 'tracked_content_count', 0);
    @endphp

    <div class="mb-6">
        <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Argusly Command Center</p>
        <h1 class="text-2xl font-semibold tracking-tight text-textPrimary">{{ __('app.dashboard.title') }}</h1>
        <p class="text-textSecondary mt-1">{{ __('app.dashboard.subtitle') }}</p>
    </div>

    <x-assistant.timeline
        class="mb-8"
        :items="$assistantFeed ?? collect()"
        title="What Argusly is doing for you"
        description="Opportunities, actions, impact, progress, and decisions in one guided assistant view."
    />

    <section class="mb-8 rounded-lg border border-border bg-surface p-5" aria-label="Growth Health">
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Growth Health</p>
                <h2 class="mt-1 text-lg font-semibold text-textPrimary">What matters right now</h2>
            </div>
            <p class="text-sm text-textSecondary">{{ $trackedContentCount }} tracked page{{ $trackedContentCount === 1 ? '' : 's' }} contributing to recent results</p>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-md border border-border bg-background p-4">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm text-textSecondary">Opportunities</p>
                    <i data-lucide="target" class="h-4 w-4 text-primary"></i>
                </div>
                <p class="mt-2 text-3xl font-semibold text-textPrimary">{{ number_format($openOpportunityCount) }}</p>
                <p class="mt-1 text-xs text-textSecondary">{{ (int) data_get($opportunityIntelligenceSummary, 'high_priority', 0) }} high impact</p>
            </div>
            <div class="rounded-md border border-border bg-background p-4">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm text-textSecondary">Actions</p>
                    <i data-lucide="list-checks" class="h-4 w-4 text-primary"></i>
                </div>
                <p class="mt-2 text-3xl font-semibold text-textPrimary">{{ number_format($recommendedActionCount) }}</p>
                <p class="mt-1 text-xs text-textSecondary">{{ (int) data_get($distributionSummary, 'variants_pending', 0) }} ready for review</p>
            </div>
            <div class="rounded-md border border-border bg-background p-4">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm text-textSecondary">Impact</p>
                    <i data-lucide="trending-up" class="h-4 w-4 text-primary"></i>
                </div>
                <p class="mt-2 text-3xl font-semibold text-textPrimary">{{ data_get($actionFirstDashboard, 'recommended_action.estimated_impact', __('app.dashboard_action_first.low')) }}</p>
                <p class="mt-1 text-xs text-textSecondary">{{ $urgentDecisionCount }} urgent decision{{ $urgentDecisionCount === 1 ? '' : 's' }}</p>
            </div>
            <div class="rounded-md border border-border bg-background p-4">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm text-textSecondary">Progress</p>
                    <i data-lucide="gauge" class="h-4 w-4 text-primary"></i>
                </div>
                <p class="mt-2 text-3xl font-semibold text-textPrimary">{{ $progressScore }}%</p>
                <div class="mt-3 h-2 overflow-hidden rounded-full bg-surfaceMuted">
                    <div class="h-full rounded-full bg-primary" style="width: {{ max(0, min(100, $progressScore)) }}%"></div>
                </div>
            </div>
        </div>
    </section>

    <div class="mb-8 space-y-4">
        <x-dashboard.recommended-action-widget :action="data_get($actionFirstDashboard, 'recommended_action')" />

        <div class="grid gap-4 lg:grid-cols-2">
            <x-dashboard.recommended-actions-widget :summary="$recommendedActionsSummary ?? []" />
            <x-dashboard.open-opportunities-widget :summary="data_get($actionFirstDashboard, 'open_opportunities')" />
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-dashboard.risk-summary-widget :summary="data_get($actionFirstDashboard, 'risk_summary')" />
            <x-dashboard.next-journey-step-widget :step="data_get($actionFirstDashboard, 'journey_step')" />
        </div>

        <x-dashboard.intelligence-feed-widget :items="data_get($actionFirstDashboard, 'intelligence_feed', collect())" />

        <x-dashboard.human-signals-widget :summary="$humanSignalsSummary ?? []" />
    </div>

    @if (!empty($activation) && !data_get($activation, 'is_active'))
        <x-activation-banner class="mb-8" :activation="$activation" />
    @elseif (!empty($isEmptyDashboard))
        <div class="mb-8 rounded-md border border-border bg-surface p-5">
            <h2 class="text-base font-semibold text-textPrimary">Start your first growth mission</h2>
            <p class="mt-1 text-sm text-textSecondary">The Command Center is waiting for enough context. Add your site and brand inputs so Argusly can recommend the first useful action.</p>
            <a href="{{ route('app.activation.index') }}" class="mt-4 inline-flex h-9 items-center gap-2 rounded-md bg-primary px-3 text-sm font-semibold text-white hover:bg-primaryHover">
                <i data-lucide="list-checks" class="h-4 w-4"></i>
                Start setup
            </a>
        </div>
    @endif

    @php
        $publicationQueue = collect($publicationQueue ?? []);
        $publicationQueueTimezone = $publicationQueueTimezone ?? 'Europe/Amsterdam';
        $publicationStatusClasses = [
            'scheduled' => 'border-sky-200 bg-sky-50 text-sky-700',
            'queued' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            'rate_limited' => 'border-amber-200 bg-amber-50 text-amber-800',
            'failed' => 'border-red-200 bg-red-50 text-red-700',
        ];
    @endphp

    <section class="mb-8 rounded-lg border border-border bg-surface" aria-label="Publication queue">
        <div class="flex flex-col gap-3 border-b border-border p-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Publication queue</p>
                <h2 class="mt-1 text-lg font-semibold text-textPrimary">Ready to go live</h2>
                <p class="mt-1 text-sm text-textSecondary">Scheduled, queued, rate-limited, and failed LinkedIn publications that need attention soon.</p>
            </div>
            <a href="{{ route('app.agentic-marketing.distribution.index') }}" class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-border px-3 text-sm font-medium text-textPrimary hover:bg-background">
                <i data-lucide="send" class="h-4 w-4"></i>
                Open distribution
            </a>
        </div>

        <div class="divide-y divide-border">
            @forelse ($publicationQueue as $publication)
                @php
                    $publicationStatus = (string) ($publication->status?->value ?? $publication->status);
                    $scheduledAt = $publication->scheduled_for?->copy()->timezone($publicationQueueTimezone);
                    $retryAt = $publication->next_retry_at?->copy()->timezone($publicationQueueTimezone);
                    $queueTime = $retryAt ?: $scheduledAt ?: $publication->queued_at?->copy()->timezone($publicationQueueTimezone) ?: $publication->created_at?->copy()->timezone($publicationQueueTimezone);
                    $isPastDue = $scheduledAt && $scheduledAt->isPast() && in_array($publicationStatus, ['scheduled', 'queued', 'rate_limited'], true);
                    $title = $publication->variant?->campaign?->name
                        ?: $publication->campaign?->name
                        ?: (string) data_get($publication->payload_snapshot, 'title', 'LinkedIn post');
                    $accountLabel = $publication->socialAccount?->display_name ?: 'No account assigned';
                    $statusLabel = (string) str($publicationStatus)->replace('_', ' ')->title();
                    $statusClass = $publicationStatusClasses[$publicationStatus] ?? 'border-border bg-background text-textSecondary';
                    $publicationError = $publication->publicErrorMessage();
                @endphp
                <article class="grid gap-3 p-4 md:grid-cols-[minmax(0,1fr)_10rem_12rem_auto] md:items-center">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate text-sm font-semibold text-textPrimary">{{ $title }}</p>
                            @if ($isPastDue)
                                <span class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-800">Due</span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-textSecondary">{{ $accountLabel }} · LinkedIn</p>
                        @if ($publicationError)
                            <p class="mt-1 line-clamp-1 text-xs text-red-700">{{ $publicationError }}</p>
                        @endif
                    </div>
                    <div>
                        <span class="inline-flex rounded-full border px-2.5 py-1 text-xs font-medium {{ $statusClass }}">{{ $statusLabel }}</span>
                    </div>
                    <div class="text-sm text-textSecondary">
                        @if ($queueTime)
                            <span class="font-medium text-textPrimary">{{ $queueTime->format('d-m-Y H:i') }}</span>
                        @else
                            <span>No time set</span>
                        @endif
                    </div>
                    <a href="{{ route('app.agentic-marketing.distribution.index') }}" class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-border px-3 text-sm font-medium text-textPrimary hover:bg-background">
                        Review
                        <i data-lucide="arrow-right" class="h-4 w-4"></i>
                    </a>
                </article>
            @empty
                <div class="flex min-h-28 items-center justify-center px-4 text-center">
                    <div>
                        <i data-lucide="calendar-check" class="mx-auto h-7 w-7 text-textFaint"></i>
                        <p class="mt-2 text-sm font-medium text-textPrimary">No active publication queue</p>
                        <p class="mt-1 text-sm text-textSecondary">Approved LinkedIn posts will appear here once they are scheduled or queued.</p>
                    </div>
                </div>
            @endforelse
        </div>
    </section>

    <div class="mb-4">
        <h2 class="text-lg font-semibold text-textPrimary">{{ __('app.dashboard_action_first.supporting_metrics') }}</h2>
        <p class="mt-1 text-sm text-textSecondary">{{ __('app.dashboard_action_first.supporting_metrics_hint') }}</p>
    </div>

    <div class="mb-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
        <div class="bg-surface rounded-lg border border-border p-5">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-sm text-textSecondary">Content progress</p>
                    <p class="text-3xl font-semibold text-textPrimary mt-1">{{ $briefCount }}</p>
                    <p class="text-sm text-textSecondary mt-1">planned content items</p>
                </div>
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-accentYellow-100">
                    <i data-lucide="file-text" class="h-5 w-5 text-accentYellow-900"></i>
                </div>
            </div>
        </div>
        <div class="bg-surface rounded-lg border border-border p-5">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-sm text-textSecondary">Automated growth</p>
                    <p class="text-3xl font-semibold text-textPrimary mt-1">{{ (int) data_get($programmaticGrowthSummary, 'active_growth_programs', 0) }}</p>
                    <a href="{{ route('app.growth-programs.index') }}" class="text-sm mt-2 inline-block text-primary hover:underline">Open growth actions</a>
                </div>
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-accentYellow-100">
                    <i data-lucide="workflow" class="h-5 w-5 text-accentYellow-900"></i>
                </div>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-textSecondary">
                <span>{{ (int) data_get($programmaticGrowthSummary, 'opportunities_ready_for_scaling', 0) }} scalable opportunities</span>
                <span>{{ (int) data_get($programmaticGrowthSummary, 'content_assets_ready', 0) }} ready assets</span>
                <span>{{ (int) data_get($programmaticGrowthSummary, 'scheduled_publication_records', 0) }} scheduled actions</span>
                <span>{{ (int) data_get($programmaticGrowthSummary, 'blocked_items', 0) }} need decisions</span>
            </div>
        </div>
        <div class="bg-surface rounded-lg border border-border p-5">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-sm text-textSecondary">Connected sites</p>
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
                    <p class="text-sm text-textSecondary">Action capacity</p>
                    <p class="text-3xl font-semibold text-textPrimary mt-1">{{ $totalAvailableCredits ?? 0 }}</p>
                    <a href="{{ route('app.billing.index') }}" class="text-sm mt-2 inline-block text-primary hover:underline">Add capacity</a>
                </div>
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-accentYellow-100">
                    <i data-lucide="wallet" class="h-5 w-5 text-accentYellow-900"></i>
                </div>
            </div>
        </div>
        <div class="bg-surface rounded-lg border border-border p-5">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-sm text-textSecondary">Scheduled actions</p>
                    <p class="text-3xl font-semibold text-textPrimary mt-1">{{ (int) data_get($distributionSummary, 'scheduled_posts', 0) }}</p>
                    <a href="{{ route('app.agentic-marketing.distribution.index') }}" class="text-sm mt-2 inline-block text-primary hover:underline">
                        {{ (int) data_get($distributionSummary, 'variants_pending', 0) }} action{{ (int) data_get($distributionSummary, 'variants_pending', 0) === 1 ? '' : 's' }} pending
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
                        {{ (int) data_get($opportunityIntelligenceSummary, 'high_priority', 0) }} high impact
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
                'hint' => 'Engagement and conversion performance blended into one score.',
            ],
            'ai_visibility' => [
                'label' => 'AI Visibility',
                'hint' => 'Estimated visibility based on citations and mentions.',
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
                <h3 class="font-semibold text-textPrimary">Recent Results</h3>
                <p class="text-xs text-textSecondary">
                    Based on {{ (int) ($performanceSummary['tracked_content_count'] ?? 0) }} tracked pages in recent content.
                </p>
            </div>
            <a href="{{ route('app.content.pipeline.index') }}" class="text-xs text-primary hover:underline">Open content pipeline</a>
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
            <a href="{{ route('app.content.pipeline.index') }}" class="inline-flex items-center gap-1 text-sm text-textSecondary hover:text-textPrimary">
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
