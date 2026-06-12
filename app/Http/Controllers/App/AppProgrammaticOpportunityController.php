<?php

namespace App\Http\Controllers\App;

use App\Enums\GrowthProgramStatus;
use App\Enums\ProgrammaticPatternType;
use App\Http\Controllers\Controller;
use App\Models\GrowthProgram;
use App\Models\Opportunity;
use App\Models\ProgrammaticOpportunity;
use App\Models\Workspace;
use App\Services\Growth\GrowthProgramOrchestrator;
use App\Services\Growth\ProgrammaticOpportunityDetector;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppProgrammaticOpportunityController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', ProgrammaticOpportunity::class);
        $workspace = $this->resolveWorkspace($request);

        $opportunities = ProgrammaticOpportunity::query()
            ->where('workspace_id', $workspace->id)
            ->with(['growthProgram', 'source'])
            ->when($request->query('pattern_type'), fn ($query, $value) => $query->where('pattern_type', $value))
            ->when($request->query('status'), fn ($query, $value) => $query->where('status', $value))
            ->when($request->query('linked') === 'linked', fn ($query) => $query->whereNotNull('growth_program_id'))
            ->when($request->query('linked') === 'unlinked', fn ($query) => $query->whereNull('growth_program_id'))
            ->when($request->query('min_score'), fn ($query, $value) => $query->where('scale_score', '>=', (float) $value))
            ->orderByDesc('scale_score')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('app.programmatic-opportunities.index', [
            'workspace' => $workspace,
            'workspaces' => $this->workspaces($request),
            'opportunities' => $opportunities,
            'patternTypes' => ProgrammaticPatternType::cases(),
            'statuses' => [
                ProgrammaticOpportunity::STATUS_DETECTED,
                ProgrammaticOpportunity::STATUS_VALIDATED,
                ProgrammaticOpportunity::STATUS_REJECTED,
                ProgrammaticOpportunity::STATUS_PLANNED,
                ProgrammaticOpportunity::STATUS_EXPANDED,
            ],
            'filters' => $request->only(['pattern_type', 'status', 'linked', 'min_score']),
        ]);
    }

    public function show(Request $request, ProgrammaticOpportunity $programmaticOpportunity): View
    {
        $this->authorize('view', $programmaticOpportunity);
        $workspace = $this->resolveWorkspace($request, $programmaticOpportunity->workspace_id);
        if ((string) $workspace->id !== (string) $programmaticOpportunity->workspace_id) {
            throw new AuthorizationException('Programmatic opportunity is not available for this workspace.');
        }

        $programmaticOpportunity->load(['source', 'growthProgram']);
        $cluster = \App\Models\ProgrammaticCluster::query()
            ->where('programmatic_opportunity_id', $programmaticOpportunity->id)
            ->withCount('items')
            ->first();

        return view('app.programmatic-opportunities.show', [
            'workspace' => $workspace,
            'opportunity' => $programmaticOpportunity,
            'growthPrograms' => GrowthProgram::query()
                ->where('workspace_id', $workspace->id)
                ->orderByDesc('updated_at')
                ->get(),
            'cluster' => $cluster,
        ]);
    }

    public function detectFromOpportunity(Opportunity $opportunity, ProgrammaticOpportunityDetector $detector): RedirectResponse
    {
        $detected = $detector->detect($opportunity);

        if (! $detected) {
            return back()->with('status', 'No programmatic pattern detected for this opportunity yet.');
        }

        return redirect()
            ->route('app.programmatic-opportunities.show', $detected)
            ->with('status', 'Programmatic potential detected.');
    }

    public function validateOpportunity(ProgrammaticOpportunity $programmaticOpportunity): RedirectResponse
    {
        $this->authorize('approve', $programmaticOpportunity);
        $programmaticOpportunity->validate();

        return back()->with('status', 'Programmatic opportunity validated.');
    }

    public function reject(ProgrammaticOpportunity $programmaticOpportunity): RedirectResponse
    {
        $this->authorize('approve', $programmaticOpportunity);
        $programmaticOpportunity->reject();

        return back()->with('status', 'Programmatic opportunity rejected.');
    }

    public function attach(Request $request, ProgrammaticOpportunity $programmaticOpportunity, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('approve', $programmaticOpportunity);
        $request->validate(['growth_program_id' => ['required', 'uuid', 'exists:growth_programs,id']]);

        $program = GrowthProgram::query()->findOrFail((string) $request->input('growth_program_id'));
        if ((string) $program->workspace_id !== (string) $programmaticOpportunity->workspace_id) {
            throw new AuthorizationException('Growth program is not available for this workspace.');
        }

        $orchestrator->attachProgrammaticOpportunity($program, $programmaticOpportunity);

        return back()->with('status', 'Programmatic opportunity attached to growth program.');
    }

    public function createGrowthProgram(Request $request, ProgrammaticOpportunity $programmaticOpportunity, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('update', $programmaticOpportunity);
        $workspace = $this->resolveWorkspace($request, $programmaticOpportunity->workspace_id);

        $program = $orchestrator->create($workspace, [
            'name' => $programmaticOpportunity->pattern_type->label().': '.$programmaticOpportunity->base_topic,
            'description' => $programmaticOpportunity->pattern_type->description(),
            'status' => GrowthProgramStatus::QUALIFIED->value,
            'score' => (float) ($programmaticOpportunity->scale_score ?? 0),
            'estimated_ai_visibility_impact' => (float) ($programmaticOpportunity->ai_visibility_score ?? 0),
            'source' => 'programmatic_opportunity',
            'metadata' => ['programmatic_opportunity_id' => (string) $programmaticOpportunity->id],
        ], $request->user());

        $orchestrator->attachProgrammaticOpportunity($program, $programmaticOpportunity);

        return redirect()
            ->route('app.growth-programs.show', $program)
            ->with('status', 'Growth program created from programmatic opportunity.');
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
