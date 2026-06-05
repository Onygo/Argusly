<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\RunCampaignClusterPlanningRequest;
use App\Jobs\CampaignClusterEngine\GenerateCampaignClustersJob;
use App\Models\AgenticActionRun;
use App\Models\CampaignCluster;
use App\Models\CampaignClusterRun;
use App\Models\ClientSite;
use App\Models\Workspace;
use App\Services\AgenticMarketing\AgenticActionRunLogger;
use App\Services\AgenticMarketing\AgenticApprovalGate;
use App\Services\CampaignClusterEngine\CampaignClusterActionMaterializer;
use App\Services\CampaignClusterEngine\CampaignClusterPlanningEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppCampaignClusterController extends Controller
{
    public function index(Request $request): View
    {
        $organizationId = (int) $request->user()->organization_id;
        $workspaces = Workspace::query()
            ->where('organization_id', $organizationId)
            ->with('clientSites:id,workspace_id,name')
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

        $clusters = CampaignCluster::query()
            ->where('workspace_id', $workspace->id)
            ->when($siteId, fn ($query) => $query->where('client_site_id', $siteId))
            ->withCount(['items', 'dependencies'])
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $runs = CampaignClusterRun::query()
            ->where('workspace_id', $workspace->id)
            ->when($siteId, fn ($query) => $query->where('client_site_id', $siteId))
            ->latest()
            ->limit(10)
            ->get();

        return view('app.campaign-clusters.index', [
            'workspace' => $workspace,
            'workspaces' => $workspaces,
            'siteId' => $siteId,
            'clusters' => $clusters,
            'runs' => $runs,
            'summary' => [
                'total' => CampaignCluster::query()->where('workspace_id', $workspace->id)->count(),
                'avg_authority' => (float) CampaignCluster::query()->where('workspace_id', $workspace->id)->avg('authority_score'),
                'avg_coverage' => (float) CampaignCluster::query()->where('workspace_id', $workspace->id)->avg('topical_coverage_score'),
                'avg_completeness' => (float) CampaignCluster::query()->where('workspace_id', $workspace->id)->avg('completeness_score'),
            ],
        ]);
    }

    public function show(Request $request, CampaignCluster $cluster): View
    {
        $this->authorizeWorkspace($request, $cluster);

        return view('app.campaign-clusters.show', [
            'cluster' => $cluster->load(['workspace', 'site', 'items.opportunity', 'dependencies.sourceItem', 'dependencies.targetItem']),
        ]);
    }

    public function run(RunCampaignClusterPlanningRequest $request, CampaignClusterPlanningEngine $engine): RedirectResponse
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
            app(AgenticActionRunLogger::class)->recordStandalone($workspace, AgenticApprovalGate::ACTION_CREATE_CAMPAIGN_CLUSTER, AgenticActionRun::STATUS_COMPLETED, [
                'reason' => 'Customer ran campaign cluster planning inline.',
                'input_snapshot' => ['site_id' => $siteId, 'source_type' => 'ui'],
                'approved_by' => $request->user()->id,
                'approved_at' => now(),
            ]);

            return back()->with('status', 'Campaign clusters generated.');
        }

        $auditRun = app(AgenticActionRunLogger::class)->recordStandalone($workspace, AgenticApprovalGate::ACTION_CREATE_CAMPAIGN_CLUSTER, AgenticActionRun::STATUS_QUEUED, [
            'reason' => 'Customer queued campaign cluster planning.',
            'input_snapshot' => ['site_id' => $siteId, 'source_type' => 'ui'],
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        GenerateCampaignClustersJob::dispatch(
            workspaceId: (string) $workspace->id,
            clientSiteId: $siteId,
            options: ['source_type' => 'ui', 'agentic_action_run_id' => (string) $auditRun->id],
        )->onQueue('intelligence')->afterCommit();

        return back()->with('status', 'Campaign cluster planning queued.');
    }

    public function materializeActions(Request $request, CampaignCluster $cluster, CampaignClusterActionMaterializer $materializer): RedirectResponse
    {
        $this->authorizeWorkspace($request, $cluster);

        $summary = $materializer->materialize($cluster);
        app(AgenticActionRunLogger::class)->recordStandalone($cluster->workspace, AgenticApprovalGate::ACTION_CREATE_CHAINED_PLAN, AgenticActionRun::STATUS_COMPLETED, [
            'reason' => 'Customer materialized a campaign cluster into Agentic Marketing actions.',
            'input_snapshot' => ['campaign_cluster_id' => (string) $cluster->id],
            'output_snapshot' => $summary,
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        return redirect()
            ->route('app.agentic-marketing.campaign-clusters.show', $cluster)
            ->with(
                'status',
                sprintf(
                    'Campaign cluster converted to actions: %d created, %d reused.',
                    (int) $summary['actions_created'],
                    (int) $summary['actions_reused']
                )
            )
            ->with('agentic_marketing_objective_id', $summary['objective_id']);
    }

    private function authorizeWorkspace(Request $request, CampaignCluster $cluster): void
    {
        abort_unless((int) $cluster->organization_id === (int) $request->user()->organization_id, 404);
    }
}
