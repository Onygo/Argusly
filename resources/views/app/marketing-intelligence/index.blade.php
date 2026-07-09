@extends('layouts.app', ['title' => $title])

@php
    $isCustomRange = (bool) data_get($timeWindowSelection, 'is_custom_range', false);
    $usesToDate = (bool) data_get($timeWindowSelection, 'uses_to_date', false);
    $activeSourceLabel = $clientSite?->name ?: 'All sources';
    $resetQuery = array_filter([
        'workspace' => $workspace->id,
        'site' => $clientSite?->id,
    ], static fn ($value): bool => filled($value));
    $metricDescription = $activeSourceLabel.' · '.$timeWindowSelection['label'].' · '.$timeWindow->start->toDateString().' to '.$timeWindow->end->toDateString();
    $evidenceDrawerAttributes = static function (array $descriptor, string $classes): \Illuminate\View\ComponentAttributeBag {
        $target = data_get($descriptor, 'target', []);
        $fallbackHref = data_get($descriptor, 'href', '#') ?: '#';
        $drawerUrl = data_get($descriptor, 'drawer_url', $fallbackHref);
        $attributes = array_filter(array_merge(data_get($descriptor, 'data_attributes', []), [
            'data-drawer-trigger' => 'button',
            'data-marketing-intelligence-evidence-trigger' => 'true',
            'data-drawer-target' => data_get($target, 'target'),
            'data-drawer-mode' => data_get($target, 'mode', 'inspect'),
            'data-drawer-url' => $drawerUrl,
            'data-drawer-payload' => $descriptor === [] ? null : json_encode($descriptor),
            'aria-haspopup' => 'dialog',
            'href' => $fallbackHref,
            'role' => 'button',
        ]), static fn ($value): bool => $value !== null && $value !== '');

        return (new \Illuminate\View\ComponentAttributeBag($attributes))
            ->class('inline-flex items-center justify-center gap-2 rounded-md border border-border bg-surface font-medium text-textPrimary hover:bg-surfaceMuted focus:outline-none focus:ring-2 focus:ring-primary/30 '.$classes);
    };
@endphp

@section('pageHeader')
    <x-page-header title="Unified Marketing Intelligence Workspace" eyebrow="Marketing Intelligence" icon="sparkles">
        <x-slot:description>See what changed, why it changed, why it matters, and what to do next.</x-slot:description>
    </x-page-header>
@endsection

@section('filterBar')
    <form method="GET" action="{{ route('app.marketing-intelligence.index') }}" class="grid w-full gap-3 md:grid-cols-2 xl:grid-cols-12">
        @if ($workspaces->count() > 1)
            <label class="grid gap-1 text-xs font-medium text-textSecondary xl:col-span-3">
                Workspace
                <select name="workspace" class="pl-select h-9 w-full" onchange="this.form.submit()">
                    @foreach ($workspaces as $option)
                        <option value="{{ $option->id }}" @selected((string) $option->id === (string) $workspace->id)>{{ $option->display_name ?: $option->name }}</option>
                    @endforeach
                </select>
            </label>
        @else
            <input type="hidden" name="workspace" value="{{ $workspace->id }}">
        @endif

        @if ($clientSites->isNotEmpty())
            <label class="grid gap-1 text-xs font-medium text-textSecondary xl:col-span-3">
                Source
                <select name="site" class="pl-select h-9 w-full" onchange="this.form.submit()">
                    <option value="">All sources</option>
                    @foreach ($clientSites as $siteOption)
                        <option value="{{ $siteOption->id }}" @selected((string) $siteOption->id === (string) ($clientSite?->id ?? ''))>{{ $siteOption->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <label class="grid gap-1 text-xs font-medium text-textSecondary xl:col-span-2">
            Timeframe
            <select name="time_window" class="pl-select h-9 w-full" onchange="this.form.submit()">
                @foreach ($timeWindowSelection['options'] as $option)
                    <option value="{{ $option['value'] }}" @selected($option['value'] === $timeWindowSelection['preset'])>{{ $option['label'] }}</option>
                @endforeach
            </select>
        </label>

        @if ($isCustomRange)
            <label class="grid gap-1 text-xs font-medium text-textSecondary xl:col-span-2">
                From
                <input type="date" name="from" value="{{ $timeWindowSelection['from'] ?? $timeWindow->start->toDateString() }}" class="pl-input h-9 w-full">
            </label>

            <label class="grid gap-1 text-xs font-medium text-textSecondary xl:col-span-2">
                To
                <input type="date" name="to" value="{{ $timeWindowSelection['to'] ?? $timeWindow->end->toDateString() }}" class="pl-input h-9 w-full">
            </label>
        @elseif ($usesToDate)
            <label class="grid gap-1 text-xs font-medium text-textSecondary xl:col-span-2">
                Ending
                <input type="date" name="to" value="{{ $timeWindowSelection['to'] ?? $timeWindow->end->toDateString() }}" class="pl-input h-9 w-full">
            </label>
        @endif

        <div class="flex items-end gap-2 xl:col-span-2">
            <button type="submit" class="inline-flex h-9 flex-1 items-center justify-center gap-2 rounded-md border border-border bg-surface px-3 text-sm font-medium text-textPrimary hover:bg-surfaceMuted sm:flex-none">
                <i data-lucide="refresh-cw" class="h-4 w-4"></i>
                Update
            </button>
            <a href="{{ route('app.marketing-intelligence.index', $resetQuery) }}" class="inline-flex h-9 items-center justify-center rounded-md border border-border px-3 text-sm font-medium text-textSecondary hover:bg-surfaceMuted hover:text-textPrimary">Reset</a>
        </div>
    </form>
@endsection

@section('metricSection')
    <x-metric-section title="Intelligence Summary" :description="$metricDescription">
        <x-metric-card label="Trends" :value="number_format((int) data_get($summary, 'trends', 0))" icon="trending-up" :helper="$snapshot->observationsCount.' evidence row'.($snapshot->observationsCount === 1 ? '' : 's')" />
        <x-metric-card label="Risks" :value="number_format((int) data_get($summary, 'risks', 0))" icon="triangle-alert" helper="Signals that may need mitigation" />
        <x-metric-card label="Opportunities" :value="number_format((int) data_get($summary, 'opportunities', 0))" icon="sparkle" helper="Growth momentum worth reviewing" />
        <x-metric-card label="Recommendations" :value="number_format((int) data_get($summary, 'recommendations', 0))" icon="list-checks" helper="Next actions available" />
    </x-metric-section>
@endsection

@section('content')
    @if (filled(data_get($timeWindowSelection, 'filter_notice')))
        <x-alert class="mb-6">{{ data_get($timeWindowSelection, 'filter_notice') }}</x-alert>
    @endif

    @if (! $hasWorkspaceData)
        <x-empty-state
            title="No intelligence yet"
            description="No trend evidence, recommendations, reports, or briefings were found for this source and timeframe."
            icon="inbox"
            class="mb-6"
        />
    @endif

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <div class="space-y-6">
            <section class="rounded-lg border border-border bg-surface" aria-label="What changed">
                <div class="border-b border-border p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">What changed?</p>
                    <h2 class="mt-1 text-lg font-semibold text-textPrimary">Key trends</h2>
                </div>

                <div class="divide-y divide-border">
                    @forelse ($trendCards as $trend)
                        <article id="evidence-{{ \Illuminate\Support\Str::slug($trend['key']) }}" class="p-5">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-full border border-border bg-background px-2.5 py-1 text-[11px] font-semibold text-textSecondary">Trend</span>
                                        <span class="rounded-full border border-border bg-background px-2.5 py-1 text-[11px] font-semibold text-textSecondary">{{ $trend['direction'] }}</span>
                                        <span class="{{ $trend['tone'] === 'risk' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' }} rounded-full border px-2.5 py-1 text-[11px] font-semibold">{{ $trend['impact'] }} impact</span>
                                    </div>
                                    <h3 class="mt-3 text-base font-semibold text-textPrimary">{{ $trend['title'] }}</h3>
                                    <p class="mt-2 max-w-3xl text-sm leading-6 text-textSecondary">{{ $trend['explanation'] }}</p>
                                </div>
                                <a {{ $evidenceDrawerAttributes($trend['descriptor'], 'h-9 w-full shrink-0 px-3 py-1.5 text-xs sm:w-auto') }}>
                                    <i data-lucide="panel-right-open" class="h-4 w-4"></i>
                                    Evidence
                                </a>
                            </div>

                            <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-textFaint">Timeframe</dt>
                                    <dd class="mt-1 text-sm text-textPrimary">{{ $trend['timeframe'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-textFaint">Confidence</dt>
                                    <dd class="mt-1 text-sm text-textPrimary">{{ $trend['confidence'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-textFaint">Source</dt>
                                    <dd class="mt-1 text-sm text-textPrimary">{{ $trend['source'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-textFaint">Change</dt>
                                    <dd class="mt-1 text-sm text-textPrimary">{{ $trend['change'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-textFaint">Evidence</dt>
                                    <dd class="mt-1 text-sm text-textPrimary">{{ $trend['evidence_count'] }} row{{ $trend['evidence_count'] === 1 ? '' : 's' }}</dd>
                                </div>
                            </dl>
                        </article>
                    @empty
                        <x-empty-state title="No trend evidence in this timeframe" description="Trend cards appear when enough evidence exists for this period and the comparison period." icon="activity" class="rounded-none border-0 shadow-none" />
                    @endforelse
                </div>
            </section>

            <section class="grid gap-6 lg:grid-cols-2" aria-label="Why it matters">
                <div class="rounded-lg border border-border bg-surface">
                    <div class="border-b border-border p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Why does it matter?</p>
                        <h2 class="mt-1 text-lg font-semibold text-textPrimary">Risk</h2>
                    </div>
                    <div class="divide-y divide-border">
                        @forelse ($riskCards as $risk)
                            <article class="p-5">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <h3 class="text-sm font-semibold text-textPrimary">{{ $risk['title'] }}</h3>
                                        <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $risk['summary'] }}</p>
                                    </div>
                                    <a {{ $evidenceDrawerAttributes($risk['descriptor'], 'h-8 w-full px-2 py-1 text-xs sm:w-auto') }}>
                                        <i data-lucide="panel-right-open" class="h-3.5 w-3.5"></i>
                                        Evidence
                                    </a>
                                </div>
                                <p class="mt-3 text-xs font-medium text-textPrimary">Next action: {{ $risk['next_action'] }}</p>
                            </article>
                        @empty
                            <x-empty-state title="No risk signals" description="Risk signals appear when evidence shows material declines or pressure." icon="shield-check" class="rounded-none border-0 shadow-none" />
                        @endforelse
                    </div>
                </div>

                <div class="rounded-lg border border-border bg-surface">
                    <div class="border-b border-border p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Why does it matter?</p>
                        <h2 class="mt-1 text-lg font-semibold text-textPrimary">Opportunity</h2>
                    </div>
                    <div class="divide-y divide-border">
                        @forelse ($opportunityCards as $opportunity)
                            <article class="p-5">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <h3 class="text-sm font-semibold text-textPrimary">{{ $opportunity['title'] }}</h3>
                                        <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $opportunity['summary'] }}</p>
                                    </div>
                                    <a {{ $evidenceDrawerAttributes($opportunity['descriptor'], 'h-8 w-full px-2 py-1 text-xs sm:w-auto') }}>
                                        <i data-lucide="panel-right-open" class="h-3.5 w-3.5"></i>
                                        Evidence
                                    </a>
                                </div>
                                <p class="mt-3 text-xs font-medium text-textPrimary">Next action: {{ $opportunity['next_action'] }}</p>
                            </article>
                        @empty
                            <x-empty-state title="No opportunity signals" description="Opportunity signals appear when trend evidence shows momentum worth expanding." icon="sparkles" class="rounded-none border-0 shadow-none" />
                        @endforelse
                    </div>
                </div>
            </section>
        </div>

        <aside class="space-y-6">
            <section class="rounded-lg border border-border bg-surface" aria-label="What should we do next">
                <div class="border-b border-border p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">What should we do next?</p>
                    <h2 class="mt-1 text-lg font-semibold text-textPrimary">Recommendation</h2>
                </div>
                <div class="divide-y divide-border">
                    @forelse ($recommendations as $recommendation)
                        <article class="p-5">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full border border-border bg-background px-2.5 py-1 text-[11px] font-semibold text-textSecondary">{{ $recommendation['source'] }}</span>
                                <span class="rounded-full border border-border bg-background px-2.5 py-1 text-[11px] font-semibold text-textSecondary">{{ $recommendation['impact'] }} impact</span>
                                <span class="rounded-full border border-border bg-background px-2.5 py-1 text-[11px] font-semibold text-textSecondary">{{ $recommendation['confidence'] }} confidence</span>
                            </div>
                            <h3 class="mt-3 text-sm font-semibold text-textPrimary">{{ $recommendation['title'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-textSecondary">{{ $recommendation['summary'] }}</p>
                            <p class="mt-3 text-xs font-medium text-textPrimary">Next action: {{ $recommendation['next_action'] }}</p>
                            @if ($recommendation['url'])
                                <a href="{{ $recommendation['url'] }}" class="mt-4 inline-flex h-9 items-center gap-2 rounded-md border border-border px-3 text-sm font-medium text-textPrimary hover:bg-background">
                                    Open recommendation
                                    <i data-lucide="arrow-right" class="h-4 w-4"></i>
                                </a>
                            @endif
                        </article>
                    @empty
                        <x-empty-state title="No recommendations yet" description="Recommendations appear when existing actions or deterministic reasoning results are available." icon="list-checks" class="rounded-none border-0 shadow-none" />
                    @endforelse
                </div>
            </section>

            <section class="rounded-lg border border-border bg-surface" aria-label="Evidence summary">
                <div class="border-b border-border p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Evidence</p>
                    <h2 class="mt-1 text-lg font-semibold text-textPrimary">Source summary</h2>
                </div>
                <dl class="grid gap-3 p-5">
                    <div class="grid grid-cols-2 gap-3">
                        <dt class="text-sm text-textSecondary">Source</dt>
                        <dd class="text-sm font-medium text-textPrimary">{{ data_get($evidence, 'source') }}</dd>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <dt class="text-sm text-textSecondary">Timeframe</dt>
                        <dd class="text-sm font-medium text-textPrimary">{{ data_get($evidence, 'timeframe') }}</dd>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <dt class="text-sm text-textSecondary">Evidence</dt>
                        <dd class="text-sm font-medium text-textPrimary">{{ number_format((int) data_get($evidence, 'observations_count', 0)) }} rows</dd>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <dt class="text-sm text-textSecondary">Explanation</dt>
                        <dd class="text-sm font-medium text-textPrimary">{{ number_format((int) data_get($evidence, 'reasoning_result_count', 0)) }} results</dd>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <dt class="text-sm text-textSecondary">Links</dt>
                        <dd class="text-sm font-medium text-textPrimary">{{ number_format((int) data_get($evidence, 'graph_edge_count', 0)) }} edges</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-lg border border-border bg-surface" aria-label="Reports and briefings">
                <div class="border-b border-border p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-textFaint">Source</p>
                    <h2 class="mt-1 text-lg font-semibold text-textPrimary">Reports and briefings</h2>
                </div>
                <div class="divide-y divide-border">
                    @forelse ($reports as $report)
                        <a href="{{ $report['url'] }}" class="flex items-start justify-between gap-3 p-5 hover:bg-background">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-textPrimary">{{ $report['title'] }}</span>
                                <span class="mt-1 block text-xs text-textSecondary">{{ $report['timeframe'] }} · {{ $report['status'] }}</span>
                            </span>
                            <i data-lucide="arrow-right" class="mt-0.5 h-4 w-4 shrink-0 text-textFaint"></i>
                        </a>
                    @empty
                        <p class="p-5 text-sm text-textSecondary">No reports in this timeframe.</p>
                    @endforelse

                    @forelse ($briefings as $briefing)
                        <a href="{{ $briefing['url'] }}" class="flex items-start justify-between gap-3 p-5 hover:bg-background">
                            <span class="min-w-0">
                                <span class="block text-sm font-semibold text-textPrimary">{{ $briefing['title'] }}</span>
                                <span class="mt-1 block text-xs text-textSecondary">{{ $briefing['frequency'] }} · {{ $briefing['status'] }} · {{ $briefing['timeframe'] }}</span>
                            </span>
                            <i data-lucide="arrow-right" class="mt-0.5 h-4 w-4 shrink-0 text-textFaint"></i>
                        </a>
                    @empty
                        <p class="p-5 text-sm text-textSecondary">No active briefings available.</p>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>
@endsection

@section('detailDrawer')
    <x-drawer.drawer
        :open="false"
        :drawer="[
            'key' => 'marketing-intelligence.evidence',
            'mode' => 'inspect',
            'modal' => false,
            'width' => 'lg',
            'title' => 'Evidence',
            'subtitle' => 'Trend source metadata',
            'description' => 'Evidence, source, timeframe, confidence, and graph metadata for the selected trend.',
            'tabs' => [],
            'sections' => [
                [
                    'key' => 'summary',
                    'title' => 'Evidence metadata',
                    'items' => [
                        ['label' => 'Source', 'value' => data_get($evidence, 'source')],
                        ['label' => 'Timeframe', 'value' => data_get($evidence, 'timeframe')],
                        ['label' => 'Evidence', 'value' => data_get($evidence, 'observations_count').' rows'],
                        ['label' => 'Reasoning', 'value' => data_get($evidence, 'reasoning_result_count').' results'],
                        ['label' => 'Links', 'value' => data_get($evidence, 'graph_edge_count').' graph edges'],
                    ],
                ],
            ],
            'footer_actions' => [],
            'empty_state' => [
                'title' => 'No evidence selected',
                'description' => 'Evidence detail remains read-only.',
            ],
            'state' => [
                'mode' => 'inspect',
                'open' => false,
                'loading' => false,
                'empty' => false,
                'error' => false,
                'message' => null,
                'interactive' => false,
                'can_edit' => false,
                'metadata' => array_merge((array) data_get($evidence, 'metadata', []), [
                    'source_read_model' => 'EvidenceBag',
                    'drawer_target' => 'marketing-intelligence.evidence',
                    'read_only' => true,
                ]),
            ],
        ]"
    >
        <div data-marketing-intelligence-evidence-panel class="space-y-5">
            <div class="rounded-md border border-dashed border-border bg-background p-5 text-sm text-textSecondary">
                <p class="font-semibold text-textPrimary">No evidence selected</p>
                <p class="mt-1">Choose Evidence on a trend, risk, or opportunity to inspect source, timeframe, confidence, impact, and next action metadata.</p>
            </div>
        </div>
    </x-drawer.drawer>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var drawer = document.querySelector('[data-drawer="marketing-intelligence.evidence"]');
            if (!drawer) {
                return;
            }

            var panel = drawer.querySelector('[data-marketing-intelligence-evidence-panel]');
            var closeButtons = drawer.querySelectorAll('[data-drawer-close]');
            var lastTrigger = null;

            var escapeHtml = function (value) {
                return String(value == null ? '' : value)
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            };

            var renderItems = function (items) {
                if (!Array.isArray(items) || items.length === 0) {
                    return '<p class="text-sm text-textSecondary">No metadata available.</p>';
                }

                return '<dl class="grid gap-3">' + items.map(function (item) {
                    return '<div class="grid gap-1 sm:grid-cols-3 sm:gap-4">'
                        + '<dt class="text-sm font-medium text-textSecondary">' + escapeHtml(item.label || item.key || 'Item') + '</dt>'
                        + '<dd class="min-w-0 text-sm text-textPrimary sm:col-span-2">' + escapeHtml(item.value || '') + '</dd>'
                        + '</div>';
                }).join('') + '</dl>';
            };

            var renderSections = function (sections) {
                if (!Array.isArray(sections) || sections.length === 0) {
                    return '<div class="rounded-md border border-dashed border-border bg-background p-5 text-sm text-textSecondary">No evidence metadata is available for this item.</div>';
                }

                return sections.map(function (section) {
                    return '<section class="border-b border-divider py-5 first:pt-0 last:border-b-0">'
                        + '<div class="mb-4">'
                        + '<h3 class="text-sm font-semibold text-textPrimary">' + escapeHtml(section.title || 'Evidence') + '</h3>'
                        + (section.description ? '<p class="mt-1 text-sm text-textSecondary">' + escapeHtml(section.description) + '</p>' : '')
                        + '</div>'
                        + renderItems(section.items || [])
                        + '</section>';
                }).join('');
            };

            var renderBadges = function (badges) {
                if (!Array.isArray(badges) || badges.length === 0) {
                    return '';
                }

                return '<div class="flex flex-wrap gap-2">' + badges.map(function (badge) {
                    var tone = badge.tone === 'risk'
                        ? 'border-rose-200 bg-rose-50 text-rose-700'
                        : (badge.tone === 'opportunity' ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-border bg-background text-textSecondary');

                    return '<span class="' + tone + ' rounded-full border px-2.5 py-1 text-[11px] font-semibold">' + escapeHtml(badge.label || '') + '</span>';
                }).join('') + '</div>';
            };

            var showError = function () {
                if (!panel) {
                    return;
                }

                panel.innerHTML = '<div class="rounded-md border border-rose-200 bg-rose-50 p-4 text-rose-900">'
                    + '<p class="text-sm font-semibold">Unable to show evidence</p>'
                    + '<p class="mt-1 text-sm text-rose-800">The evidence metadata could not be rendered safely.</p>'
                    + '</div>';
            };

            var openDrawer = function (descriptor) {
                if (!panel) {
                    return;
                }

                var metadata = descriptor.metadata || {};
                panel.innerHTML = '<div class="space-y-4">'
                    + renderBadges(descriptor.badges || [])
                    + '<div>'
                    + '<p class="text-xs font-semibold uppercase tracking-wide text-textFaint">' + escapeHtml(descriptor.title || 'Evidence') + '</p>'
                    + '<h3 class="mt-1 text-base font-semibold text-textPrimary">' + escapeHtml(descriptor.subtitle || 'Evidence detail') + '</h3>'
                    + '<p class="mt-1 text-sm text-textSecondary">Read-only source metadata from ' + escapeHtml(metadata.source_read_model || 'the selected read model') + '.</p>'
                    + '</div>'
                    + renderSections(descriptor.sections || [])
                    + '</div>';

                drawer.hidden = false;
                drawer.removeAttribute('aria-hidden');

                var close = drawer.querySelector('[data-drawer-close]');
                if (close) {
                    close.focus({ preventScroll: true });
                }
            };

            document.querySelectorAll('[data-marketing-intelligence-evidence-trigger]').forEach(function (trigger) {
                trigger.addEventListener('click', function (event) {
                    var rawPayload = trigger.getAttribute('data-drawer-payload') || '';
                    if (rawPayload === '') {
                        return;
                    }

                    event.preventDefault();
                    lastTrigger = trigger;

                    try {
                        openDrawer(JSON.parse(rawPayload));
                    } catch (error) {
                        drawer.hidden = false;
                        showError();
                    }
                });
            });

            closeButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    drawer.hidden = true;

                    if (lastTrigger) {
                        lastTrigger.focus({ preventScroll: true });
                    }
                });
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !drawer.hidden) {
                    drawer.hidden = true;

                    if (lastTrigger) {
                        lastTrigger.focus({ preventScroll: true });
                    }
                }
            });
        });
    </script>
@endsection
