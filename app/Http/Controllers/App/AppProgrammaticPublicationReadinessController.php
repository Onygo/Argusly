<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Content;
use App\Models\GrowthAsset;
use App\Models\GrowthProgram;
use App\Models\ProgrammaticPublicationReadiness;
use App\Models\Workspace;
use App\Services\Growth\GrowthProgramOrchestrator;
use App\Services\Growth\ProgrammaticPublicationReadinessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppProgrammaticPublicationReadinessController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', ProgrammaticPublicationReadiness::class);
        $workspace = $this->resolveWorkspace($request);

        $readinessRecords = ProgrammaticPublicationReadiness::query()
            ->where('workspace_id', $workspace->id)
            ->with(['content', 'review', 'growthProgram'])
            ->when($request->query('status'), fn ($query, $value) => $query->where('status', $value))
            ->orderByDesc('readiness_score')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('app.programmatic-publication-readiness.index', [
            'workspace' => $workspace,
            'readinessRecords' => $readinessRecords,
            'statuses' => ProgrammaticPublicationReadiness::statuses(),
            'filters' => $request->only(['status']),
        ]);
    }

    public function show(Request $request, ProgrammaticPublicationReadiness $readiness): View
    {
        $this->authorize('view', $readiness);
        $workspace = $this->resolveWorkspace($request, $readiness->workspace_id);
        $this->assertWorkspaceId($readiness->workspace_id, $workspace);
        $readiness->load(['content', 'review', 'request', 'cluster', 'item', 'growthProgram', 'approver', 'planItems.plan']);

        return view('app.programmatic-publication-readiness.show', [
            'workspace' => $workspace,
            'readiness' => $readiness,
        ]);
    }

    public function runForContent(Content $content, GrowthProgramOrchestrator $orchestrator, ProgrammaticPublicationReadinessService $service): RedirectResponse
    {
        $this->authorize('update', $content);

        $program = $this->programForContent($content);
        $readiness = $program instanceof GrowthProgram
            ? $orchestrator->runPublicationReadinessForContent($program, $content)
            : $service->checkContent($content);

        return redirect()
            ->route('app.programmatic-publication-readiness.show', $readiness)
            ->with('status', 'Publication readiness check completed.');
    }

    public function approve(ProgrammaticPublicationReadiness $readiness, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $readiness);

        try {
            $readiness->approve(request()->user());
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['publication_readiness' => $exception->getMessage()]);
        }
        $this->refreshProgramMetrics($readiness, $orchestrator);

        return back()->with('status', 'Publication readiness approved.');
    }

    public function needsWork(ProgrammaticPublicationReadiness $readiness, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $readiness);
        $readiness->needsWork();
        $this->refreshProgramMetrics($readiness, $orchestrator);

        return back()->with('status', 'Publication readiness marked as needs work.');
    }

    public function block(ProgrammaticPublicationReadiness $readiness, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $readiness);
        $readiness->block();
        $this->refreshProgramMetrics($readiness, $orchestrator);

        return back()->with('status', 'Publication readiness blocked.');
    }

    public function reject(ProgrammaticPublicationReadiness $readiness, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $readiness);
        $readiness->reject();
        $this->refreshProgramMetrics($readiness, $orchestrator);

        return back()->with('status', 'Publication readiness rejected.');
    }

    private function programForContent(Content $content): ?GrowthProgram
    {
        $asset = GrowthAsset::query()
            ->where('role', GrowthAsset::ROLE_CONTENT)
            ->where('assetable_type', $content->getMorphClass())
            ->where('assetable_id', $content->id)
            ->with('program')
            ->latest()
            ->first();

        if ($asset?->program instanceof GrowthProgram) {
            return $asset->program;
        }

        $programId = (string) data_get($content->aeo_breakdown, 'programmatic_metadata.growth_program_id', '');

        return $programId !== '' ? GrowthProgram::query()->find($programId) : null;
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

    private function refreshProgramMetrics(ProgrammaticPublicationReadiness $readiness, GrowthProgramOrchestrator $orchestrator): void
    {
        $readiness->loadMissing('growthProgram');
        if ($readiness->growthProgram instanceof GrowthProgram) {
            $orchestrator->refreshMetrics($readiness->growthProgram);
        }
    }
}
