<?php

namespace App\Services\Dashboard;

use App\Enums\OpportunityStatus;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\SignalDetection;
use App\Models\Workspace;
use App\Services\Journey\WorkspaceJourneyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DashboardActionFirstService
{
    public function __construct(private readonly WorkspaceJourneyService $journey)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function forWorkspace(?Workspace $workspace): array
    {
        if (! $workspace) {
            return $this->emptyState();
        }

        $journey = $this->journey->forWorkspace($workspace);
        $growthOpportunities = $this->growthOpportunities($workspace)->get();
        $openRisks = $this->openRisks($workspace)->get();
        $openOpportunities = $this->openOpportunities($workspace)->get();
        $executionPlans = $this->executionPlans($workspace)->get();

        return [
            'recommended_action' => $this->recommendedAction($workspace, $journey, $growthOpportunities, $openRisks, $openOpportunities, $executionPlans),
            'open_opportunities' => [
                'count' => $openOpportunities->count(),
                'high_priority_count' => $openOpportunities->where('priority_score', '>=', 75)->count(),
                'items' => $openOpportunities->take(5)->values(),
            ],
            'risk_summary' => [
                'count' => $openRisks->count(),
                'high_priority_count' => $openRisks->where('priority_score', '>=', 75)->count(),
                'items' => $openRisks->take(4)->values(),
            ],
            'journey_step' => [
                'current_stage' => $journey['current_stage']?->label ?? __('app.dashboard_action_first.monitoring'),
                'next_stage' => $journey['next_stage']?->label ?? __('app.dashboard_action_first.monitoring'),
                'progress' => $this->journeyProgress($journey['steps']),
                'primary_cta_label' => $journey['recommended_action']->title,
                'primary_cta_route' => $journey['recommended_action']->route,
            ],
            'intelligence_feed' => $this->feed($growthOpportunities, $openRisks, $openOpportunities),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function recommendedAction(Workspace $workspace, array $journey, Collection $growthOpportunities, Collection $openRisks, Collection $openOpportunities, Collection $executionPlans): array
    {
        if ($growthOpportunities->isNotEmpty()) {
            $count = $growthOpportunities->count();

            return [
                'eyebrow' => __('app.dashboard_action_first.recommended_action'),
                'title' => __('app.dashboard_action_first.review_growth_opportunities'),
                'what_happened' => trans_choice('app.dashboard_action_first.growth_opportunities_found', $count, ['count' => $count]),
                'why_it_matters' => __('app.dashboard_action_first.growth_opportunities_matter'),
                'recommended_action' => __('app.dashboard_action_first.review_detected_opportunities'),
                'expected_outcome' => __('app.dashboard_action_first.growth_opportunities_outcome'),
                'estimated_impact' => $this->impactLabel($growthOpportunities->max('opportunity_score')),
                'primary_cta_label' => __('app.dashboard_action_first.review_opportunities'),
                'primary_cta_route' => route('app.opportunity-review.index', ['workspace' => $workspace->id]),
                'secondary_cta_label' => __('app.dashboard_action_first.view_market_signals'),
                'secondary_cta_route' => route('app.signal-intelligence.index', ['workspace' => $workspace->id]),
            ];
        }

        if ($openRisks->isNotEmpty()) {
            $count = $openRisks->count();

            return [
                'eyebrow' => __('app.dashboard_action_first.recommended_action'),
                'title' => __('app.dashboard_action_first.review_active_risks'),
                'what_happened' => trans_choice('app.dashboard_action_first.active_risks_found', $count, ['count' => $count]),
                'why_it_matters' => __('app.dashboard_action_first.active_risks_matter'),
                'recommended_action' => __('app.dashboard_action_first.review_risks'),
                'expected_outcome' => __('app.dashboard_action_first.active_risks_outcome'),
                'estimated_impact' => $this->impactLabel($openRisks->max('risk_score') ?: $openRisks->max('priority_score')),
                'primary_cta_label' => __('app.dashboard_action_first.review_risks_cta'),
                'primary_cta_route' => route('app.signal-intelligence.index', ['workspace' => $workspace->id, 'category' => SignalDetection::CATEGORY_RISK_DETECTION]),
                'secondary_cta_label' => __('app.dashboard_action_first.view_market_signals'),
                'secondary_cta_route' => route('app.signal-intelligence.index', ['workspace' => $workspace->id]),
            ];
        }

        if ($openOpportunities->isNotEmpty()) {
            $opportunity = $openOpportunities->first();

            return [
                'eyebrow' => __('app.dashboard_action_first.recommended_action'),
                'title' => __('app.dashboard_action_first.move_opportunity_forward'),
                'what_happened' => trans_choice('app.dashboard_action_first.open_opportunities_found', $openOpportunities->count(), ['count' => $openOpportunities->count()]),
                'why_it_matters' => __('app.dashboard_action_first.open_opportunities_matter'),
                'recommended_action' => __('app.dashboard_action_first.approve_or_plan_opportunity'),
                'expected_outcome' => __('app.dashboard_action_first.open_opportunities_outcome'),
                'estimated_impact' => $this->impactLabel($openOpportunities->max('impact_score') ?: $openOpportunities->max('priority_score')),
                'primary_cta_label' => __('app.dashboard_action_first.open_opportunity'),
                'primary_cta_route' => $opportunity ? route('app.opportunity-intelligence.opportunities.show', $opportunity) : route('app.agentic-marketing.intelligence.index'),
                'secondary_cta_label' => __('app.dashboard_action_first.view_all_opportunities'),
                'secondary_cta_route' => route('app.agentic-marketing.intelligence.index', ['workspace' => $workspace->id]),
            ];
        }

        if ($executionPlans->isNotEmpty()) {
            $plan = $executionPlans->first();

            return [
                'eyebrow' => __('app.dashboard_action_first.recommended_action'),
                'title' => __('app.dashboard_action_first.prepare_execution'),
                'what_happened' => trans_choice('app.dashboard_action_first.execution_plans_ready', $executionPlans->count(), ['count' => $executionPlans->count()]),
                'why_it_matters' => __('app.dashboard_action_first.execution_plans_matter'),
                'recommended_action' => __('app.dashboard_action_first.create_brief_from_plan'),
                'expected_outcome' => __('app.dashboard_action_first.execution_plans_outcome'),
                'estimated_impact' => $this->impactLabel($executionPlans->max('expected_impact') ?: $executionPlans->max('priority_score')),
                'primary_cta_label' => __('app.dashboard_action_first.open_execution_plan'),
                'primary_cta_route' => $plan ? route('app.opportunity-intelligence.execution-plans.show', $plan) : null,
                'secondary_cta_label' => __('app.dashboard_action_first.view_all_opportunities'),
                'secondary_cta_route' => route('app.agentic-marketing.intelligence.index', ['workspace' => $workspace->id]),
            ];
        }

        return [
            'eyebrow' => __('app.dashboard_action_first.recommended_action'),
            'title' => __('app.dashboard_action_first.continue_monitoring'),
            'what_happened' => __('app.dashboard_action_first.no_urgent_changes'),
            'why_it_matters' => __('app.dashboard_action_first.monitoring_matter'),
            'recommended_action' => __('app.dashboard_action_first.continue_market_monitoring'),
            'expected_outcome' => __('app.dashboard_action_first.monitoring_outcome'),
            'estimated_impact' => __('app.dashboard_action_first.low'),
            'primary_cta_label' => __('app.dashboard_action_first.continue_monitoring'),
            'primary_cta_route' => $journey['recommended_action']->route ?? route('app.signal-intelligence.index', ['workspace' => $workspace->id]),
            'secondary_cta_label' => null,
            'secondary_cta_route' => null,
        ];
    }

    private function growthOpportunities(Workspace $workspace): Builder
    {
        return SignalDetection::query()
            ->where('workspace_id', $workspace->id)
            ->open()
            ->where(function (Builder $query): void {
                $query->where('category', SignalDetection::CATEGORY_OPPORTUNITY_DETECTION)
                    ->orWhere('opportunity_score', '>=', 70);
            })
            ->orderByDesc('opportunity_score')
            ->orderByDesc('last_seen_at');
    }

    private function openRisks(Workspace $workspace): Builder
    {
        return SignalDetection::query()
            ->where('workspace_id', $workspace->id)
            ->open()
            ->where('category', SignalDetection::CATEGORY_RISK_DETECTION)
            ->orderByDesc('risk_score')
            ->orderByDesc('priority_score');
    }

    private function openOpportunities(Workspace $workspace): Builder
    {
        return Opportunity::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', [
                OpportunityStatus::OPEN->value,
                OpportunityStatus::REVIEWING->value,
                OpportunityStatus::APPROVED->value,
                OpportunityStatus::PLANNED->value,
            ])
            ->orderByDesc('priority_score')
            ->latest('last_seen_at');
    }

    private function executionPlans(Workspace $workspace): Builder
    {
        return OpportunityExecutionPlan::query()
            ->where('workspace_id', $workspace->id)
            ->active()
            ->orderByDesc('expected_impact')
            ->latest('created_at');
    }

    private function impactLabel(mixed $score): string
    {
        $score = (float) $score;

        if ($score >= 75) {
            return __('app.dashboard_action_first.high');
        }

        if ($score >= 45) {
            return __('app.dashboard_action_first.medium');
        }

        return __('app.dashboard_action_first.low');
    }

    private function journeyProgress(Collection $steps): int
    {
        return (int) round(($steps->where('status', 'completed')->count() / max(1, $steps->count())) * 100);
    }

    private function feed(Collection $growthOpportunities, Collection $openRisks, Collection $openOpportunities): Collection
    {
        return collect()
            ->merge($growthOpportunities->take(4)->map(fn (SignalDetection $item): array => [
                'type' => __('app.dashboard_action_first.growth_opportunity'),
                'title' => $item->title,
                'description' => $item->summary ?: $item->primary_topic,
                'impact' => $this->impactLabel($item->opportunity_score),
                'route' => route('app.signal-intelligence.detections.show', $item),
                'seen_at' => $item->last_seen_at ?: $item->created_at,
            ]))
            ->merge($openRisks->take(3)->map(fn (SignalDetection $item): array => [
                'type' => __('app.dashboard_action_first.active_risk'),
                'title' => $item->title,
                'description' => $item->summary ?: $item->primary_topic,
                'impact' => $this->impactLabel($item->risk_score ?: $item->priority_score),
                'route' => route('app.signal-intelligence.detections.show', $item),
                'seen_at' => $item->last_seen_at ?: $item->created_at,
            ]))
            ->merge($openOpportunities->take(3)->map(fn (Opportunity $item): array => [
                'type' => __('app.dashboard_action_first.open_opportunity'),
                'title' => $item->title,
                'description' => $item->summary ?: $item->topic,
                'impact' => $this->impactLabel($item->impact_score ?: $item->priority_score),
                'route' => route('app.opportunity-intelligence.opportunities.show', $item),
                'seen_at' => $item->last_seen_at ?: $item->created_at,
            ]))
            ->sortByDesc('seen_at')
            ->take(8)
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyState(): array
    {
        return [
            'recommended_action' => [
                'eyebrow' => __('app.dashboard_action_first.recommended_action'),
                'title' => __('app.dashboard_action_first.complete_setup'),
                'what_happened' => __('app.dashboard_action_first.no_workspace_yet'),
                'why_it_matters' => __('app.dashboard_action_first.setup_matter'),
                'recommended_action' => __('app.dashboard_action_first.open_activation'),
                'expected_outcome' => __('app.dashboard_action_first.setup_outcome'),
                'estimated_impact' => __('app.dashboard_action_first.medium'),
                'primary_cta_label' => __('app.dashboard_action_first.open_activation'),
                'primary_cta_route' => route('app.activation.index'),
                'secondary_cta_label' => null,
                'secondary_cta_route' => null,
            ],
            'open_opportunities' => ['count' => 0, 'high_priority_count' => 0, 'items' => collect()],
            'risk_summary' => ['count' => 0, 'high_priority_count' => 0, 'items' => collect()],
            'journey_step' => ['current_stage' => __('app.dashboard_action_first.setup'), 'next_stage' => __('app.dashboard_action_first.market_intelligence'), 'progress' => 0, 'primary_cta_label' => __('app.dashboard_action_first.open_activation'), 'primary_cta_route' => route('app.activation.index')],
            'intelligence_feed' => collect(),
        ];
    }
}
