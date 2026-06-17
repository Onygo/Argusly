<?php

namespace App\Http\Controllers\App;

use App\Enums\OpportunityCategory;
use App\Enums\OpportunitySignalSource;
use App\Enums\OpportunityStatus;
use App\Http\Controllers\Controller;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\OpportunitySignal;
use App\Models\SignalDetection;
use App\Models\Workspace;
use App\Services\OpportunityIntelligence\ExecutionPlanBriefService;
use App\Services\OpportunityIntelligence\OpportunityExecutionPlanBuilder;
use App\Services\OpportunityIntelligence\OpportunityIntelligenceEngine;
use App\Services\Journey\FirstValueExperienceService;
use App\Services\Growth\ProgrammaticGrowthBetaSummary;
use App\Services\Onboarding\FirstValueActivationService;
use App\Services\Onboarding\WorkspaceReadinessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use RuntimeException;

class AppOpportunityIntelligenceController extends Controller
{
    public function index(
        Request $request,
        WorkspaceReadinessService $readiness,
        FirstValueActivationService $activation,
        FirstValueExperienceService $firstValue,
        ProgrammaticGrowthBetaSummary $programmaticGrowthBetaSummary,
    ): View
    {
        $workspace = $this->resolveWorkspace($request);
        $moduleReadiness = $readiness->getModuleReadiness($workspace, 'opportunity_intelligence');
        $activeOpportunityStatuses = [
            OpportunityStatus::OPEN->value,
            OpportunityStatus::REVIEWING->value,
            OpportunityStatus::APPROVED->value,
            OpportunityStatus::PLANNED->value,
        ];

        $opportunityQuery = Opportunity::query()
            ->where('workspace_id', $workspace->id)
            ->with(['campaign', 'content', 'contentCluster', 'signals'])
            ->when($request->query('category'), fn ($query, $category) => $query->where('category', $category))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status), fn ($query) => $query->whereIn('status', $activeOpportunityStatuses));

        $opportunities = $opportunityQuery
            ->orderByDesc('priority_score')
            ->latest('last_seen_at')
            ->paginate(20)
            ->withQueryString();

        $signals = OpportunitySignal::query()
            ->where('workspace_id', $workspace->id)
            ->latest('observed_at')
            ->limit(40)
            ->get();

        $promotedSignalCount = OpportunitySignal::query()
            ->where('workspace_id', $workspace->id)
            ->where('source', OpportunitySignalSource::SIGNAL_INTELLIGENCE->value)
            ->whereNotNull('metadata->signal_detection_id')
            ->count();

        $timeline = Opportunity::query()
            ->where('workspace_id', $workspace->id)
            ->latest('last_seen_at')
            ->limit(30)
            ->get()
            ->groupBy(fn (Opportunity $opportunity): string => $opportunity->last_seen_at?->format('Y-m-d') ?? $opportunity->created_at?->format('Y-m-d') ?? 'Unseen');

        return view('app.opportunity-intelligence.index', [
            'workspace' => $workspace,
            'opportunities' => $opportunities,
            'signals' => $signals,
            'timeline' => $timeline,
            'categories' => OpportunityCategory::values(),
            'sources' => OpportunitySignalSource::values(),
            'filters' => $request->only(['category', 'status']),
            'readiness' => $moduleReadiness,
            'emptyStateGuide' => $readiness->getEmptyState($workspace, 'opportunity_intelligence'),
            'activation' => $activation->forWorkspace($workspace),
            'firstOpportunityCard' => $firstValue->firstOpportunityCard($workspace),
            'firstValueCelebrations' => $firstValue->celebrations($workspace),
            'canRunOpportunityEngine' => $promotedSignalCount > 0,
            'promotedSignalCount' => $promotedSignalCount,
            'programmaticGrowthSummary' => $programmaticGrowthBetaSummary->forWorkspace($workspace),
            'summary' => [
                'open' => Opportunity::query()->where('workspace_id', $workspace->id)->whereIn('status', $activeOpportunityStatuses)->count(),
                'avg_priority' => (float) Opportunity::query()->where('workspace_id', $workspace->id)->avg('priority_score'),
                'signals' => OpportunitySignal::query()->where('workspace_id', $workspace->id)->count(),
                'high_confidence' => Opportunity::query()->where('workspace_id', $workspace->id)->whereIn('status', $activeOpportunityStatuses)->where('confidence_score', '>=', 75)->count(),
            ],
        ]);
    }

    public function run(Request $request, OpportunityIntelligenceEngine $engine): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request);
        $result = $engine->run($workspace);

        return back()->with('status', sprintf('Opportunity intelligence refreshed: %d created, %d updated.', $result['created'], $result['updated']));
    }

    public function show(
        Request $request,
        Opportunity $opportunity,
        WorkspaceReadinessService $readiness,
        FirstValueExperienceService $firstValue,
    ): View
    {
        $workspace = $this->resolveWorkspace($request, $opportunity->workspace_id);
        $this->assertOpportunityWorkspace($opportunity, $workspace);

        $opportunity->load([
            'workspace',
            'campaign',
            'content',
            'contentCluster',
            'signals' => fn ($query) => $query->orderByDesc('observed_at'),
            'activeExecutionPlans',
        ]);

        $signalDetectionIds = $opportunity->signals
            ->pluck('metadata.signal_detection_id')
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values();

        $signalDetections = SignalDetection::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $signalDetectionIds)
            ->with([
                'events' => fn ($query) => $query->with(['signalSource', 'signalMention', 'signalFeedItem'])->orderByDesc('observed_at'),
            ])
            ->get()
            ->keyBy(fn (SignalDetection $detection): string => (string) $detection->id);

        return view('app.opportunity-intelligence.show', [
            'title' => 'Opportunity Intelligence',
            'workspace' => $workspace,
            'opportunity' => $opportunity,
            'signalDetections' => $signalDetections,
            'firstOpportunityCard' => $firstValue->opportunityCard($opportunity),
            'firstValueCelebrations' => $firstValue->celebrations($workspace),
            'executionPlanningEmptyState' => $readiness->getEmptyState($workspace, 'execution_planning'),
            'canCreateExecutionPlan' => in_array((string) ($opportunity->status?->value ?? $opportunity->status), [OpportunityStatus::APPROVED->value, OpportunityStatus::REVIEWING->value], true)
                && $opportunity->activeExecutionPlans->isEmpty(),
        ]);
    }

    public function storeExecutionPlan(Request $request, Opportunity $opportunity, OpportunityExecutionPlanBuilder $builder): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request, $opportunity->workspace_id);
        $this->assertOpportunityWorkspace($opportunity, $workspace);
        $this->assertCanManage($request);

        try {
            $plan = $builder->build($opportunity, $request->user());
        } catch (AuthorizationException $exception) {
            return back()->withErrors(['execution_plan' => $exception->getMessage()]);
        }

        return redirect()
            ->route('app.opportunity-intelligence.execution-plans.show', $plan)
            ->with('status', 'Execution plan created.');
    }

    public function showExecutionPlan(
        Request $request,
        OpportunityExecutionPlan $plan,
        FirstValueExperienceService $firstValue,
    ): View
    {
        $workspace = $this->resolveWorkspace($request, $plan->workspace_id);
        $this->assertPlanWorkspace($plan, $workspace);

        $plan->load(['workspace', 'clientSite', 'opportunity.signals', 'creator', 'approver']);

        $signalDetectionIds = collect(data_get($plan->source_evidence, 'signals', []))
            ->pluck('signal_detection_id')
            ->filter()
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values();

        $signalDetections = SignalDetection::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $signalDetectionIds)
            ->get()
            ->keyBy(fn (SignalDetection $detection): string => (string) $detection->id);

        return view('app.opportunity-intelligence.execution-plan-show', [
            'title' => 'Opportunity Execution Plan',
            'workspace' => $workspace,
            'plan' => $plan,
            'signalDetections' => $signalDetections,
            'preparedLinks' => $this->preparedExecutionLinks($plan),
            'firstValueCelebrations' => $firstValue->celebrations($workspace),
            'canCreateBrief' => $this->canCreateBriefFromPlan($request, $plan),
        ]);
    }

    public function createBrief(Request $request, OpportunityExecutionPlan $plan, ExecutionPlanBriefService $service): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request, $plan->workspace_id);
        $this->assertPlanWorkspace($plan, $workspace);
        $this->assertCanManage($request);

        try {
            $brief = $service->createBrief($plan, $request->user());
        } catch (AuthorizationException $exception) {
            return back()->withErrors(['brief' => $exception->getMessage()]);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['brief' => $exception->getMessage()]);
        }

        $route = Route::has('app.content.workspace.show')
            ? 'app.content.workspace.show'
            : 'app.briefs.show';

        return redirect()
            ->route($route, $brief)
            ->with('status', 'Content brief created from execution plan.');
    }

    public function reviewExecutionPlan(Request $request, OpportunityExecutionPlan $plan): RedirectResponse
    {
        return $this->transitionPlan($request, $plan, 'review');
    }

    public function approveExecutionPlan(Request $request, OpportunityExecutionPlan $plan): RedirectResponse
    {
        return $this->transitionPlan($request, $plan, 'approve');
    }

    public function plannedExecutionPlan(Request $request, OpportunityExecutionPlan $plan): RedirectResponse
    {
        return $this->transitionPlan($request, $plan, 'planned');
    }

    public function archiveExecutionPlan(Request $request, OpportunityExecutionPlan $plan): RedirectResponse
    {
        return $this->transitionPlan($request, $plan, 'archive');
    }

    public function review(Request $request, Opportunity $opportunity): RedirectResponse
    {
        return $this->transition($request, $opportunity, OpportunityStatus::REVIEWING, [
            'reviewed_by' => (string) $request->user()->id,
            'reviewed_at' => now()->toIso8601String(),
        ], 'Opportunity marked as reviewing.');
    }

    public function approve(Request $request, Opportunity $opportunity): RedirectResponse
    {
        return $this->transition($request, $opportunity, OpportunityStatus::APPROVED, [
            'approved_by' => (string) $request->user()->id,
            'approved_at' => now()->toIso8601String(),
        ], 'Opportunity approved.');
    }

    public function dismiss(Request $request, Opportunity $opportunity): RedirectResponse
    {
        return $this->transition($request, $opportunity, OpportunityStatus::DISMISSED, [
            'dismissed_by' => (string) $request->user()->id,
            'dismissed_at' => now()->toIso8601String(),
        ], 'Opportunity dismissed.');
    }

    public function resolve(Request $request, Opportunity $opportunity): RedirectResponse
    {
        return $this->transition($request, $opportunity, OpportunityStatus::RESOLVED, [
            'resolved_by' => (string) $request->user()->id,
            'resolved_at' => now()->toIso8601String(),
        ], 'Opportunity resolved.');
    }

    public function archive(Request $request, Opportunity $opportunity): RedirectResponse
    {
        return $this->transition($request, $opportunity, OpportunityStatus::ARCHIVED, [
            'archived_by' => (string) $request->user()->id,
            'archived_at' => now()->toIso8601String(),
        ], 'Opportunity archived.');
    }

    private function transition(Request $request, Opportunity $opportunity, OpportunityStatus $status, array $metadata, string $message): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request, $opportunity->workspace_id);
        $this->assertOpportunityWorkspace($opportunity, $workspace);
        $this->assertCanManage($request);

        $opportunity->forceFill([
            'status' => $status->value,
            'metadata' => array_merge($opportunity->metadata ?? [], $metadata),
        ])->save();

        return redirect()
            ->route('app.opportunity-intelligence.opportunities.show', $opportunity)
            ->with('status', $message);
    }

    private function resolveWorkspace(Request $request, mixed $preferredWorkspaceId = null): Workspace
    {
        return Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when($preferredWorkspaceId ?: $request->query('workspace_id') ?: $request->query('workspace'), fn ($query, $id) => $query->where('id', $id))
            ->orderBy('created_at')
            ->firstOrFail();
    }

    private function assertOpportunityWorkspace(Opportunity $opportunity, Workspace $workspace): void
    {
        if ((string) $opportunity->workspace_id !== (string) $workspace->id) {
            throw new AuthorizationException('Opportunity is not available for this workspace.');
        }
    }

    private function assertPlanWorkspace(OpportunityExecutionPlan $plan, Workspace $workspace): void
    {
        if ((string) $plan->workspace_id !== (string) $workspace->id) {
            throw new AuthorizationException('Execution plan is not available for this workspace.');
        }
    }

    private function transitionPlan(Request $request, OpportunityExecutionPlan $plan, string $action): RedirectResponse
    {
        $workspace = $this->resolveWorkspace($request, $plan->workspace_id);
        $this->assertPlanWorkspace($plan, $workspace);
        $this->assertCanManage($request);

        match ($action) {
            'review' => $plan->markReviewing(),
            'approve' => $plan->approve($request->user()),
            'planned' => $plan->markPlanned(),
            'archive' => $plan->archive(),
            default => throw new \InvalidArgumentException('Unsupported execution plan action.'),
        };

        return redirect()
            ->route('app.opportunity-intelligence.execution-plans.show', $plan)
            ->with('status', 'Execution plan updated.');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function preparedExecutionLinks(OpportunityExecutionPlan $plan): array
    {
        return [
            [
                'label' => 'Create content brief',
                'url' => data_get($plan->metadata, 'brief_id') && Route::has('app.content.workspace.show')
                    ? route('app.content.workspace.show', data_get($plan->metadata, 'brief_id'))
                    : null,
                'enabled' => (bool) data_get($plan->metadata, 'brief_id'),
            ],
            [
                'label' => 'Create campaign idea',
                'url' => null,
                'enabled' => false,
            ],
            [
                'label' => 'Create social draft',
                'url' => null,
                'enabled' => false,
            ],
        ];
    }

    private function canCreateBriefFromPlan(Request $request, OpportunityExecutionPlan $plan): bool
    {
        return in_array((string) $plan->status, [OpportunityExecutionPlan::STATUS_APPROVED, OpportunityExecutionPlan::STATUS_PLANNED], true)
            && ! data_get($plan->metadata, 'brief_id')
            && $request->user()
            && ($request->user()->is_admin || in_array((string) $request->user()->role, ['owner', 'admin', 'editor'], true));
    }

    private function assertCanManage(Request $request): void
    {
        $user = $request->user();

        if (! $user || (! $user->is_admin && ! in_array((string) $user->role, ['owner', 'admin', 'editor'], true))) {
            throw new AuthorizationException('You are not allowed to update this opportunity.');
        }
    }
}
