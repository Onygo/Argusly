<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverDraftJob;
use App\Jobs\SeoAudit\GenerateSeoFixSuggestionsJob;
use App\Jobs\SeoAudit\RunSeoAuditJob;
use App\Models\ClientSite;
use App\Models\SeoAudit;
use App\Models\SeoAuditFixSuggestion;
use App\Models\SeoAuditIssue;
use App\Models\SeoAuditPage;
use App\Services\Entitlements\WorkspaceEntitlementsService;
use App\Services\PlanQuotaService;
use App\Services\SeoAudit\SeoAuditAiFixService;
use App\Services\SeoAudit\SeoAuditRunDashboardPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AppSiteSeoAuditController extends Controller
{
    public function index(Request $request, ClientSite $site, PlanQuotaService $quotaService): View
    {
        $this->assertSiteInOrganization($request, $site);

        $audits = SeoAudit::query()
            ->where('client_site_id', $site->id)
            ->latest('started_at')
            ->with([
                'pages:id,seo_audit_id,page_type',
                'issues:id,seo_audit_id,seo_audit_page_id,severity',
            ])
            ->limit(20)
            ->get()
            ->map(function (SeoAudit $audit): SeoAudit {
                $publishLayerPageIds = $audit->pages
                    ->where('page_type', SeoAuditPage::PAGE_TYPE_PUBLISHLAYER_ARTICLE)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                $visibleIssues = $publishLayerPageIds !== []
                    ? $audit->issues
                        ->filter(fn (SeoAuditIssue $issue): bool => $issue->seo_audit_page_id !== null
                            && in_array((int) $issue->seo_audit_page_id, $publishLayerPageIds, true))
                        ->values()
                    : $audit->issues;

                $audit->setAttribute('overview_issue_counts', [
                    'error' => (int) $visibleIssues->where('severity', 'error')->count(),
                    'warning' => (int) $visibleIssues->where('severity', 'warning')->count(),
                    'info' => (int) $visibleIssues->where('severity', 'info')->count(),
                ]);

                return $audit;
            });

        $lastAudit = $audits->first();

        return view('app.sites.seo-audits.index', [
            'site' => $site,
            'audits' => $audits,
            'lastAudit' => $lastAudit,
            'auditPageLimit' => $quotaService->limitForMetric($site->workspace, PlanQuotaService::METRIC_AUDIT_PAGES_CRAWLED, -1),
            'auditPagesUsed' => $quotaService->periodUsage($site->workspace, PlanQuotaService::METRIC_AUDIT_PAGES_CRAWLED, now()->format('Ym')),
        ]);
    }

    public function run(Request $request, ClientSite $site): RedirectResponse
    {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);

        $data = $request->validate([
            'max_pages' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $maxPages = (int) ($data['max_pages'] ?? 50);

        RunSeoAuditJob::dispatch((string) $site->id, $maxPages)->onQueue('default');

        return back()->with('status', 'SEO audit queued.');
    }

    public function show(Request $request, ClientSite $site, SeoAudit $audit): View
    {
        $this->assertSiteInOrganization($request, $site);
        $this->assertAuditInSite($site, $audit);

        $scope = trim((string) $request->query('scope', ''));
        if ($scope === '') {
            $legacyScope = $request->string('page_scope')->toString();
            $scope = $legacyScope === 'all' ? 'all' : ($legacyScope === 'publishlayer' ? 'publishlayer' : '');
        }
        $issueFilter = (string) $request->query('issue_filter', 'all');
        $issueType = trim((string) $request->query('issue_type', ''));
        $issueType = $issueType !== '' ? $issueType : null;
        $showAllAi = in_array(strtolower(trim((string) $request->query('ai_show_all', '0'))), ['1', 'true', 'yes'], true);
        $focusPageId = (int) $request->query('focus_page_id', 0);
        $focusPageId = $focusPageId > 0 ? $focusPageId : null;

        $audit->load([
            'pages' => fn ($query) => $query->orderByDesc('broken_links_count')->orderBy('url'),
            'issues' => fn ($query) => $query
                ->orderByRaw("CASE severity WHEN 'error' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
                ->orderBy('code'),
            'issues.page',
            'fixSuggestions' => fn ($query) => $query->with(['applyLog', 'page.publishlayerArticle.drafts'])->orderByDesc('id'),
        ]);

        $historyAudits = SeoAudit::query()
            ->where('client_site_id', $site->id)
            ->latest('started_at')
            ->limit(8)
            ->with([
                'pages' => fn ($query) => $query->select(['id', 'seo_audit_id', 'page_type', 'url']),
                'issues' => fn ($query) => $query->select(['id', 'seo_audit_id', 'seo_audit_page_id', 'severity', 'code']),
            ])
            ->get();

        $dashboard = app(SeoAuditRunDashboardPresenter::class)->build(
            audit: $audit,
            scope: $scope,
            issueFilter: $issueFilter,
            issueType: $issueType,
            showAllAi: $showAllAi,
            focusPageId: $focusPageId,
            historyAudits: $historyAudits
        );

        return view('app.sites.seo-audits.show', [
            'site' => $site,
            'audit' => $audit,
            'dashboard' => $dashboard,
        ]);
    }

    public function generateFixSuggestions(
        Request $request,
        ClientSite $site,
        SeoAudit $audit,
        SeoAuditAiFixService $aiFixService
    ): RedirectResponse {
        $this->assertCanUseAiSeoFix($request);
        $this->assertSiteInOrganization($request, $site);
        $this->assertAuditInSite($site, $audit);

        $data = $request->validate([
            'issue_ids' => ['required', 'array', 'min:1'],
            'issue_ids.*' => ['integer'],
        ]);

        $issueIds = collect((array) ($data['issue_ids'] ?? []))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($issueIds->isEmpty()) {
            return back()->withErrors(['ai_fix' => 'Select at least one supported issue to generate AI suggestions.']);
        }

        $issues = SeoAuditIssue::query()
            ->where('seo_audit_id', $audit->id)
            ->whereIn('id', $issueIds->all())
            ->with(['page.publishlayerArticle'])
            ->get();

        $supportedIssueIds = $issues
            ->filter(fn (SeoAuditIssue $issue): bool => $issue->page !== null && $aiFixService->isSupportedIssueCode((string) $issue->code))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();

        if ($supportedIssueIds->isEmpty()) {
            return back()->withErrors(['ai_fix' => 'No supported SEO issues were selected.']);
        }

        foreach ($issues as $issue) {
            $content = $issue->page?->publishlayerArticle;
            if ($content) {
                $this->authorize('update', $content);
            }
        }

        GenerateSeoFixSuggestionsJob::dispatch(
            auditId: (int) $audit->id,
            issueIds: $supportedIssueIds->all(),
            userId: (int) $request->user()->id,
        )->onQueue('generation');

        $estimate = $aiFixService->estimateCreditsForIssueCount($supportedIssueIds->count());

        return back()->with('status', sprintf(
            'AI SEO Fix queued for %d issue(s). Estimated credits: %d.',
            $supportedIssueIds->count(),
            $estimate
        ));
    }

    public function applyFixSuggestion(
        Request $request,
        ClientSite $site,
        SeoAudit $audit,
        SeoAuditFixSuggestion $suggestion,
        SeoAuditAiFixService $aiFixService
    ): RedirectResponse {
        $this->assertCanUseAiSeoFix($request);
        $this->assertSiteInOrganization($request, $site);
        $this->assertAuditInSite($site, $audit);

        if ((int) $suggestion->seo_audit_id !== (int) $audit->id) {
            abort(404);
        }

        $page = $suggestion->page;
        $content = $page?->publishlayerArticle;
        if (! $page || ! $content) {
            return back()->withErrors(['ai_fix' => 'This suggestion can only be applied to PublishLayer content.']);
        }

        $this->authorize('update', $content);

        try {
            $aiFixService->applySuggestionToDraft($suggestion, $content, (int) $request->user()->id);
        } catch (\Throwable $exception) {
            return back()->withErrors(['ai_fix' => $exception->getMessage()]);
        }

        return back()->with('status', 'AI SEO Fix applied to draft revision. No publish action was executed.');
    }

    public function syncFixSuggestion(
        Request $request,
        ClientSite $site,
        SeoAudit $audit,
        SeoAuditFixSuggestion $suggestion,
        SeoAuditAiFixService $aiFixService,
        WorkspaceEntitlementsService $entitlements
    ): RedirectResponse {
        $this->assertCanUseAiSeoFix($request);
        $this->assertSiteInOrganization($request, $site);
        $this->assertAuditInSite($site, $audit);

        if ((int) $suggestion->seo_audit_id !== (int) $audit->id) {
            abort(404);
        }

        $page = $suggestion->page;
        $content = $page?->publishlayerArticle;
        if (! $page || ! $content) {
            return back()->withErrors(['ai_fix' => 'This suggestion is not linked to editable PublishLayer content.']);
        }

        $this->authorize('update', $content);

        if (ClientSite::normalizeType((string) ($site->type ?? '')) !== ClientSite::TYPE_WORDPRESS) {
            return back()->withErrors(['ai_fix' => 'WordPress sync is only available for WordPress-connected sites.']);
        }

        try {
            $entitlements->assertCanPushToWp($site->workspace);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['ai_fix' => $exception->getMessage()]);
        }

        $draft = $aiFixService->ensureEditableDraftForContent($content, (int) $request->user()->id);
        $draft->update([
            'status' => 'ready_to_deliver',
            'delivery_status' => 'pending',
            'delivery_last_error' => null,
        ]);

        DeliverDraftJob::dispatch((string) $draft->id, forceDelivery: true)
            ->onQueue((string) config('publishlayer.webhooks.queue', 'deliveries'));

        return back()->with('status', 'Suggestion sync queued for WordPress.');
    }

    public function editFixSuggestion(
        Request $request,
        ClientSite $site,
        SeoAudit $audit,
        SeoAuditFixSuggestion $suggestion,
        SeoAuditAiFixService $aiFixService
    ): RedirectResponse {
        $this->assertCanUseAiSeoFix($request);
        $this->assertSiteInOrganization($request, $site);
        $this->assertAuditInSite($site, $audit);

        if ((int) $suggestion->seo_audit_id !== (int) $audit->id) {
            abort(404);
        }

        $page = $suggestion->page;
        $content = $page?->publishlayerArticle;
        if (! $page || ! $content) {
            return back()->withErrors(['ai_fix' => 'This suggestion is informational only and is not linked to editable PublishLayer content.']);
        }

        $this->authorize('update', $content);

        try {
            $draft = $aiFixService->ensureEditableDraftForContent($content, (int) $request->user()->id);
        } catch (\Throwable $exception) {
            return back()->withErrors(['ai_fix' => $exception->getMessage()]);
        }

        return redirect()
            ->route('app.drafts.show', ['draft' => $draft, 'tab' => 'improve'])
            ->with('status', 'Editable draft ready.');
    }

    private function assertSiteInOrganization(Request $request, ClientSite $site): void
    {
        if ((int) $site->workspace?->organization_id !== (int) $request->user()->organization_id) {
            abort(404);
        }
    }

    private function assertAuditInSite(ClientSite $site, SeoAudit $audit): void
    {
        if ((string) $audit->client_site_id !== (string) $site->id) {
            abort(404);
        }
    }

    private function assertCanUseAiSeoFix(Request $request): void
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        if ($user->is_admin) {
            return;
        }

        if (! in_array((string) $user->role, ['owner', 'admin', 'editor'], true)) {
            abort(403);
        }
    }
}
