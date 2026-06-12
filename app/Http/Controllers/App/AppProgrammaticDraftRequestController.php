<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\GrowthProgram;
use App\Models\ProgrammaticBriefBlueprint;
use App\Models\ProgrammaticDraftRequest;
use App\Models\Workspace;
use App\Services\Growth\GrowthProgramOrchestrator;
use App\Services\Growth\ProgrammaticDraftRequestBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppProgrammaticDraftRequestController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', ProgrammaticDraftRequest::class);
        $workspace = $this->resolveWorkspace($request);

        $draftRequests = ProgrammaticDraftRequest::query()
            ->where('workspace_id', $workspace->id)
            ->with(['brief', 'blueprint', 'cluster', 'item', 'growthProgram'])
            ->when($request->query('status'), fn ($query, $value) => $query->where('status', $value))
            ->orderByDesc('priority_score')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('app.programmatic-draft-requests.index', [
            'workspace' => $workspace,
            'workspaces' => $this->workspaces($request),
            'draftRequests' => $draftRequests,
            'statuses' => ProgrammaticDraftRequest::statuses(),
            'filters' => $request->only(['status']),
        ]);
    }

    public function show(Request $request, ProgrammaticDraftRequest $draftRequest): View
    {
        $this->authorize('view', $draftRequest);
        $workspace = $this->resolveWorkspace($request, $draftRequest->workspace_id);
        $this->assertWorkspaceId($draftRequest->workspace_id, $workspace);
        $draftRequest->load(['brief', 'blueprint', 'cluster', 'item', 'growthProgram', 'review']);

        return view('app.programmatic-draft-requests.show', [
            'workspace' => $workspace,
            'draftRequest' => $draftRequest,
        ]);
    }

    public function prepareForBlueprint(
        ProgrammaticBriefBlueprint $blueprint,
        ProgrammaticDraftRequestBuilder $builder,
        GrowthProgramOrchestrator $orchestrator,
    ): RedirectResponse {
        $this->authorize('prepare', $blueprint);

        try {
            if ($blueprint->growthProgram instanceof GrowthProgram) {
                $draftRequest = $orchestrator->prepareDraftRequestForBlueprint($blueprint->growthProgram, $blueprint);
            } else {
                $draftRequest = $builder->buildForBlueprint($blueprint);
            }
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['draft_request' => $exception->getMessage()]);
        }

        return redirect()
            ->route('app.programmatic-draft-requests.show', $draftRequest)
            ->with('status', 'Draft request prepared.');
    }

    public function approve(ProgrammaticDraftRequest $draftRequest, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('approve', $draftRequest);
        $draftRequest->approve();
        $this->refreshProgramMetrics($draftRequest, $orchestrator);

        return back()->with('status', 'Draft request approved.');
    }

    public function reject(ProgrammaticDraftRequest $draftRequest, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('approve', $draftRequest);
        $draftRequest->reject();
        $this->refreshProgramMetrics($draftRequest, $orchestrator);

        return back()->with('status', 'Draft request rejected.');
    }

    public function cancel(ProgrammaticDraftRequest $draftRequest, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('cancel', $draftRequest);
        $draftRequest->cancel();
        $this->refreshProgramMetrics($draftRequest, $orchestrator);

        return back()->with('status', 'Draft request cancelled.');
    }

    public function generate(ProgrammaticDraftRequest $draftRequest, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('prepare', $draftRequest);
        $draftRequest->loadMissing('growthProgram');

        if (! $draftRequest->growthProgram) {
            return back()->withErrors(['draft_request' => 'Attach this request to a growth program before generating a draft.']);
        }

        try {
            $draft = $orchestrator->generateDraftForRequest($draftRequest->growthProgram, $draftRequest);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['draft_request' => $exception->getMessage()]);
        }

        return redirect()
            ->route('app.drafts.show', $draft)
            ->with('status', 'Programmatic draft generated.');
    }

    private function resolveWorkspace(Request $request, mixed $preferredWorkspaceId = null): Workspace
    {
        return Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when($preferredWorkspaceId ?: $request->query('workspace_id') ?: $request->query('workspace'), fn ($query, $id) => $query->where('id', $id))
            ->orderBy('created_at')
            ->firstOrFail();
    }

    private function workspaces(Request $request)
    {
        return Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->orderBy('created_at')
            ->get();
    }

    private function assertWorkspaceId(mixed $workspaceId, Workspace $workspace): void
    {
        if ((string) $workspaceId !== (string) $workspace->id) {
            throw new AuthorizationException('This record is not available for this workspace.');
        }
    }

    private function refreshProgramMetrics(ProgrammaticDraftRequest $draftRequest, GrowthProgramOrchestrator $orchestrator): void
    {
        $draftRequest->loadMissing('growthProgram');
        if ($draftRequest->growthProgram instanceof GrowthProgram) {
            $orchestrator->refreshMetrics($draftRequest->growthProgram);
        }
    }
}
