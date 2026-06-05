<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Opportunity;
use App\Models\Workspace;
use App\Services\CampaignPlanning\CampaignAssetGenerationService;
use App\Services\CampaignPlanning\CampaignPlannerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppCampaignPlannerController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = $this->resolveWorkspace($request);

        $campaigns = Campaign::query()
            ->where('workspace_id', $workspace->id)
            ->withCount(['contents', 'distributionPlans'])
            ->latest('last_planned_at')
            ->limit(12)
            ->get();

        $selectedCampaign = $request->query('campaign')
            ? Campaign::query()
                ->where('workspace_id', $workspace->id)
                ->with(['contents.distributionPlans.distributionChannel', 'opportunities'])
                ->find($request->query('campaign'))
            : $campaigns->first()?->load(['contents.distributionPlans.distributionChannel', 'opportunities']);

        $opportunities = Opportunity::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', ['open', 'reviewing', 'planned'])
            ->orderByDesc('priority_score')
            ->limit(8)
            ->get();

        return view('app.campaign-planner.index', [
            'workspace' => $workspace,
            'campaigns' => $campaigns,
            'selectedCampaign' => $selectedCampaign,
            'opportunities' => $opportunities,
        ]);
    }

    public function store(Request $request, CampaignPlannerService $planner): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);

        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:180'],
            'goals' => ['nullable', 'string', 'max:2000'],
            'audience' => ['nullable', 'string', 'max:500'],
            'start_date' => ['nullable', 'date'],
        ]);

        $campaign = $planner->plan($workspace, $validated['topic'], [
            'goals' => $validated['goals'] ?? '',
            'audience' => $validated['audience'] ?? '',
            'start_date' => $validated['start_date'] ?? null,
            'owner_user_id' => $request->user()->id,
        ]);

        return redirect()
            ->route('app.agentic-marketing.campaign-planner.index', ['campaign' => $campaign->id])
            ->with('status', 'Campaign plan generated as a draft with approval checkpoints.');
    }

    public function generate(Request $request, Campaign $campaign, CampaignAssetGenerationService $generator): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);
        abort_unless((string) $campaign->workspace_id === (string) $workspace->id, 404);

        $summary = $generator->generate($campaign, $request->user());

        return redirect()
            ->route('app.agentic-marketing.campaign-planner.index', ['campaign' => $campaign->id])
            ->with('status', sprintf(
                'Suggested content queued: %d draft generation job(s), %d social draft(s), %d structured answer block(s), %d skipped.',
                $summary['generated_content'],
                $summary['generated_social'],
                $summary['generated_answer_blocks'],
                $summary['skipped'],
            ));
    }

    private function resolveWorkspace(Request $request): Workspace
    {
        return Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when($request->query('workspace_id'), fn ($query, $id) => $query->where('id', $id))
            ->orderBy('created_at')
            ->firstOrFail();
    }
}
