<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Services\AgenticMarketing\AgenticMarketingActionPlanner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AgenticCanonicalPlannerApplyExperimentService
{
    public const METADATA_VERSION = 'agentic-planner-canonical-apply:v1';

    public function __construct(
        private readonly AgenticCanonicalPlannerExperimentService $experiment,
        private readonly AgenticMarketingActionPlanner $planner,
    ) {}

    /**
     * @param  array{detector?:string|null}  $filters
     * @return array<string,mixed>
     */
    public function run(AgenticMarketingObjective $objective, array $filters, int $limit, bool $apply): array
    {
        $report = $this->experiment->compare($objective, [
            'detector' => $filters['detector'] ?? null,
        ]);

        $readinessByLegacyId = collect((array) $report['readiness_rows'])
            ->keyBy('legacy_agentic_opportunity_id');
        $signatureEquivalence = collect((array) $report['action_signature_equivalence'])
            ->groupBy(fn (array $row): string => (string) $row['legacy_opportunity_id'].':'.(string) $row['action_type']);

        $eligibleRows = [];
        $skippedRows = [];
        $blockedRows = [];
        $createdActionIds = [];
        $reusedActionIds = [];
        $plannedActionIds = [];
        $legacyOpportunityIds = [];
        $canonicalOpportunityIds = [];
        $sourceSignatures = [];

        foreach ((array) $report['excluded_rows'] as $excludedRow) {
            $hardReasons = array_values(array_diff((array) ($excludedRow['blocked_reasons'] ?? []), ['missing_safe_canonical_bridge']));

            if ((($excludedRow['readiness_status'] ?? null) === AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_BLOCKED && $hardReasons !== [])
                || (bool) ($excludedRow['duplicate_action_risk'] ?? false)
                || ((bool) ($excludedRow['signature_blocked'] ?? false) && $hardReasons !== [])
                || (bool) ($excludedRow['continuity_blocked'] ?? false)
                || ((bool) ($excludedRow['lifecycle_blocked'] ?? false) && $hardReasons !== [])) {
                $blockedRows[] = $excludedRow;

                continue;
            }

            $skippedRows[] = [
                'legacy_opportunity_id' => $excludedRow['legacy_opportunity_id'] ?? null,
                'canonical_opportunity_id' => $excludedRow['canonical_opportunity_id'] ?? null,
                'reason' => $excludedRow['readiness_status'] ?? 'not_phase_3l_ready',
            ];
        }

        foreach (collect((array) $report['canonical_experiment_order'])->take($limit) as $row) {
            $actions = collect((array) ($row['dry_run_actions'] ?? []))
                ->reject(fn (array $action): bool => (bool) ($action['expected_noop'] ?? false))
                ->values();

            $blockers = $actions
                ->flatMap(function (array $action) use ($signatureEquivalence, $row): array {
                    $key = (string) $row['legacy_opportunity_id'].':'.(string) ($action['action_type'] ?? '');
                    $matches = collect($signatureEquivalence->get($key, []));

                    if ($matches->isEmpty()) {
                        return ['missing_phase_3m_signature_equivalence'];
                    }

                    return $matches
                        ->reject(fn (array $match): bool => (bool) ($match['equivalent'] ?? false) && ((array) ($match['blocked_reasons'] ?? [])) === [])
                        ->flatMap(fn (array $match): array => array_merge(
                            ['phase_3m_signature_not_equivalent'],
                            (array) ($match['blocked_reasons'] ?? [])
                        ))
                        ->all();
                })
                ->unique()
                ->values()
                ->all();

            if ($actions->isEmpty()) {
                $skippedRows[] = [
                    'legacy_opportunity_id' => $row['legacy_opportunity_id'],
                    'canonical_opportunity_id' => $row['canonical_opportunity_id'],
                    'reason' => 'no_planner_actions_with_met_prerequisites',
                ];

                continue;
            }

            if ($blockers !== []) {
                $blockedRows[] = [
                    'legacy_opportunity_id' => $row['legacy_opportunity_id'],
                    'canonical_opportunity_id' => $row['canonical_opportunity_id'],
                    'readiness_status' => $row['readiness_status'],
                    'blocked_reasons' => $blockers,
                ];

                continue;
            }

            $eligibleRows[] = $row;
            $legacyOpportunityIds[] = (string) $row['legacy_opportunity_id'];
            $canonicalOpportunityIds[] = (string) $row['canonical_opportunity_id'];
            $sourceSignatures = array_values(array_unique(array_merge(
                $sourceSignatures,
                $actions->pluck('signature.signature')->filter()->map(fn (mixed $value): string => (string) $value)->all()
            )));

            if (! $apply) {
                continue;
            }

            $opportunity = AgenticMarketingOpportunity::query()
                ->with(['objective', 'content'])
                ->whereKey((string) $row['legacy_opportunity_id'])
                ->first();

            if (! $opportunity) {
                $blockedRows[] = [
                    'legacy_opportunity_id' => $row['legacy_opportunity_id'],
                    'canonical_opportunity_id' => $row['canonical_opportunity_id'],
                    'readiness_status' => $row['readiness_status'],
                    'blocked_reasons' => ['missing_legacy_agentic_marketing_opportunity'],
                ];

                continue;
            }

            $before = AgenticMarketingAction::query()
                ->where('opportunity_id', $opportunity->id)
                ->pluck('id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all();

            $plannerResult = $this->planner->planForOpportunity($opportunity);
            $plannedIds = collect((array) ($plannerResult['action_ids'] ?? []))
                ->map(fn (mixed $id): string => (string) $id)
                ->values()
                ->all();

            $created = array_values(array_diff($plannedIds, $before));
            $reused = array_values(array_intersect($plannedIds, $before));
            $createdActionIds = array_values(array_unique(array_merge($createdActionIds, $created)));
            $reusedActionIds = array_values(array_unique(array_merge($reusedActionIds, $reused)));
            $plannedActionIds = array_values(array_unique(array_merge($plannedActionIds, $plannedIds)));

            foreach ($plannedIds as $actionId) {
                $this->attachExperimentMetadata(
                    $actionId,
                    $row,
                    (array) $readinessByLegacyId->get((string) $row['legacy_opportunity_id'], []),
                    $signatureEquivalence
                );
            }
        }

        return [
            'objective_id' => (string) $objective->id,
            'workspace_id' => $this->stringValue($objective->workspace_id),
            'site_id' => $this->stringValue($objective->client_site_id),
            'apply' => $apply,
            'summary' => [
                'inspected_objectives' => 1,
                'legacy_candidate_count' => (int) data_get($report, 'summary.legacy_candidate_count', 0),
                'canonical_experiment_candidate_count' => count((array) $report['canonical_experiment_order']),
                'eligible_apply_candidate_count' => count($eligibleRows),
                'skipped_candidate_count' => count($skippedRows),
                'created_action_count' => count($createdActionIds),
                'reused_action_count' => count($reusedActionIds),
                'blocked_count' => count($blockedRows),
            ],
            'blocker_samples' => collect($blockedRows)
                ->map(fn (array $row): array => [
                    'legacy_opportunity_id' => $row['legacy_opportunity_id'] ?? null,
                    'canonical_opportunity_id' => $row['canonical_opportunity_id'] ?? null,
                    'reasons' => array_slice((array) ($row['blocked_reasons'] ?? []), 0, 4),
                ])
                ->take(10)
                ->values()
                ->all(),
            'skipped_samples' => array_slice($skippedRows, 0, 10),
            'created_action_ids' => $createdActionIds,
            'reused_action_ids' => $reusedActionIds,
            'planned_action_ids' => $plannedActionIds,
            'legacy_opportunity_ids' => array_values(array_unique($legacyOpportunityIds)),
            'linked_canonical_opportunity_ids' => array_values(array_unique($canonicalOpportunityIds)),
            'source_signatures' => array_slice($sourceSignatures, 0, 20),
            'rollback_notes' => [
                'Disable features.mos_agentic_planner_canonical_apply_experiment / ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_APPLY_EXPERIMENT.',
                'Execution remains legacy-owned through AgenticMarketingOpportunity ids.',
                'Canonical opportunity ids are payload metadata only and can be ignored by runtime flows.',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $readiness
     * @param  Collection<int|string,Collection<int,array<string,mixed>>>  $signatureEquivalence
     */
    private function attachExperimentMetadata(string $actionId, array $row, array $readiness, $signatureEquivalence): void
    {
        $action = AgenticMarketingAction::query()->whereKey($actionId)->first();
        if (! $action) {
            return;
        }

        $signature = collect($signatureEquivalence->get((string) $row['legacy_opportunity_id'].':'.(string) $action->action_type, []))
            ->pluck('canonical_signature')
            ->filter()
            ->first();

        $payload = (array) ($action->payload ?? []);
        $payload['planner_experiment'] = [
            'version' => self::METADATA_VERSION,
            'canonical_opportunity_id' => (string) $row['canonical_opportunity_id'],
            'legacy_agentic_marketing_opportunity_id' => (string) $row['legacy_opportunity_id'],
            'objective_id' => (string) $action->objective_id,
            'workspace_id' => $this->stringValue($action->objective?->workspace_id) ?: ($readiness['workspace_id'] ?? null),
            'selection_source' => 'canonical_experiment',
            'phase_3m_signature' => $signature,
            'phase_3l_readiness_status' => (string) ($readiness['readiness_status'] ?? $row['readiness_status']),
            'applied_at' => now()->toIso8601String(),
            'applied_by' => 'command',
        ];

        DB::table($action->getTable())
            ->where('id', $action->id)
            ->update([
                'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            ]);
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
