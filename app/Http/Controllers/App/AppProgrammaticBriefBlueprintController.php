<?php

namespace App\Http\Controllers\App;

use App\Enums\GrowthAssetType;
use App\Http\Controllers\Controller;
use App\Models\GrowthProgram;
use App\Models\ProgrammaticBriefBlueprint;
use App\Models\ProgrammaticCluster;
use App\Models\ProgrammaticClusterItem;
use App\Models\Workspace;
use App\Services\Growth\GrowthProgramOrchestrator;
use App\Services\Growth\ProgrammaticBriefConverter;
use App\Services\Growth\ProgrammaticBriefBlueprintBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppProgrammaticBriefBlueprintController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', ProgrammaticBriefBlueprint::class);
        $workspace = $this->resolveWorkspace($request);

        $blueprints = ProgrammaticBriefBlueprint::query()
            ->where('workspace_id', $workspace->id)
            ->with(['cluster', 'item', 'growthProgram'])
            ->when($request->query('status'), fn ($query, $value) => $query->where('status', $value))
            ->when($request->query('asset_type'), fn ($query, $value) => $query->where('growth_asset_type', $value))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('app.programmatic-brief-blueprints.index', [
            'workspace' => $workspace,
            'workspaces' => $this->workspaces($request),
            'blueprints' => $blueprints,
            'statuses' => ProgrammaticBriefBlueprint::statuses(),
            'assetTypes' => GrowthAssetType::cases(),
            'filters' => $request->only(['status', 'asset_type']),
        ]);
    }

    public function show(Request $request, ProgrammaticBriefBlueprint $blueprint): View
    {
        $this->authorize('view', $blueprint);
        $workspace = $this->resolveWorkspace($request, $blueprint->workspace_id);
        $this->assertWorkspaceId($blueprint->workspace_id, $workspace);
        $blueprint->load(['cluster', 'item', 'growthProgram', 'draftRequest']);

        return view('app.programmatic-brief-blueprints.show', [
            'workspace' => $workspace,
            'blueprint' => $blueprint,
        ]);
    }

    public function buildForItem(
        ProgrammaticClusterItem $item,
        ProgrammaticBriefBlueprintBuilder $builder,
        GrowthProgramOrchestrator $orchestrator,
    ): RedirectResponse {
        $item->loadMissing('cluster.growthProgram');
        $this->authorize('prepare', $item->cluster);

        if ($item->cluster?->growthProgram) {
            $blueprint = $orchestrator->buildBriefBlueprintForClusterItem($item->cluster->growthProgram, $item);
        } else {
            $blueprint = $builder->build($item);
        }

        return redirect()
            ->route('app.programmatic-brief-blueprints.show', $blueprint)
            ->with('status', 'Brief blueprint built.');
    }

    public function buildForCluster(ProgrammaticCluster $cluster, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('prepare', $cluster);
        $cluster->loadMissing('growthProgram');

        if ($cluster->growthProgram) {
            $count = $orchestrator->buildBriefBlueprintsForCluster($cluster->growthProgram, $cluster);
        } else {
            $builder = app(ProgrammaticBriefBlueprintBuilder::class);
            $count = 0;
            $cluster->items()
                ->whereIn('status', [ProgrammaticClusterItem::STATUS_PREVIEW, ProgrammaticClusterItem::STATUS_ACCEPTED])
                ->get()
                ->each(function (ProgrammaticClusterItem $item) use ($builder, &$count): void {
                    $builder->build($item);
                    $count++;
                });
        }

        return back()->with('status', $count.' brief blueprints built.');
    }

    public function review(ProgrammaticBriefBlueprint $blueprint, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $blueprint);
        $blueprint->markReviewed();
        $this->refreshProgramMetrics($blueprint, $orchestrator);

        return back()->with('status', 'Brief blueprint reviewed.');
    }

    public function approve(ProgrammaticBriefBlueprint $blueprint, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('approve', $blueprint);
        $blueprint->approve();
        $this->refreshProgramMetrics($blueprint, $orchestrator);

        return back()->with('status', 'Brief blueprint approved.');
    }

    public function reject(ProgrammaticBriefBlueprint $blueprint, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('approve', $blueprint);
        $blueprint->reject();
        $this->refreshProgramMetrics($blueprint, $orchestrator);

        return back()->with('status', 'Brief blueprint rejected.');
    }

    public function convert(
        ProgrammaticBriefBlueprint $blueprint,
        ProgrammaticBriefConverter $converter,
        GrowthProgramOrchestrator $orchestrator,
    ): RedirectResponse {
        $this->authorize('convert', $blueprint);

        try {
            $brief = $converter->convertBlueprint($blueprint);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['blueprint' => $exception->getMessage()]);
        }

        $blueprint->refresh()->loadMissing('growthProgram');
        if ($blueprint->growthProgram instanceof GrowthProgram) {
            $orchestrator->attachConvertedBrief($blueprint->growthProgram, $blueprint, $brief);
        }

        return redirect()
            ->route('app.content.workspace.show', $brief)
            ->with('status', 'Brief created from approved blueprint.');
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

    private function refreshProgramMetrics(ProgrammaticBriefBlueprint $blueprint, GrowthProgramOrchestrator $orchestrator): void
    {
        $blueprint->loadMissing('growthProgram');
        if ($blueprint->growthProgram instanceof GrowthProgram) {
            $orchestrator->refreshMetrics($blueprint->growthProgram);
        }
    }
}
