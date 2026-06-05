<?php

namespace App\Services\SeoAudit;

use App\Enums\SeoAuditSuggestionState;
use App\Models\ClientSite;
use App\Models\SeoAudit;
use App\Models\SeoAuditFixSuggestion;
use App\Models\SeoAuditIssue;
use App\Models\SeoAuditPage;
use App\Services\Seo\SeoFieldSyncCapabilityResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class SeoAuditRunDashboardPresenter
{
    public const SCOPE_PUBLISHLAYER = 'publishlayer';
    public const SCOPE_OTHER = 'other';
    public const SCOPE_ALL = 'all';

    public const ISSUE_FILTER_ALL = 'all';
    public const ISSUE_FILTER_PUBLISHLAYER = 'publishlayer';
    public const ISSUE_FILTER_OTHER = 'other';
    public const ISSUE_FILTER_ACTIONABLE = 'actionable';
    public const ISSUE_FILTER_NOT_ACTIONABLE = 'not_actionable';

    public function __construct(
        private readonly SeoAuditAiFixService $aiFixService,
        private readonly SeoAuditScoreCalculator $scoreCalculator,
        private readonly SeoFieldSyncCapabilityResolver $seoFieldSyncCapabilityResolver,
    ) {
    }

    /**
     * @param  Collection<int,SeoAudit>|null  $historyAudits
     * @return array<string,mixed>
     */
    public function build(
        SeoAudit $audit,
        string $scope,
        string $issueFilter = self::ISSUE_FILTER_ALL,
        ?string $issueType = null,
        bool $showAllAi = false,
        ?int $focusPageId = null,
        ?Collection $historyAudits = null
    ): array {
        $audit->loadMissing([
            'pages.publishlayerArticle',
            'issues.page.publishlayerArticle',
            'fixSuggestions.applyLog',
            'fixSuggestions.page.publishlayerArticle.drafts',
            'site',
        ]);

        $pages = $audit->pages;
        $issues = $audit->issues;
        $fixSuggestions = $audit->fixSuggestions;

        $scope = $this->normalizeScope($scope, $pages);
        $issueFilter = $this->normalizeIssueFilter($issueFilter);
        $issueType = $this->normalizeIssueType($issueType);

        $visiblePages = $this->filterPagesByScope($pages, $scope);
        $visiblePageIds = $visiblePages->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $visibleIssues = $issues->filter(function (SeoAuditIssue $issue) use ($scope, $visiblePageIds): bool {
            if ($scope === self::SCOPE_ALL) {
                return true;
            }

            if (! $issue->seo_audit_page_id) {
                return false;
            }

            return in_array((int) $issue->seo_audit_page_id, $visiblePageIds, true);
        })->values();

        $issueCodesByPageId = $issues
            ->filter(fn (SeoAuditIssue $issue): bool => $issue->seo_audit_page_id !== null)
            ->groupBy(fn (SeoAuditIssue $issue): int => (int) $issue->seo_audit_page_id)
            ->map(
                fn (Collection $group): array => $group
                    ->pluck('code')
                    ->filter(fn ($code): bool => is_string($code) && trim($code) !== '')
                    ->map(fn ($code): string => trim((string) $code))
                    ->unique()
                    ->values()
                    ->all()
            );

        $overallSeoHealthScore = $this->scoreCalculator->scoreForAudit($pages, $issues);
        $seoHealthLevel = $this->scoreCalculator->levelForScore($overallSeoHealthScore);

        $scopeIssueCounts = [
            'error' => (int) $visibleIssues->where('severity', 'error')->count(),
            'warning' => (int) $visibleIssues->where('severity', 'warning')->count(),
            'info' => (int) $visibleIssues->where('severity', 'info')->count(),
        ];

        $summary = [
            'seo_health_score' => $overallSeoHealthScore,
            'seo_health_level' => $seoHealthLevel,
            'issues' => [
                'error' => $scopeIssueCounts['error'],
                'warning' => $scopeIssueCounts['warning'],
                'improvement' => $scopeIssueCounts['info'],
                'total' => array_sum($scopeIssueCounts),
            ],
            'pages_analysed_total' => (int) $pages->count(),
            'publishlayer_pages_count' => (int) $pages->where('page_type', SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE)->count(),
            'other_pages_count' => (int) $pages->where('page_type', '!=', SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE)->count(),
            'scope_pages_count' => (int) $visiblePages->count(),
        ];

        $priorityFixes = $this->buildPriorityFixes($visibleIssues);

        $issuesForOverview = $this->applyIssueFilter($visibleIssues, $issueFilter);
        if ($issueType !== null) {
            $issuesForOverview = $issuesForOverview
                ->filter(fn (SeoAuditIssue $issue): bool => (string) $issue->code === $issueType)
                ->values();
        }

        $issuesOverview = $this->buildIssuesOverview($issuesForOverview);
        $aiPanel = $this->buildAiPanel($visibleIssues, $fixSuggestions, $showAllAi, $focusPageId, $issueType, $visiblePageIds, $scope, $audit->site);
        $pageTableRows = $this->buildPageRows($visiblePages, $issues, $issueCodesByPageId, $scope);

        $history = $this->buildHistory($historyAudits ?? collect([$audit]));
        $diagnostics = $this->buildDiagnostics($audit);

        return [
            'scope' => $scope,
            'issue_filter' => $issueFilter,
            'issue_type' => $issueType,
            'show_all_ai' => $showAllAi,
            'focus_page_id' => $focusPageId,
            'run_status' => [
                'status' => (string) $audit->status,
                'error_message' => trim((string) ($audit->error_message ?? '')),
            ],
            'summary' => $summary,
            'diagnostics' => $diagnostics,
            'scope_tabs' => [
                self::SCOPE_PUBLISHLAYER => [
                    'label' => 'Argusly Content',
                    'count' => (int) $pages->where('page_type', SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE)->count(),
                ],
                self::SCOPE_OTHER => [
                    'label' => 'Other Website Pages',
                    'count' => (int) $pages->where('page_type', '!=', SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE)->count(),
                ],
                self::SCOPE_ALL => [
                    'label' => 'All',
                    'count' => (int) $pages->count(),
                ],
            ],
            'priority_fixes' => $priorityFixes,
            'ai_panel' => $aiPanel,
            'issues_overview' => $issuesOverview,
            'page_table_rows' => $pageTableRows,
            'history' => $history,
            'ai_fix_credit_cost' => $this->aiFixService->creditCostPerSuggestion(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildDiagnostics(SeoAudit $audit): array
    {
        $fetchDiagnostics = (array) data_get($audit->meta, 'fetch_diagnostics', []);
        $fetchSamples = collect((array) ($fetchDiagnostics['fetch_samples'] ?? []))
            ->take(5)
            ->values()
            ->all();

        return [
            'crawl_source' => (string) data_get($audit->meta, 'crawl_source', 'unknown'),
            'errors_by_category' => (array) ($fetchDiagnostics['errors_by_category'] ?? []),
            'fetch_samples' => $fetchSamples,
        ];
    }

    /**
     * @param  Collection<int,SeoAuditPage>  $pages
     */
    public function normalizeScope(string $scope, Collection $pages): string
    {
        $scope = strtolower(trim($scope));

        if (in_array($scope, [self::SCOPE_PUBLISHLAYER, self::SCOPE_OTHER, self::SCOPE_ALL], true)) {
            if ($scope === self::SCOPE_PUBLISHLAYER && $pages->where('page_type', SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE)->isEmpty()) {
                return self::SCOPE_ALL;
            }

            return $scope;
        }

        return $pages->where('page_type', SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE)->isNotEmpty()
            ? self::SCOPE_PUBLISHLAYER
            : self::SCOPE_ALL;
    }

    private function normalizeIssueFilter(string $issueFilter): string
    {
        $value = strtolower(trim($issueFilter));

        return in_array($value, [
            self::ISSUE_FILTER_ALL,
            self::ISSUE_FILTER_PUBLISHLAYER,
            self::ISSUE_FILTER_OTHER,
            self::ISSUE_FILTER_ACTIONABLE,
            self::ISSUE_FILTER_NOT_ACTIONABLE,
        ], true)
            ? $value
            : self::ISSUE_FILTER_ALL;
    }

    private function normalizeIssueType(?string $issueType): ?string
    {
        $issueType = trim((string) $issueType);

        return $issueType === '' ? null : $issueType;
    }

    /**
     * @param  Collection<int,SeoAuditPage>  $pages
     * @return Collection<int,SeoAuditPage>
     */
    private function filterPagesByScope(Collection $pages, string $scope): Collection
    {
        return match ($scope) {
            self::SCOPE_PUBLISHLAYER => $pages
                ->where('page_type', SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE)
                ->values(),
            self::SCOPE_OTHER => $pages
                ->where('page_type', '!=', SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE)
                ->values(),
            default => $pages->values(),
        };
    }

    /**
     * @param  Collection<int,SeoAuditIssue>  $issues
     * @return Collection<int,array<string,mixed>>
     */
    private function buildPriorityFixes(Collection $issues): Collection
    {
        $severityWeight = [
            'error' => 3,
            'warning' => 2,
            'info' => 1,
        ];

        return $issues
            ->groupBy('code')
            ->map(function (Collection $group, string $code) use ($severityWeight): array {
                $first = $group->first();
                $highestSeverity = $group
                    ->pluck('severity')
                    ->map(fn ($severity): string => (string) $severity)
                    ->sortByDesc(fn (string $severity): int => $severityWeight[$severity] ?? 0)
                    ->first() ?? 'info';

                $impact = match ($highestSeverity) {
                    'error' => 'High',
                    'warning' => 'Medium',
                    default => 'Low',
                };

                $actionableCount = $group->filter(fn (SeoAuditIssue $issue): bool => $this->isIssueActionable($issue))->count();

                return [
                    'code' => $code,
                    'title' => (string) ($first?->title ?? Str::headline(str_replace('_', ' ', $code))),
                    'severity' => $highestSeverity,
                    'impact' => $impact,
                    'impact_badge_class' => match ($impact) {
                        'High' => 'text-rose-600',
                        'Medium' => 'text-amber-600',
                        default => 'text-textSecondary',
                    },
                    'pages_affected' => (int) $group
                        ->pluck('seo_audit_page_id')
                        ->filter(fn ($id): bool => (int) $id > 0)
                        ->unique()
                        ->count(),
                    'issues_count' => (int) $group->count(),
                    'actionable_count' => (int) $actionableCount,
                    'primary_action' => $actionableCount > 0 ? 'Fix with AI' : 'View pages',
                    'is_actionable' => $actionableCount > 0,
                    'note' => $actionableCount > 0 ? null : 'Not an Argusly draft',
                ];
            })
            ->values()
            ->sort(function (array $a, array $b) use ($severityWeight): int {
                $severityCompare = ($severityWeight[$b['severity']] ?? 0) <=> ($severityWeight[$a['severity']] ?? 0);
                if ($severityCompare !== 0) {
                    return $severityCompare;
                }

                $pagesCompare = ((int) $b['pages_affected']) <=> ((int) $a['pages_affected']);
                if ($pagesCompare !== 0) {
                    return $pagesCompare;
                }

                return strcmp((string) $a['title'], (string) $b['title']);
            })
            ->values();
    }

    /**
     * @param  Collection<int,SeoAuditIssue>  $issues
     * @return Collection<int,SeoAuditIssue>
     */
    private function applyIssueFilter(Collection $issues, string $issueFilter): Collection
    {
        return match ($issueFilter) {
            self::ISSUE_FILTER_PUBLISHLAYER => $issues->filter(fn (SeoAuditIssue $issue): bool => $issue->page?->page_type === SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE)->values(),
            self::ISSUE_FILTER_OTHER => $issues->filter(fn (SeoAuditIssue $issue): bool => $issue->page?->page_type !== SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE)->values(),
            self::ISSUE_FILTER_ACTIONABLE => $issues->filter(fn (SeoAuditIssue $issue): bool => $this->isIssueActionable($issue))->values(),
            self::ISSUE_FILTER_NOT_ACTIONABLE => $issues->filter(fn (SeoAuditIssue $issue): bool => ! $this->isIssueActionable($issue))->values(),
            default => $issues->values(),
        };
    }

    /**
     * @param  Collection<int,SeoAuditIssue>  $issues
     * @return Collection<int,array<string,mixed>>
     */
    private function buildIssuesOverview(Collection $issues): Collection
    {
        $severityMeta = [
            'error' => 'Critical problems that can hurt crawlability and ranking now.',
            'warning' => 'Important problems that reduce SEO quality and visibility.',
            'info' => 'Improvements that can increase long-term SEO performance.',
        ];

        return collect(['error', 'warning', 'info'])
            ->map(function (string $severity) use ($issues, $severityMeta): array {
                $severityIssues = $issues->where('severity', $severity)->values();

                $issueTypes = $severityIssues
                    ->groupBy('code')
                    ->map(function (Collection $group, string $code): array {
                        $first = $group->first();

                        return [
                            'code' => $code,
                            'title' => (string) ($first?->title ?? Str::headline(str_replace('_', ' ', $code))),
                            'recommendation_short' => Str::limit((string) ($first?->recommendation ?? ''), 110),
                            'pages_affected' => (int) $group
                                ->pluck('seo_audit_page_id')
                                ->filter(fn ($id): bool => (int) $id > 0)
                                ->unique()
                                ->count(),
                            'issues_count' => (int) $group->count(),
                            'rows' => $group->map(function (SeoAuditIssue $issue): array {
                                $page = $issue->page;

                                return [
                                    'id' => (int) $issue->id,
                                    'page_url' => (string) ($page?->url ?? 'Unknown page'),
                                    'scope_label' => $page?->page_type === SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE
                                        ? 'Argusly'
                                        : 'Other page',
                                    'is_actionable' => $this->isIssueActionable($issue),
                                    'description' => Str::limit((string) ($issue->description ?? ''), 180),
                                    'recommendation' => Str::limit((string) ($issue->recommendation ?? ''), 180),
                                ];
                            })->values(),
                        ];
                    })
                    ->sortBy(fn (array $row): string => (string) $row['title'])
                    ->values();

                return [
                    'severity' => $severity,
                    'label' => ucfirst($severity),
                    'count' => (int) $severityIssues->count(),
                    'explanation' => $severityMeta[$severity],
                    'issue_types' => $issueTypes,
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int,SeoAuditIssue>  $issues
     * @param  Collection<int,SeoAuditFixSuggestion>  $suggestions
     * @param  array<int,int>  $visiblePageIds
     * @return array<string,mixed>
     */
    private function buildAiPanel(
        Collection $issues,
        Collection $suggestions,
        bool $showAllAi,
        ?int $focusPageId,
        ?string $issueType,
        array $visiblePageIds,
        string $scope,
        ?ClientSite $site
    ): array {
        $severityWeight = ['error' => 3, 'warning' => 2, 'info' => 1];
        $syncCapabilities = $this->seoFieldSyncCapabilityResolver->forSite($site, true);

        $candidates = $issues
            ->filter(fn (SeoAuditIssue $issue): bool => $issue->page !== null && $this->aiFixService->isSupportedIssueCode((string) $issue->code))
            ->values();

        $rows = $candidates
            ->map(function (SeoAuditIssue $issue) use ($site): array {
                $page = $issue->page;
                $actionable = $this->isIssueActionable($issue);
                $wpSync = $this->resolveWordPressSyncStatus((string) $issue->code, $site, $actionable);

                return [
                    'id' => (int) $issue->id,
                    'issue_code' => (string) $issue->code,
                    'issue_label' => (string) $issue->title,
                    'page_id' => (int) ($page?->id ?? 0),
                    'page_url' => (string) ($page?->url ?? 'Unknown page'),
                    'severity' => (string) $issue->severity,
                    'impact' => match ((string) $issue->severity) {
                        'error' => 'High',
                        'warning' => 'Medium',
                        default => 'Low',
                    },
                    'scope' => $page?->page_type === SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE
                        ? 'Argusly content'
                        : 'Other site page',
                    'scope_note' => $actionable
                        ? 'Can apply to draft'
                        : 'Read only: not linked to an Argusly draft',
                    'actionable' => $actionable,
                    'wordpress_sync_label' => $wpSync['label'],
                    'wordpress_sync_note' => $wpSync['note'],
                    'wordpress_sync_mode' => $wpSync['mode'],
                ];
            })
            ->values()
            ->sort(function (array $a, array $b) use ($severityWeight): int {
                if ($a['actionable'] !== $b['actionable']) {
                    return $a['actionable'] ? -1 : 1;
                }

                $severityCompare = ($severityWeight[$b['severity']] ?? 0) <=> ($severityWeight[$a['severity']] ?? 0);
                if ($severityCompare !== 0) {
                    return $severityCompare;
                }

                return strcmp((string) $a['page_url'], (string) $b['page_url']);
            })
            ->values();

        if (! $showAllAi) {
            $rows = $rows->where('actionable', true)->values();
        }

        $preselectedIssueIds = collect();

        if ($focusPageId !== null && $focusPageId > 0) {
            $preselectedIssueIds = $preselectedIssueIds->merge(
                $rows
                    ->where('actionable', true)
                    ->where('page_id', $focusPageId)
                    ->pluck('id')
            );
        }

        if ($issueType !== null) {
            $preselectedIssueIds = $preselectedIssueIds->merge(
                $rows
                    ->where('actionable', true)
                    ->where('issue_code', $issueType)
                    ->pluck('id')
            );
        }

        $preselectedIssueIds = $preselectedIssueIds
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $visibleSuggestions = $suggestions
            ->filter(function (SeoAuditFixSuggestion $suggestion) use ($scope, $visiblePageIds): bool {
                if ($scope === self::SCOPE_ALL) {
                    return true;
                }

                if (! $suggestion->seo_audit_page_id) {
                    return false;
                }

                return in_array((int) $suggestion->seo_audit_page_id, $visiblePageIds, true);
            })
            ->values()
            ->map(function (SeoAuditFixSuggestion $suggestion) use ($site): array {
                $page = $suggestion->page;
                $content = $page?->publishlayerArticle;
                $payload = is_array($suggestion->suggestion) ? $suggestion->suggestion : [];
                $isActionable = $page?->page_type === SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE
                    && $content !== null;
                $wpSync = $this->resolveWordPressSyncStatus((string) $suggestion->issue_code, $site, $isActionable);
                $suggestionState = $this->aiFixService->ensureSuggestionState($suggestion, $content);
                $latestDraft = $content?->drafts
                    ?->sortByDesc(fn ($draft): int => $draft->updated_at?->getTimestamp() ?? 0)
                    ->first();
                $canApply = $suggestionState === SeoAuditSuggestionState::SUGGESTED && $isActionable;
                $canEdit = $isActionable;
                $canSync = $suggestionState === SeoAuditSuggestionState::APPLIED_LOCAL
                    && $latestDraft !== null
                    && $wpSync['mode'] === 'sync';
                $presentation = $this->resolveSuggestionPresentation(
                    suggestionState: $suggestionState,
                    isActionable: $isActionable,
                    canSync: $canSync,
                    wordpressSyncMode: (string) ($wpSync['mode'] ?? 'advisory'),
                );

                return [
                    'id' => (int) $suggestion->id,
                    'status' => (string) $suggestion->status,
                    'suggestion_state' => $suggestionState->value,
                    'display_state' => $presentation['display_state'],
                    'status_label' => $presentation['status_label'],
                    'status_message' => $presentation['status_message'],
                    'status_badge_class' => $presentation['status_badge_class'],
                    'card_class' => $presentation['card_class'],
                    'issue_code' => (string) $suggestion->issue_code,
                    'page_url' => (string) ($page?->url ?? 'Unknown page'),
                    'is_actionable' => $isActionable,
                    'draft_id' => $latestDraft?->id,
                    'can_apply' => $canApply,
                    'can_edit' => $canEdit,
                    'can_sync' => $canSync,
                    'can_undo' => false,
                    'model_payload' => $payload,
                    'wordpress_sync_label' => $wpSync['label'],
                    'wordpress_sync_note' => $wpSync['note'],
                    'wordpress_sync_mode' => $wpSync['mode'],
                    'seo_field_statuses' => $this->resolveSuggestionFieldStatuses($payload, $site, $isActionable),
                ];
            })
            ->sortByDesc('id')
            ->values();

        return [
            'rows' => $rows,
            'preselected_issue_ids' => $preselectedIssueIds->all(),
            'show_all' => $showAllAi,
            'seo_capability' => $syncCapabilities,
            'generated_suggestions' => $visibleSuggestions,
        ];
    }

    /**
     * @return array{display_state:string,status_label:string,status_message:string,status_badge_class:string,card_class:string}
     */
    private function resolveSuggestionPresentation(
        SeoAuditSuggestionState $suggestionState,
        bool $isActionable,
        bool $canSync,
        string $wordpressSyncMode
    ): array {
        if (! $isActionable && $suggestionState === SeoAuditSuggestionState::SUGGESTED) {
            return [
                'display_state' => 'not_actionable',
                'status_label' => 'Informational only',
                'status_message' => 'This suggestion is informational only.',
                'status_badge_class' => 'border-border text-textSecondary',
                'card_class' => 'border border-border bg-surfaceSubtle/40',
            ];
        }

        return match ($suggestionState) {
            SeoAuditSuggestionState::SUGGESTED => [
                'display_state' => SeoAuditSuggestionState::SUGGESTED->value,
                'status_label' => 'Suggestion ready',
                'status_message' => 'Suggestion ready. Review, apply, or open a draft to edit it.',
                'status_badge_class' => 'border-sky-500/30 bg-sky-500/10 text-sky-700',
                'card_class' => 'border border-border bg-background',
            ],
            SeoAuditSuggestionState::APPLIED_LOCAL => [
                'display_state' => SeoAuditSuggestionState::APPLIED_LOCAL->value,
                'status_label' => 'Applied to content',
                'status_message' => $canSync
                    ? 'Already applied to content. Sync to publish changes.'
                    : ($wordpressSyncMode === 'sync'
                        ? 'Already applied to content.'
                        : 'Already applied to content. External sync is not available for this field.'),
                'status_badge_class' => 'border-amber-500/30 bg-amber-500/10 text-amber-700',
                'card_class' => 'border border-amber-500/20 bg-amber-500/5',
            ],
            SeoAuditSuggestionState::SYNCED_EXTERNAL => [
                'display_state' => SeoAuditSuggestionState::SYNCED_EXTERNAL->value,
                'status_label' => 'Synced to WordPress',
                'status_message' => 'Synced to WordPress.',
                'status_badge_class' => 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700',
                'card_class' => 'border border-emerald-500/20 bg-emerald-500/5',
            ],
        };
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,string>>
     */
    private function resolveSuggestionFieldStatuses(array $payload, ?ClientSite $site, bool $isActionable): array
    {
        $available = collect($this->seoFieldSyncCapabilityResolver->forSite($site, $isActionable)['fields'] ?? [])
            ->keyBy('key');

        $fieldCandidates = [
            'seo_title' => ['title', 'recommended_title'],
            'seo_h1' => ['h1', 'recommended_h1'],
            'seo_meta_description' => ['meta_description', 'recommended_meta_description'],
            'seo_canonical' => ['canonical', 'recommended_canonical'],
            'seo_og_title' => ['og_title', 'recommended_og_title'],
            'seo_og_description' => ['og_description', 'recommended_og_description'],
            'seo_twitter_title' => ['twitter_title', 'recommended_twitter_title'],
            'seo_twitter_description' => ['twitter_description', 'recommended_twitter_description'],
        ];

        $statuses = [];

        foreach ($fieldCandidates as $fieldKey => $paths) {
            if (! $this->hasSuggestionValue($payload, $paths)) {
                continue;
            }

            $fieldStatus = $available->get($fieldKey);
            if (! is_array($fieldStatus)) {
                continue;
            }

            $statuses[] = [
                'key' => (string) ($fieldStatus['key'] ?? $fieldKey),
                'label' => (string) ($fieldStatus['label'] ?? Str::headline($fieldKey)),
                'status' => (string) ($fieldStatus['status'] ?? 'advisory'),
                'status_label' => (string) ($fieldStatus['status_label'] ?? 'Advice only'),
                'status_badge_class' => (string) ($fieldStatus['status_badge_class'] ?? 'border-amber-500/30 bg-amber-500/10 text-amber-700'),
                'note' => (string) ($fieldStatus['note'] ?? ''),
            ];
        }

        return $statuses;
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,string> $paths
     */
    private function hasSuggestionValue(array $payload, array $paths): bool
    {
        foreach ($paths as $path) {
            $value = trim((string) data_get($payload, $path, ''));
            if ($value !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int,SeoAuditPage>  $visiblePages
     * @param  Collection<int,SeoAuditIssue>  $issues
     * @param  Collection<int,array<int,string>>  $issueCodesByPageId
     * @return Collection<int,array<string,mixed>>
     */
    private function buildPageRows(
        Collection $visiblePages,
        Collection $issues,
        Collection $issueCodesByPageId,
        string $scope
    ): Collection {
        $rows = $visiblePages
            ->map(function (SeoAuditPage $page) use ($issueCodesByPageId, $issues): array {
                $issueCodes = (array) ($issueCodesByPageId->get((int) $page->id, []));
                $score = $this->scoreCalculator->scoreFromIssueCodes($issueCodes);
                $level = $this->scoreCalculator->levelForScore($score);

                $supportedIssuesForPage = $issues
                    ->where('seo_audit_page_id', $page->id)
                    ->filter(fn (SeoAuditIssue $issue): bool => $this->isIssueActionable($issue));

                return [
                    'id' => (int) $page->id,
                    'url' => (string) $page->url,
                    'scope' => $page->page_type === SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE
                        ? 'Argusly content'
                        : 'Other site page',
                    'is_publishlayer' => $page->page_type === SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE,
                    'is_actionable_page' => $page->page_type === SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE && $page->publishlayerArticle !== null,
                    'seo_score' => $score,
                    'seo_level' => $level,
                    'title_status' => $this->resolveTitleStatus($page, $issueCodes),
                    'meta_status' => $this->resolveSimpleStatus($page->meta_description, $issueCodes, 'meta_description_missing'),
                    'canonical_status' => $this->resolveSimpleStatus($page->canonical_url, $issueCodes, 'canonical_missing'),
                    'internal_links_count' => (int) ($page->internal_links_count ?? 0),
                    'ai_fix_available' => $supportedIssuesForPage->isNotEmpty(),
                    'actionable_issue_ids' => $supportedIssuesForPage->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                ];
            })
            ->values()
            ->all();

        usort($rows, static function (array $a, array $b): int {
            if ($a['is_publishlayer'] !== $b['is_publishlayer']) {
                return $a['is_publishlayer'] ? -1 : 1;
            }

            if ($a['seo_score'] !== $b['seo_score']) {
                return $a['seo_score'] <=> $b['seo_score'];
            }

            return strcmp((string) $a['url'], (string) $b['url']);
        });

        if ($scope !== self::SCOPE_ALL) {
            $rows = collect($rows)
                ->sortBy(fn (array $row): int => (int) $row['seo_score'])
                ->values()
                ->all();
        }

        return collect($rows);
    }

    /**
     * @param  Collection<int,SeoAudit>  $historyAudits
     * @return Collection<int,array<string,mixed>>
     */
    private function buildHistory(Collection $historyAudits): Collection
    {
        return $historyAudits
            ->map(function (SeoAudit $historyAudit): array {
                $historyAudit->loadMissing(['pages', 'issues']);

                $score = $this->scoreCalculator->scoreForAudit($historyAudit->pages, $historyAudit->issues);

                $issueCounts = is_array($historyAudit->issue_counts)
                    ? $historyAudit->issue_counts
                    : [
                        'error' => (int) $historyAudit->issues->where('severity', 'error')->count(),
                        'warning' => (int) $historyAudit->issues->where('severity', 'warning')->count(),
                        'info' => (int) $historyAudit->issues->where('severity', 'info')->count(),
                    ];

                return [
                    'id' => (int) $historyAudit->id,
                    'started_at' => $historyAudit->started_at,
                    'status' => (string) $historyAudit->status,
                    'seo_health_score' => $score,
                    'issue_counts' => [
                        'error' => (int) ($issueCounts['error'] ?? 0),
                        'warning' => (int) ($issueCounts['warning'] ?? 0),
                        'info' => (int) ($issueCounts['info'] ?? 0),
                    ],
                    'pages_crawled' => (int) ($historyAudit->pages_crawled ?? $historyAudit->pages->count()),
                ];
            })
            ->sortByDesc(fn (array $row): int => (int) (data_get($row, 'started_at')?->getTimestamp() ?? 0))
            ->values();
    }

    /**
     * @param  array<int,string>  $issueCodes
     * @return array{label:string,classes:string}
     */
    private function resolveTitleStatus(SeoAuditPage $page, array $issueCodes): array
    {
        if (in_array('title_missing', $issueCodes, true) || trim((string) $page->title) === '') {
            return [
                'label' => 'Missing',
                'classes' => 'text-rose-600',
            ];
        }

        if (in_array('title_long', $issueCodes, true)) {
            return [
                'label' => 'Too long',
                'classes' => 'text-amber-600',
            ];
        }

        return [
            'label' => 'OK',
            'classes' => 'text-success',
        ];
    }

    /**
     * @param  array<int,string>  $issueCodes
     * @return array{label:string,classes:string}
     */
    private function resolveSimpleStatus(?string $value, array $issueCodes, string $missingCode): array
    {
        if (in_array($missingCode, $issueCodes, true) || trim((string) $value) === '') {
            return [
                'label' => 'Missing',
                'classes' => 'text-rose-600',
            ];
        }

        return [
            'label' => 'OK',
            'classes' => 'text-success',
        ];
    }

    private function isIssueActionable(SeoAuditIssue $issue): bool
    {
        $page = $issue->page;

        return $page !== null
            && $page->page_type === SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE
            && $page->publishlayerArticle !== null
            && $this->aiFixService->isSupportedIssueCode((string) $issue->code);
    }

    /**
     * @return array{label:string,note:string,mode:string}
     */
    private function resolveWordPressSyncStatus(string $issueCode, ?ClientSite $site, bool $isActionable): array
    {
        if (! $isActionable) {
            return [
                'label' => 'Advice only',
                'note' => 'No linked Argusly draft.',
                'mode' => 'advisory',
            ];
        }

        $issueCode = trim($issueCode);
        if (in_array($issueCode, [
            SeoAuditAiFixService::ISSUE_TITLE_LONG,
            SeoAuditAiFixService::ISSUE_TITLE_MISSING,
            SeoAuditAiFixService::ISSUE_H1_MISSING,
        ], true)) {
            return [
                'label' => 'Can sync to WordPress',
                'note' => 'Title and H1 updates are always applicable.',
                'mode' => 'sync',
            ];
        }

        $siteIsWordPress = $site && ClientSite::normalizeType((string) $site->type) === ClientSite::TYPE_WORDPRESS;
        if (! $siteIsWordPress) {
            return [
                'label' => 'Recommendation only',
                'note' => 'WordPress SEO sync requires a WordPress connector.',
                'mode' => 'advisory',
            ];
        }

        $supportsMetaDescription = (bool) ($site->supports_meta_description ?? false);
        $supportsCanonical = (bool) ($site->supports_canonical ?? false);
        $supportsOgTags = (bool) ($site->supports_og_tags ?? false);

        $canSync = match ($issueCode) {
            SeoAuditAiFixService::ISSUE_META_DESCRIPTION_MISSING => $supportsMetaDescription,
            SeoAuditAiFixService::ISSUE_CANONICAL_MISSING => $supportsCanonical,
            default => $supportsMetaDescription || $supportsCanonical || $supportsOgTags,
        };

        if ($canSync) {
            return [
                'label' => 'Can sync to WordPress',
                'note' => 'Detected SEO plugin supports metadata sync.',
                'mode' => 'sync',
            ];
        }

        return [
            'label' => 'Recommendation only',
            'note' => 'Requires supported SEO plugin.',
            'mode' => 'advisory',
        ];
    }
}
