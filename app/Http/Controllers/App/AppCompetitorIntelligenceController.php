<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\ImportCompetitorContentRequest;
use App\Http\Requests\App\RunCompetitorIntelligenceRequest;
use App\Http\Resources\App\CompetitorInsightResource;
use App\Jobs\CompetitorIntelligence\AnalyzeCompetitorIntelligenceJob;
use App\Models\ClientSite;
use App\Models\CompetitorContentItem;
use App\Models\CompetitorContentOpportunity;
use App\Models\CompetitorIntelligenceRun;
use App\Models\CompetitorTopicSignal;
use App\Models\SiteCompetitor;
use App\Services\CompetitorIntelligence\CompetitorContentImportPipeline;
use App\Services\CompetitorIntelligence\CompetitorIntelligenceAnalyzer;
use App\Services\Entitlements\FeatureGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AppCompetitorIntelligenceController extends Controller
{
    public function index(Request $request, ClientSite $site, FeatureGate $featureGate): View
    {
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);

        $competitors = SiteCompetitor::query()
            ->where('client_site_id', $site->id)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->withCount(['contentItems', 'topicSignals', 'contentOpportunities'])
            ->get();

        $selectedCompetitorId = $request->integer('competitor_id') ?: null;
        $selectedCompetitor = $selectedCompetitorId
            ? $competitors->firstWhere('id', $selectedCompetitorId)
            : $competitors->firstWhere('is_active', true);

        $topicSignals = CompetitorTopicSignal::query()
            ->where('workspace_id', $site->workspace_id)
            ->where('client_site_id', $site->id)
            ->when($selectedCompetitor, fn ($query) => $query->where('site_competitor_id', $selectedCompetitor->id))
            ->orderByDesc('opportunity_score')
            ->limit(40)
            ->get();

        $opportunities = CompetitorContentOpportunity::query()
            ->where('workspace_id', $site->workspace_id)
            ->where('client_site_id', $site->id)
            ->when($selectedCompetitor, fn ($query) => $query->where('site_competitor_id', $selectedCompetitor->id))
            ->orderByDesc('priority_score')
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get();

        $contentItems = CompetitorContentItem::query()
            ->where('workspace_id', $site->workspace_id)
            ->where('client_site_id', $site->id)
            ->when($selectedCompetitor, fn ($query) => $query->where('site_competitor_id', $selectedCompetitor->id))
            ->orderByDesc('imported_at')
            ->limit(20)
            ->get();

        $runs = CompetitorIntelligenceRun::query()
            ->where('workspace_id', $site->workspace_id)
            ->where('client_site_id', $site->id)
            ->when($selectedCompetitor, fn ($query) => $query->where('site_competitor_id', $selectedCompetitor->id))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('app.competitor-intelligence.index', [
            'site' => $site,
            'competitors' => $competitors,
            'selectedCompetitor' => $selectedCompetitor,
            'topicSignals' => $topicSignals,
            'opportunities' => $opportunities,
            'contentItems' => $contentItems,
            'runs' => $runs,
        ]);
    }

    public function importContent(
        ImportCompetitorContentRequest $request,
        ClientSite $site,
        FeatureGate $featureGate,
        CompetitorContentImportPipeline $pipeline
    ): RedirectResponse {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);

        $competitor = $this->competitorForSite($site, (int) $request->integer('site_competitor_id'));
        $pipeline->import($competitor, $request->validated());

        return redirect()
            ->route('app.sites.competitor-intelligence.index', ['site' => $site, 'competitor_id' => $competitor->id])
            ->with('status', 'Competitor content imported and normalized.');
    }

    public function analyze(
        RunCompetitorIntelligenceRequest $request,
        ClientSite $site,
        FeatureGate $featureGate,
        CompetitorIntelligenceAnalyzer $analyzer
    ): RedirectResponse {
        Gate::authorize('manage-organization');
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);

        $competitor = $request->filled('site_competitor_id')
            ? $this->competitorForSite($site, (int) $request->integer('site_competitor_id'))
            : null;

        if ($request->boolean('run_inline')) {
            $analyzer->analyze($site->workspace, $competitor, ['requested_from' => 'ui']);

            return redirect()
                ->route('app.sites.competitor-intelligence.index', ['site' => $site, 'competitor_id' => $competitor?->id])
                ->with('status', 'Competitor intelligence analysis completed.');
        }

        AnalyzeCompetitorIntelligenceJob::dispatch(
            workspaceId: (string) $site->workspace_id,
            siteCompetitorId: $competitor?->id,
            input: ['requested_from' => 'ui'],
        )
            ->onQueue('intelligence')
            ->afterCommit();

        return redirect()
            ->route('app.sites.competitor-intelligence.index', ['site' => $site, 'competitor_id' => $competitor?->id])
            ->with('status', 'Competitor intelligence analysis queued.');
    }

    public function opportunities(Request $request, ClientSite $site, FeatureGate $featureGate): JsonResponse
    {
        $this->assertSiteInOrganization($request, $site);
        $this->assertFeature($featureGate, $site);

        $opportunities = CompetitorContentOpportunity::query()
            ->where('workspace_id', $site->workspace_id)
            ->where('client_site_id', $site->id)
            ->orderByDesc('priority_score')
            ->limit(100)
            ->get();

        return CompetitorInsightResource::collection($opportunities)->response();
    }

    private function competitorForSite(ClientSite $site, int $competitorId): SiteCompetitor
    {
        return SiteCompetitor::query()
            ->where('client_site_id', $site->id)
            ->where('workspace_id', $site->workspace_id)
            ->findOrFail($competitorId);
    }

    private function assertSiteInOrganization(Request $request, ClientSite $site): void
    {
        if ((int) $site->workspace?->organization_id !== (int) $request->user()->organization_id) {
            abort(404);
        }
    }

    private function assertFeature(FeatureGate $featureGate, ClientSite $site): void
    {
        try {
            $featureGate->assert($site->workspace, 'link_intelligence');
        } catch (AuthorizationException $exception) {
            abort(403, $exception->getMessage());
        }
    }
}
