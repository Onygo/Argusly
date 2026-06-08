@extends('layouts.app', ['title' => 'SEO Audit Run'])

@section('content')
    @php
        $scope = data_get($dashboard, 'scope', 'all');
        $issueFilter = data_get($dashboard, 'issue_filter', 'all');
        $issueType = data_get($dashboard, 'issue_type');
        $scopeTabs = data_get($dashboard, 'scope_tabs', []);
        $summary = data_get($dashboard, 'summary', []);
        $priorityFixes = data_get($dashboard, 'priority_fixes', collect());
        $aiPanel = data_get($dashboard, 'ai_panel', []);
        $issuesOverview = data_get($dashboard, 'issues_overview', collect());
        $pageRows = data_get($dashboard, 'page_table_rows', collect());
        $historyRows = data_get($dashboard, 'history', collect());
        $aiFixCreditCost = (int) data_get($dashboard, 'ai_fix_credit_cost', 0);
        $preselectedIds = collect(data_get($aiPanel, 'preselected_issue_ids', []))->map(fn ($id) => (int) $id)->all();
        $oldIssueIds = collect(old('issue_ids', []))->map(fn ($id) => (int) $id)->all();
        $hasOldSelection = ! empty($oldIssueIds);
        $baseQuery = request()->query();
        $runStatus = data_get($dashboard, 'run_status', []);
        $diagnostics = data_get($dashboard, 'diagnostics', []);
        $diagnosticSamples = collect(data_get($diagnostics, 'fetch_samples', []));
    @endphp

    <div class="space-y-6">
        <x-app.insights-header
            :site="$site"
            :title="'Audit #'.$audit->id"
            :description="'Review crawl results, issue priorities, and AI-assisted fixes from '.optional($audit->started_at)->toDateTimeString().'.'"
            active="audits"
        >
            <a href="{{ route('app.sites.seo-audits.index', $site) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Back to Audits</a>
            <a href="{{ route('app.sites.show', $site) }}" class="rounded-md border border-border px-3 py-2 text-sm text-textPrimary hover:bg-surfaceSubtle">Site setup</a>
        </x-app.insights-header>

        @if (session('status'))
            <x-alert>{{ session('status') }}</x-alert>
        @endif

        @if ($errors->has('ai_fix'))
            <div class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-700">
                {{ $errors->first('ai_fix') }}
            </div>
        @endif

        @if (data_get($runStatus, 'error_message'))
            <section class="rounded-lg border border-amber-500/30 bg-amber-500/10 p-6">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-textPrimary">Crawl failure reason</h2>
                        <p class="mt-2 text-sm text-textPrimary">{{ data_get($runStatus, 'error_message') }}</p>
                    </div>
                    <span class="text-xs text-textSecondary">Source: {{ data_get($diagnostics, 'crawl_source', 'unknown') }}</span>
                </div>

                @if (! empty(data_get($diagnostics, 'errors_by_category', [])))
                    <p class="mt-3 text-xs text-textSecondary">
                        Categories:
                        {{ collect(data_get($diagnostics, 'errors_by_category', []))->map(fn ($count, $code) => $code.': '.$count)->implode(' · ') }}
                    </p>
                @endif

                @if ($diagnosticSamples->isNotEmpty())
                    <div class="mt-4 overflow-x-auto rounded border border-border/70 bg-background">
                        <table class="min-w-full text-xs text-textPrimary">
                            <thead>
                                <tr class="text-left text-textSecondary">
                                    <th class="px-3 py-2 font-medium">URL</th>
                                    <th class="px-3 py-2 font-medium">Status</th>
                                    <th class="px-3 py-2 font-medium">Type</th>
                                    <th class="px-3 py-2 font-medium">Bytes</th>
                                    <th class="px-3 py-2 font-medium">Failure</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($diagnosticSamples as $sample)
                                    <tr class="border-t border-border/70 align-top">
                                        <td class="px-3 py-2 text-textSecondary">{{ data_get($sample, 'target_url') }}</td>
                                        <td class="px-3 py-2">{{ data_get($sample, 'status_code', 0) }}</td>
                                        <td class="px-3 py-2">{{ data_get($sample, 'content_type', 'n/a') }}</td>
                                        <td class="px-3 py-2">{{ number_format((int) data_get($sample, 'response_length', 0)) }}</td>
                                        <td class="px-3 py-2">
                                            {{ data_get($sample, 'error_category', 'ok') }}
                                            @if (data_get($sample, 'final_url'))
                                                <p class="mt-1 text-[11px] text-textSecondary">Final: {{ data_get($sample, 'final_url') }}</p>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif

    <section class="rounded-lg border border-border bg-surface p-6">
        <div class="mb-6 flex items-start justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-textPrimary">SEO Health Summary</h2>
                <p class="mt-1 text-xs text-textSecondary">Focus on Priority Fixes first. AI suggestions never auto publish changes.</p>
            </div>
            <span class="text-xs text-textSecondary">Run status: {{ $audit->status }}</span>
        </div>

        <div class="grid gap-4 md:grid-cols-3 xl:grid-cols-5">
            <div class="rounded-lg border border-border bg-background p-4">
                <p class="text-xs text-textSecondary">SEO Health Score</p>
                <p class="mt-1 text-xl font-semibold {{ data_get($summary, 'seo_health_level.classes', 'text-textPrimary') }}">
                    {{ number_format((float) data_get($summary, 'seo_health_score', 0), 1) }}
                </p>
                <p class="text-xs {{ data_get($summary, 'seo_health_level.classes', 'text-textSecondary') }}">{{ data_get($summary, 'seo_health_level.label', 'N/A') }}</p>
            </div>

            <div class="rounded-lg border border-border bg-background p-4">
                <p class="text-xs text-textSecondary">Issues Overview</p>
                <p class="mt-1 text-sm text-textPrimary">
                    Errors {{ number_format((int) data_get($summary, 'issues.error', 0)) }}
                    · Warnings {{ number_format((int) data_get($summary, 'issues.warning', 0)) }}
                    · Improvements {{ number_format((int) data_get($summary, 'issues.improvement', 0)) }}
                </p>
                <p class="text-xs text-textSecondary">Total {{ number_format((int) data_get($summary, 'issues.total', 0)) }}</p>
            </div>

            <div class="rounded-lg border border-border bg-background p-4">
                <p class="text-xs text-textSecondary">Pages analysed</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((int) data_get($summary, 'pages_analysed_total', 0)) }}</p>
                <p class="text-xs text-textSecondary">In scope: {{ number_format((int) data_get($summary, 'scope_pages_count', 0)) }}</p>
            </div>

            <div class="rounded-lg border border-border bg-background p-4">
                <p class="text-xs text-textSecondary">Argusly pages</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((int) data_get($summary, 'argusly_pages_count', 0)) }}</p>
            </div>

            <div class="rounded-lg border border-border bg-background p-4">
                <p class="text-xs text-textSecondary">Other pages</p>
                <p class="mt-1 text-xl font-semibold text-textPrimary">{{ number_format((int) data_get($summary, 'other_pages_count', 0)) }}</p>
            </div>
        </div>
    </section>

    <section class="rounded-lg border border-border bg-surface p-6">
        <h2 class="text-sm font-semibold text-textPrimary">Scope</h2>
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach ($scopeTabs as $scopeKey => $tab)
                @php
                    $tabQuery = array_merge($baseQuery, ['scope' => $scopeKey]);
                @endphp
                <a
                    href="{{ route('app.sites.seo-audits.show', array_merge([$site, $audit], $tabQuery)) }}"
                    class="rounded border px-3 py-1.5 text-xs {{ $scope === $scopeKey ? 'border-textPrimary text-textPrimary' : 'border-border text-textSecondary' }}"
                >
                    {{ data_get($tab, 'label') }} ({{ number_format((int) data_get($tab, 'count', 0)) }})
                </a>
            @endforeach
        </div>
    </section>

    <section class="rounded-lg border border-border bg-surface p-6">
        <div class="mb-4 flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold text-textPrimary">Priority Fixes</h2>
            <span class="text-xs text-textSecondary">Ranked by severity and affected pages</span>
        </div>

        @if ($priorityFixes->isEmpty())
            <p class="text-sm text-textSecondary">No priority fixes in the selected scope.</p>
        @else
            <div class="overflow-x-auto rounded border border-border/70">
                <table class="min-w-full text-sm text-textPrimary">
                    <thead>
                        <tr class="text-left text-xs text-textSecondary">
                            <th class="px-3 py-2 font-medium">Issue type</th>
                            <th class="px-3 py-2 font-medium text-right">Pages affected</th>
                            <th class="px-3 py-2 font-medium text-right">Impact</th>
                            <th class="px-3 py-2 font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($priorityFixes as $row)
                            @php
                                $rowQuery = array_merge($baseQuery, ['issue_type' => $row['code']]);
                                $rowUrl = route('app.sites.seo-audits.show', array_merge([$site, $audit], $rowQuery));
                            @endphp
                            <tr class="border-t border-border/70 align-top">
                                <td class="px-3 py-2">
                                    <p class="font-medium text-textPrimary">{{ $row['title'] }}</p>
                                    <p class="text-xs text-textSecondary">{{ $row['code'] }}</p>
                                </td>
                                <td class="px-3 py-2 text-right">{{ number_format((int) $row['pages_affected']) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <span class="text-xs {{ $row['impact_badge_class'] }}">{{ $row['impact'] }}</span>
                                </td>
                                <td class="px-3 py-2">
                                    @if ($row['is_actionable'])
                                        <a href="{{ $rowUrl }}#ai-seo-fix" class="rounded border border-textPrimary px-2 py-1 text-xs text-textPrimary">Fix with AI</a>
                                    @else
                                        <a href="{{ $rowUrl }}#issues-overview" class="rounded border border-border px-2 py-1 text-xs text-textPrimary">View pages</a>
                                        <p class="mt-1 text-[11px] text-textSecondary">Not an Argusly draft</p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section id="ai-seo-fix" class="rounded-lg border border-border bg-surface p-6">
        <div class="sticky top-4 z-10 -mx-6 -mt-6 mb-6 border-b border-border bg-surface px-6 py-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-textPrimary">AI SEO Fix</h2>
                    <p class="mt-1 text-xs text-textSecondary">Compact actionable queue. Apply writes only draft metadata, never publishes.</p>
                </div>
                <a
                    href="{{ route('app.sites.seo-audits.show', array_merge([$site, $audit], array_merge($baseQuery, ['ai_show_all' => data_get($aiPanel, 'show_all') ? 0 : 1]))) }}#ai-seo-fix"
                    class="rounded border border-border px-2 py-1 text-xs text-textPrimary"
                >
                    {{ data_get($aiPanel, 'show_all') ? 'Show actionable only' : 'Show all' }}
                </a>
            </div>
        </div>

        @if (collect(data_get($aiPanel, 'rows', []))->isEmpty())
            <p class="text-sm text-textSecondary">No AI-fix candidates in this scope.</p>
        @else
            @php
                $seoCapability = data_get($aiPanel, 'seo_capability', []);
                $seoCapabilityFields = collect(data_get($seoCapability, 'fields', []));
                $seoCapabilityCounts = data_get($seoCapability, 'counts', []);
            @endphp

            <div class="mb-6 rounded-lg border border-border bg-background p-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="text-xs font-semibold text-textPrimary">Connector SEO capabilities</h3>
                    <p class="text-xs text-textSecondary">
                        Provider: {{ data_get($seoCapability, 'provider_label', 'No SEO plugin detected') }}
                    </p>
                </div>
                <p class="mt-1 text-xs text-textSecondary">
                    Can sync: {{ (int) data_get($seoCapabilityCounts, 'sync', 0) }}
                    · Advice only: {{ (int) data_get($seoCapabilityCounts, 'advisory', 0) }}
                    · Requires plugin: {{ (int) data_get($seoCapabilityCounts, 'requires_provider', 0) }}
                </p>

                <div class="mt-3 overflow-x-auto rounded border border-border/70">
                    <table class="min-w-full text-xs text-textPrimary">
                        <thead>
                            <tr class="text-left text-textSecondary">
                                <th class="px-2 py-1 font-medium">Field</th>
                                <th class="px-2 py-1 font-medium">Status</th>
                                <th class="px-2 py-1 font-medium">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($seoCapabilityFields as $capabilityField)
                                <tr class="border-t border-border/60 align-top">
                                    <td class="px-2 py-1">{{ $capabilityField['label'] }}</td>
                                    <td class="px-2 py-1">
                                        <span class="rounded border px-1.5 py-0.5 {{ $capabilityField['status_badge_class'] }}">
                                            {{ $capabilityField['status_label'] }}
                                        </span>
                                    </td>
                                    <td class="px-2 py-1 text-textSecondary">{{ $capabilityField['note'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <form method="POST" action="{{ route('app.sites.seo-audits.ai-fix.generate', [$site, $audit]) }}" class="mb-6">
                @csrf

                <div class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-border bg-background p-4">
                    <button type="submit" class="rounded border border-textPrimary px-3 py-1.5 text-xs text-textPrimary">Generate fixes</button>
                    <p class="text-xs text-textSecondary">Selected: <span id="ai-fix-selected-count">0</span></p>
                    <p class="text-xs text-textSecondary">Estimated credits: <span id="ai-fix-estimated-total">0</span></p>
                    <p class="text-xs text-textSecondary">Rate: {{ $aiFixCreditCost }} per suggestion</p>
                </div>

                <div class="overflow-x-auto rounded border border-border/70">
                    <table class="min-w-full text-sm text-textPrimary">
                        <thead>
                            <tr class="text-left text-xs text-textSecondary">
                                <th class="px-3 py-2 font-medium">Select</th>
                                <th class="px-3 py-2 font-medium">Issue</th>
                                <th class="px-3 py-2 font-medium">Page</th>
                                <th class="px-3 py-2 font-medium text-right">Impact</th>
                                <th class="px-3 py-2 font-medium">Scope</th>
                                <th class="px-3 py-2 font-medium">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (data_get($aiPanel, 'rows', []) as $row)
                                @php
                                    $shouldCheck = in_array((int) $row['id'], $oldIssueIds, true)
                                        || (! $hasOldSelection && in_array((int) $row['id'], $preselectedIds, true));
                                @endphp
                                <tr class="border-t border-border/70 align-top">
                                    <td class="px-3 py-2">
                                        @if ($row['actionable'])
                                            <input
                                                type="checkbox"
                                                name="issue_ids[]"
                                                value="{{ $row['id'] }}"
                                                class="ai-fix-issue-checkbox rounded border-border text-textPrimary focus:ring-0"
                                                data-preselected="{{ $shouldCheck ? '1' : '0' }}"
                                                {{ $shouldCheck ? 'checked' : '' }}
                                            >
                                        @else
                                            <input type="checkbox" disabled class="rounded border-border text-border" title="Read only: no linked Argusly draft">
                                        @endif
                                    </td>
                                    <td class="px-3 py-2">
                                        <p class="font-medium text-textPrimary">{{ $row['issue_label'] }}</p>
                                        <p class="text-xs text-textSecondary">{{ $row['issue_code'] }}</p>
                                    </td>
                                    <td class="px-3 py-2 max-w-[360px] truncate text-xs text-textSecondary" title="{{ $row['page_url'] }}">{{ $row['page_url'] }}</td>
                                    <td class="px-3 py-2 text-right text-xs {{ $row['impact'] === 'High' ? 'text-rose-600' : ($row['impact'] === 'Medium' ? 'text-amber-600' : 'text-textSecondary') }}">
                                        {{ $row['impact'] }}
                                    </td>
                                    <td class="px-3 py-2 text-xs text-textSecondary">{{ $row['scope'] }}</td>
                                    <td class="px-3 py-2 text-xs text-textSecondary">
                                        @if ($row['actionable'])
                                            <div class="text-textPrimary">Generate</div>
                                            <div class="mt-0.5 {{ ($row['wordpress_sync_mode'] ?? 'advisory') === 'sync' ? 'text-success' : 'text-amber-700' }}">
                                                {{ $row['wordpress_sync_label'] ?? 'Recommendation only' }}
                                            </div>
                                            @if (! empty($row['wordpress_sync_note']))
                                                <div class="text-[11px] text-textSecondary">{{ $row['wordpress_sync_note'] }}</div>
                                            @endif
                                        @else
                                            Read only: not an Argusly draft
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </form>
        @endif

        @if (collect(data_get($aiPanel, 'generated_suggestions', []))->isNotEmpty())
            <div class="mt-6 space-y-4">
                <h3 class="text-sm font-semibold text-textPrimary">Generated Suggestions</h3>
                @foreach (data_get($aiPanel, 'generated_suggestions', []) as $suggestion)
                    @php
                        $payload = is_array($suggestion['model_payload'] ?? null) ? $suggestion['model_payload'] : [];
                        $titleSuggestion = (string) ($payload['recommended_title'] ?? $payload['title'] ?? '');
                        $metaSuggestion = (string) ($payload['recommended_meta_description'] ?? $payload['meta_description'] ?? '');
                        $canonicalSuggestion = (string) ($payload['recommended_canonical'] ?? $payload['canonical'] ?? '');
                        $h1Suggestion = (string) ($payload['recommended_h1'] ?? $payload['h1'] ?? '');
                        $internalLinks = is_array($payload['internal_link_suggestions'] ?? null)
                            ? $payload['internal_link_suggestions']
                            : (is_array($payload['internal_links'] ?? null) ? $payload['internal_links'] : []);
                        $fieldStatuses = collect($suggestion['seo_field_statuses'] ?? [])->keyBy('key');
                    @endphp
                    <details class="rounded-lg p-4 {{ $suggestion['card_class'] ?? 'border border-border bg-background' }}" {{ $suggestion['status'] === 'failed' ? 'open' : '' }}>
                        <summary class="cursor-pointer text-sm font-medium text-textPrimary">
                            {{ $suggestion['issue_code'] }} · {{ $suggestion['page_url'] }}
                            <span class="ml-2 rounded border border-border px-2 py-0.5 text-[11px] uppercase text-textSecondary">{{ $suggestion['status'] }}</span>
                            <span class="ml-2 rounded border px-2 py-0.5 text-[11px] uppercase {{ $suggestion['status_badge_class'] ?? 'border-border text-textSecondary' }}">
                                {{ $suggestion['status_label'] ?? 'Suggestion ready' }}
                            </span>
                        </summary>

                        <div class="mt-3 space-y-2 text-xs text-textSecondary">
                            @if ($titleSuggestion !== '')
                                <p>
                                    <span class="font-medium text-textPrimary">Title:</span> {{ $titleSuggestion }}
                                    @if ($fieldStatuses->has('seo_title'))
                                        <span class="ml-1 rounded border px-1.5 py-0.5 {{ data_get($fieldStatuses->get('seo_title'), 'status_badge_class') }}">
                                            {{ data_get($fieldStatuses->get('seo_title'), 'status_label') }}
                                        </span>
                                    @endif
                                </p>
                            @endif
                            @if ($metaSuggestion !== '')
                                <p>
                                    <span class="font-medium text-textPrimary">Meta description:</span> {{ $metaSuggestion }}
                                    @if ($fieldStatuses->has('seo_meta_description'))
                                        <span class="ml-1 rounded border px-1.5 py-0.5 {{ data_get($fieldStatuses->get('seo_meta_description'), 'status_badge_class') }}">
                                            {{ data_get($fieldStatuses->get('seo_meta_description'), 'status_label') }}
                                        </span>
                                    @endif
                                </p>
                            @endif
                            @if ($canonicalSuggestion !== '')
                                <p>
                                    <span class="font-medium text-textPrimary">Canonical:</span> {{ $canonicalSuggestion }}
                                    @if ($fieldStatuses->has('seo_canonical'))
                                        <span class="ml-1 rounded border px-1.5 py-0.5 {{ data_get($fieldStatuses->get('seo_canonical'), 'status_badge_class') }}">
                                            {{ data_get($fieldStatuses->get('seo_canonical'), 'status_label') }}
                                        </span>
                                    @endif
                                </p>
                            @endif
                            @if ($h1Suggestion !== '')
                                <p>
                                    <span class="font-medium text-textPrimary">H1:</span> {{ $h1Suggestion }}
                                    @if ($fieldStatuses->has('seo_h1'))
                                        <span class="ml-1 rounded border px-1.5 py-0.5 {{ data_get($fieldStatuses->get('seo_h1'), 'status_badge_class') }}">
                                            {{ data_get($fieldStatuses->get('seo_h1'), 'status_label') }}
                                        </span>
                                    @endif
                                </p>
                            @endif
                            @if (! empty($internalLinks))
                                <p class="font-medium text-textPrimary">Internal link suggestions:</p>
                                <ul class="list-disc pl-5">
                                    @foreach ($internalLinks as $link)
                                        <li>{{ data_get($link, 'anchor', '-') }} → {{ data_get($link, 'url', '-') }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            @if ($fieldStatuses->isNotEmpty())
                                <p class="font-medium text-textPrimary">Field sync summary:</p>
                                <ul class="list-disc pl-5">
                                    @foreach ($fieldStatuses as $fieldStatus)
                                        <li>{{ $fieldStatus['label'] }}: {{ $fieldStatus['status_label'] }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        <div class="mt-3">
                            <p class="mb-2 text-xs text-textSecondary">
                                {{ $suggestion['status_message'] ?? 'Suggestion ready. Review and apply to Argusly content.' }}
                            </p>

                            <p class="mb-2 text-xs {{ ($suggestion['wordpress_sync_mode'] ?? 'advisory') === 'sync' ? 'text-success' : 'text-amber-700' }}">
                                {{ $suggestion['wordpress_sync_label'] ?? 'Recommendation only' }}
                                @if (! empty($suggestion['wordpress_sync_note']))
                                    · {{ $suggestion['wordpress_sync_note'] }}
                                @endif
                            </p>

                            @if (($suggestion['can_apply'] ?? false) || ($suggestion['can_edit'] ?? false) || ($suggestion['can_sync'] ?? false))
                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    @if ($suggestion['can_apply'] ?? false)
                                        <form method="POST" action="{{ route('app.sites.seo-audits.ai-fix.apply', [$site, $audit, $suggestion['id']]) }}">
                                            @csrf
                                            <button type="submit" class="rounded border border-textPrimary px-3 py-1.5 text-xs text-textPrimary">Apply suggestion</button>
                                        </form>
                                    @endif

                                    @if ($suggestion['can_sync'] ?? false)
                                        <form method="POST" action="{{ route('app.sites.seo-audits.ai-fix.sync', [$site, $audit, $suggestion['id']]) }}">
                                            @csrf
                                            <button type="submit" class="rounded border border-textPrimary px-3 py-1.5 text-xs text-textPrimary">Sync to WordPress</button>
                                        </form>
                                    @endif

                                    @if ($suggestion['can_edit'] ?? false)
                                        <a href="{{ route('app.sites.seo-audits.ai-fix.edit', [$site, $audit, $suggestion['id']]) }}" class="rounded border border-border px-3 py-1.5 text-xs text-textPrimary">Edit</a>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>
        @endif
    </section>

    <section id="issues-overview" class="rounded-lg border border-border bg-surface p-6">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-semibold text-textPrimary">Issues Overview</h2>
            <p class="text-xs text-textSecondary">Grouped by severity and issue type</p>
        </div>

        <div class="mb-6 flex flex-wrap gap-2">
            @foreach ([
                'all' => 'All',
                'argusly' => 'Argusly content',
                'other' => 'Other pages',
                'actionable' => 'Only actionable',
                'not_actionable' => 'Only not actionable',
            ] as $filterKey => $filterLabel)
                @php
                    $filterQuery = array_merge($baseQuery, ['issue_filter' => $filterKey]);
                @endphp
                <a
                    href="{{ route('app.sites.seo-audits.show', array_merge([$site, $audit], $filterQuery)) }}#issues-overview"
                    class="rounded border px-2 py-1 text-xs {{ $issueFilter === $filterKey ? 'border-textPrimary text-textPrimary' : 'border-border text-textSecondary' }}"
                >
                    {{ $filterLabel }}
                </a>
            @endforeach
        </div>

        <div class="space-y-4">
            @foreach ($issuesOverview as $severityGroup)
                <details class="rounded-lg border border-border bg-background p-4" {{ $severityGroup['count'] > 0 && $severityGroup['severity'] === 'error' ? 'open' : '' }}>
                    <summary class="cursor-pointer text-sm font-semibold text-textPrimary">
                        {{ $severityGroup['label'] }} ({{ number_format((int) $severityGroup['count']) }})
                    </summary>
                    <p class="mt-1 text-xs text-textSecondary">{{ $severityGroup['explanation'] }}</p>

                    @if (collect($severityGroup['issue_types'])->isEmpty())
                        <p class="mt-3 text-sm text-textSecondary">No {{ strtolower($severityGroup['label']) }} issues in this filter.</p>
                    @else
                        <div class="mt-4 space-y-3">
                            @foreach ($severityGroup['issue_types'] as $issueTypeGroup)
                                <details class="rounded-lg border border-border/70 bg-surface p-3">
                                    <summary class="cursor-pointer text-sm font-medium text-textPrimary">
                                        {{ $issueTypeGroup['title'] }}
                                        <span class="ml-1 text-xs text-textSecondary">{{ number_format((int) $issueTypeGroup['pages_affected']) }} pages</span>
                                    </summary>

                                    @if (! empty($issueTypeGroup['recommendation_short']))
                                        <p class="mt-1 text-xs text-textSecondary">{{ $issueTypeGroup['recommendation_short'] }}</p>
                                    @endif

                                    <div class="mt-2 overflow-x-auto rounded border border-border/70">
                                        <table class="min-w-full text-xs text-textPrimary">
                                            <thead>
                                                <tr class="text-left text-textSecondary">
                                                    <th class="px-2 py-1 font-medium">Page</th>
                                                    <th class="px-2 py-1 font-medium">Scope</th>
                                                    <th class="px-2 py-1 font-medium">Actionability</th>
                                                    <th class="px-2 py-1 font-medium">Recommendation</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($issueTypeGroup['rows'] as $issueRow)
                                                    <tr class="border-t border-border/60">
                                                        <td class="px-2 py-1 max-w-[320px] truncate" title="{{ $issueRow['page_url'] }}">{{ $issueRow['page_url'] }}</td>
                                                        <td class="px-2 py-1 text-textSecondary">{{ $issueRow['scope_label'] }}</td>
                                                        <td class="px-2 py-1">
                                                            @if ($issueRow['is_actionable'])
                                                                <span class="text-success">Actionable</span>
                                                            @else
                                                                <span class="text-textSecondary">Not actionable</span>
                                                            @endif
                                                        </td>
                                                        <td class="px-2 py-1 text-textSecondary">{{ $issueRow['recommendation'] }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                            @endforeach
                        </div>
                    @endif
                </details>
            @endforeach
        </div>
    </section>

    <section id="page-table" class="rounded-lg border border-border bg-surface p-6">
        <div class="mb-4 flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold text-textPrimary">Page Level Table</h2>
            <p class="text-xs text-textSecondary">Argusly pages are prioritised by default</p>
        </div>

        <div class="overflow-x-auto rounded border border-border/70">
            <table class="min-w-max w-full table-auto text-sm text-textPrimary">
                <thead>
                    <tr class="text-left text-xs text-textSecondary">
                        <th class="px-4 py-3 font-medium">Page</th>
                        <th class="px-4 py-3 font-medium text-center">SEO score</th>
                        <th class="px-4 py-3 font-medium text-center">Title status</th>
                        <th class="px-4 py-3 font-medium text-center">Meta status</th>
                        <th class="px-4 py-3 font-medium text-center">Canonical status</th>
                        <th class="px-4 py-3 font-medium text-center">Internal links</th>
                        <th class="px-4 py-3 font-medium text-center">AI fix available</th>
                        <th class="px-4 py-3 font-medium text-center">Scope</th>
                        <th class="min-w-[140px] px-4 py-3 font-medium text-right">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($pageRows as $row)
                        <tr class="h-14 border-t border-border/70 align-middle transition hover:bg-surfaceSubtle/60">
                            <td class="max-w-[320px] px-4 py-3 align-middle" title="{{ $row['url'] }}">
                                <span class="block truncate">{{ $row['url'] }}</span>
                            </td>
                            <td class="px-4 py-3 text-center align-middle">
                                <span class="whitespace-nowrap text-sm font-medium {{ data_get($row, 'seo_level.classes', 'text-textPrimary') }}">{{ number_format((float) $row['seo_score'], 0) }}</span>
                            </td>
                            <td class="px-4 py-3 text-center align-middle">
                                <span class="whitespace-nowrap text-sm font-medium {{ $row['title_status']['classes'] }}">{{ $row['title_status']['label'] }}</span>
                            </td>
                            <td class="px-4 py-3 text-center align-middle">
                                <span class="whitespace-nowrap text-sm font-medium {{ $row['meta_status']['classes'] }}">{{ $row['meta_status']['label'] }}</span>
                            </td>
                            <td class="px-4 py-3 text-center align-middle">
                                <span class="whitespace-nowrap text-sm font-medium {{ $row['canonical_status']['classes'] }}">{{ $row['canonical_status']['label'] }}</span>
                            </td>
                            <td class="px-4 py-3 text-center align-middle">
                                <span class="whitespace-nowrap text-sm font-medium text-textPrimary">{{ number_format((int) $row['internal_links_count']) }}</span>
                            </td>
                            <td class="px-4 py-3 text-center align-middle">
                                @if ($row['ai_fix_available'])
                                    <span class="whitespace-nowrap text-sm font-medium text-success">Yes</span>
                                @else
                                    <span class="whitespace-nowrap text-sm font-medium text-textSecondary">No</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-center align-middle">
                                <span class="whitespace-nowrap text-sm text-textSecondary">{{ $row['scope'] }}</span>
                            </td>
                            <td class="min-w-[140px] px-4 py-3 text-right align-middle">
                                @if ($row['is_actionable_page'] && $row['ai_fix_available'])
                                    <a
                                        href="{{ route('app.sites.seo-audits.show', array_merge([$site, $audit], array_merge($baseQuery, ['focus_page_id' => $row['id']]))) }}#ai-seo-fix"
                                        class="inline-flex items-center justify-center gap-1 whitespace-nowrap rounded-md border border-textPrimary px-3 py-1.5 text-sm font-medium text-textPrimary transition hover:bg-surfaceSubtle"
                                    >
                                        Fix with AI
                                    </a>
                                @elseif ($row['is_actionable_page'])
                                    <span class="inline-flex items-center whitespace-nowrap text-sm text-textSecondary">No AI fix candidates</span>
                                @else
                                    <span class="inline-flex items-center whitespace-nowrap text-sm text-textSecondary" title="Read only. This page is not mapped to an Argusly draft.">Read only</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-4 text-textSecondary">No pages found for this scope.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="rounded-lg border border-border bg-surface p-6">
        <h2 class="text-sm font-semibold text-textPrimary">Audit history</h2>
        <p class="mt-1 text-xs text-textSecondary">Recent runs for this site</p>

        @if ($historyRows->isEmpty())
            <p class="mt-3 text-sm text-textSecondary">No historical runs.</p>
        @else
            <div class="mt-4 space-y-3">
                @foreach ($historyRows as $history)
                    <a href="{{ route('app.sites.seo-audits.show', [$site, $history['id']]) }}" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-background p-4 hover:bg-surfaceSubtle">
                        <div>
                            <p class="text-sm font-medium text-textPrimary">Run #{{ $history['id'] }} · {{ optional($history['started_at'])->toDateTimeString() }}</p>
                            <p class="text-xs text-textSecondary">Status: {{ $history['status'] }} · Pages: {{ number_format((int) $history['pages_crawled']) }}</p>
                        </div>
                        <div class="text-right text-xs text-textSecondary">
                            <p>SEO {{ number_format((float) $history['seo_health_score'], 1) }}</p>
                            <p>E {{ number_format((int) data_get($history, 'issue_counts.error', 0)) }} · W {{ number_format((int) data_get($history, 'issue_counts.warning', 0)) }} · I {{ number_format((int) data_get($history, 'issue_counts.info', 0)) }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const checkboxes = Array.from(document.querySelectorAll('.ai-fix-issue-checkbox'));
            const selectedCountNode = document.getElementById('ai-fix-selected-count');
            const estimatedNode = document.getElementById('ai-fix-estimated-total');
            const creditCost = {{ $aiFixCreditCost }};

            const sync = function () {
                const selectedCount = checkboxes.filter((checkbox) => checkbox.checked).length;

                if (selectedCountNode) {
                    selectedCountNode.textContent = String(selectedCount);
                }

                if (estimatedNode) {
                    estimatedNode.textContent = String(selectedCount * creditCost);
                }
            };

            checkboxes.forEach((checkbox) => {
                checkbox.addEventListener('change', sync);
            });

            sync();
        });
    </script>
@endsection
