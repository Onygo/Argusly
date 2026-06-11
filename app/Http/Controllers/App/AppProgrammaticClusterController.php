<?php

namespace App\Http\Controllers\App;

use App\Enums\GrowthAssetType;
use App\Enums\ProgrammaticPatternType;
use App\Http\Controllers\Controller;
use App\Models\GrowthProgram;
use App\Models\ProgrammaticCluster;
use App\Models\ProgrammaticOpportunity;
use App\Models\Workspace;
use App\Services\Growth\GrowthProgramOrchestrator;
use App\Services\Growth\ProgrammaticClusterBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppProgrammaticClusterController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', ProgrammaticCluster::class);
        $workspace = $this->resolveWorkspace($request);

        $clusters = ProgrammaticCluster::query()
            ->where('workspace_id', $workspace->id)
            ->with(['programmaticOpportunity', 'growthProgram'])
            ->withCount('items')
            ->when($request->query('pattern_type'), fn ($query, $value) => $query->where('pattern_type', $value))
            ->when($request->query('status'), fn ($query, $value) => $query->where('status', $value))
            ->orderByDesc('estimated_business_impact')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('app.programmatic-clusters.index', [
            'workspace' => $workspace,
            'workspaces' => $this->workspaces($request),
            'clusters' => $clusters,
            'patternTypes' => ProgrammaticPatternType::cases(),
            'statuses' => [
                ProgrammaticCluster::STATUS_PREVIEW,
                ProgrammaticCluster::STATUS_VALIDATED,
                ProgrammaticCluster::STATUS_REJECTED,
                ProgrammaticCluster::STATUS_PLANNED,
            ],
            'filters' => $request->only(['pattern_type', 'status']),
        ]);
    }

    public function show(Request $request, ProgrammaticCluster $cluster): View
    {
        $this->authorize('view', $cluster);
        $workspace = $this->resolveWorkspace($request, $cluster->workspace_id);
        if ((string) $workspace->id !== (string) $cluster->workspace_id) {
            throw new AuthorizationException('Programmatic cluster is not available for this workspace.');
        }

        $itemFilters = $request->only(['asset_type', 'priority', 'status', 'duplicate_risk']);
        $cluster->load([
            'programmaticOpportunity',
            'growthProgram',
            'items' => fn ($query) => $query
                ->with('briefBlueprint.draftRequest.review.publicationReadiness')
                ->when($request->query('asset_type'), fn ($query, $value) => $query->where('growth_asset_type', $value))
                ->when($request->query('status'), fn ($query, $value) => $query->where('status', $value))
                ->when($request->query('priority'), function ($query, $value): void {
                    match ($value) {
                        'high' => $query->where('priority_score', '>=', 70),
                        'medium' => $query->whereBetween('priority_score', [40, 69.99]),
                        'low' => $query->where('priority_score', '<', 40),
                        default => null,
                    };
                })
                ->when($request->query('duplicate_risk'), function ($query, $value): void {
                    match ($value) {
                        'high' => $query->where('duplicate_risk_score', '>=', 70),
                        'medium' => $query->whereBetween('duplicate_risk_score', [30, 69.99]),
                        'low' => $query->where('duplicate_risk_score', '<', 30),
                        default => null,
                    };
                })
                ->orderByDesc('priority_score'),
        ]);

        return view('app.programmatic-clusters.show', [
            'workspace' => $workspace,
            'cluster' => $cluster,
            'growthPrograms' => GrowthProgram::query()->where('workspace_id', $workspace->id)->orderByDesc('updated_at')->get(),
            'assetTypes' => GrowthAssetType::cases(),
            'itemFilters' => $itemFilters,
        ]);
    }

    public function build(ProgrammaticOpportunity $programmaticOpportunity, ProgrammaticClusterBuilder $builder): RedirectResponse
    {
        $this->authorize('update', $programmaticOpportunity);
        $cluster = $builder->build($programmaticOpportunity);

        return redirect()
            ->route('app.programmatic-clusters.show', $cluster)
            ->with('status', 'Programmatic cluster preview built.');
    }

    public function validateCluster(ProgrammaticCluster $cluster): RedirectResponse
    {
        $this->authorize('update', $cluster);
        $cluster->validate();

        return back()->with('status', 'Programmatic cluster validated.');
    }

    public function reject(ProgrammaticCluster $cluster): RedirectResponse
    {
        $this->authorize('update', $cluster);
        $cluster->reject();

        return back()->with('status', 'Programmatic cluster rejected.');
    }

    public function attach(Request $request, ProgrammaticCluster $cluster, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $cluster);
        $request->validate(['growth_program_id' => ['required', 'uuid', 'exists:growth_programs,id']]);

        $program = GrowthProgram::query()->findOrFail((string) $request->input('growth_program_id'));
        if ((string) $program->workspace_id !== (string) $cluster->workspace_id) {
            throw new AuthorizationException('Growth program is not available for this workspace.');
        }

        $orchestrator->attachProgrammaticCluster($program, $cluster);

        return back()->with('status', 'Programmatic cluster attached to growth program.');
    }

    public function convertApprovedBlueprints(ProgrammaticCluster $cluster, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $cluster);
        $cluster->loadMissing('growthProgram');

        if (! $cluster->growthProgram) {
            return back()->withErrors(['growth_program' => 'Attach this cluster to a growth program before converting blueprints.']);
        }

        $count = $orchestrator->convertApprovedBlueprintsForCluster($cluster->growthProgram, $cluster);

        return back()->with('status', $count.' approved blueprints converted to briefs.');
    }

    public function prepareDraftRequests(ProgrammaticCluster $cluster, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $cluster);
        $cluster->loadMissing('growthProgram');

        if (! $cluster->growthProgram) {
            return back()->withErrors(['growth_program' => 'Attach this cluster to a growth program before preparing draft requests.']);
        }

        $count = $orchestrator->prepareDraftRequestsForCluster($cluster->growthProgram, $cluster);

        return back()->with('status', $count.' draft requests prepared.');
    }

    public function generateApprovedRequests(ProgrammaticCluster $cluster, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $cluster);
        $cluster->loadMissing('growthProgram');

        if (! $cluster->growthProgram) {
            return back()->withErrors(['growth_program' => 'Attach this cluster to a growth program before generating drafts.']);
        }

        try {
            $count = $orchestrator->generateApprovedDraftsForCluster($cluster->growthProgram, $cluster);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['draft_request' => $exception->getMessage()]);
        }

        return back()->with('status', $count.' approved requests generated.');
    }

    public function reviewGeneratedDrafts(ProgrammaticCluster $cluster, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $cluster);
        $cluster->loadMissing('growthProgram');

        if (! $cluster->growthProgram) {
            return back()->withErrors(['growth_program' => 'Attach this cluster to a growth program before reviewing drafts.']);
        }

        $count = $orchestrator->reviewGeneratedDraftsForCluster($cluster->growthProgram, $cluster);

        return back()->with('status', $count.' generated drafts reviewed.');
    }

    public function convertApprovedReviewsToContent(ProgrammaticCluster $cluster, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $cluster);
        $cluster->loadMissing('growthProgram');

        if (! $cluster->growthProgram) {
            return back()->withErrors(['growth_program' => 'Attach this cluster to a growth program before converting reviews to content.']);
        }

        $count = $orchestrator->convertApprovedReviewsForCluster($cluster->growthProgram, $cluster, createdByUserId: request()->user()?->id);

        return back()->with('status', $count.' approved reviews converted to content.');
    }

    public function runPublicationReadiness(ProgrammaticCluster $cluster, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $cluster);
        $cluster->loadMissing('growthProgram');

        if (! $cluster->growthProgram) {
            return back()->withErrors(['growth_program' => 'Attach this cluster to a growth program before running publication readiness.']);
        }

        $count = $orchestrator->runPublicationReadinessForCluster($cluster->growthProgram, $cluster);

        return back()->with('status', $count.' publication readiness checks completed.');
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
}
