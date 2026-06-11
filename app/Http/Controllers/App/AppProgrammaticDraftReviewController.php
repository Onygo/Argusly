<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\GrowthProgram;
use App\Models\ProgrammaticDraftRequest;
use App\Models\ProgrammaticDraftReview;
use App\Models\Workspace;
use App\Services\Growth\GrowthProgramOrchestrator;
use App\Services\Growth\ProgrammaticDraftReviewService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppProgrammaticDraftReviewController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', ProgrammaticDraftReview::class);
        $workspace = $this->resolveWorkspace($request);

        $reviews = ProgrammaticDraftReview::query()
            ->where('workspace_id', $workspace->id)
            ->with(['draft', 'request', 'brief', 'growthProgram'])
            ->when($request->query('status'), fn ($query, $value) => $query->where('status', $value))
            ->orderByDesc('overall_score')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('app.programmatic-draft-reviews.index', [
            'workspace' => $workspace,
            'reviews' => $reviews,
            'statuses' => ProgrammaticDraftReview::statuses(),
            'filters' => $request->only(['status']),
        ]);
    }

    public function show(Request $request, ProgrammaticDraftReview $review): View
    {
        $this->authorize('view', $review);
        $workspace = $this->resolveWorkspace($request, $review->workspace_id);
        $this->assertWorkspaceId($review->workspace_id, $workspace);
        $review->load(['draft', 'request', 'brief', 'growthProgram', 'cluster', 'item', 'publicationReadiness']);

        return view('app.programmatic-draft-reviews.show', [
            'workspace' => $workspace,
            'review' => $review,
        ]);
    }

    public function runForRequest(ProgrammaticDraftRequest $draftRequest, GrowthProgramOrchestrator $orchestrator, ProgrammaticDraftReviewService $service): RedirectResponse
    {
        $this->authorize('update', $draftRequest);

        try {
            if ($draftRequest->growthProgram instanceof GrowthProgram) {
                $review = $orchestrator->reviewDraftRequest($draftRequest->growthProgram, $draftRequest);
            } else {
                $review = $service->reviewRequest($draftRequest);
            }
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['review' => $exception->getMessage()]);
        }

        return redirect()->route('app.programmatic-draft-reviews.show', $review)->with('status', 'Draft review completed.');
    }

    public function approve(ProgrammaticDraftReview $review, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $review);
        try {
            $review->approve(request()->user());
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['review' => $exception->getMessage()]);
        }
        $this->refreshProgramMetrics($review, $orchestrator);

        return back()->with('status', 'Draft review approved.');
    }

    public function needsWork(ProgrammaticDraftReview $review, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $review);
        $review->needsWork(request()->user());
        $this->refreshProgramMetrics($review, $orchestrator);

        return back()->with('status', 'Draft review marked as needs work.');
    }

    public function block(ProgrammaticDraftReview $review, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $review);
        $review->block(request()->user());
        $this->refreshProgramMetrics($review, $orchestrator);

        return back()->with('status', 'Draft review blocked.');
    }

    public function reject(ProgrammaticDraftReview $review, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $review);
        $review->reject(request()->user());
        $this->refreshProgramMetrics($review, $orchestrator);

        return back()->with('status', 'Draft review rejected.');
    }

    public function convertToContent(ProgrammaticDraftReview $review, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $review);
        $review->loadMissing('growthProgram');

        if (! $review->growthProgram instanceof GrowthProgram) {
            return back()->withErrors(['growth_program' => 'Attach this review to a growth program before converting it to content.']);
        }

        try {
            $content = $orchestrator->convertReviewToContent($review->growthProgram, $review, createdByUserId: request()->user()?->id);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['content' => $exception->getMessage()]);
        }

        return back()->with('status', 'Draft review converted to content: '.$content->title);
    }

    private function resolveWorkspace(Request $request, mixed $preferredWorkspaceId = null): Workspace
    {
        return Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when($preferredWorkspaceId ?: $request->query('workspace_id') ?: $request->query('workspace'), fn ($query, $id) => $query->where('id', $id))
            ->orderBy('created_at')
            ->firstOrFail();
    }

    private function assertWorkspaceId(mixed $workspaceId, Workspace $workspace): void
    {
        if ((string) $workspaceId !== (string) $workspace->id) {
            throw new AuthorizationException('This record is not available for this workspace.');
        }
    }

    private function refreshProgramMetrics(ProgrammaticDraftReview $review, GrowthProgramOrchestrator $orchestrator): void
    {
        $review->loadMissing('growthProgram');
        if ($review->growthProgram instanceof GrowthProgram) {
            $orchestrator->refreshMetrics($review->growthProgram);
        }
    }
}
