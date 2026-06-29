<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticMarketingObjective;
use Illuminate\Support\Collection;

class AgenticCanonicalPlannerShadowService
{
    public function __construct(
        private readonly AgenticCanonicalPlannerExperimentService $experiment,
        private readonly AgenticPlannerApplyExperimentAuditService $audit,
    ) {}

    /**
     * @param  array{status?:string|null,detector?:string|null,limit?:int|null}  $filters
     * @return array<string,mixed>
     */
    public function compare(AgenticMarketingObjective $objective, array $filters = []): array
    {
        $experiment = $this->experiment->compare($objective, [
            'status' => $filters['status'] ?? null,
            'detector' => $filters['detector'] ?? null,
        ]);
        $audit = $this->audit->audit([
            'workspace' => $this->stringValue($objective->workspace_id),
            'objective' => (string) $objective->id,
            'site' => $this->stringValue($objective->client_site_id),
            'detector' => $filters['detector'] ?? null,
            'limit' => max(1, (int) ($filters['limit'] ?? 100)),
        ]);

        $legacyOrder = collect((array) $experiment['legacy_order']);
        $canonicalOrder = collect((array) $experiment['canonical_experiment_order']);
        $excludedRows = collect((array) $experiment['excluded_rows']);
        $readinessRows = collect((array) $experiment['readiness_rows']);
        $auditRows = collect((array) $audit['rows']);
        $auditRiskyRows = $this->riskyAuditRows($auditRows);
        $priorityOrderDifferences = collect((array) $experiment['priority_order_differences']);
        $signatureEquivalence = collect((array) $experiment['action_signature_equivalence']);
        $legacyIds = $legacyOrder->pluck('legacy_opportunity_id')->values();
        $canonicalIds = $canonicalOrder->pluck('legacy_opportunity_id')->values();
        $exactOrderMatch = $legacyIds->all() === $canonicalIds->all();
        $blockedCanonicalCount = $excludedRows
            ->filter(fn (array $row): bool => ($row['readiness_status'] ?? null) !== AgenticPlannerReadinessInspectionService::STATUS_LEGACY_ONLY)
            ->count();

        $summary = [
            'inspected_objectives' => 1,
            'legacy_candidate_count' => $legacyOrder->count(),
            'shadow_canonical_candidate_count' => $canonicalOrder->count(),
            'exact_order_match_count' => $exactOrderMatch ? 1 : 0,
            'priority_order_difference_count' => $priorityOrderDifferences->count(),
            'skipped_legacy_only_count' => $this->skippedLegacyOnlyCount($legacyOrder, $canonicalOrder, $readinessRows),
            'blocked_canonical_candidate_count' => $blockedCanonicalCount,
            'readiness_regression_count' => (int) data_get($audit, 'summary.readiness_regression_count', 0),
            'phase_3o_clean_count' => $this->cleanAuditCount($auditRows),
            'phase_3o_metadata_only_ok_count' => (int) data_get($audit, 'summary.metadata_only_ok_count', 0),
            'phase_3o_risky_count' => $auditRiskyRows->count(),
            'duplicate_risk_count' => max(
                (int) data_get($experiment, 'summary.duplicate_risk_count', 0),
                (int) data_get($audit, 'summary.duplicate_risk_count', 0),
            ),
            'continuity_risk_count' => max(
                (int) data_get($experiment, 'summary.continuity_blocker_count', 0),
                (int) data_get($audit, 'summary.continuity_risk_count', 0),
            ),
            'lifecycle_risk_count' => max(
                (int) data_get($experiment, 'summary.lifecycle_blocker_count', 0),
                (int) data_get($audit, 'summary.lifecycle_risk_count', 0),
            ),
            'signature_mismatch_count' => max(
                $signatureEquivalence->where('equivalent', false)->count(),
                (int) data_get($audit, 'summary.signature_mismatch_count', 0),
            ),
            'feature_enabled' => (bool) config('features.mos_agentic_planner_canonical_shadow', false),
        ];
        $summary['shadow_safe_objective_count'] = $this->isShadowSafe($summary) ? 1 : 0;
        $summary['blocked_objective_count'] = $this->isBlocked($summary) ? 1 : 0;
        $summary['recommendation'] = $this->recommendation($summary);

        return [
            'objective_id' => (string) $objective->id,
            'workspace_id' => $this->stringValue($objective->workspace_id),
            'site_id' => $this->stringValue($objective->client_site_id),
            'summary' => $summary,
            'legacy_order' => $legacyOrder->all(),
            'shadow_canonical_order' => $canonicalOrder->all(),
            'readiness_rows' => $readinessRows->all(),
            'phase_3o_audit_rows' => $auditRows->all(),
            'phase_3o_risky_rows' => $auditRiskyRows->values()->all(),
            'excluded_rows' => $excludedRows->all(),
            'priority_order_differences' => $priorityOrderDifferences->all(),
            'action_signature_equivalence' => $signatureEquivalence->all(),
            'expected_created_or_reused_action_types' => [
                'legacy' => $this->actionTypes($legacyOrder),
                'shadow_canonical' => $this->actionTypes($canonicalOrder),
            ],
            'sample_differences' => $this->sampleDifferences($excludedRows, $priorityOrderDifferences, $auditRiskyRows),
            'phase_3m_report' => $experiment,
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $auditRows
     * @return Collection<int,array<string,mixed>>
     */
    private function riskyAuditRows(Collection $auditRows): Collection
    {
        return $auditRows
            ->reject(fn (array $row): bool => in_array($row['audit_status'] ?? null, [
                AgenticPlannerApplyExperimentAuditService::STATUS_CLEAN,
                AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK,
            ], true))
            ->values();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $legacyOrder
     * @param  Collection<int,array<string,mixed>>  $canonicalOrder
     * @param  Collection<int,array<string,mixed>>  $readinessRows
     */
    private function skippedLegacyOnlyCount(Collection $legacyOrder, Collection $canonicalOrder, Collection $readinessRows): int
    {
        $canonicalLegacyIds = $canonicalOrder->pluck('legacy_opportunity_id')->all();
        $skippedOpenLegacy = $legacyOrder
            ->reject(fn (array $row): bool => in_array($row['legacy_opportunity_id'], $canonicalLegacyIds, true))
            ->count();

        return max(
            $skippedOpenLegacy,
            $readinessRows->where('readiness_status', AgenticPlannerReadinessInspectionService::STATUS_LEGACY_ONLY)->count(),
        );
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $auditRows
     */
    private function cleanAuditCount(Collection $auditRows): int
    {
        return $auditRows
            ->filter(fn (array $row): bool => in_array($row['audit_status'] ?? null, [
                AgenticPlannerApplyExperimentAuditService::STATUS_CLEAN,
                AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK,
            ], true))
            ->count();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<int,string>
     */
    private function actionTypes(Collection $rows): array
    {
        return $rows
            ->flatMap(fn (array $row): array => (array) ($row['action_types'] ?? []))
            ->map(fn (mixed $type): string => trim((string) $type))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $excludedRows
     * @param  Collection<int,array<string,mixed>>  $priorityOrderDifferences
     * @param  Collection<int,array<string,mixed>>  $auditRiskyRows
     * @return array<int,array<string,mixed>>
     */
    private function sampleDifferences(Collection $excludedRows, Collection $priorityOrderDifferences, Collection $auditRiskyRows): array
    {
        return collect()
            ->merge($priorityOrderDifferences->map(fn (array $row): array => [
                'type' => 'priority_order_difference',
                'legacy_opportunity_id' => $row['legacy_opportunity_id'] ?? null,
                'legacy_rank' => $row['legacy_rank'] ?? null,
                'canonical_rank' => $row['canonical_rank'] ?? null,
            ]))
            ->merge($excludedRows->map(fn (array $row): array => [
                'type' => 'readiness_excluded',
                'legacy_opportunity_id' => $row['legacy_opportunity_id'] ?? null,
                'readiness_status' => $row['readiness_status'] ?? null,
                'reasons' => array_slice((array) ($row['blocked_reasons'] ?? []), 0, 3),
            ]))
            ->merge($auditRiskyRows->map(fn (array $row): array => [
                'type' => 'phase_3o_risky',
                'action_id' => $row['action_id'] ?? null,
                'legacy_opportunity_id' => $row['legacy_opportunity_id'] ?? null,
                'audit_status' => $row['audit_status'] ?? null,
                'reasons' => array_slice((array) ($row['blocked_reasons'] ?? []), 0, 3),
            ]))
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function isShadowSafe(array $summary): bool
    {
        return (int) $summary['shadow_canonical_candidate_count'] > 0
            && ! $this->isBlocked($summary);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function isBlocked(array $summary): bool
    {
        foreach ([
            'blocked_canonical_candidate_count',
            'readiness_regression_count',
            'phase_3o_risky_count',
            'duplicate_risk_count',
            'continuity_risk_count',
            'lifecycle_risk_count',
            'signature_mismatch_count',
        ] as $key) {
            if ((int) $summary[$key] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function recommendation(array $summary): string
    {
        if ($this->isBlocked($summary)) {
            return 'blocked';
        }

        if ((int) $summary['shadow_canonical_candidate_count'] > 0) {
            return 'continue shadow';
        }

        return 'keep legacy';
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
