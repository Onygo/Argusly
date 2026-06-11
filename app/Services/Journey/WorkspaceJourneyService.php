<?php

namespace App\Services\Journey;

use App\Enums\OpportunityStatus;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\LlmTrackingQuery;
use App\Models\LlmTrackingQueryRun;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\SignalDetection;
use App\Models\SignalEvent;
use App\Models\Workspace;
use App\Services\Onboarding\FirstValueActivationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

class WorkspaceJourneyService
{
    private const MINIMUM_SETUP_SCORE = 38;

    public function __construct(private readonly FirstValueActivationService $activation)
    {
    }

    /**
     * @return array{workspace:Workspace,steps:Collection<int,JourneyStep>,current_stage:?JourneyStep,next_stage:?JourneyStep,estimated_time_to_value:string,recommended_action:JourneyAction,counts:array<string,int>}
     */
    public function forWorkspace(Workspace $workspace): array
    {
        $site = $this->firstSite($workspace);
        $counts = $this->counts($workspace);
        $activation = $this->activation->forWorkspace($workspace);
        $setupCompleted = (int) $activation['score'] >= self::MINIMUM_SETUP_SCORE;

        $steps = collect([
            $this->step('setup', 1, $this->runtime('Setup'), $setupCompleted ? 'completed' : 'active', null, $this->runtime('Complete the minimum workspace setup before the intelligence journey can start.'), $this->route('app.activation.index', ['workspace' => $workspace->id])),
            $this->step('ai_visibility', 2, $this->runtime('AI Visibility'), $this->aiVisibilityStatus($setupCompleted, $counts), $this->aiVisibilityBlocker($setupCompleted), $this->runtime('Create and run buyer-facing visibility queries.'), $site ? $this->route('app.sites.llm-tracking.index', $site) : null),
            $this->step('signal_intelligence', 3, $this->runtime('Signal Intelligence'), $this->signalStatus($counts), $this->signalBlocker($counts), $this->runtime('Turn visibility evidence and source events into reviewable opportunity candidates.'), $this->route('app.signal-intelligence.index', ['workspace' => $workspace->id])),
            $this->step('opportunity_review', 4, $this->runtime('Opportunity Review'), $this->opportunityReviewStatus($counts), $this->opportunityReviewBlocker($counts), $this->runtime('Review the first opportunity candidate before Opportunity Intelligence unlocks.'), $this->route('app.opportunity-review.index', ['workspace' => $workspace->id])),
            $this->step('opportunity_intelligence', 5, $this->runtime('Opportunity Intelligence'), $this->opportunityStatus($counts), $this->opportunityBlocker($counts), $this->runtime('Promote and approve useful reviewed candidates into opportunities.'), $this->route('app.agentic-marketing.intelligence.index')),
            $this->step('execution_planning', 6, $this->runtime('Execution Planning'), $this->executionStatus($counts), $this->executionBlocker($counts), $this->runtime('Translate an approved opportunity into a practical plan.'), $this->executionRoute($workspace)),
            $this->step('briefing', 7, $this->runtime('Briefing'), $this->briefingStatus($counts), $this->briefingBlocker($counts), $this->runtime('Create the content brief from an execution plan.'), $this->briefRoute($workspace)),
            $this->step('drafting', 8, $this->runtime('Drafting'), $this->draftingStatus($counts), $this->draftingBlocker($counts), $this->runtime('Generate or write the first draft from the brief.'), $this->draftRoute($workspace)),
        ]);

        return [
            'workspace' => $workspace,
            'steps' => $steps,
            'current_stage' => $steps->first(fn (JourneyStep $step): bool => $step->status === 'active') ?? $steps->firstWhere('status', 'available'),
            'next_stage' => $steps->first(fn (JourneyStep $step): bool => in_array($step->status, ['available', 'locked'], true)),
            'estimated_time_to_value' => $this->estimatedTimeToValue($counts),
            'recommended_action' => $this->getRecommendedAction($workspace),
            'counts' => $counts,
        ];
    }

    public function getRecommendedAction(Workspace $workspace): JourneyAction
    {
        $site = $this->firstSite($workspace);
        $counts = $this->counts($workspace);
        $activation = $this->activation->forWorkspace($workspace);

        if ((int) $activation['score'] < self::MINIMUM_SETUP_SCORE) {
            $next = $activation['next_action'] ?? null;

            return new JourneyAction(
                (string) ($next['action_label'] ?? $this->runtime('Complete Setup')),
                (string) ($next['description'] ?? $this->runtime('Finish the minimum workspace setup before starting the intelligence journey.')),
                (string) ($next['action_route'] ?? $this->route('app.activation.index', ['workspace' => $workspace->id])),
                100,
            );
        }

        if ($counts['queries'] === 0) {
            return new JourneyAction(
                $this->runtime('Generate Starter Queries'),
                $this->runtime('Create the first AI Visibility prompts so Argusly has questions to check.'),
                $site ? $this->route('app.sites.llm-tracking.starter.preview', $site) : $this->route('app.activation.index', ['workspace' => $workspace->id]),
                95,
            );
        }

        if ($counts['runs'] === 0) {
            return new JourneyAction(
                $this->runtime('Run First Visibility Check'),
                $this->runtime('Run an existing AI Visibility query to produce evidence for signals.'),
                $site ? $this->route('app.sites.llm-tracking.index', $site) : null,
                90,
            );
        }

        if ($counts['detections'] === 0) {
            return new JourneyAction($this->runtime('Review Detection'), $this->runtime('Open Signal Intelligence and run or review detections from the available signals.'), $this->route('app.signal-intelligence.index', ['workspace' => $workspace->id]), 80);
        }

        if ($counts['opportunities'] === 0 && $counts['opportunity_candidates'] === 0) {
            return new JourneyAction($this->runtime('Find Opportunity Candidate'), $this->runtime('Run or review Signal Intelligence until the first candidate is detected.'), $this->route('app.signal-intelligence.index', ['workspace' => $workspace->id]), 78);
        }

        if ($counts['opportunities'] === 0) {
            return new JourneyAction($this->runtime('Review Opportunity'), $this->runtime('Review the first candidate before moving into Opportunity Intelligence.'), $this->route('app.opportunity-review.index', ['workspace' => $workspace->id]), 75);
        }

        if ($counts['approved_opportunities'] === 0) {
            return new JourneyAction($this->runtime('Approve Opportunity'), $this->runtime('Open Opportunity Intelligence and approve the opportunity when it is ready for planning.'), $this->latestOpportunityRoute($workspace), 74);
        }

        if ($counts['execution_plans'] === 0) {
            return new JourneyAction($this->runtime('Create Execution Plan'), $this->runtime('Open an approved opportunity and create the first plan.'), $this->approvedOpportunityRoute($workspace), 70);
        }

        if ($counts['briefs'] === 0) {
            return new JourneyAction($this->runtime('Create Content Brief'), $this->runtime('Open the execution plan and create a brief for the recommended content.'), $this->executionRoute($workspace), 65);
        }

        if ($counts['drafts'] === 0) {
            return new JourneyAction($this->runtime('Create First Draft'), $this->runtime('Open the brief workspace and create the first draft.'), $this->briefRoute($workspace), 60);
        }

        if ($counts['approved_drafts'] === 0) {
            return new JourneyAction($this->runtime('Review Draft'), $this->runtime('Open the draft and approve it when it is ready for publishing.'), $this->draftRoute($workspace), 55);
        }

        return new JourneyAction($this->runtime('Monitor New Signals'), $this->runtime('The first intelligence-to-content journey is complete. Keep monitoring for new signals.'), $this->draftRoute($workspace), 10);
    }

    /**
     * @return array<string,int>
     */
    private function counts(Workspace $workspace): array
    {
        $siteIds = ClientSite::query()->where('workspace_id', $workspace->id)->pluck('id');

        return [
            'queries' => LlmTrackingQuery::query()->where('workspace_id', $workspace->id)->count(),
            'runs' => LlmTrackingQueryRun::query()->whereHas('trackingQuery', fn ($query) => $query->where('workspace_id', $workspace->id))->count(),
            'signal_events' => SignalEvent::query()->where('workspace_id', $workspace->id)->count(),
            'detections' => SignalDetection::query()->where('workspace_id', $workspace->id)->count(),
            'opportunity_candidates' => SignalDetection::query()
                ->where('workspace_id', $workspace->id)
                ->open()
                ->where(function ($query): void {
                    $query->where('category', SignalDetection::CATEGORY_OPPORTUNITY_DETECTION)
                        ->orWhere('opportunity_score', '>=', 70);
                })
                ->count(),
            'opportunities' => Opportunity::query()->where('workspace_id', $workspace->id)->count(),
            'approved_opportunities' => Opportunity::query()->where('workspace_id', $workspace->id)->where('status', OpportunityStatus::APPROVED->value)->count(),
            'execution_plans' => OpportunityExecutionPlan::query()->where('workspace_id', $workspace->id)->active()->count(),
            'briefs' => Brief::query()->whereIn('client_site_id', $siteIds)->count(),
            'drafts' => Draft::query()->whereIn('client_site_id', $siteIds)->count(),
            'approved_drafts' => Draft::query()->whereIn('client_site_id', $siteIds)->where('status', Draft::STATUS_APPROVED_FOR_PUBLISHING)->count(),
        ];
    }

    private function aiVisibilityStatus(bool $setupCompleted, array $counts): string
    {
        if (! $setupCompleted) {
            return 'locked';
        }

        if ($counts['runs'] > 0) {
            return 'completed';
        }

        return $counts['queries'] > 0 ? 'active' : 'available';
    }

    private function signalStatus(array $counts): string
    {
        if ($counts['opportunity_candidates'] > 0 || $counts['opportunities'] > 0) {
            return 'completed';
        }

        if ($counts['detections'] > 0 || $counts['signal_events'] > 0) {
            return 'active';
        }

        return $counts['runs'] > 0 ? 'available' : 'locked';
    }

    private function opportunityReviewStatus(array $counts): string
    {
        if ($counts['opportunities'] > 0) {
            return 'completed';
        }

        return $counts['opportunity_candidates'] > 0 ? 'active' : 'locked';
    }

    private function opportunityStatus(array $counts): string
    {
        if ($counts['execution_plans'] > 0) {
            return 'completed';
        }

        if ($counts['opportunities'] > 0) {
            return 'active';
        }

        return 'locked';
    }

    private function executionStatus(array $counts): string
    {
        if ($counts['execution_plans'] > 0) {
            return 'completed';
        }

        return $counts['approved_opportunities'] > 0 ? 'active' : 'locked';
    }

    private function briefingStatus(array $counts): string
    {
        if ($counts['briefs'] > 0) {
            return 'completed';
        }

        return $counts['execution_plans'] > 0 ? 'active' : 'locked';
    }

    private function draftingStatus(array $counts): string
    {
        if ($counts['approved_drafts'] > 0) {
            return 'completed';
        }

        return $counts['drafts'] > 0 || $counts['briefs'] > 0 ? 'active' : 'locked';
    }

    private function step(string $key, int $number, string $label, string $status, ?string $blockingMessage, string $tooltip, ?string $route): JourneyStep
    {
        return new JourneyStep($key, $number, $label, $status, $blockingMessage ?: $tooltip, $route, $blockingMessage);
    }

    private function aiVisibilityBlocker(bool $setupCompleted): ?string
    {
        return $setupCompleted ? null : $this->runtime('Complete the minimum setup before AI Visibility can start producing useful evidence.');
    }

    private function signalBlocker(array $counts): ?string
    {
        return $counts['runs'] > 0 ? null : $this->runtime('Run an AI Visibility check before signals can be reviewed.');
    }

    private function opportunityBlocker(array $counts): ?string
    {
        return $counts['opportunities'] > 0 ? null : $this->runtime('Complete Opportunity Review before Opportunity Intelligence unlocks.');
    }

    private function opportunityReviewBlocker(array $counts): ?string
    {
        return $counts['opportunity_candidates'] > 0 ? null : $this->runtime('Detect the first opportunity candidate in Signal Intelligence before review unlocks.');
    }

    private function executionBlocker(array $counts): ?string
    {
        return $counts['approved_opportunities'] > 0 ? null : $this->runtime('Approve an Opportunity before planning becomes available.');
    }

    private function briefingBlocker(array $counts): ?string
    {
        return $counts['execution_plans'] > 0 ? null : $this->runtime('Create an Execution Plan before generating a content brief.');
    }

    private function draftingBlocker(array $counts): ?string
    {
        return $counts['briefs'] > 0 ? null : $this->runtime('Create a content brief before drafting content.');
    }

    private function estimatedTimeToValue(array $counts): string
    {
        if ($counts['approved_drafts'] > 0) {
            return 'Complete';
        }

        if ($counts['drafts'] > 0) {
            return '5-10 min';
        }

        if ($counts['runs'] > 0 || $counts['detections'] > 0) {
            return '10-20 min';
        }

        if ($counts['queries'] > 0) {
            return '1-3 min';
        }

        return '5-10 min';
    }

    private function firstSite(Workspace $workspace): ?ClientSite
    {
        return ClientSite::query()->where('workspace_id', $workspace->id)->orderBy('created_at')->first();
    }

    private function approvedOpportunityRoute(Workspace $workspace): ?string
    {
        $opportunity = Opportunity::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', OpportunityStatus::APPROVED->value)
            ->latest('created_at')
            ->first();

        return $opportunity ? $this->route('app.opportunity-intelligence.opportunities.show', $opportunity) : null;
    }

    private function latestOpportunityRoute(Workspace $workspace): ?string
    {
        $opportunity = Opportunity::query()
            ->where('workspace_id', $workspace->id)
            ->latest('created_at')
            ->first();

        return $opportunity ? $this->route('app.opportunity-intelligence.opportunities.show', $opportunity) : $this->route('app.agentic-marketing.intelligence.index');
    }

    private function executionRoute(Workspace $workspace): ?string
    {
        $plan = OpportunityExecutionPlan::query()->where('workspace_id', $workspace->id)->active()->latest('created_at')->first();

        return $plan ? $this->route('app.opportunity-intelligence.execution-plans.show', $plan) : $this->approvedOpportunityRoute($workspace);
    }

    private function briefRoute(Workspace $workspace): ?string
    {
        $siteIds = ClientSite::query()->where('workspace_id', $workspace->id)->pluck('id');
        $brief = Brief::query()->whereIn('client_site_id', $siteIds)->latest('created_at')->first();

        return $brief ? $this->route('app.content.workspace.show', $brief) : $this->executionRoute($workspace);
    }

    private function draftRoute(Workspace $workspace): ?string
    {
        $siteIds = ClientSite::query()->where('workspace_id', $workspace->id)->pluck('id');
        $draft = Draft::query()->whereIn('client_site_id', $siteIds)->latest('created_at')->first();

        return $draft ? $this->route('app.drafts.show', $draft) : $this->briefRoute($workspace);
    }

    private function route(string $name, mixed $parameters = []): ?string
    {
        return Route::has($name) ? route($name, $parameters) : null;
    }

    private function runtime(string $key): string
    {
        $lines = trans('app.runtime');

        return is_array($lines) ? (string) ($lines[$key] ?? $key) : $key;
    }
}
