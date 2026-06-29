<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use Illuminate\Support\Collection;

class AgenticPlannerDefaultSelectionScopedRolloutPlanService
{
    public const ELIGIBILITY_ELIGIBLE = 'eligible';

    public const ELIGIBILITY_BLOCKED = 'blocked';

    public const ROLLOUT_MODE = 'scoped_read_only_plan';

    private const PHASE_3T_READY_RECOMMENDATION = 'eligible for limited multi-objective Phase 3U';

    public function __construct(
        private readonly AgenticPlannerDefaultSelectionRolloutReadinessService $readiness,
    ) {}

    /**
     * @param  array{workspace?:string|null,site?:string|null,detector?:string|null,objectives?:array<int,string>|string|null,limit?:int|null,include_metadata_only_ok?:bool|null}  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $workspace = $this->stringValue($input['workspace'] ?? null);
        $requestedObjectiveIds = $this->objectiveIds($input['objectives'] ?? null);

        $phase3t = $this->readiness->inspect([
            'workspace' => $workspace,
            'objectives' => $requestedObjectiveIds,
            'site' => $input['site'] ?? null,
            'detector' => $input['detector'] ?? null,
            'limit' => max(1, (int) ($input['limit'] ?? 0)),
            'include_metadata_only_ok' => (bool) ($input['include_metadata_only_ok'] ?? false),
        ]);

        $rows = collect((array) ($phase3t['objective_rows'] ?? []));
        $inspectedObjectiveIds = $rows
            ->pluck('objective_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->filter()
            ->unique()
            ->values();
        $phase3tStatus = $this->stringValue($phase3t['rollout_readiness_status'] ?? null);
        $phase3tRecommendation = $this->stringValue($phase3t['recommendation'] ?? null);
        $phase3tWorkspace = $this->stringValue($phase3t['workspace_id'] ?? null);
        $blockedReasons = $this->blockedReasons(
            workspace: $workspace,
            phase3tWorkspace: $phase3tWorkspace,
            requestedObjectiveIds: $requestedObjectiveIds,
            inspectedObjectiveIds: $inspectedObjectiveIds,
            phase3tStatus: $phase3tStatus,
            phase3tRecommendation: $phase3tRecommendation,
            rows: $rows,
        );
        $eligible = $blockedReasons === [];
        $summary = (array) ($phase3t['summary'] ?? []);

        return [
            'phase' => '3U',
            'plan_name' => 'scoped_rollout_plan',
            'workspace_id' => $phase3tWorkspace ?? $workspace,
            'site_id' => $this->stringValue($phase3t['site_id'] ?? null),
            'detector_key' => $this->stringValue($phase3t['detector_key'] ?? null),
            'limit_per_objective' => (int) ($phase3t['limit_per_objective'] ?? max(1, (int) ($input['limit'] ?? 0))),
            'requested_objectives' => $requestedObjectiveIds,
            'inspected_objectives' => $inspectedObjectiveIds->all(),
            'readiness_status' => $phase3tStatus ?? AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_NO_CANDIDATE_SCOPE,
            'phase_3t_recommendation' => $phase3tRecommendation ?? 'missing',
            'rollout_eligibility' => $eligible ? self::ELIGIBILITY_ELIGIBLE : self::ELIGIBILITY_BLOCKED,
            'blocked_reasons' => $blockedReasons,
            'recommended_rollout_mode' => self::ROLLOUT_MODE,
            'recommended_first_rollout_scope' => $this->recommendedScope($eligible, $phase3t, $requestedObjectiveIds, $inspectedObjectiveIds->all()),
            'objectives_included' => $eligible ? $this->objectiveSummaries($rows)->all() : [],
            'objectives_excluded' => $eligible ? [] : $this->excludedObjectives($requestedObjectiveIds, $rows)->all(),
            'operator_checklist' => $this->operatorChecklist(),
            'rollback_checklist' => $this->rollbackChecklist(),
            'metadata_only_ok_review_requirement' => [
                'manual_review_required' => (int) ($summary['metadata_only_ok_count'] ?? 0) > 0,
                'metadata_only_ok_count' => (int) ($summary['metadata_only_ok_count'] ?? 0),
                'ownership_migration_approved' => false,
                'statement' => 'metadata_only_ok rows require operator review and never approve ownership migration.',
            ],
            'order_parity_confirmation' => [
                'confirmed' => $rows->isNotEmpty() && $rows->every(fn (array $row): bool => (bool) ($row['canonical_legacy_order_exact_match'] ?? false)),
                'statement' => 'Phase 3T sampled canonical and legacy order must be identical before any future runtime rollout.',
            ],
            'duplicate_risk_confirmation' => [
                'confirmed' => $rows->isNotEmpty() && $rows->every(fn (array $row): bool => (int) ($row['duplicate_open_action_risk_count'] ?? 0) === 0),
                'statement' => 'Duplicate open legacy action risk must remain zero.',
            ],
            'canonical_coverage_confirmation' => [
                'confirmed' => $rows->isNotEmpty() && $rows->every(fn (array $row): bool => (bool) ($row['canonical_coverage_sufficient'] ?? false)),
                'statement' => 'Canonical coverage is selection context only and does not transfer action ownership.',
            ],
            'legacy_action_ownership_preserved' => true,
            'canonical_ids_metadata_selection_context_only' => true,
            'runtime_activation_enabled' => false,
            'runtime_activation_statement' => 'Runtime activation is still not enabled; Phase 3U is a read-only scoped rollout planning artifact only.',
            'phase_3t_readiness_report' => $phase3t,
        ];
    }

    /**
     * @param  array<int,string>  $requestedObjectiveIds
     * @param  Collection<int,string>  $inspectedObjectiveIds
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<int,string>
     */
    private function blockedReasons(
        ?string $workspace,
        ?string $phase3tWorkspace,
        array $requestedObjectiveIds,
        Collection $inspectedObjectiveIds,
        ?string $phase3tStatus,
        ?string $phase3tRecommendation,
        Collection $rows,
    ): array {
        $blocked = [];

        if ($workspace === null) {
            $blocked[] = 'phase_3u_requires_explicit_workspace_scope';
        }

        if ($workspace !== null && $phase3tWorkspace !== $workspace) {
            $blocked[] = 'phase_3t_workspace_scope_mismatch:'.($phase3tWorkspace ?? 'missing');
        }

        if ($requestedObjectiveIds === []) {
            $blocked[] = 'phase_3u_requires_explicit_objective_scope';
        }

        if ($requestedObjectiveIds !== [] && $inspectedObjectiveIds->count() !== count($requestedObjectiveIds)) {
            $blocked[] = 'explicit_scope_did_not_resolve_all_requested_objectives';
        }

        if ($requestedObjectiveIds !== [] && $this->sortedStrings($requestedObjectiveIds) !== $this->sortedStrings($inspectedObjectiveIds->all())) {
            $blocked[] = 'phase_3t_objective_scope_mismatch';
        }

        if ($phase3tStatus !== AgenticPlannerDefaultSelectionRolloutReadinessService::STATUS_READY_FOR_SCOPED_EXPANSION) {
            $blocked[] = 'phase_3t_status_not_ready_for_scoped_expansion:'.($phase3tStatus ?? 'missing');
        }

        if ($phase3tRecommendation !== self::PHASE_3T_READY_RECOMMENDATION) {
            $blocked[] = 'phase_3t_recommendation_not_ready_for_scoped_expansion:'.($phase3tRecommendation ?? 'missing');
        }

        $rowReasons = $rows
            ->flatMap(fn (array $row): array => collect((array) ($row['blocked_reasons'] ?? []))
                ->map(fn (string $reason): string => $row['objective_id'].':'.$reason)
                ->all())
            ->values()
            ->all();

        return collect($blocked)
            ->merge($rowReasons)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $phase3t
     * @param  array<int,string>  $requestedObjectiveIds
     * @param  array<int,string>  $inspectedObjectiveIds
     * @return array<string,mixed>
     */
    private function recommendedScope(bool $eligible, array $phase3t, array $requestedObjectiveIds, array $inspectedObjectiveIds): array
    {
        if (! $eligible) {
            return [
                'scope_type' => 'none',
                'workspace_id' => $this->stringValue($phase3t['workspace_id'] ?? null),
                'objective_ids' => [],
                'reason' => 'rollout_eligibility_blocked',
            ];
        }

        return [
            'scope_type' => 'explicit_workspace_objectives',
            'workspace_id' => $this->stringValue($phase3t['workspace_id'] ?? null),
            'site_id' => $this->stringValue($phase3t['site_id'] ?? null),
            'detector_key' => $this->stringValue($phase3t['detector_key'] ?? null),
            'objective_ids' => $inspectedObjectiveIds,
            'requested_objective_ids' => $requestedObjectiveIds,
            'limit_per_objective' => (int) ($phase3t['limit_per_objective'] ?? 1),
            'rollout_mode' => self::ROLLOUT_MODE,
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return Collection<int,array<string,mixed>>
     */
    private function objectiveSummaries(Collection $rows): Collection
    {
        return $rows
            ->map(fn (array $row): array => [
                'objective_id' => (string) $row['objective_id'],
                'rollout_readiness_status' => (string) $row['rollout_readiness_status'],
                'candidate_action_count' => (int) ($row['candidate_action_count'] ?? 0),
                'metadata_only_ok_count' => (int) ($row['metadata_only_ok_count'] ?? 0),
                'metadata_only_action_ownership_approved' => false,
                'legacy_action_ownership_preserved' => true,
            ])
            ->values();
    }

    /**
     * @param  array<int,string>  $requestedObjectiveIds
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return Collection<int,array<string,mixed>>
     */
    private function excludedObjectives(array $requestedObjectiveIds, Collection $rows): Collection
    {
        $rowsByObjective = $rows->keyBy('objective_id');

        return collect($requestedObjectiveIds)
            ->map(function (string $objectiveId) use ($rowsByObjective): array {
                $row = (array) ($rowsByObjective[$objectiveId] ?? []);

                return [
                    'objective_id' => $objectiveId,
                    'rollout_readiness_status' => (string) ($row['rollout_readiness_status'] ?? 'missing_from_phase_3t_scope'),
                    'blocked_reasons' => (array) ($row['blocked_reasons'] ?? ['missing_from_phase_3t_scope']),
                ];
            })
            ->values();
    }

    /**
     * @return array<int,string>
     */
    private function operatorChecklist(): array
    {
        return [
            'review metadata_only_ok rows manually',
            'confirm sampled canonical and legacy order are identical',
            'confirm duplicate open legacy action risk is zero',
            'confirm no lifecycle ambiguity/conflict',
            'confirm no continuity blockers',
            'confirm all objectives are still in explicit scoped list',
            'confirm rollback remains legacy-first',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function rollbackChecklist(): array
    {
        return [
            'disable any future scoped feature flag before runtime rollout',
            'preserve legacy AgenticMarketingOpportunity action ownership',
            'ignore additive canonical metadata',
            'do not rewrite historical execution parents',
            'do not mutate dedupe hashes',
            'do not mutate action statuses',
            'use Phase 3T and Phase 3U reports as audit artifacts only',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function objectiveIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn (mixed $id): ?string => $this->stringValue($id))
            ->filter()
            ->unique()
            ->values()
            ->all();
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

    /**
     * @param  array<int,string>  $values
     * @return array<int,string>
     */
    private function sortedStrings(array $values): array
    {
        $values = collect($values)
            ->map(fn (mixed $value): ?string => $this->stringValue($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        sort($values, SORT_STRING);

        return $values;
    }
}
