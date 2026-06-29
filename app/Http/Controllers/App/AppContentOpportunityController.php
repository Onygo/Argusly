<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\RunContentOpportunityEngineRequest;
use App\Jobs\ContentOpportunityEngine\GenerateContentOpportunitiesJob;
use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingExecutionSetting;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\ContentOpportunity;
use App\Models\ContentOpportunityRun;
use App\Models\Opportunity;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticActionRunLogger;
use App\Services\AgenticMarketing\AgenticApprovalGate;
use App\Services\ContentOpportunityEngine\ContentOpportunityEngine;
use App\Services\Mos\Opportunity\ContentOpportunityBriefPayloadBuilder;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalBriefWriter;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalBriefWriteResult;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppContentOpportunityController extends Controller
{
    public function index(Request $request): View
    {
        $organizationId = (int) $request->user()->organization_id;
        $workspaces = Workspace::query()
            ->where('organization_id', $organizationId)
            ->with(['clientSites' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('name')
                ->select(['id', 'workspace_id', 'name'])])
            ->orderBy('created_at')
            ->get(['id', 'organization_id', 'name', 'display_name']);

        if ($workspaces->isEmpty()) {
            abort(404);
        }

        $workspace = $request->query('workspace_id')
            ? $workspaces->firstWhere('id', (string) $request->query('workspace_id'))
            : $workspaces->first();

        if (! $workspace) {
            abort(404);
        }

        $siteId = trim((string) $request->query('client_site_id', '')) ?: null;
        if ($siteId && ! $workspace->clientSites->contains('id', $siteId)) {
            abort(404);
        }

        $query = ContentOpportunity::query()
            ->with('site:id,name')
            ->where('workspace_id', $workspace->id)
            ->when($siteId, fn ($query) => $query->where('client_site_id', $siteId))
            ->when($request->query('type'), fn ($query, $type) => $query->where('type', $type))
            ->when($request->query('funnel_stage'), fn ($query, $stage) => $query->where('funnel_stage', $stage))
            ->when($request->query('intent'), fn ($query, $intent) => $query->where('primary_search_intent', $intent))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status), fn ($query) => $query->where('status', ContentOpportunity::STATUS_OPEN));

        match ((string) $request->query('sort', 'priority')) {
            'urgency' => $query->orderByDesc('urgency_score'),
            'business_value' => $query->orderByDesc('business_value_score'),
            'newest' => $query->latest(),
            default => $query->orderByDesc('priority_score')->orderByDesc('last_seen_at'),
        };

        $opportunities = $query->paginate(25)->withQueryString();
        $executionSettings = AgenticMarketingExecutionSetting::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('brand_voice_id')
            ->first()
            ?: AgenticMarketingExecutionSetting::defaultsFor($workspace);
        $gate = app(AgenticApprovalGate::class);
        $opportunityPolicies = $opportunities->getCollection()
            ->mapWithKeys(function (ContentOpportunity $opportunity) use ($gate, $workspace): array {
                $siteId = (string) ($opportunity->client_site_id ?? '');
                if ($siteId === '' && $workspace->clientSites->count() === 1) {
                    $siteId = (string) $workspace->clientSites->first()->id;
                }
                $context = [
                    'site_id' => $siteId !== '' ? $siteId : null,
                    'priority_score' => (int) round((float) $opportunity->priority_score),
                    'estimated_credit_impact' => $this->estimatedOpportunityCredits($opportunity),
                    'is_external_publication' => true,
                    'content_complete' => true,
                ];

                return [
                    (string) $opportunity->id => [
                        'brief' => $gate->forAction(AgenticApprovalGate::ACTION_GENERATE_BRIEF, $workspace, $context),
                        'chained' => $gate->forAction(AgenticApprovalGate::ACTION_CREATE_CHAINED_PLAN, $workspace, $context),
                        'estimated_credits' => $context['estimated_credit_impact'],
                    ],
                ];
            });
        $runs = ContentOpportunityRun::query()
            ->where('workspace_id', $workspace->id)
            ->when($siteId, fn ($query) => $query->where('client_site_id', $siteId))
            ->latest()
            ->limit(10)
            ->get();

        return view('app.content-opportunities.index', [
            'workspace' => $workspace,
            'workspaces' => $workspaces,
            'siteId' => $siteId,
            'opportunities' => $opportunities,
            'runs' => $runs,
            'filters' => $request->only(['type', 'funnel_stage', 'intent', 'status', 'sort']),
            'executionSettings' => $executionSettings,
            'opportunityPolicies' => $opportunityPolicies,
            'summary' => [
                'total' => ContentOpportunity::query()->where('workspace_id', $workspace->id)->count(),
                'open' => ContentOpportunity::query()->where('workspace_id', $workspace->id)->where('status', ContentOpportunity::STATUS_OPEN)->count(),
                'strategic' => ContentOpportunity::query()->where('workspace_id', $workspace->id)->where('expected_impact', 'strategic')->count(),
                'avg_priority' => (float) ContentOpportunity::query()->where('workspace_id', $workspace->id)->avg('priority_score'),
            ],
        ]);
    }

    private function estimatedOpportunityCredits(ContentOpportunity $opportunity): int
    {
        return match ((string) $opportunity->type) {
            'campaign_cluster' => 18,
            'comparison_page', 'implementation_guide', 'bofu_page', 'use_case_page', 'industry_page', 'feature_page' => 12,
            'refresh_opportunity', 'answer_block_opportunity', 'faq_opportunity' => 6,
            default => 8,
        };
    }

    public function run(RunContentOpportunityEngineRequest $request, ContentOpportunityEngine $engine): RedirectResponse
    {
        $data = $request->validated();
        $workspace = Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->findOrFail($data['workspace_id']);

        $siteId = $data['client_site_id'] ?? null;
        if ($siteId) {
            ClientSite::query()
                ->where('workspace_id', $workspace->id)
                ->findOrFail($siteId);
        }

        if ($request->boolean('run_inline')) {
            $engine->run($workspace, $siteId, ['source_type' => 'ui']);

            return back()->with('status', 'Content Opportunity Engine run completed.');
        }

        GenerateContentOpportunitiesJob::dispatch(
            workspaceId: (string) $workspace->id,
            clientSiteId: $siteId,
            options: ['source_type' => 'ui'],
        )->onQueue('intelligence')->afterCommit();

        return back()->with('status', 'Content Opportunity Engine run queued.');
    }

    public function createBrief(
        Request $request,
        ContentOpportunity $opportunity,
        ContentOpportunityBriefPayloadBuilder $payloadBuilder,
        ContentOpportunityCanonicalBriefWriter $canonicalBriefWriter,
    ): RedirectResponse {
        abort_unless((int) $opportunity->organization_id === (int) $request->user()->organization_id, 404);

        $data = $request->validate([
            'mode' => ['required', 'in:single,chained'],
            'site_id' => ['nullable', 'uuid'],
        ]);

        $opportunity->loadMissing('workspace', 'site');

        $site = $this->resolveBriefSite($opportunity, $request, $data);
        if ($site instanceof RedirectResponse) {
            return $site;
        }

        if (! $site) {
            return back()->withErrors(['opportunity' => 'Connect a site before creating a brief from this opportunity.']);
        }

        if ((bool) config('features.mos_canonical_content_opportunity_brief_writer', false)) {
            $canonicalResult = $this->createCanonicalBriefWhenSafe(
                $opportunity,
                $site,
                $data['mode'],
                $request,
                $canonicalBriefWriter,
            );

            if ($canonicalResult?->brief) {
                $this->markLegacyOpportunityPlanned($opportunity);
                $this->recordBriefCreationRun($opportunity, $canonicalResult->brief, $site, $data['mode'], $request);

                return $this->redirectToCreatedBrief($canonicalResult->brief, $data['mode']);
            }

            if ($canonicalResult?->duplicateBrief) {
                $this->markLegacyOpportunityPlanned($opportunity);
                $this->recordBriefCreationRun($opportunity, $canonicalResult->duplicateBrief, $site, $data['mode'], $request);

                return $this->redirectToCreatedBrief($canonicalResult->duplicateBrief, $data['mode']);
            }
        }

        $brief = Brief::query()->create($payloadBuilder->build(
            $opportunity,
            $site,
            $data['mode'],
            (int) $request->user()->id,
        ));

        $this->markLegacyOpportunityPlanned($opportunity);
        $this->recordBriefCreationRun($opportunity, $brief, $site, $data['mode'], $request);

        return $this->redirectToCreatedBrief($brief, $data['mode']);
    }

    /**
     * @param  array{mode: string, site_id?: string|null}  $data
     */
    private function resolveBriefSite(ContentOpportunity $opportunity, Request $request, array $data): ClientSite|RedirectResponse|null
    {
        $site = $opportunity->site;

        if (! $site && $request->filled('site_id')) {
            return ClientSite::query()
                ->where('workspace_id', $opportunity->workspace_id)
                ->where('is_active', true)
                ->findOrFail((string) $data['site_id']);
        }

        if (! $site && $opportunity->workspace) {
            $sites = ClientSite::query()
                ->where('workspace_id', $opportunity->workspace_id)
                ->where('is_active', true)
                ->orderBy('created_at')
                ->limit(2)
                ->get();

            if ($sites->count() === 1) {
                return $sites->first();
            }

            if ($sites->count() > 1) {
                return back()->withErrors(['site_id' => 'Select the publishing site before creating a brief from this opportunity.']);
            }
        }

        return $site;
    }

    private function createCanonicalBriefWhenSafe(
        ContentOpportunity $opportunity,
        ClientSite $site,
        string $mode,
        Request $request,
        ContentOpportunityCanonicalBriefWriter $canonicalBriefWriter,
    ): ?ContentOpportunityCanonicalBriefWriteResult {
        $canonical = Opportunity::query()
            ->where('content_opportunity_id', $opportunity->id)
            ->where('workspace_id', $opportunity->workspace_id)
            ->where('organization_id', $opportunity->organization_id)
            ->oldest()
            ->first();

        if (! $canonical) {
            return null;
        }

        $dryRun = $canonicalBriefWriter->dryRun($opportunity, $canonical, $site, $mode, $request->user());
        if ($dryRun->duplicateBrief) {
            return $dryRun;
        }

        if (! $dryRun->safe) {
            return null;
        }

        return $canonicalBriefWriter->apply($opportunity, $canonical, $site, $mode, $request->user());
    }

    private function markLegacyOpportunityPlanned(ContentOpportunity $opportunity): void
    {
        $opportunity->forceFill(['status' => ContentOpportunity::STATUS_PLANNED])->save();
    }

    private function recordBriefCreationRun(
        ContentOpportunity $opportunity,
        Brief $brief,
        ClientSite $site,
        string $mode,
        Request $request,
    ): void {
        app(AgenticActionRunLogger::class)->recordStandalone(
            $opportunity->workspace,
            $mode === 'chained' ? AgenticApprovalGate::ACTION_CREATE_CHAINED_PLAN : AgenticApprovalGate::ACTION_GENERATE_BRIEF,
            AgenticActionRun::STATUS_COMPLETED,
            [
                'content_id' => $opportunity->content_id,
                'reason' => 'Customer created a brief from a content opportunity.',
                'input_snapshot' => [
                    'mode' => $mode,
                    'site_id' => (string) $site->id,
                    'opportunity_id' => (string) $opportunity->id,
                ],
                'output_snapshot' => [
                    'brief_id' => (string) $brief->id,
                    'title' => $brief->title,
                ],
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]
        );
    }

    private function redirectToCreatedBrief(Brief $brief, string $mode): RedirectResponse
    {
        if ($mode === 'chained') {
            return redirect()
                ->route('app.content.series.create', ['source_brief' => $brief->id])
                ->with('status', 'Brief created from opportunity. Review the chained article plan.');
        }

        return redirect()
            ->route('app.content.workspace.show', $brief)
            ->with('status', 'Brief created from opportunity. Generate a single article draft when ready.');
    }
}
