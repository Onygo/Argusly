@props([
    'content',
    'activeTab' => 'overview',
    'selectedInsight' => null,
    'contentHealthScore' => 0,
    'localizationStatusLabel' => 'Not analyzed',
    'localizationStatusClass' => '',
    'refreshStatusLabel' => 'Not analyzed',
    'refreshStatusClass' => '',
    'linksStatusLabel' => 'Not analyzed',
    'linksStatusClass' => '',
    'hasAnyInsightResults' => false,
    'hasLocalizationResults' => false,
    'hasRefreshResults' => false,
    'hasLinksResults' => false,
    'localizationSummary' => null,
    'refreshSummary' => null,
    'linksSummary' => null,
    'localizationRun' => null,
    'refreshRun' => null,
    'internalLinkingRun' => null,
    'localizedContentSource' => null,
    'localeMismatchAnalysis' => null,
])

@php
    $selectedInsight = in_array($selectedInsight, ['localization', 'refresh', 'links'], true)
        ? $selectedInsight
        : null;

    $baseViewParams = array_merge(request()->query(), ['content' => $content]);
    $hideInsightParams = \Illuminate\Support\Arr::except($baseViewParams, ['insight']);
    $healthBarClass = match (true) {
        $contentHealthScore >= 80 => 'bg-emerald-500',
        $contentHealthScore >= 60 => 'bg-amber-500',
        default => 'bg-rose-500',
    };
    $statusHint = function (?object $run): string {
        if (! $run) {
            return 'Not analyzed yet';
        }

        $timestamp = $run->finished_at ?? $run->created_at;

        return $timestamp ? 'Checked '.$timestamp->diffForHumans() : 'Not analyzed yet';
    };

    $localizationSuggestions = collect((array) data_get(
        $localizationRun?->output_payload ?? [],
        'suggestions',
        data_get($localizationRun?->output_payload ?? [], 'raw_payload.recommendations', [])
    ))
        ->filter(fn (mixed $item): bool => is_array($item))
        ->values();
    $localizationActions = $localizationSuggestions
        ->flatMap(function (array $suggestion): array {
            return collect((array) data_get($suggestion, 'actions', []))
                ->filter(fn (mixed $item): bool => is_array($item))
                ->map(fn (array $action): array => ['action' => $action, 'title' => data_get($suggestion, 'title', 'Recommendation')])
                ->all();
        })
        ->take(6)
        ->values();
    $refreshSummaryText = trim((string) data_get($refreshRun?->output_payload ?? [], 'summary', $refreshRun?->summary ?? $refreshSummary ?? ''));
    $refreshScore = data_get($refreshRun?->output_payload ?? [], 'metrics.refresh_score', data_get($refreshRun?->output_payload ?? [], 'raw_payload.refresh_score'));
    $refreshUrgency = (string) data_get($refreshRun?->output_payload ?? [], 'metrics.urgency_level', data_get($refreshRun?->output_payload ?? [], 'raw_payload.urgency_level', ''));
    $refreshReasons = collect((array) data_get($refreshRun?->output_payload ?? [], 'raw_payload.reasons', []))
        ->filter(fn (mixed $item): bool => is_array($item))
        ->take(2)
        ->values();
    $refreshActions = collect((array) data_get($refreshRun?->output_payload ?? [], 'raw_payload.suggested_actions', []))
        ->filter(fn (mixed $item): bool => is_array($item))
        ->take(2)
        ->values();
    $internalLinkSuggestions = collect((array) data_get($internalLinkingRun?->output_payload ?? [], 'suggestions', []))
        ->filter(fn (mixed $item): bool => is_array($item))
        ->values();
    $severityClasses = fn (string $severity): string => match ($severity) {
        'high' => 'bg-rose-500/10 text-rose-700',
        'medium' => 'bg-amber-500/10 text-amber-700',
        default => 'bg-slate-100 text-slate-600',
    };
    $cardClasses = 'rounded-2xl border border-border/80 bg-white p-5';
    $nestedCardClasses = 'rounded-2xl border border-border/70 bg-slate-50 p-4';
    $actionButtonClasses = 'flex w-full items-center justify-between rounded-xl border border-border bg-white px-3 py-3 text-sm text-textPrimary transition hover:border-borderStrong hover:bg-surfaceSubtle';
    $secondaryButtonClasses = 'rounded-xl border border-border bg-white px-3 py-2 text-sm text-textPrimary transition hover:border-borderStrong hover:bg-surfaceSubtle';
    $pillClasses = 'inline-flex items-center rounded-full px-3 py-1 text-sm font-medium';
    $priorityActions = collect([
        $hasLocalizationResults ? ['priority' => 'high', 'title' => 'Improve localization', 'summary' => $localizationSummary ?: 'Resolve locale consistency issues and missing target variants.'] : null,
        $hasLinksResults ? ['priority' => 'high', 'title' => 'Add internal links', 'summary' => $linksSummary ?: 'Apply contextual internal links to strengthen content discovery.'] : null,
        $hasRefreshResults ? ['priority' => 'medium', 'title' => 'Refresh this content', 'summary' => $refreshSummary ?: 'Update structure and freshness signals to keep the page competitive.'] : null,
        $contentHealthScore < 60 ? ['priority' => 'high', 'title' => 'Raise content health baseline', 'summary' => 'Run the AI checks below and apply the top recommendations first.'] : null,
        $localeMismatchAnalysis && data_get($localeMismatchAnalysis, 'has_mismatch') ? ['priority' => 'high', 'title' => 'Fix locale mismatch', 'summary' => 'Correct the detected locale before publishing or translating further.'] : null,
    ])->filter()->values();
    $highPriorityActions = $priorityActions->where('priority', 'high')->values();
    $mediumPriorityActions = $priorityActions->where('priority', 'medium')->values();
@endphp

<aside class="space-y-4 lg:sticky lg:top-6">
    <div class="{{ $cardClasses }}">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">AI Assistant Panel</div>
                <h3 class="mt-1 text-lg font-semibold text-textPrimary">Content Health</h3>
                <p class="mt-1 text-sm text-textSecondary">Single operational score across localization, freshness, and internal linking.</p>
            </div>
            <div class="text-right">
                <div class="text-3xl font-bold text-textPrimary">{{ $contentHealthScore }}</div>
                <div class="text-xs text-textSecondary">/ 100</div>
            </div>
        </div>

        <div class="mt-4 h-2 overflow-hidden rounded-md bg-gray-100">
            <div class="h-full rounded-md {{ $healthBarClass }}" style="width: {{ max(0, min(100, (int) $contentHealthScore)) }}%;"></div>
        </div>

        <div class="mt-5 space-y-3">
            <div class="flex items-start justify-between gap-3 text-sm">
                <div>
                    <span class="text-textSecondary">Localization</span>
                    <p class="mt-1 text-[11px] text-textSecondary">{{ $statusHint($localizationRun) }}</p>
                </div>
                <span class="{{ $localizationStatusClass }}">{{ $localizationStatusLabel }}</span>
            </div>

            <div class="flex items-start justify-between gap-3 text-sm">
                <div>
                    <span class="text-textSecondary">Freshness</span>
                    <p class="mt-1 text-[11px] text-textSecondary">{{ $statusHint($refreshRun) }}</p>
                </div>
                <span class="{{ $refreshStatusClass }}">{{ $refreshStatusLabel }}</span>
            </div>

            <div class="flex items-start justify-between gap-3 text-sm">
                <div>
                    <span class="text-textSecondary">Internal links</span>
                    <p class="mt-1 text-[11px] text-textSecondary">{{ $statusHint($internalLinkingRun) }}</p>
                </div>
                <span class="{{ $linksStatusClass }}">{{ $linksStatusLabel }}</span>
            </div>
        </div>
    </div>

    <div class="{{ $cardClasses }}">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="text-lg font-semibold text-textPrimary">Recommended Actions</h3>
                <p class="mt-1 text-sm text-textSecondary">Priority-ordered next steps for this content item.</p>
            </div>
        </div>

        <div class="mt-5 space-y-5">
            <div>
                <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">High impact</div>
                <div class="mt-3 space-y-3">
                    @forelse ($highPriorityActions as $action)
                        <div class="{{ $nestedCardClasses }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-medium text-textPrimary">{{ $action['title'] }}</div>
                                    <p class="mt-1 text-xs text-textSecondary">{{ $action['summary'] }}</p>
                                </div>
                                <span class="rounded-full bg-rose-50 px-2.5 py-1 text-[11px] font-medium text-rose-700">High</span>
                            </div>
                        </div>
                    @empty
                        <div class="{{ $nestedCardClasses }}">
                            <p class="text-sm text-textSecondary">No urgent actions right now.</p>
                        </div>
                    @endforelse
                </div>
            </div>

            <div>
                <div class="text-xs font-medium uppercase tracking-[0.18em] text-textSecondary">Medium impact</div>
                <div class="mt-3 space-y-3">
                    @forelse ($mediumPriorityActions as $action)
                        <div class="{{ $nestedCardClasses }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <div class="text-sm font-medium text-textPrimary">{{ $action['title'] }}</div>
                                    <p class="mt-1 text-xs text-textSecondary">{{ $action['summary'] }}</p>
                                </div>
                                <span class="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-medium text-amber-800">Medium</span>
                            </div>
                        </div>
                    @empty
                        <div class="{{ $nestedCardClasses }}">
                            <p class="text-sm text-textSecondary">Deeper optimization opportunities will appear after more AI runs.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="{{ $cardClasses }}">
        <div>
            <h3 class="text-lg font-semibold text-textPrimary">Run AI checks</h3>
            <p class="mt-1 text-sm text-textSecondary">Launch focused analysis to improve the content workspace.</p>
        </div>

        <div class="mt-4 space-y-2">
            @can('runAgent', $content)
                <form method="POST" action="{{ route('app.content.localization.run', $content) }}">
                    @csrf
                    <input type="hidden" name="tab" value="{{ $activeTab }}">
                    <button class="{{ $actionButtonClasses }}">
                        <span>Run localization</span>
                        <span class="text-textSecondary">Run</span>
                    </button>
                </form>

                <form method="POST" action="{{ route('app.content.refresh-recommendations.run', $content) }}">
                    @csrf
                    <input type="hidden" name="tab" value="{{ $activeTab }}">
                    <button class="{{ $actionButtonClasses }}">
                        <span>Run refresh check</span>
                        <span class="text-textSecondary">Run</span>
                    </button>
                </form>

                <form method="POST" action="{{ route('app.content.internal-linking.run', $content) }}">
                    @csrf
                    <input type="hidden" name="tab" value="{{ $activeTab }}">
                    <button class="{{ $actionButtonClasses }}">
                        <span>Find internal links</span>
                        <span class="text-textSecondary">Run</span>
                    </button>
                </form>
            @else
                <div class="rounded-md border border-border bg-background px-3 py-2 text-sm text-textSecondary">
                    Insight actions are unavailable for your current role.
                </div>
            @endcan
        </div>
    </div>

    @if ($localeMismatchAnalysis && data_get($localeMismatchAnalysis, 'has_mismatch'))
        @php
            $declaredLocale = data_get($localeMismatchAnalysis, 'declared_locale', '');
            $detectedLocale = data_get($localeMismatchAnalysis, 'detected_locale', '');
            $confidence = (float) data_get($localeMismatchAnalysis, 'confidence', 0);
            $canAutoFix = (bool) data_get($localeMismatchAnalysis, 'can_auto_fix', false);
            $detectedLanguage = \App\Enums\SupportedLanguage::tryFrom($detectedLocale);
            $detectedLabel = $detectedLanguage?->englishLabel() ?? strtoupper($detectedLocale);
            $declaredLanguage = \App\Enums\SupportedLanguage::tryFrom($declaredLocale);
            $declaredLabel = $declaredLanguage?->englishLabel() ?? strtoupper($declaredLocale);
        @endphp

        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4">
            <div class="flex items-start gap-3">
                <div class="flex-shrink-0">
                    <svg class="h-5 w-5 text-amber-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="text-sm font-semibold text-amber-800">Locale Mismatch Detected</h3>
                    <p class="mt-1 text-xs text-amber-700">
                        Content is marked as <span class="font-medium">{{ $declaredLabel }}</span> but appears to be <span class="font-medium">{{ $detectedLabel }}</span>
                        <span class="text-amber-600">({{ number_format($confidence * 100, 0) }}% confidence)</span>
                    </p>

                    @can('update', $content)
                        @if ($canAutoFix && $detectedLocale)
                            <div class="mt-3 flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('app.content.fix-locale', $content) }}">
                                    @csrf
                                    <input type="hidden" name="target_locale" value="{{ $detectedLocale }}">
                                    <button type="submit" class="inline-flex items-center rounded-md border border-amber-300 bg-white px-3 py-1 text-sm font-medium text-amber-800 transition hover:border-amber-400 hover:bg-amber-50 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2">
                                        {{ $detectedLocale === 'nl' ? 'Fix locale to NL' : 'Fix locale to ' . $detectedLabel }}
                                    </button>
                                </form>

                                @if ($detectedLocale === 'nl')
                                    <form method="POST" action="{{ route('app.content.convert-to-nl-and-regenerate-en', $content) }}">
                                        @csrf
                                        <button type="submit" class="inline-flex items-center rounded-md border border-amber-300 bg-white px-3 py-1 text-sm font-medium text-amber-800 transition hover:border-amber-400 hover:bg-amber-50 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2">
                                            Convert to NL and regenerate EN
                                        </button>
                                    </form>
                                @endif

                                @if ($content->is_source_locale)
                                    <form method="POST" action="{{ route('app.content.fix-locale-and-set-source', $content) }}">
                                        @csrf
                                        <input type="hidden" name="target_locale" value="{{ $detectedLocale }}">
                                        <button type="submit" class="inline-flex items-center rounded-md border border-amber-300 bg-white px-3 py-1 text-sm font-medium text-amber-800 transition hover:border-amber-400 hover:bg-amber-50 focus:outline-none focus:ring-2 focus:ring-amber-500 focus:ring-offset-2">
                                            Fix & set as source
                                        </button>
                                    </form>
                                @endif
                            </div>
                        @else
                            <p class="mt-2 text-xs text-amber-600">
                                Auto-fix is not available. Manual review recommended.
                            </p>
                        @endif
                    @endcan
                </div>
            </div>
        </div>
    @endif

    @if ($hasAnyInsightResults)
        <div class="{{ $cardClasses }}">
            <h3 class="text-lg font-semibold text-textPrimary">AI findings</h3>
            <p class="mt-1 text-sm text-textSecondary">Focused diagnostics and actions from the latest AI runs.</p>

            <div class="mt-4 space-y-4">
                @if ($hasLocalizationResults)
                    <div class="{{ $nestedCardClasses }}">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-textPrimary">Localization</p>
                                <p class="mt-1 text-[11px] text-textSecondary">{{ $statusHint($localizationRun) }}</p>
                            </div>
                            <a
                                href="{{ route('app.content.show', $selectedInsight === 'localization' ? $hideInsightParams : array_merge($baseViewParams, ['insight' => 'localization'])) }}"
                                class="text-xs text-link underline"
                            >
                                {{ $selectedInsight === 'localization' ? 'Hide' : 'View' }}
                            </a>
                        </div>
                        <p class="mt-2 text-xs text-textSecondary">{{ $localizationSummary }}</p>

                        @if ($selectedInsight === 'localization')
                            <div class="mt-3 space-y-3 border-t border-border pt-3">
                                @foreach ($localizationSuggestions->take(2) as $suggestion)
                                    <div class="rounded-lg border border-border bg-background px-3 py-2">
                                        <div class="flex items-start justify-between gap-2">
                                            <div class="text-sm font-medium text-textPrimary">
                                                {{ data_get($suggestion, 'title', 'Recommendation') }}
                                            </div>
                                            <span class="inline-flex rounded-full px-3 py-1 text-sm font-medium capitalize {{ $severityClasses((string) data_get($suggestion, 'severity', 'low')) }}">
                                                {{ data_get($suggestion, 'severity', 'low') }}
                                            </span>
                                        </div>
                                        @if (trim((string) data_get($suggestion, 'description', '')) !== '')
                                            <p class="mt-1 text-xs text-textSecondary">{{ data_get($suggestion, 'description') }}</p>
                                        @endif
                                    </div>
                                @endforeach

                                @if ($localizationActions->isNotEmpty())
                                    <div>
                                        <div class="text-xs font-medium uppercase tracking-wide text-textSecondary">Next steps</div>
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach ($localizationActions as $item)
                                                @php($action = (array) data_get($item, 'action', []))
                                                @php($actionType = (string) data_get($action, 'type', ''))
                                                @php($actionLabel = trim((string) data_get($action, 'label', 'Open')))

                                                @if (in_array($actionType, ['translate_content_locale', 'refresh_content_locale'], true))
                                                    @can('update', $content)
                                                        <form method="POST" action="{{ route('app.content.translate', $localizedContentSource ?? $content) }}">
                                                            @csrf
                                                            <input type="hidden" name="target_locale" value="{{ data_get($action, 'target_locale') }}">
                                                            <button class="{{ $secondaryButtonClasses }}">
                                                                {{ $actionLabel }}
                                                            </button>
                                                        </form>
                                                    @endcan
                                                @elseif ($actionType === 'open_content' && trim((string) data_get($action, 'content_id', '')) !== '')
                                                    <a href="{{ route('app.content.show', data_get($action, 'content_id')) }}" class="{{ $secondaryButtonClasses }}">
                                                        {{ $actionLabel }}
                                                    </a>
                                                @elseif ($actionType === 'open_draft' && trim((string) data_get($action, 'draft_id', '')) !== '')
                                                    <a href="{{ route('app.drafts.show', data_get($action, 'draft_id')) }}" class="{{ $secondaryButtonClasses }}">
                                                        {{ $actionLabel }}
                                                    </a>
                                                @elseif (trim((string) data_get($action, 'href', '')) !== '')
                                                    <a href="{{ data_get($action, 'href') }}" class="{{ $secondaryButtonClasses }}">
                                                        {{ $actionLabel }}
                                                    </a>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                @endif

                @if ($hasRefreshResults)
                    <div class="{{ $nestedCardClasses }}">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-textPrimary">Freshness</p>
                                <p class="mt-1 text-[11px] text-textSecondary">{{ $statusHint($refreshRun) }}</p>
                            </div>
                            <a
                                href="{{ route('app.content.show', $selectedInsight === 'refresh' ? $hideInsightParams : array_merge($baseViewParams, ['insight' => 'refresh'])) }}"
                                class="text-xs text-link underline"
                            >
                                {{ $selectedInsight === 'refresh' ? 'Hide' : 'View' }}
                            </a>
                        </div>
                        <p class="mt-2 text-xs text-textSecondary">{{ $refreshSummary }}</p>

                        @if ($selectedInsight === 'refresh')
                            <div class="mt-3 space-y-3 border-t border-border pt-3">
                                <div class="flex flex-wrap gap-2 text-xs">
                                    @if (is_numeric($refreshScore))
                                        <span class="{{ $pillClasses }} bg-slate-50 text-textPrimary">Refresh score {{ number_format((float) $refreshScore, 0) }}</span>
                                    @endif
                                    @if ($refreshUrgency !== '')
                                        <span class="{{ $pillClasses }} bg-slate-50 capitalize text-textPrimary">{{ $refreshUrgency }}</span>
                                    @endif
                                </div>

                                @if ($refreshSummaryText !== '')
                                    <p class="text-sm text-textPrimary">{{ $refreshSummaryText }}</p>
                                @endif

                                @if ($refreshReasons->isNotEmpty())
                                    <div>
                                        <div class="text-xs font-medium uppercase tracking-wide text-textSecondary">Top reasons</div>
                                        <div class="mt-2 space-y-2">
                                            @foreach ($refreshReasons as $reason)
                                                <div class="rounded-lg border border-border bg-background px-3 py-2">
                                                    <div class="text-sm font-medium text-textPrimary">{{ data_get($reason, 'title', 'Reason') }}</div>
                                                    @if (trim((string) data_get($reason, 'description', '')) !== '')
                                                        <p class="mt-1 text-xs text-textSecondary">{{ data_get($reason, 'description') }}</p>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if ($refreshActions->isNotEmpty())
                                    <div>
                                        <div class="text-xs font-medium uppercase tracking-wide text-textSecondary">Suggested Actions</div>
                                        <div class="mt-2 space-y-2">
                                            @foreach ($refreshActions as $action)
                                                <div class="rounded-lg border border-border bg-background px-3 py-2">
                                                    <div class="text-sm font-medium text-textPrimary">{{ data_get($action, 'title', 'Action') }}</div>
                                                    @if (trim((string) data_get($action, 'description', '')) !== '')
                                                        <p class="mt-1 text-xs text-textSecondary">{{ data_get($action, 'description') }}</p>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @can('generateDraft', $content)
                                    @if ($refreshRun)
                                        <form method="POST" action="{{ route('app.content.refresh-recommendations.create-draft', $content) }}">
                                            @csrf
                                            <input type="hidden" name="agent_run_id" value="{{ $refreshRun->id }}">
                                            <button class="w-full rounded-md border border-border bg-white px-3 py-2 text-sm text-textPrimary transition hover:border-borderStrong hover:bg-surfaceSubtle">
                                                Create refresh draft
                                            </button>
                                        </form>
                                    @endif
                                @endcan
                            </div>
                        @endif
                    </div>
                @endif

                @if ($hasLinksResults)
                    <div class="{{ $nestedCardClasses }}">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-textPrimary">Internal links</p>
                                <p class="mt-1 text-[11px] text-textSecondary">{{ $statusHint($internalLinkingRun) }}</p>
                            </div>
                            <a
                                href="{{ route('app.content.show', $selectedInsight === 'links' ? $hideInsightParams : array_merge($baseViewParams, ['insight' => 'links'])) }}"
                                class="text-xs text-link underline"
                            >
                                {{ $selectedInsight === 'links' ? 'Hide' : 'View' }}
                            </a>
                        </div>
                        <p class="mt-2 text-xs text-textSecondary">{{ $linksSummary }}</p>

                        @if ($selectedInsight === 'links')
                            <div class="mt-3 space-y-2 border-t border-border pt-3">
                                @foreach ($internalLinkSuggestions as $index => $suggestion)
                                    @php($isApplied = filled(data_get($suggestion, 'applied_at')))
                                    <div class="rounded-lg border border-border bg-background p-3">
                                        <div class="text-sm font-medium text-textPrimary">
                                            {{ data_get($suggestion, 'anchor_text', 'Suggested anchor') }}
                                        </div>
                                        <div class="mt-1 text-xs text-textSecondary">
                                            {{ data_get($suggestion, 'target_title', 'Untitled target') }}
                                        </div>
                                        @if (trim((string) data_get($suggestion, 'reason', '')) !== '')
                                            <p class="mt-2 text-xs text-textSecondary">{{ data_get($suggestion, 'reason') }}</p>
                                        @endif

                                        <div class="mt-3">
                                            @can('update', $content)
                                                @if ($internalLinkingRun && ! $isApplied)
                                                    <form method="POST" action="{{ route('app.content.internal-linking.apply', $content) }}">
                                                        @csrf
                                                        <input type="hidden" name="agent_run_id" value="{{ $internalLinkingRun->id }}">
                                                        <input type="hidden" name="suggestion_index" value="{{ $index }}">
                                                        <input type="hidden" name="tab" value="{{ $activeTab }}">
                                                        <button class="{{ $secondaryButtonClasses }}">
                                                            Apply suggestion
                                                        </button>
                                                    </form>
                                                @elseif ($isApplied)
                                                    <span class="{{ $pillClasses }} bg-emerald-50 text-emerald-700">Applied</span>
                                                @endif
                                            @endcan
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="rounded-lg border border-dashed border-border bg-white p-4">
            <h3 class="text-sm font-semibold text-textPrimary">No findings yet</h3>
            <p class="mt-1 text-xs text-textSecondary">
                Run one of the checks above to generate actionable recommendations.
            </p>
        </div>
    @endif
</aside>
