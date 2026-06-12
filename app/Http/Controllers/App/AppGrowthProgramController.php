<?php

namespace App\Http\Controllers\App;

use App\Enums\GrowthProgramStatus;
use App\Http\Controllers\Controller;
use App\Models\Brief;
use App\Models\Draft;
use App\Models\GrowthAsset;
use App\Models\GrowthProgramBetaEvent;
use App\Models\GrowthProgram;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\ProgrammaticCluster;
use App\Models\Workspace;
use App\Services\Growth\GrowthProgramBetaMetrics;
use App\Services\Growth\GrowthProgramOrchestrator;
use App\Services\Growth\GrowthProgramNextActionResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AppGrowthProgramController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', GrowthProgram::class);

        $workspace = $this->resolveWorkspace($request);
        $programs = GrowthProgram::query()
            ->where('workspace_id', $workspace->id)
            ->with(['owner'])
            ->withCount(['assets', 'runs'])
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('score')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $summary = [
            'total' => GrowthProgram::query()->where('workspace_id', $workspace->id)->count(),
            'active' => GrowthProgram::query()->where('workspace_id', $workspace->id)->whereNotIn('status', [GrowthProgramStatus::PUBLISHED->value, GrowthProgramStatus::MEASURED->value])->count(),
            'published' => GrowthProgram::query()->where('workspace_id', $workspace->id)->whereIn('status', [GrowthProgramStatus::PUBLISHED->value, GrowthProgramStatus::MEASURED->value])->count(),
            'avg_score' => (float) GrowthProgram::query()->where('workspace_id', $workspace->id)->avg('score'),
        ];

        return view('app.growth-programs.index', [
            'workspace' => $workspace,
            'workspaces' => $this->workspaces($request),
            'programs' => $programs,
            'statuses' => GrowthProgramStatus::cases(),
            'filters' => $request->only(['status']),
            'summary' => $summary,
        ]);
    }

    public function show(
        Request $request,
        GrowthProgram $program,
        GrowthProgramOrchestrator $orchestrator,
        GrowthProgramNextActionResolver $nextActionResolver,
        GrowthProgramBetaMetrics $betaMetrics,
    ): View
    {
        $this->authorize('view', $program);
        $workspace = $this->resolveWorkspace($request, $program->workspace_id);
        $this->assertProgramWorkspace($program, $workspace);

        $program = $orchestrator->refreshMetrics($program);
        $program->load([
            'workspace',
            'owner',
            'runs' => fn ($query) => $query->latest('created_at')->limit(30),
            'assets.assetable',
        ]);
        $program->assets
            ->where('role', GrowthAsset::ROLE_BRIEF_BLUEPRINT)
            ->each(fn ($asset) => $asset->assetable instanceof \App\Models\ProgrammaticBriefBlueprint ? $asset->assetable->loadMissing(['cluster', 'item']) : null);
        $program->assets
            ->where('role', GrowthAsset::ROLE_DRAFT_REQUEST)
            ->each(fn ($asset) => $asset->assetable instanceof \App\Models\ProgrammaticDraftRequest ? $asset->assetable->loadMissing(['brief', 'blueprint', 'cluster', 'item']) : null);
        $program->assets
            ->where('role', GrowthAsset::ROLE_DRAFT_REVIEW)
            ->each(fn ($asset) => $asset->assetable instanceof \App\Models\ProgrammaticDraftReview ? $asset->assetable->loadMissing(['draft', 'request', 'brief']) : null);
        $program->assets
            ->where('role', GrowthAsset::ROLE_PUBLICATION_READINESS)
            ->each(fn ($asset) => $asset->assetable instanceof \App\Models\ProgrammaticPublicationReadiness ? $asset->assetable->loadMissing(['content', 'review']) : null);
        $program->assets
            ->where('role', GrowthAsset::ROLE_PUBLICATION_PLAN)
            ->each(fn ($asset) => $asset->assetable instanceof \App\Models\ProgrammaticPublicationPlan ? $asset->assetable->loadMissing(['items.contentPublication', 'destination']) : null);
        $program->assets
            ->where('role', GrowthAsset::ROLE_PROGRAMMATIC_CLUSTER)
            ->each(fn ($asset) => $asset->assetable instanceof ProgrammaticCluster ? $asset->assetable->loadMissing('items') : null);

        return view('app.growth-programs.show', [
            'workspace' => $workspace,
            'program' => $program,
            'assetsByRole' => $program->assets->groupBy('role'),
            'timeline' => $this->timeline($program),
            'metrics' => $program->metrics ?? [],
            'betaMetrics' => $betaMetrics->forProgram($program),
            'internalBetaMode' => (bool) $request->session()->get('programmatic_growth_internal_beta_mode', false),
            'canUseInternalBetaMode' => $this->canUseInternalBetaMode($request),
            'commandCenter' => $nextActionResolver->resolve($program),
        ]);
    }

    public function betaReport(Request $request, GrowthProgramBetaMetrics $betaMetrics): View
    {
        $this->authorize('viewAny', GrowthProgram::class);
        abort_unless($this->canUseInternalBetaMode($request), 403);

        $workspace = $this->resolveWorkspace($request);

        return view('app.programmatic-growth.beta-report', [
            'workspace' => $workspace,
            'report' => $betaMetrics->reportForWorkspace($workspace),
            'internalBetaMode' => (bool) $request->session()->get('programmatic_growth_internal_beta_mode', false),
            'canUseInternalBetaMode' => true,
        ]);
    }

    public function storeFeedback(Request $request, GrowthProgram $program): RedirectResponse
    {
        $this->authorize('view', $program);
        $workspace = $this->resolveWorkspace($request, $program->workspace_id);
        $this->assertProgramWorkspace($program, $workspace);

        $data = $request->validate([
            'clarity' => ['required', 'in:yes,somewhat,no'],
            'step' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        GrowthProgramBetaEvent::query()->create([
            'organization_id' => $program->organization_id,
            'workspace_id' => $workspace->id,
            'growth_program_id' => $program->id,
            'user_id' => $request->user()?->id,
            'event_type' => GrowthProgramBetaEvent::TYPE_FEEDBACK,
            'step' => $data['step'] ?? null,
            'clarity' => $data['clarity'],
            'message' => $data['message'] ?? null,
            'metadata' => [
                'route' => optional($request->route())->getName(),
            ],
        ]);

        return back()->with('status', 'Thanks. Your beta feedback was saved.');
    }

    public function toggleInternalBetaMode(Request $request): RedirectResponse
    {
        abort_unless($this->canUseInternalBetaMode($request), 403);

        $request->validate([
            'enabled' => ['nullable', 'boolean'],
        ]);

        $request->session()->put('programmatic_growth_internal_beta_mode', $request->boolean('enabled'));

        return back()->with('status', $request->boolean('enabled') ? 'Internal beta tester mode enabled.' : 'Internal beta tester mode disabled.');
    }

    public function storeFromOpportunity(Request $request, Opportunity $opportunity, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('create', GrowthProgram::class);
        $workspace = $this->resolveWorkspace($request, $opportunity->workspace_id);
        if ((string) $opportunity->workspace_id !== (string) $workspace->id) {
            throw new AuthorizationException('Opportunity is not available for this workspace.');
        }

        $program = $orchestrator->createFromOpportunity($opportunity, $request->user());

        return redirect()
            ->route('app.growth-programs.show', $program)
            ->with('status', 'Growth program created from opportunity.');
    }

    public function storeFromExecutionPlan(Request $request, OpportunityExecutionPlan $plan, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('create', GrowthProgram::class);
        $workspace = $this->resolveWorkspace($request, $plan->workspace_id);
        $this->assertWorkspaceId($plan->workspace_id, $workspace);

        $plan->loadMissing('opportunity');
        $program = $plan->opportunity
            ? $orchestrator->createFromOpportunity($plan->opportunity, $request->user())
            : $orchestrator->create($workspace, [
                'name' => 'Execution plan: '.Str::limit($plan->title, 90, ''),
                'description' => $plan->summary,
                'status' => GrowthProgramStatus::PLANNED->value,
                'score' => (float) $plan->priority_score,
                'estimated_impact' => (float) $plan->expected_impact,
                'source' => 'execution_plan',
            ], $request->user());

        $orchestrator->attachExecutionPlan($program, $plan);

        return redirect()
            ->route('app.growth-programs.show', $program)
            ->with('status', 'Growth program created from execution plan.');
    }

    public function storeFromBrief(Request $request, Brief $brief, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('create', GrowthProgram::class);
        $brief->loadMissing('clientSite');
        $workspace = $this->resolveWorkspace($request, $brief->clientSite?->workspace_id);
        $this->assertWorkspaceId($brief->clientSite?->workspace_id, $workspace);

        $program = $orchestrator->create($workspace, [
            'name' => 'Brief: '.Str::limit($brief->title, 90, ''),
            'description' => $brief->notes,
            'status' => GrowthProgramStatus::BRIEFED->value,
            'source' => 'brief',
        ], $request->user());

        $orchestrator->attachBrief($program, $brief);
        $orchestrator->syncDraftsFromBriefs($program);

        return redirect()
            ->route('app.growth-programs.show', $program)
            ->with('status', 'Growth program created from brief.');
    }

    public function storeFromDraft(Request $request, Draft $draft, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('create', GrowthProgram::class);
        $draft->loadMissing('clientSite');
        $workspace = $this->resolveWorkspace($request, $draft->clientSite?->workspace_id);
        $this->assertWorkspaceId($draft->clientSite?->workspace_id, $workspace);

        $program = $orchestrator->create($workspace, [
            'name' => 'Draft: '.Str::limit($draft->title, 90, ''),
            'status' => GrowthProgramStatus::DRAFTING->value,
            'source' => 'draft',
        ], $request->user());

        $orchestrator->attachDraft($program, $draft);
        $orchestrator->syncPublicationsFromDrafts($program);

        return redirect()
            ->route('app.growth-programs.show', $program)
            ->with('status', 'Growth program created from draft.');
    }

    public function attachOpportunity(Request $request, Opportunity $opportunity, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $program = $this->programForAttach($request, $opportunity->workspace_id);
        $orchestrator->attachOpportunity($program, $opportunity);

        return back()->with('status', 'Opportunity attached to growth program.');
    }

    public function attachExecutionPlan(Request $request, OpportunityExecutionPlan $plan, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $program = $this->programForAttach($request, $plan->workspace_id);
        $orchestrator->attachExecutionPlan($program, $plan);

        return back()->with('status', 'Execution plan attached to growth program.');
    }

    public function attachBrief(Request $request, Brief $brief, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $brief->loadMissing('clientSite');
        $program = $this->programForAttach($request, $brief->clientSite?->workspace_id);
        $orchestrator->attachBrief($program, $brief);
        $orchestrator->syncDraftsFromBriefs($program);

        return back()->with('status', 'Brief attached to growth program.');
    }

    public function attachDraft(Request $request, Draft $draft, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $draft->loadMissing('clientSite');
        $program = $this->programForAttach($request, $draft->clientSite?->workspace_id);
        $orchestrator->attachDraft($program, $draft);
        $orchestrator->syncPublicationsFromDrafts($program);

        return back()->with('status', 'Draft attached to growth program.');
    }

    public function transition(Request $request, GrowthProgram $program, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('prepare', $program);
        $request->validate([
            'status' => ['required', 'in:'.implode(',', GrowthProgramStatus::values())],
        ]);

        $orchestrator->transition($program, (string) $request->input('status'));

        return redirect()
            ->route('app.growth-programs.show', $program)
            ->with('status', 'Growth program updated.');
    }

    public function detectProgrammaticOpportunities(GrowthProgram $program, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('prepare', $program);
        $count = $orchestrator->detectProgrammaticOpportunitiesForProgram($program);

        return back()->with('status', $count.' programmatic opportunities detected.');
    }

    public function buildClusterPreviews(GrowthProgram $program, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('prepare', $program);
        $count = $orchestrator->syncProgrammaticClustersForProgram($program);

        return back()->with('status', $count.' cluster previews built.');
    }

    public function buildBriefBlueprints(GrowthProgram $program, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('prepare', $program);
        $count = $orchestrator->syncBriefBlueprintsForProgram($program);

        return back()->with('status', $count.' brief blueprints built.');
    }

    public function convertApprovedBlueprints(GrowthProgram $program, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('approve', $program);
        $count = $orchestrator->convertApprovedBlueprintsForProgram($program);

        return back()->with('status', $count.' approved blueprints converted to briefs.');
    }

    public function prepareDraftRequests(GrowthProgram $program, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('prepare', $program);
        $count = $orchestrator->prepareDraftRequestsForProgram($program);

        return back()->with('status', $count.' draft requests prepared.');
    }

    public function generateApprovedDraftRequests(GrowthProgram $program, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('prepare', $program);

        try {
            $count = $orchestrator->generateApprovedDraftsForProgram($program);
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['draft_request' => $exception->getMessage()]);
        }

        return back()->with('status', $count.' approved draft requests generated.');
    }

    public function reviewGeneratedDrafts(GrowthProgram $program, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('prepare', $program);
        $count = $orchestrator->reviewGeneratedDraftsForProgram($program);

        return back()->with('status', $count.' generated drafts reviewed.');
    }

    public function convertApprovedReviewsToContent(GrowthProgram $program, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('approve', $program);
        $count = $orchestrator->convertApprovedReviewsForProgram($program, createdByUserId: request()->user()?->id);

        return back()->with('status', $count.' approved reviews converted to content.');
    }

    public function runPublicationReadiness(GrowthProgram $program, GrowthProgramOrchestrator $orchestrator): RedirectResponse
    {
        $this->authorize('prepare', $program);
        $count = $orchestrator->runPublicationReadinessForProgram($program);

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

    private function assertProgramWorkspace(GrowthProgram $program, Workspace $workspace): void
    {
        if ((string) $program->workspace_id !== (string) $workspace->id) {
            throw new AuthorizationException('Growth program is not available for this workspace.');
        }
    }

    private function assertWorkspaceId(mixed $workspaceId, Workspace $workspace): void
    {
        if ((string) $workspaceId !== (string) $workspace->id) {
            throw new AuthorizationException('This record is not available for this workspace.');
        }
    }

    private function programForAttach(Request $request, mixed $workspaceId): GrowthProgram
    {
        $request->validate([
            'growth_program_id' => ['required', 'uuid', 'exists:growth_programs,id'],
        ]);

        $program = GrowthProgram::query()->findOrFail((string) $request->input('growth_program_id'));
        $this->authorize('prepare', $program);
        if ((string) $program->workspace_id !== (string) $workspaceId) {
            throw new AuthorizationException('Growth program is not available for this workspace.');
        }

        return $program;
    }

    private function canUseInternalBetaMode(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user && ($user->is_admin || in_array((string) $user->role, ['owner', 'admin'], true));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function timeline(GrowthProgram $program): array
    {
        $statuses = collect(GrowthProgramStatus::cases())
            ->map(fn (GrowthProgramStatus $status): array => [
                'label' => $status->label(),
                'status' => $status->value,
                'at' => $program->{$status->timestampColumn()},
                'complete' => $status->progress() <= $program->progress(),
            ]);

        $runs = $program->runs
            ->map(fn ($run): array => [
                'label' => 'Run: '.str_replace('_', ' ', (string) $run->triggered_by),
                'status' => (string) $run->stage,
                'at' => $run->started_at,
                'complete' => (string) $run->status === 'completed',
            ]);

        return $statuses->merge($runs)
            ->sortByDesc(fn (array $item): string => optional($item['at'])->toIso8601String() ?: '')
            ->values()
            ->all();
    }
}
