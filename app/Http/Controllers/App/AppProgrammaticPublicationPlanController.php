<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\GrowthProgram;
use App\Models\ProgrammaticCluster;
use App\Models\ProgrammaticPublicationPlan;
use App\Models\ProgrammaticPublicationReadiness;
use App\Models\Workspace;
use App\Services\Growth\GrowthProgramOrchestrator;
use App\Services\Growth\ProgrammaticPublicationPlanBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AppProgrammaticPublicationPlanController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', ProgrammaticPublicationPlan::class);
        $workspace = $this->resolveWorkspace($request);

        $plans = ProgrammaticPublicationPlan::query()
            ->where('workspace_id', $workspace->id)
            ->with(['growthProgram', 'destination'])
            ->withCount('items')
            ->when($request->query('status'), fn ($query, $value) => $query->where('status', $value))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('app.programmatic-publication-plans.index', [
            'workspace' => $workspace,
            'plans' => $plans,
            'statuses' => ProgrammaticPublicationPlan::statuses(),
            'filters' => $request->only(['status']),
        ]);
    }

    public function show(Request $request, ProgrammaticPublicationPlan $plan): View
    {
        $this->authorize('view', $plan);
        $workspace = $this->resolveWorkspace($request, $plan->workspace_id);
        $this->assertWorkspaceId($plan->workspace_id, $workspace);
        $plan->load(['growthProgram', 'destination', 'items.content', 'items.readiness', 'items.contentPublication']);

        return view('app.programmatic-publication-plans.show', [
            'workspace' => $workspace,
            'plan' => $plan,
            'cadences' => ProgrammaticPublicationPlan::cadences(),
        ]);
    }

    public function createFromReadiness(Request $request, ProgrammaticPublicationReadiness $readiness, GrowthProgramOrchestrator $orchestrator, ProgrammaticPublicationPlanBuilder $builder): RedirectResponse
    {
        $this->authorize('approve', $readiness);
        $attributes = $this->validatedPlanInput($request);
        $readiness->loadMissing('growthProgram');

        try {
            $plan = $readiness->growthProgram instanceof GrowthProgram
                ? $orchestrator->createPublicationPlanFromReadiness($readiness->growthProgram, $readiness, $attributes)
                : $builder->createFromReadiness($readiness, $attributes);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['publication_plan' => $exception->getMessage()]);
        }

        return redirect()->route('app.programmatic-publication-plans.show', $plan)->with('status', 'Publication plan created.');
    }

    public function createFromCluster(Request $request, ProgrammaticCluster $cluster, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('approve', $cluster);
        $cluster->loadMissing('growthProgram');

        if (! $cluster->growthProgram) {
            return back()->withErrors(['growth_program' => 'Attach this cluster to a growth program before creating a publication plan.']);
        }

        try {
            $plan = $orchestrator->createPublicationPlanForCluster($cluster->growthProgram, $cluster, $this->validatedPlanInput($request));
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['publication_plan' => $exception->getMessage()]);
        }

        return redirect()->route('app.programmatic-publication-plans.show', $plan)->with('status', 'Publication plan created from cluster.');
    }

    public function createFromProgram(Request $request, GrowthProgram $program, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('approve', $program);

        try {
            $plan = $orchestrator->createPublicationPlanForProgram($program, $this->validatedPlanInput($request));
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['publication_plan' => $exception->getMessage()]);
        }

        return redirect()->route('app.programmatic-publication-plans.show', $plan)->with('status', 'Publication plan created from growth program.');
    }

    public function approve(ProgrammaticPublicationPlan $plan, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('approve', $plan);

        try {
            $plan->approve();
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['publication_plan' => $exception->getMessage()]);
        }
        $this->refreshProgramMetrics($plan, $orchestrator);

        return back()->with('status', 'Publication plan approved.');
    }

    public function schedule(ProgrammaticPublicationPlan $plan, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('schedule', $plan);
        $plan->loadMissing('growthProgram');

        if (! $plan->growthProgram instanceof GrowthProgram) {
            return back()->withErrors(['growth_program' => 'Attach this publication plan to a growth program before scheduling.']);
        }

        try {
            $count = $orchestrator->schedulePublicationPlan($plan->growthProgram, $plan);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['publication_plan' => $exception->getMessage()]);
        }

        return back()->with('status', $count.' scheduled publication records prepared.');
    }

    public function scheduleForProgram(GrowthProgram $program, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('approve', $program);

        try {
            $count = $orchestrator->scheduleApprovedPlansForProgram($program);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['publication_plan' => $exception->getMessage()]);
        }

        return back()->with('status', $count.' scheduled publication records prepared.');
    }

    public function cancel(Request $request, ProgrammaticPublicationPlan $plan, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('cancel', $plan);

        try {
            $plan->cancel($request->user());
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['publication_plan' => $exception->getMessage()]);
        }
        $this->refreshProgramMetrics($plan, $orchestrator);

        return back()->with('status', 'Publication plan cancelled.');
    }

    public function recalculate(ProgrammaticPublicationPlan $plan, ProgrammaticPublicationPlanBuilder $builder, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('prepare', $plan);
        $builder->recalculateCadence($plan);
        $this->refreshProgramMetrics($plan, $orchestrator);

        return back()->with('status', 'Publication plan cadence recalculated.');
    }

    /**
     * @return array<string,mixed>
     */
    private function validatedPlanInput(Request $request): array
    {
        return $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'planned_start_at' => ['nullable', 'date'],
            'cadence' => ['nullable', Rule::in(ProgrammaticPublicationPlan::cadences())],
            'destination_id' => ['nullable', 'uuid', 'exists:content_destinations,id'],
            'custom_interval_days' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);
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

    private function refreshProgramMetrics(ProgrammaticPublicationPlan $plan, GrowthProgramOrchestrator $orchestrator): void
    {
        $plan->loadMissing('growthProgram');
        if ($plan->growthProgram instanceof GrowthProgram) {
            $orchestrator->refreshMetrics($plan->growthProgram);
        }
    }
}
