<?php

namespace App\Services\GrowthAutopilot;

use App\Models\CompetitorContentOpportunity;
use App\Models\CompetitorTopicSignal;
use App\Models\ContentOpportunity;
use App\Models\GrowthAutopilotQueueItem;
use App\Models\RecommendedAction;
use App\Models\Workspace;
use App\Services\RecommendedActions\RecommendedActionEngine;
use Illuminate\Support\Collection;

class GrowthAutopilotQueueBuilder
{
    public function __construct(
        private readonly RecommendedActionEngine $recommendedActions,
        private readonly GrowthAutopilotPrioritizationEngine $prioritization,
    ) {
    }

    /**
     * @return Collection<int,GrowthAutopilotQueueItem>
     */
    public function build(Workspace $workspace, int $limit = 12): Collection
    {
        $actions = $this->recommendedActions
            ->hydrateWorkspace($workspace, 5)
            ->merge($this->competitorActions($workspace, 4))
            ->merge($this->contentOpportunityActions($workspace, 4))
            ->unique('source_signature')
            ->values();

        return $actions
            ->map(fn (RecommendedAction $action): GrowthAutopilotQueueItem => $this->upsertFromAction($action))
            ->sortByDesc('priority_score')
            ->take($limit)
            ->values();
    }

    public function approve(GrowthAutopilotQueueItem $item): GrowthAutopilotQueueItem
    {
        $item->approve();

        if ($item->recommendedAction) {
            $item->recommendedAction->forceFill([
                'status' => RecommendedAction::STATUS_APPROVED,
                'approved_at' => now(),
            ])->save();
        }

        return $item->refresh();
    }

    public function dismiss(GrowthAutopilotQueueItem $item): GrowthAutopilotQueueItem
    {
        $item->dismiss();

        if ($item->recommendedAction) {
            $item->recommendedAction->forceFill([
                'status' => RecommendedAction::STATUS_DISMISSED,
                'dismissed_at' => now(),
            ])->save();
        }

        return $item->refresh();
    }

    private function upsertFromAction(RecommendedAction $action): GrowthAutopilotQueueItem
    {
        $assets = $this->preparedAssets($action);
        $approvalRequired = $this->approvalRequired($action);
        $priority = $this->prioritization->score(
            (int) $action->priority_score,
            (int) $action->expected_impact_score,
            (int) $action->confidence_score,
            [
                'approval_required' => $approvalRequired,
                'prepared_assets' => $assets !== [],
                'competitor_pressure' => $action->source_group === RecommendedAction::SOURCE_AI_VISIBILITY
                    || str_contains((string) $action->source_group, 'competitor'),
            ],
        );

        return GrowthAutopilotQueueItem::query()->updateOrCreate(
            ['source_signature' => $this->signature($action)],
            [
                'workspace_id' => $action->workspace_id,
                'organization_id' => $action->organization_id,
                'recommended_action_id' => $action->id,
                'source_type' => $action->source_type,
                'source_id' => $action->source_id,
                'source_group' => $action->source_group,
                'status' => $approvalRequired ? GrowthAutopilotQueueItem::STATUS_NEEDS_APPROVAL : GrowthAutopilotQueueItem::STATUS_PREPARED,
                'opportunity' => $action->title,
                'recommended_action' => (string) data_get($action->metadata, 'recommended_action', $action->expected_outcome),
                'expected_impact' => $action->expected_outcome,
                'expected_impact_score' => $action->expected_impact_score,
                'confidence_score' => $action->confidence_score,
                'priority_score' => $priority,
                'priority_label' => $this->prioritization->label($priority),
                'approval_requirement' => $action->what_requires_approval,
                'approval_required' => $approvalRequired,
                'prepared_assets' => $assets,
                'approval_cta_label' => $action->primary_cta_label ?: 'Review action',
                'approval_cta_url' => $action->primary_cta_url,
                'metadata' => [
                    'recommended_action_priority' => $action->priority_score,
                    'recommended_action_id' => (string) $action->id,
                    'what_argusly_will_do' => $action->what_argusly_will_do,
                    'why_this_matters' => $action->why_this_matters,
                ],
                'queued_at' => now(),
            ]
        );
    }

    private function signature(RecommendedAction $action): string
    {
        return sha1(implode('|', [
            'growth-autopilot',
            $action->workspace_id,
            $action->source_signature,
        ]));
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function preparedAssets(RecommendedAction $action): array
    {
        $assets = [
            [
                'type' => 'action_brief',
                'label' => 'Action brief',
                'status' => 'prepared',
            ],
            [
                'type' => 'impact_summary',
                'label' => 'Impact summary',
                'status' => 'prepared',
            ],
        ];

        if (in_array($action->source_group, [RecommendedAction::SOURCE_OPPORTUNITY, RecommendedAction::SOURCE_AI_VISIBILITY], true)) {
            $assets[] = ['type' => 'execution_path', 'label' => 'Execution path', 'status' => 'prepared'];
        }

        if ($action->source_group === RecommendedAction::SOURCE_CONTENT_INTELLIGENCE) {
            $assets[] = ['type' => 'content_change_plan', 'label' => 'Content change plan', 'status' => 'prepared'];
        }

        if ($action->source_group === RecommendedAction::SOURCE_LEARNING) {
            $assets[] = ['type' => 'learning_evidence', 'label' => 'Learning evidence', 'status' => 'prepared'];
        }

        return $assets;
    }

    private function approvalRequired(RecommendedAction $action): bool
    {
        $approval = strtolower((string) $action->what_requires_approval);

        return str_contains($approval, 'approve')
            || str_contains($approval, 'choose')
            || str_contains($approval, 'decide')
            || str_contains($approval, 'review');
    }

    /**
     * @return Collection<int,RecommendedAction>
     */
    private function competitorActions(Workspace $workspace, int $limit): Collection
    {
        $contentOpportunities = CompetitorContentOpportunity::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotIn('status', ['dismissed', 'archived', 'completed'])
            ->orderByDesc('priority_score')
            ->limit($limit)
            ->get()
            ->map(fn (CompetitorContentOpportunity $opportunity): RecommendedAction => $this->recommendedActions->upsertFromSource($opportunity));

        $topicSignals = CompetitorTopicSignal::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('opportunity_score')
            ->limit($limit)
            ->get()
            ->map(fn (CompetitorTopicSignal $signal): RecommendedAction => $this->recommendedActions->upsertFromSource($signal));

        return $contentOpportunities->merge($topicSignals);
    }

    /**
     * @return Collection<int,RecommendedAction>
     */
    private function contentOpportunityActions(Workspace $workspace, int $limit): Collection
    {
        return ContentOpportunity::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', ContentOpportunity::STATUS_OPEN)
            ->orderByDesc('priority_score')
            ->limit($limit)
            ->get()
            ->map(fn (ContentOpportunity $opportunity): RecommendedAction => $this->recommendedActions->upsertFromSource($opportunity));
    }
}
