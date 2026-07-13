<?php

namespace App\Http\Controllers\App;

use App\Enums\BrandGrowthPlanReviewState;
use App\Enums\BrandGrowthPlanStatus;
use App\Http\Controllers\Controller;
use App\Models\BrandGrowthAudienceProposal;
use App\Models\BrandGrowthPlan;
use App\Models\BrandGrowthPlanFinding;
use App\Models\Workspace;
use App\Services\BrandGrowthPlanning\BrandGrowthAudiencePromotionService;
use App\Services\BrandGrowthPlanning\BrandGrowthFindingPromotionService;
use App\Services\BrandGrowthPlanning\BrandGrowthPlanGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AppBrandGrowthPlanController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = $this->resolveWorkspace($request);
        $this->authorize('viewAny', BrandGrowthPlan::class);

        $plans = BrandGrowthPlan::query()
            ->where('workspace_id', $workspace->id)
            ->withCount(['findings', 'audienceProposals'])
            ->orderByDesc('version')
            ->paginate(12)
            ->withQueryString();

        $latestPlan = BrandGrowthPlan::query()
            ->where('workspace_id', $workspace->id)
            ->withCount(['findings', 'audienceProposals'])
            ->orderByDesc('version')
            ->first();

        return view('app.brand-growth-plans.index', [
            'title' => 'Brand Growth Plans',
            'workspace' => $workspace,
            'plans' => $plans,
            'latestPlan' => $latestPlan,
            'clientSites' => $workspace->clientSites()->orderBy('name')->get(),
            'summary' => [
                'plans' => BrandGrowthPlan::query()->where('workspace_id', $workspace->id)->count(),
                'pending_findings' => BrandGrowthPlanFinding::query()->where('workspace_id', $workspace->id)->where('review_state', BrandGrowthPlanReviewState::PENDING->value)->count(),
                'approved_findings' => BrandGrowthPlanFinding::query()->where('workspace_id', $workspace->id)->where('review_state', BrandGrowthPlanReviewState::APPROVED->value)->count(),
                'pending_audiences' => BrandGrowthAudienceProposal::query()->where('workspace_id', $workspace->id)->where('review_state', BrandGrowthPlanReviewState::PENDING->value)->count(),
            ],
        ]);
    }

    public function generate(Request $request, BrandGrowthPlanGenerator $generator): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);
        $this->authorize('create', BrandGrowthPlan::class);

        $validated = $request->validate([
            'client_site_id' => ['nullable', 'uuid'],
            'planning_horizon' => ['nullable', 'string', 'max:80'],
            'business_objective' => ['nullable', 'string', 'max:2000'],
            'brand_objective' => ['nullable', 'string', 'max:2000'],
        ]);

        $plan = $generator->generate($workspace, $request->user(), $validated);

        return redirect()
            ->route('app.agentic-marketing.brand-growth-plans.show', ['plan' => $plan, 'workspace_id' => $workspace->id])
            ->with('status', 'Draft Brand Growth Plan generated.');
    }

    public function show(Request $request, BrandGrowthPlan $plan): View
    {
        $workspace = $this->resolveWorkspace($request, $plan->workspace_id);
        $this->assertPlanWorkspace($plan, $workspace);
        $this->authorize('view', $plan);

        $plan->load([
            'clientSite',
            'supersedesPlan',
            'findings' => fn ($query) => $query->with('opportunity')->orderByDesc('impact_score')->orderByDesc('urgency_score'),
            'audienceProposals' => fn ($query) => $query->with('persona')->orderByDesc('confidence_score')->orderBy('name'),
        ]);

        return view('app.brand-growth-plans.show', [
            'title' => 'Brand Growth Plan',
            'workspace' => $workspace,
            'plan' => $plan,
            'planHistory' => BrandGrowthPlan::query()
                ->where('workspace_id', $workspace->id)
                ->whereKeyNot($plan->id)
                ->orderByDesc('version')
                ->limit(8)
                ->get(),
        ]);
    }

    public function update(Request $request, BrandGrowthPlan $plan): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request, $plan->workspace_id);
        $this->assertPlanWorkspace($plan, $workspace);
        $this->authorize('update', $plan);

        $validated = $request->validate([
            'business_objective' => ['nullable', 'string', 'max:2000'],
            'brand_objective' => ['nullable', 'string', 'max:2000'],
            'messaging_priorities_text' => ['nullable', 'string', 'max:4000'],
            'content_priorities_text' => ['nullable', 'string', 'max:4000'],
            'top_prioritized_actions_text' => ['nullable', 'string', 'max:4000'],
            'kpi_recommendations_text' => ['nullable', 'string', 'max:4000'],
        ]);

        $plan->forceFill([
            'business_objective' => $validated['business_objective'] ?? null,
            'brand_objective' => $validated['brand_objective'] ?? null,
            'messaging_priorities' => $this->lines($validated['messaging_priorities_text'] ?? ''),
            'content_priorities' => $this->lines($validated['content_priorities_text'] ?? ''),
            'top_prioritized_actions' => $this->lines($validated['top_prioritized_actions_text'] ?? ''),
            'kpi_recommendations' => $this->lines($validated['kpi_recommendations_text'] ?? ''),
            'status' => $plan->status === BrandGrowthPlanStatus::APPROVED ? BrandGrowthPlanStatus::APPROVED->value : BrandGrowthPlanStatus::REVIEWING->value,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        return back()->with('status', 'Brand Growth Plan priorities updated.');
    }

    public function approvePlan(Request $request, BrandGrowthPlan $plan): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request, $plan->workspace_id);
        $this->assertPlanWorkspace($plan, $workspace);
        $this->authorize('approve', $plan);

        $plan->forceFill([
            'status' => BrandGrowthPlanStatus::APPROVED->value,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ])->save();

        return back()->with('status', 'Brand Growth Plan approved.');
    }

    public function approveFinding(Request $request, BrandGrowthPlanFinding $finding): RedirectResponse
    {
        return $this->reviewFinding($request, $finding, BrandGrowthPlanReviewState::APPROVED, 'Finding approved.');
    }

    public function rejectFinding(Request $request, BrandGrowthPlanFinding $finding): RedirectResponse
    {
        return $this->reviewFinding($request, $finding, BrandGrowthPlanReviewState::REJECTED, 'Finding rejected.');
    }

    public function promoteFinding(Request $request, BrandGrowthPlanFinding $finding, BrandGrowthFindingPromotionService $service): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request, $finding->workspace_id);
        $this->assertFindingWorkspace($finding, $workspace);
        $this->authorize('promote', $finding);

        $opportunity = $service->promote($finding, $request->user());

        return redirect()
            ->route('app.opportunities.show', ['opportunity' => $opportunity, 'workspace_id' => $workspace->id])
            ->with('status', 'Finding promoted into Opportunities.');
    }

    public function approveAudience(Request $request, BrandGrowthAudienceProposal $proposal): RedirectResponse
    {
        return $this->reviewAudience($request, $proposal, BrandGrowthPlanReviewState::APPROVED, 'Audience proposal approved.');
    }

    public function rejectAudience(Request $request, BrandGrowthAudienceProposal $proposal): RedirectResponse
    {
        return $this->reviewAudience($request, $proposal, BrandGrowthPlanReviewState::REJECTED, 'Audience proposal rejected.');
    }

    public function promoteAudience(Request $request, BrandGrowthAudienceProposal $proposal, BrandGrowthAudiencePromotionService $service): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request, $proposal->workspace_id);
        $this->assertAudienceWorkspace($proposal, $workspace);
        $this->authorize('promote', $proposal);

        $service->promote($proposal, $request->user());

        return back()->with('status', 'Audience proposal promoted to an approved Persona.');
    }

    private function reviewFinding(Request $request, BrandGrowthPlanFinding $finding, BrandGrowthPlanReviewState $state, string $message): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request, $finding->workspace_id);
        $this->assertFindingWorkspace($finding, $workspace);
        $this->authorize('review', $finding);

        $finding->forceFill([
            'review_state' => $state->value,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        return back()->with('status', $message);
    }

    private function reviewAudience(Request $request, BrandGrowthAudienceProposal $proposal, BrandGrowthPlanReviewState $state, string $message): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request, $proposal->workspace_id);
        $this->assertAudienceWorkspace($proposal, $workspace);
        $this->authorize('review', $proposal);

        $proposal->forceFill([
            'review_state' => $state->value,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ])->save();

        return back()->with('status', $message);
    }

    private function resolveWorkspace(Request $request, mixed $preferredWorkspaceId = null): Workspace
    {
        return Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when($preferredWorkspaceId ?: $request->query('workspace_id') ?: $request->query('workspace'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('created_at')
            ->firstOrFail();
    }

    private function assertPlanWorkspace(BrandGrowthPlan $plan, Workspace $workspace): void
    {
        if ((string) $plan->workspace_id !== (string) $workspace->id) {
            throw new AuthorizationException('Brand Growth Plan is not available for this workspace.');
        }
    }

    private function assertFindingWorkspace(BrandGrowthPlanFinding $finding, Workspace $workspace): void
    {
        if ((string) $finding->workspace_id !== (string) $workspace->id) {
            throw new AuthorizationException('Brand Growth finding is not available for this workspace.');
        }
    }

    private function assertAudienceWorkspace(BrandGrowthAudienceProposal $proposal, Workspace $workspace): void
    {
        if ((string) $proposal->workspace_id !== (string) $workspace->id) {
            throw new AuthorizationException('Audience proposal is not available for this workspace.');
        }
    }

    /**
     * @return array<int, string>
     */
    private function lines(?string $value): array
    {
        return collect(preg_split('/\r\n|\r|\n/', (string) $value) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->unique(fn (string $line): string => mb_strtolower($line))
            ->values()
            ->all();
    }
}
