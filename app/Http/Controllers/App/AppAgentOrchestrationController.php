<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\RunAgentOrchestrationRequest;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOrchestrationRun;
use App\Models\ClientSite;
use App\Models\Workspace;
use App\Services\AgenticMarketing\Orchestration\AgentOrchestrationService;
use App\Services\AgenticMarketing\Orchestration\AgentRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppAgentOrchestrationController extends Controller
{
    public function index(Request $request, AgentRegistry $registry): View
    {
        $organizationId = (int) $request->user()->organization_id;
        $workspaces = Workspace::query()
            ->where('organization_id', $organizationId)
            ->with('clientSites:id,workspace_id,name')
            ->orderBy('created_at')
            ->get(['id', 'organization_id', 'name', 'display_name']);

        abort_if($workspaces->isEmpty(), 404);

        $workspace = $request->query('workspace_id')
            ? $workspaces->firstWhere('id', (string) $request->query('workspace_id'))
            : $workspaces->first();

        abort_unless($workspace, 404);

        $runs = AgenticMarketingOrchestrationRun::query()
            ->where('workspace_id', $workspace->id)
            ->withCount(['tasks', 'conflicts'])
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('app.agentic-marketing.orchestration.index', [
            'workspace' => $workspace,
            'workspaces' => $workspaces,
            'runs' => $runs,
            'agents' => $registry->definitions(),
        ]);
    }

    public function show(Request $request, AgenticMarketingOrchestrationRun $run, AgentRegistry $registry): View
    {
        abort_unless((int) $run->organization_id === (int) $request->user()->organization_id, 404);

        return view('app.agentic-marketing.orchestration.show', [
            'run' => $run->load(['workspace', 'site', 'objective', 'tasks.traces', 'traces', 'conflicts']),
            'agents' => collect($registry->definitions())->keyBy('key'),
        ]);
    }

    public function run(RunAgentOrchestrationRequest $request, AgentOrchestrationService $orchestration): RedirectResponse
    {
        $data = $request->validated();
        $workspace = Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->findOrFail($data['workspace_id']);

        $siteId = $data['client_site_id'] ?? null;
        if ($siteId) {
            ClientSite::query()->where('workspace_id', $workspace->id)->findOrFail($siteId);
        }

        $objective = null;
        if (! empty($data['objective_id'])) {
            $objective = AgenticMarketingObjective::query()
                ->where('organization_id', $request->user()->organization_id)
                ->where('workspace_id', $workspace->id)
                ->findOrFail($data['objective_id']);
        }

        $run = $orchestration->start(
            workspace: $workspace,
            clientSiteId: $siteId,
            objective: $objective,
            actor: $request->user(),
            input: [
                'focus_topic' => $data['focus_topic'] ?? null,
                'mode' => $data['mode'] ?? 'manual',
                'provider_key' => $data['provider_key'] ?? 'deterministic',
                'trigger_source' => 'ui',
            ],
            runInline: $request->boolean('run_inline'),
        );

        return redirect()
            ->route('app.agentic-marketing.orchestration.show', $run)
            ->with('status', $request->boolean('run_inline') ? 'Agent orchestration completed.' : 'Agent orchestration queued.');
    }
}
