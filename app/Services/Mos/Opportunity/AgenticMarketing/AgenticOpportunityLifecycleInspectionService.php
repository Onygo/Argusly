<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingExecutionPipeline;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Builder;

class AgenticOpportunityLifecycleInspectionService
{
    public function __construct(
        private readonly AgenticOpportunityLifecycleMap $map,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function inspect(AgenticMarketingOpportunity $opportunity, ?Opportunity $canonical = null): array
    {
        $opportunity->loadMissing('objective');

        $canonical = $canonical
            ? $this->validateProvidedCanonical($opportunity, $canonical, $bridgeBlockedReasons)
            : $this->safeLinkedCanonicalOpportunity($opportunity, $bridgeBlockedReasons);

        $mapped = $this->map->map($opportunity->status);
        $candidateStatuses = $mapped['candidate_canonical_status'];
        $canonicalStatus = $this->statusValue($canonical?->status);
        $actionsByStatus = $this->groupByStatus(AgenticMarketingAction::query()->where('opportunity_id', $opportunity->id));
        $pipelinesByStatus = $this->groupByStatus(AgenticMarketingExecutionPipeline::query()->where('opportunity_id', $opportunity->id));
        $actionRunsByStatus = $this->groupByStatus($this->actionRunQuery($opportunity));

        $completedHasCompletedActions = $this->hasAnyStatus($actionsByStatus, ['completed']);
        $completedHasCompletedPipelines = $this->hasAnyStatus($pipelinesByStatus, ['completed']);
        $dismissedHasOpenOrRunningActions = $this->hasAnyStatus($actionsByStatus, [
            AgenticMarketingAction::STATUS_PROPOSED,
            AgenticMarketingAction::STATUS_APPROVED,
            AgenticMarketingAction::STATUS_RUNNING,
        ]);
        $openAlreadyHasCompletedExecution = $this->hasAnyStatus($actionsByStatus, ['completed'])
            || $this->hasAnyStatus($pipelinesByStatus, ['completed'])
            || $this->hasAnyStatus($actionRunsByStatus, ['completed']);

        $statusConflict = $canonicalStatus !== null
            && $candidateStatuses !== []
            && ! in_array($canonicalStatus, $candidateStatuses, true);
        $statusAlignment = $this->statusAlignment($canonical, $canonicalStatus, $candidateStatuses, $mapped, $statusConflict);
        $ambiguousReasons = $this->lifecycleAmbiguityReasons(
            (string) $mapped['legacy_status'],
            $candidateStatuses,
            $completedHasCompletedActions,
            $completedHasCompletedPipelines,
            $dismissedHasOpenOrRunningActions,
            $openAlreadyHasCompletedExecution,
            $actionsByStatus,
            $pipelinesByStatus,
        );
        $blockedReasons = array_values(array_unique(array_filter(array_merge(
            $bridgeBlockedReasons,
            [(string) $mapped['blocked_reason']],
            (bool) $mapped['unmapped'] ? ['unmapped_agentic_lifecycle_status'] : [],
            $statusConflict ? ['canonical_status_conflicts_with_candidate_mapping'] : [],
            $ambiguousReasons,
        ))));

        return [
            'legacy_agentic_opportunity_id' => (string) $opportunity->id,
            'canonical_opportunity_id' => $canonical?->id ? (string) $canonical->id : null,
            'legacy_status' => (string) $mapped['legacy_status'],
            'canonical_status' => $canonicalStatus,
            'candidate_mapped_canonical_status' => $candidateStatuses,
            'status_alignment' => $statusAlignment,
            'status_conflict' => $statusConflict,
            'existing_action_counts_by_status' => $actionsByStatus,
            'existing_pipeline_counts_by_status' => $pipelinesByStatus,
            'existing_action_runs_by_status' => $actionRunsByStatus,
            'completed_has_completed_actions' => $completedHasCompletedActions,
            'completed_has_completed_pipelines' => $completedHasCompletedPipelines,
            'dismissed_still_has_open_or_running_actions' => $dismissedHasOpenOrRunningActions,
            'open_already_has_completed_execution' => $openAlreadyHasCompletedExecution,
            'lifecycle_status_ambiguous' => $ambiguousReasons !== [],
            'blocked_reasons' => $blockedReasons,
            'blocked' => $blockedReasons !== [],
            'recommended_future_migration_path' => $this->recommendedFutureMigrationPath($canonical, $mapped, $statusConflict, $ambiguousReasons, $bridgeBlockedReasons),
        ];
    }

    private function validateProvidedCanonical(AgenticMarketingOpportunity $opportunity, Opportunity $canonical, ?array &$blockedReasons): ?Opportunity
    {
        $blockedReasons = [];

        if ((string) $canonical->agentic_marketing_opportunity_id !== (string) $opportunity->id) {
            $blockedReasons[] = 'provided_canonical_bridge_mismatch';

            return null;
        }

        return $this->bridgeIsWorkspaceSafe($opportunity, $canonical, $blockedReasons) ? $canonical : null;
    }

    private function safeLinkedCanonicalOpportunity(AgenticMarketingOpportunity $opportunity, ?array &$blockedReasons): ?Opportunity
    {
        $blockedReasons = [];
        $linked = Opportunity::query()
            ->where('agentic_marketing_opportunity_id', $opportunity->id)
            ->orderBy('id')
            ->get();

        if ($linked->count() > 1) {
            $blockedReasons[] = 'multiple_canonical_opportunities_linked_to_agentic_row';

            return null;
        }

        $canonical = $linked->first();
        if (! $canonical) {
            $blockedReasons[] = 'missing_safe_canonical_bridge';

            return null;
        }

        return $this->bridgeIsWorkspaceSafe($opportunity, $canonical, $blockedReasons) ? $canonical : null;
    }

    private function bridgeIsWorkspaceSafe(AgenticMarketingOpportunity $opportunity, Opportunity $canonical, array &$blockedReasons): bool
    {
        $legacyWorkspaceId = $this->stringValue($opportunity->objective?->workspace_id);
        if ($legacyWorkspaceId && (string) $canonical->workspace_id !== $legacyWorkspaceId) {
            $blockedReasons[] = 'canonical_bridge_workspace_mismatch';

            return false;
        }

        return true;
    }

    /**
     * @return array<string,int>
     */
    private function groupByStatus(Builder $query): array
    {
        return $query
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    private function actionRunQuery(AgenticMarketingOpportunity $opportunity): Builder
    {
        $actionIds = AgenticMarketingAction::query()
            ->where('opportunity_id', $opportunity->id)
            ->pluck('id')
            ->all();

        return AgenticActionRun::query()
            ->where(function (Builder $query) use ($opportunity, $actionIds): void {
                $query->where('opportunity_id', $opportunity->id);

                if ($actionIds !== []) {
                    $query->orWhereIn('action_id', $actionIds);
                }
            });
    }

    /**
     * @param  array<string,int>  $counts
     * @param  array<int,string>  $statuses
     */
    private function hasAnyStatus(array $counts, array $statuses): bool
    {
        foreach ($statuses as $status) {
            if (($counts[$status] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,string>  $candidateStatuses
     * @param  array<string,mixed>  $mapped
     */
    private function statusAlignment(?Opportunity $canonical, ?string $canonicalStatus, array $candidateStatuses, array $mapped, bool $statusConflict): string
    {
        if ((bool) $mapped['unmapped']) {
            return 'unmapped';
        }

        if (! $canonical) {
            return 'legacy_only';
        }

        if ($statusConflict) {
            return 'conflict';
        }

        return $canonicalStatus !== null && in_array($canonicalStatus, $candidateStatuses, true) ? 'aligned_candidate' : 'missing_canonical_status';
    }

    /**
     * @param  array<int,string>  $candidateStatuses
     * @param  array<string,int>  $actionsByStatus
     * @param  array<string,int>  $pipelinesByStatus
     * @return array<int,string>
     */
    private function lifecycleAmbiguityReasons(
        string $legacyStatus,
        array $candidateStatuses,
        bool $completedHasCompletedActions,
        bool $completedHasCompletedPipelines,
        bool $dismissedHasOpenOrRunningActions,
        bool $openAlreadyHasCompletedExecution,
        array $actionsByStatus,
        array $pipelinesByStatus,
    ): array {
        $reasons = [];

        if (count($candidateStatuses) > 1) {
            $reasons[] = 'candidate_canonical_status_is_not_single_valued';
        }

        if ($legacyStatus === 'open' && $openAlreadyHasCompletedExecution) {
            $reasons[] = 'open_agentic_status_has_completed_execution';
        }

        if ($legacyStatus === 'dismissed' && $dismissedHasOpenOrRunningActions) {
            $reasons[] = 'dismissed_agentic_status_has_open_or_running_actions';
        }

        if ($legacyStatus === 'dismissed' && array_sum($pipelinesByStatus) > 0) {
            $reasons[] = 'dismissed_agentic_status_has_execution_pipelines';
        }

        if ($legacyStatus === 'completed' && ($completedHasCompletedActions || $completedHasCompletedPipelines)) {
            $reasons[] = 'completed_agentic_status_has_execution_completion_scope';
        }

        if ($legacyStatus === 'completed' && array_sum($actionsByStatus) === 0 && array_sum($pipelinesByStatus) === 0) {
            $reasons[] = 'completed_agentic_status_has_no_execution_scope';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  array<string,mixed>  $mapped
     * @param  array<int,string>  $ambiguousReasons
     * @param  array<int,string>  $bridgeBlockedReasons
     */
    private function recommendedFutureMigrationPath(?Opportunity $canonical, array $mapped, bool $statusConflict, array $ambiguousReasons, array $bridgeBlockedReasons): string
    {
        if (in_array('multiple_canonical_opportunities_linked_to_agentic_row', $bridgeBlockedReasons, true)) {
            return 'Resolve duplicate canonical bridges before lifecycle or action ownership planning.';
        }

        if (! $canonical) {
            return 'Create or repair exactly one safe canonical bridge; keep AgenticMarketingOpportunity as lifecycle and execution authority.';
        }

        if ((bool) $mapped['unmapped']) {
            return 'Define an explicit Agentic-to-canonical lifecycle mapping before any future sync writer is considered.';
        }

        if ($statusConflict) {
            return 'Review the linked canonical status manually; do not sync status while candidate mapping conflicts.';
        }

        if ($ambiguousReasons !== []) {
            return 'Keep lifecycle read-only and resolve execution-scope ambiguity before canonical action ownership.';
        }

        return 'Status is display-aligned only; Phase 3J still permits no lifecycle sync writer.';
    }

    private function statusValue(mixed $status): ?string
    {
        return $this->stringValue($status);
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
