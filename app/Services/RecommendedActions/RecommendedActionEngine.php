<?php

namespace App\Services\RecommendedActions;

use App\Models\AgenticActionRun;
use App\Models\Campaign;
use App\Models\ContentRecommendation;
use App\Models\LearningRecommendation;
use App\Models\Opportunity;
use App\Models\OpportunityExecutionPlan;
use App\Models\RecommendedAction;
use App\Models\SignalDetection;
use App\Models\SocialPostVariant;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class RecommendedActionEngine
{
    public function __construct(private readonly RecommendedActionMapper $mapper)
    {
    }

    public function upsertFromSource(Model $source): RecommendedAction
    {
        $payload = $this->mapper->map($source);

        return RecommendedAction::query()->updateOrCreate(
            ['source_signature' => $payload['source_signature']],
            $payload
        );
    }

    /**
     * @return Collection<int,RecommendedAction>
     */
    public function forWorkspace(Workspace $workspace, int $limit = 20): Collection
    {
        return RecommendedAction::query()
            ->forWorkspace($workspace)
            ->visible()
            ->open()
            ->orderByDesc('priority_score')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int,RecommendedAction>
     */
    public function hydrateWorkspace(Workspace $workspace, int $perSource = 6): Collection
    {
        $sources = collect()
            ->merge($this->opportunities($workspace, $perSource))
            ->merge($this->learning($workspace, $perSource))
            ->merge($this->aiVisibility($workspace, $perSource))
            ->merge($this->agenticActions($workspace, $perSource))
            ->merge($this->campaigns($workspace, $perSource))
            ->merge($this->contentIntelligence($workspace, $perSource))
            ->merge($this->executionPlans($workspace, $perSource))
            ->merge($this->distribution($workspace, $perSource));

        return $sources
            ->filter(fn (Model $source): bool => $source->exists)
            ->map(fn (Model $source): RecommendedAction => $this->upsertFromSource($source))
            ->sortByDesc('priority_score')
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    public function dashboardSummary(Workspace $workspace): array
    {
        $actions = $this->hydrateWorkspace($workspace, 4)->take(5)->values();

        return [
            'count' => $actions->count(),
            'high_priority_count' => $actions->whereIn('priority_label', ['critical', 'high'])->count(),
            'approval_required_count' => $actions->filter(fn (RecommendedAction $action): bool => str_contains(strtolower((string) $action->what_requires_approval), 'approve'))->count(),
            'items' => $actions,
        ];
    }

    private function opportunities(Workspace $workspace, int $limit): Collection
    {
        return Opportunity::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', ['open', 'reviewing', 'approved', 'planned'])
            ->orderByDesc('priority_score')
            ->limit($limit)
            ->get();
    }

    private function learning(Workspace $workspace, int $limit): Collection
    {
        return LearningRecommendation::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('actioned_at')
            ->orderByDesc('priority_score')
            ->limit($limit)
            ->get();
    }

    private function aiVisibility(Workspace $workspace, int $limit): Collection
    {
        return SignalDetection::query()
            ->where('workspace_id', $workspace->id)
            ->open()
            ->orderByDesc('priority_score')
            ->limit($limit)
            ->get();
    }

    private function agenticActions(Workspace $workspace, int $limit): Collection
    {
        return AgenticActionRun::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', [AgenticActionRun::STATUS_PROPOSED, AgenticActionRun::STATUS_APPROVAL_REQUIRED, AgenticActionRun::STATUS_BLOCKED])
            ->latest()
            ->limit($limit)
            ->get();
    }

    private function campaigns(Workspace $workspace, int $limit): Collection
    {
        return Campaign::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('approved_at')
            ->latest()
            ->limit($limit)
            ->get();
    }

    private function contentIntelligence(Workspace $workspace, int $limit): Collection
    {
        return ContentRecommendation::query()
            ->whereHas('content', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->latest()
            ->limit($limit)
            ->get();
    }

    private function executionPlans(Workspace $workspace, int $limit): Collection
    {
        return OpportunityExecutionPlan::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', [OpportunityExecutionPlan::STATUS_DRAFT, OpportunityExecutionPlan::STATUS_REVIEWING, OpportunityExecutionPlan::STATUS_APPROVED])
            ->orderByDesc('priority_score')
            ->limit($limit)
            ->get();
    }

    private function distribution(Workspace $workspace, int $limit): Collection
    {
        return SocialPostVariant::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('approved_at')
            ->latest()
            ->limit($limit)
            ->get();
    }
}
