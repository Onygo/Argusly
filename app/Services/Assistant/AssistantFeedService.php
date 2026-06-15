<?php

namespace App\Services\Assistant;

use App\Models\AgenticActionRun;
use App\Models\AssistantFeedItem;
use App\Models\Content;
use App\Models\LearningRecommendation;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\SignalDetection;
use App\Models\Workspace;
use App\Services\GrowthAutopilot\GrowthAutopilotQueueBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AssistantFeedService
{
    public function __construct(
        private readonly AssistantMessageMapper $mapper,
        private readonly AssistantNotificationStrategy $notificationStrategy,
        private readonly GrowthAutopilotQueueBuilder $autopilotQueueBuilder,
    ) {
    }

    public function upsertFromSource(Model $source, bool $notify = true): AssistantFeedItem
    {
        $payload = $this->mapper->map($source);
        $signature = (string) $payload['source_signature'];

        $item = AssistantFeedItem::query()->updateOrCreate(
            ['source_signature' => $signature],
            $payload
        );

        if ($notify) {
            $this->notificationStrategy->notifyIfNeeded($item);
        }

        return $item;
    }

    /**
     * @return Collection<int,AssistantFeedItem>
     */
    public function forWorkspace(Workspace $workspace, int $limit = 12): Collection
    {
        return AssistantFeedItem::query()
            ->forWorkspace($workspace)
            ->active()
            ->visible()
            ->orderByDesc('priority_score')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int,AssistantFeedItem>
     */
    public function hydrateWorkspace(Workspace $workspace, int $perSource = 5, bool $notify = true): Collection
    {
        $queueItems = $this->autopilotQueueBuilder->build($workspace, max(6, $perSource * 2));

        if ($queueItems->isNotEmpty()) {
            return $queueItems
                ->map(fn (Model $source): AssistantFeedItem => $this->upsertFromSource($source, $notify))
                ->sortByDesc('priority_score')
                ->values();
        }

        $sources = collect()
            ->merge($this->opportunities($workspace, $perSource))
            ->merge($this->learningRecommendations($workspace, $perSource))
            ->merge($this->approvalRuns($workspace, $perSource))
            ->merge($this->executionPlans($workspace, $perSource))
            ->merge($this->signalDetections($workspace, $perSource))
            ->merge($this->contentActions($workspace, $perSource));

        return $sources
            ->map(fn (Model $source): AssistantFeedItem => $this->upsertFromSource($source, $notify))
            ->sortByDesc('priority_score')
            ->values();
    }

    /**
     * @return Collection<int,Opportunity>
     */
    private function opportunities(Workspace $workspace, int $limit): Collection
    {
        return Opportunity::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', ['open', 'reviewing', 'approved', 'planned'])
            ->orderByDesc('priority_score')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int,LearningRecommendation>
     */
    private function learningRecommendations(Workspace $workspace, int $limit): Collection
    {
        return LearningRecommendation::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('actioned_at')
            ->orderByDesc('priority_score')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int,AgenticActionRun>
     */
    private function approvalRuns(Workspace $workspace, int $limit): Collection
    {
        return AgenticActionRun::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', [AgenticActionRun::STATUS_PROPOSED, AgenticActionRun::STATUS_APPROVAL_REQUIRED, AgenticActionRun::STATUS_BLOCKED])
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int,OpportunityExecutionPlan>
     */
    private function executionPlans(Workspace $workspace, int $limit): Collection
    {
        return OpportunityExecutionPlan::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', [OpportunityExecutionPlan::STATUS_DRAFT, OpportunityExecutionPlan::STATUS_REVIEWING, OpportunityExecutionPlan::STATUS_APPROVED])
            ->orderByDesc('priority_score')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int,SignalDetection>
     */
    private function signalDetections(Workspace $workspace, int $limit): Collection
    {
        return SignalDetection::query()
            ->where('workspace_id', $workspace->id)
            ->open()
            ->orderByDesc('priority_score')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int,Content>
     */
    private function contentActions(Workspace $workspace, int $limit): Collection
    {
        return Content::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotIn('status', ['published', 'archived'])
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }
}
