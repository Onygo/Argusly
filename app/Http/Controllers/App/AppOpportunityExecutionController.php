<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\PrepareOpportunityExecutionRequest;
use App\Http\Requests\App\ReviewExecutionAssetRequest;
use App\Http\Requests\App\StoreExecutionFeedbackRequest;
use App\Jobs\AgenticMarketing\ExecutionPipeline\PrepareOpportunityExecutionPipelineJob;
use App\Models\AgenticMarketingExecutionAsset;
use App\Models\AgenticMarketingExecutionPipeline;
use App\Models\AgenticMarketingOpportunity;
use App\Services\AgenticMarketing\ExecutionPipeline\OpportunityExecutionPipelineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppOpportunityExecutionController extends Controller
{
    public function show(Request $request, AgenticMarketingOpportunity $opportunity): View
    {
        $this->authorize('view', $opportunity);

        $pipelines = $opportunity->executionPipelines()
            ->with(['assets', 'approvals', 'feedback', 'auditLogs'])
            ->latest()
            ->get();

        return view('app.agentic-marketing.execution.show', [
            'opportunity' => $opportunity->loadMissing('objective'),
            'pipelines' => $pipelines,
            'pipeline' => $pipelines->first(),
        ]);
    }

    public function prepare(
        PrepareOpportunityExecutionRequest $request,
        AgenticMarketingOpportunity $opportunity,
        OpportunityExecutionPipelineService $service
    ): RedirectResponse {
        $this->authorize('update', $opportunity);

        $mode = (string) ($request->validated('mode') ?: 'manual');

        if ($request->boolean('run_inline')) {
            $pipeline = $service->prepare($opportunity, $mode, $request->user(), ['requested_from' => 'ui']);

            return redirect()
                ->route('app.agentic-marketing.opportunities.execution.show', $opportunity)
                ->with('status', 'Execution pipeline prepared.')
                ->with('pipeline_id', (string) $pipeline->id);
        }

        PrepareOpportunityExecutionPipelineJob::dispatch(
            opportunityId: (string) $opportunity->id,
            mode: $mode,
            actorId: $request->user()?->id,
            input: ['requested_from' => 'ui'],
        )->onQueue('agentic-marketing')->afterCommit();

        return redirect()
            ->route('app.agentic-marketing.opportunities.execution.show', $opportunity)
            ->with('status', 'Execution pipeline queued.');
    }

    public function approveAsset(
        ReviewExecutionAssetRequest $request,
        AgenticMarketingExecutionAsset $asset,
        OpportunityExecutionPipelineService $service
    ): RedirectResponse {
        $asset->loadMissing('opportunity');
        $this->authorize('update', $asset->opportunity);

        $service->approveAsset($asset, $request->user(), $request->validated('feedback'));

        return back()->with('status', 'Execution asset approved.');
    }

    public function rejectAsset(
        ReviewExecutionAssetRequest $request,
        AgenticMarketingExecutionAsset $asset,
        OpportunityExecutionPipelineService $service
    ): RedirectResponse {
        $asset->loadMissing('opportunity');
        $this->authorize('update', $asset->opportunity);

        $service->rejectAsset($asset, $request->user(), (string) ($request->validated('feedback') ?: 'Changes requested.'));

        return back()->with('status', 'Changes requested for execution asset.');
    }

    public function feedback(
        StoreExecutionFeedbackRequest $request,
        AgenticMarketingExecutionPipeline $pipeline,
        OpportunityExecutionPipelineService $service
    ): RedirectResponse {
        $pipeline->loadMissing('opportunity');
        $this->authorize('view', $pipeline->opportunity);

        $asset = null;
        if ($request->validated('asset_id')) {
            $asset = AgenticMarketingExecutionAsset::query()
                ->where('pipeline_id', $pipeline->id)
                ->findOrFail($request->validated('asset_id'));
        }

        $service->feedback($pipeline, $asset, $request->user(), $request->validated('body'));

        return back()->with('status', 'Reviewer feedback recorded.');
    }

    public function retry(
        Request $request,
        AgenticMarketingExecutionPipeline $pipeline,
        OpportunityExecutionPipelineService $service
    ): RedirectResponse {
        $pipeline->loadMissing('opportunity');
        $this->authorize('update', $pipeline->opportunity);

        $service->retry($pipeline, $request->user());

        return back()->with('status', 'Execution pipeline retry prepared.');
    }
}
