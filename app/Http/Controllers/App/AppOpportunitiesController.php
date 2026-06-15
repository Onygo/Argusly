<?php

namespace App\Http\Controllers\App;

use App\Enums\OpportunityStatus;
use App\Enums\SignalStatus;
use App\Http\Controllers\Controller;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\SignalDetection;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class AppOpportunitiesController extends Controller
{
    public function index(Request $request): View
    {
        $workspace = $this->resolveWorkspace($request);
        $opportunities = $this->opportunities($workspace)->get();
        $candidates = $this->candidates($workspace)->get();
        $plans = $this->executionPlans($workspace)->get();

        return view('app.opportunities.index', [
            'title' => 'Opportunities',
            'workspace' => $workspace,
            'workspaces' => $this->workspaces($request),
            'mode' => $request->routeIs('app.opportunities.decisions') ? 'decisions' : 'inbox',
            'inboxItems' => $this->inboxItems($opportunities, $candidates),
            'decisionItems' => $this->decisionItems($opportunities, $candidates, $plans),
            'executionRecommendations' => $plans->map(fn (OpportunityExecutionPlan $plan): array => $this->planCard($plan))->values(),
            'summary' => [
                'opportunities' => $opportunities->count() + $candidates->count(),
                'decisions' => $this->decisionItems($opportunities, $candidates, $plans)->count(),
                'execution_recommendations' => $plans->count(),
                'high_impact' => $opportunities->where('impact_score', '>=', 75)->count()
                    + $candidates->where('opportunity_score', '>=', 75)->count()
                    + $plans->where('expected_impact', '>=', 75)->count(),
            ],
        ]);
    }

    public function show(Request $request, Opportunity $opportunity): View
    {
        $workspace = $this->resolveWorkspace($request, $opportunity->workspace_id);
        $this->assertOpportunityWorkspace($opportunity, $workspace);

        $opportunity->load(['activeExecutionPlans', 'campaign', 'content', 'contentCluster']);

        return view('app.opportunities.show', [
            'title' => 'Opportunity Detail',
            'workspace' => $workspace,
            'opportunity' => $opportunity,
            'opportunityCard' => $this->opportunityCard($opportunity),
            'executionRecommendations' => $opportunity->activeExecutionPlans
                ->map(fn (OpportunityExecutionPlan $plan): array => $this->planCard($plan))
                ->values(),
            'canCreateExecutionPlan' => in_array($this->statusValue($opportunity->status), [
                OpportunityStatus::APPROVED->value,
                OpportunityStatus::REVIEWING->value,
            ], true) && $opportunity->activeExecutionPlans->isEmpty(),
        ]);
    }

    public function showCandidate(Request $request, SignalDetection $detection): View
    {
        $workspace = $this->resolveWorkspace($request, $detection->workspace_id);
        $this->assertCandidateWorkspace($detection, $workspace);

        return view('app.opportunities.candidate-show', [
            'title' => 'Opportunity Detail',
            'workspace' => $workspace,
            'candidate' => $detection,
            'opportunityCard' => $this->candidateCard($detection),
        ]);
    }

    public function showExecutionRecommendation(Request $request, OpportunityExecutionPlan $plan): View
    {
        $workspace = $this->resolveWorkspace($request, $plan->workspace_id);
        $this->assertPlanWorkspace($plan, $workspace);

        $plan->load(['opportunity', 'clientSite', 'creator', 'approver']);

        return view('app.opportunities.execution-recommendation-show', [
            'title' => 'Execution Recommendation',
            'workspace' => $workspace,
            'plan' => $plan,
            'planCard' => $this->planCard($plan),
            'canCreateBrief' => in_array((string) $plan->status, [
                OpportunityExecutionPlan::STATUS_APPROVED,
                OpportunityExecutionPlan::STATUS_PLANNED,
            ], true) && ! data_get($plan->metadata, 'brief_id'),
        ]);
    }

    private function resolveWorkspace(Request $request, mixed $preferredWorkspaceId = null): Workspace
    {
        return Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->when($preferredWorkspaceId ?: $request->query('workspace_id') ?: $request->query('workspace'), fn ($query, $id) => $query->whereKey($id))
            ->orderBy('created_at')
            ->firstOrFail();
    }

    private function workspaces(Request $request): Collection
    {
        return Workspace::query()
            ->where('organization_id', $request->user()->organization_id)
            ->orderBy('created_at')
            ->get();
    }

    private function opportunities(Workspace $workspace)
    {
        return Opportunity::query()
            ->where('workspace_id', $workspace->id)
            ->with(['activeExecutionPlans'])
            ->whereIn('status', [
                OpportunityStatus::OPEN->value,
                OpportunityStatus::REVIEWING->value,
                OpportunityStatus::APPROVED->value,
                OpportunityStatus::PLANNED->value,
            ])
            ->orderByDesc('priority_score')
            ->latest('last_seen_at')
            ->limit(30);
    }

    private function candidates(Workspace $workspace)
    {
        return SignalDetection::query()
            ->where('workspace_id', $workspace->id)
            ->open()
            ->where(function ($query): void {
                $query->where('category', SignalDetection::CATEGORY_OPPORTUNITY_DETECTION)
                    ->orWhere('category', SignalDetection::CATEGORY_RISK_DETECTION)
                    ->orWhere('opportunity_score', '>=', 65);
            })
            ->orderByDesc('opportunity_score')
            ->orderByDesc('priority_score')
            ->latest('last_seen_at')
            ->limit(20);
    }

    private function executionPlans(Workspace $workspace)
    {
        return OpportunityExecutionPlan::query()
            ->where('workspace_id', $workspace->id)
            ->with(['opportunity'])
            ->active()
            ->orderByDesc('expected_impact')
            ->latest('created_at')
            ->limit(20);
    }

    private function inboxItems(Collection $opportunities, Collection $candidates): Collection
    {
        return $opportunities
            ->map(fn (Opportunity $opportunity): array => $this->opportunityCard($opportunity))
            ->merge($candidates->map(fn (SignalDetection $candidate): array => $this->candidateCard($candidate)))
            ->sortByDesc('sort_score')
            ->values();
    }

    private function decisionItems(Collection $opportunities, Collection $candidates, Collection $plans): Collection
    {
        $opportunityDecisions = $opportunities
            ->filter(fn (Opportunity $opportunity): bool => in_array($this->statusValue($opportunity->status), [
                OpportunityStatus::OPEN->value,
                OpportunityStatus::REVIEWING->value,
                OpportunityStatus::APPROVED->value,
            ], true))
            ->map(fn (Opportunity $opportunity): array => $this->opportunityCard($opportunity));

        $candidateDecisions = $candidates
            ->filter(fn (SignalDetection $candidate): bool => in_array($this->statusValue($candidate->status), [
                SignalStatus::NEW->value,
                SignalStatus::DETECTED->value,
                SignalStatus::REVIEWING->value,
            ], true))
            ->map(fn (SignalDetection $candidate): array => $this->candidateCard($candidate));

        $planDecisions = $plans
            ->filter(fn (OpportunityExecutionPlan $plan): bool => in_array((string) $plan->status, [
                OpportunityExecutionPlan::STATUS_DRAFT,
                OpportunityExecutionPlan::STATUS_REVIEWING,
                OpportunityExecutionPlan::STATUS_APPROVED,
            ], true))
            ->map(fn (OpportunityExecutionPlan $plan): array => $this->planCard($plan));

        return $opportunityDecisions
            ->merge($candidateDecisions)
            ->merge($planDecisions)
            ->sortByDesc('sort_score')
            ->values();
    }

    private function opportunityCard(Opportunity $opportunity): array
    {
        $impact = (float) ($opportunity->impact_score ?: $opportunity->priority_score);

        return [
            'kind' => 'opportunity',
            'label' => 'Opportunity',
            'title' => $opportunity->title,
            'why_it_matters' => $opportunity->summary ?: 'This opportunity can improve visibility, demand, or conversion.',
            'recommended_action' => $this->firstRecommendedAction($opportunity->recommended_actions) ?: 'Decide whether to approve, dismiss, or plan this opportunity.',
            'expected_impact' => $this->impactLabel($impact),
            'next_step' => $opportunity->activeExecutionPlans->isNotEmpty() ? 'Review the execution recommendation.' : 'Approve or create an execution recommendation.',
            'status' => $this->humanStatus($this->statusValue($opportunity->status)),
            'score' => $impact,
            'sort_score' => (float) ($opportunity->priority_score ?: $impact),
            'url' => route('app.opportunities.show', $opportunity),
            'legacy_url' => route('app.opportunity-intelligence.opportunities.show', $opportunity),
        ];
    }

    private function candidateCard(SignalDetection $candidate): array
    {
        $impact = (float) ($candidate->opportunity_score ?: $candidate->impact_score ?: $candidate->priority_score);

        return [
            'kind' => 'candidate',
            'label' => 'Opportunity',
            'title' => $candidate->title,
            'why_it_matters' => $candidate->summary ?: $candidate->primary_topic ?: 'Argusly found a market change that may deserve action.',
            'recommended_action' => $this->firstRecommendedAction($candidate->recommended_actions) ?: 'Review this opportunity and decide whether to turn it into an active opportunity.',
            'expected_impact' => $this->impactLabel($impact),
            'next_step' => 'Decide whether Argusly should turn this into an active opportunity.',
            'status' => $this->humanStatus($this->statusValue($candidate->status)),
            'score' => $impact,
            'sort_score' => (float) ($candidate->priority_score ?: $impact),
            'url' => route('app.opportunities.candidates.show', $candidate),
            'legacy_url' => route('app.signal-intelligence.detections.show', $candidate),
        ];
    }

    private function planCard(OpportunityExecutionPlan $plan): array
    {
        $impact = (float) ($plan->expected_impact ?: $plan->priority_score);

        return [
            'kind' => 'execution',
            'label' => 'Execution Recommendation',
            'title' => $plan->title,
            'why_it_matters' => $plan->summary ?: $plan->objective ?: 'This recommendation turns an approved opportunity into concrete work.',
            'recommended_action' => $plan->recommended_format
                ? 'Execute as '.$plan->recommended_format.($plan->recommended_channel ? ' through '.$plan->recommended_channel : '').'.'
                : 'Review the recommended execution path.',
            'expected_impact' => $this->impactLabel($impact),
            'next_step' => in_array((string) $plan->status, [OpportunityExecutionPlan::STATUS_APPROVED, OpportunityExecutionPlan::STATUS_PLANNED], true)
                ? 'Create the content brief or move this into delivery.'
                : 'Review and approve this recommendation.',
            'status' => $this->humanStatus((string) $plan->status),
            'score' => $impact,
            'sort_score' => (float) ($plan->priority_score ?: $impact),
            'url' => route('app.opportunities.execution-recommendations.show', $plan),
            'legacy_url' => route('app.opportunity-intelligence.execution-plans.show', $plan),
        ];
    }

    private function firstRecommendedAction(mixed $actions): ?string
    {
        $actions = collect(is_array($actions) ? $actions : []);
        $first = $actions->first();

        if (is_string($first)) {
            return $first;
        }

        if (is_array($first)) {
            return (string) ($first['title'] ?? $first['action'] ?? $first['label'] ?? '');
        }

        return null;
    }

    private function statusValue(mixed $status): string
    {
        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }

    private function humanStatus(string $status): string
    {
        return str($status)->replace('_', ' ')->title()->toString();
    }

    private function impactLabel(float $score): string
    {
        if ($score >= 75) {
            return 'High';
        }

        if ($score >= 45) {
            return 'Medium';
        }

        return 'Low';
    }

    private function assertOpportunityWorkspace(Opportunity $opportunity, Workspace $workspace): void
    {
        if ((string) $opportunity->workspace_id !== (string) $workspace->id) {
            throw new AuthorizationException('Opportunity is not available for this workspace.');
        }
    }

    private function assertCandidateWorkspace(SignalDetection $candidate, Workspace $workspace): void
    {
        if ((string) $candidate->workspace_id !== (string) $workspace->id) {
            throw new AuthorizationException('Opportunity is not available for this workspace.');
        }
    }

    private function assertPlanWorkspace(OpportunityExecutionPlan $plan, Workspace $workspace): void
    {
        if ((string) $plan->workspace_id !== (string) $workspace->id) {
            throw new AuthorizationException('Execution recommendation is not available for this workspace.');
        }
    }
}
