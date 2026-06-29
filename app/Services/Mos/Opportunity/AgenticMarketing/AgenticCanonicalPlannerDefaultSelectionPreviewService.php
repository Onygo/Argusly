<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticMarketingObjective;
use Illuminate\Support\Collection;

class AgenticCanonicalPlannerDefaultSelectionPreviewService
{
    public const STATUS_KEEP_LEGACY = 'keep_legacy';

    public const STATUS_PREVIEW_SAFE = 'preview_safe';

    public const STATUS_PREVIEW_BLOCKED = 'preview_blocked';

    public const STATUS_INSUFFICIENT_CANONICAL_COVERAGE = 'insufficient_canonical_coverage';

    public const STATUS_SHADOW_REGRESSED = 'shadow_regressed';

    public const STATUS_AUDIT_RISK = 'audit_risk';

    public const STATUS_DUPLICATE_RISK = 'duplicate_risk';

    public const STATUS_CONTINUITY_RISK = 'continuity_risk';

    public const STATUS_LIFECYCLE_RISK = 'lifecycle_risk';

    public const STATUS_SIGNATURE_RISK = 'signature_risk';

    public function __construct(
        private readonly AgenticCanonicalPlannerShadowService $shadow,
    ) {}

    /**
     * @param  array{site?:string|null,detector?:string|null,limit?:int|null}  $filters
     * @return array<string,mixed>
     */
    public function preview(AgenticMarketingObjective $objective, array $filters = []): array
    {
        $limit = max(1, (int) ($filters['limit'] ?? 100));
        $site = $this->stringValue($filters['site'] ?? null);

        if ($site !== null && $site !== $this->stringValue($objective->client_site_id)) {
            return $this->emptyReport($objective, $limit, ['site_filter_does_not_match_objective']);
        }

        $shadow = $this->shadow->compare($objective, [
            'detector' => $filters['detector'] ?? null,
            'limit' => $limit,
        ]);

        $legacyOrder = collect((array) ($shadow['legacy_order'] ?? []))->take($limit)->values();
        $canonicalOrder = collect((array) ($shadow['shadow_canonical_order'] ?? []))->take($limit)->values();
        $readinessRows = collect((array) ($shadow['readiness_rows'] ?? []));
        $auditRows = collect((array) ($shadow['phase_3o_audit_rows'] ?? []));
        $auditRiskyRows = collect((array) ($shadow['phase_3o_risky_rows'] ?? []));
        $blockedCandidates = collect((array) ($shadow['excluded_rows'] ?? []));

        $legacyIds = $legacyOrder->pluck('legacy_opportunity_id')->map(fn (mixed $id): string => (string) $id)->values();
        $canonicalIds = $canonicalOrder->pluck('legacy_opportunity_id')->map(fn (mixed $id): string => (string) $id)->values();
        $canonicalIdSet = $canonicalIds->flip();
        $legacyIdSet = $legacyIds->flip();
        $legacyOnly = $legacyOrder
            ->reject(fn (array $row): bool => $canonicalIdSet->has((string) ($row['legacy_opportunity_id'] ?? '')))
            ->values();
        $canonicalOnly = $canonicalOrder
            ->reject(fn (array $row): bool => $legacyIdSet->has((string) ($row['legacy_opportunity_id'] ?? '')))
            ->values();
        $exactOrderMatch = $legacyIds->all() === $canonicalIds->all();
        $orderDifferences = $this->orderDifferences($legacyOrder, $canonicalOrder);
        $coveragePercentage = $legacyOrder->isEmpty()
            ? ($canonicalOrder->isEmpty() ? 100.0 : 0.0)
            : round((($legacyOrder->count() - $legacyOnly->count()) / $legacyOrder->count()) * 100, 2);
        $canonicalReadinessRegressions = $this->canonicalReadinessRegressions($canonicalOrder, $readinessRows);
        $summary = (array) ($shadow['summary'] ?? []);
        $excludedReasons = $this->excludedReasons($blockedCandidates);
        $applySafety = $this->applySafety(
            shadowSummary: $summary,
            legacyCount: $legacyOrder->count(),
            canonicalCount: $canonicalOrder->count(),
            legacyOnlyCount: $legacyOnly->count(),
            canonicalOnlyCount: $canonicalOnly->count(),
            exactOrderMatch: $exactOrderMatch,
            auditRiskyCount: $auditRiskyRows->count(),
            canonicalReadinessRegressions: $canonicalReadinessRegressions,
            auditRows: $auditRows,
        );
        $status = $this->status($applySafety);

        return [
            'objective_id' => (string) $objective->id,
            'workspace_id' => $this->stringValue($objective->workspace_id),
            'site_id' => $this->stringValue($objective->client_site_id),
            'legacy_candidate_order' => $legacyOrder->all(),
            'canonical_proposed_default_order' => $canonicalOrder->all(),
            'exact_order_match' => $exactOrderMatch,
            'order_differences' => $orderDifferences,
            'canonical_only_candidates' => $canonicalOnly->all(),
            'legacy_only_candidates' => $legacyOnly->all(),
            'blocked_candidates' => $blockedCandidates->all(),
            'excluded_reasons' => $excludedReasons,
            'apply_safety' => $applySafety,
            'default_selection_preview_status' => $status,
            'summary' => [
                'legacy_candidate_count' => $legacyOrder->count(),
                'canonical_proposed_count' => $canonicalOrder->count(),
                'coverage_percentage' => $coveragePercentage,
                'exact_order_match_count' => $exactOrderMatch ? 1 : 0,
                'order_difference_count' => count($orderDifferences),
                'blocked_candidate_count' => $blockedCandidates->count(),
                'phase_3o_risky_count' => $auditRiskyRows->count(),
                'readiness_regression_count' => (int) ($summary['readiness_regression_count'] ?? 0) + count($canonicalReadinessRegressions),
                'duplicate_risk_count' => (int) ($summary['duplicate_risk_count'] ?? 0),
                'continuity_risk_count' => (int) ($summary['continuity_risk_count'] ?? 0),
                'lifecycle_risk_count' => (int) ($summary['lifecycle_risk_count'] ?? 0),
                'signature_risk_count' => (int) ($summary['signature_mismatch_count'] ?? 0),
                'preview_safe_count' => $status === self::STATUS_PREVIEW_SAFE ? 1 : 0,
                'preview_blocked_count' => $this->blockedStatus($status) ? 1 : 0,
                'recommendation' => $this->recommendation($status),
            ],
            'phase_3p_shadow_recommendation' => (string) ($summary['recommendation'] ?? 'keep legacy'),
            'phase_3o_audit_rows' => $auditRows->all(),
            'phase_3o_risky_rows' => $auditRiskyRows->all(),
            'phase_3l_readiness_rows' => $readinessRows->all(),
            'phase_3h_signature_equivalence' => (array) ($shadow['action_signature_equivalence'] ?? []),
            'phase_3p_shadow_report' => $shadow,
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $legacyOrder
     * @param  Collection<int,array<string,mixed>>  $canonicalOrder
     * @return array<int,array<string,mixed>>
     */
    private function orderDifferences(Collection $legacyOrder, Collection $canonicalOrder): array
    {
        $canonicalRanks = $canonicalOrder->pluck('rank', 'legacy_opportunity_id');

        return $legacyOrder
            ->filter(fn (array $row): bool => $canonicalRanks->has((string) ($row['legacy_opportunity_id'] ?? ''))
                && (int) $canonicalRanks[(string) $row['legacy_opportunity_id']] !== (int) ($row['rank'] ?? 0))
            ->map(fn (array $row): array => [
                'legacy_opportunity_id' => (string) $row['legacy_opportunity_id'],
                'legacy_rank' => (int) ($row['rank'] ?? 0),
                'canonical_rank' => (int) $canonicalRanks[(string) $row['legacy_opportunity_id']],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $canonicalOrder
     * @param  Collection<int,array<string,mixed>>  $readinessRows
     * @return array<int,array<string,mixed>>
     */
    private function canonicalReadinessRegressions(Collection $canonicalOrder, Collection $readinessRows): array
    {
        $readinessByLegacyId = $readinessRows->keyBy('legacy_agentic_opportunity_id');

        return $canonicalOrder
            ->reject(fn (array $row): bool => ($readinessByLegacyId[(string) ($row['legacy_opportunity_id'] ?? '')]['readiness_status'] ?? null) === AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY)
            ->map(fn (array $row): array => [
                'legacy_opportunity_id' => (string) ($row['legacy_opportunity_id'] ?? ''),
                'reason' => 'canonical_proposed_candidate_is_not_phase_3l_ready',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $blockedCandidates
     * @return array<int,array<string,mixed>>
     */
    private function excludedReasons(Collection $blockedCandidates): array
    {
        return $blockedCandidates
            ->map(fn (array $row): array => [
                'legacy_opportunity_id' => $row['legacy_opportunity_id'] ?? null,
                'canonical_opportunity_id' => $row['canonical_opportunity_id'] ?? null,
                'readiness_status' => $row['readiness_status'] ?? null,
                'reasons' => (array) ($row['blocked_reasons'] ?? []),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $shadowSummary
     * @param  Collection<int,array<string,mixed>>  $auditRows
     * @param  array<int,array<string,mixed>>  $canonicalReadinessRegressions
     * @return array<string,mixed>
     */
    private function applySafety(
        array $shadowSummary,
        int $legacyCount,
        int $canonicalCount,
        int $legacyOnlyCount,
        int $canonicalOnlyCount,
        bool $exactOrderMatch,
        int $auditRiskyCount,
        array $canonicalReadinessRegressions,
        Collection $auditRows,
    ): array {
        $metadataOnlyCount = $auditRows
            ->where('audit_status', AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK)
            ->count();

        return [
            'safe' => false,
            'phase_3p_recommendation' => (string) ($shadowSummary['recommendation'] ?? 'keep legacy'),
            'phase_3p_continue_shadow_required' => true,
            'phase_3p_continue_shadow' => ($shadowSummary['recommendation'] ?? null) === 'continue shadow',
            'phase_3o_risky_count' => $auditRiskyCount,
            'phase_3l_canonical_readiness_regression_count' => count($canonicalReadinessRegressions),
            'phase_3h_signature_risk_count' => (int) ($shadowSummary['signature_mismatch_count'] ?? 0),
            'phase_3i_continuity_risk_count' => (int) ($shadowSummary['continuity_risk_count'] ?? 0),
            'phase_3j_lifecycle_risk_count' => (int) ($shadowSummary['lifecycle_risk_count'] ?? 0),
            'duplicate_open_action_risk_count' => (int) ($shadowSummary['duplicate_risk_count'] ?? 0),
            'legacy_candidate_count' => $legacyCount,
            'canonical_proposed_count' => $canonicalCount,
            'legacy_only_count' => $legacyOnlyCount,
            'canonical_only_count' => $canonicalOnlyCount,
            'exact_order_match' => $exactOrderMatch,
            'canonical_coverage_sufficient' => $legacyCount > 0 && $legacyOnlyCount === 0 && $canonicalCount >= $legacyCount,
            'metadata_only_traceability_count' => $metadataOnlyCount,
            'metadata_only_action_ownership_approved' => false,
            'blocked_reasons' => [],
        ];
    }

    /**
     * @param  array<string,mixed>  $applySafety
     */
    private function status(array &$applySafety): string
    {
        $blocked = [];

        if ((int) $applySafety['legacy_candidate_count'] === 0 && (int) $applySafety['canonical_proposed_count'] === 0) {
            $applySafety['blocked_reasons'] = ['no_legacy_or_canonical_candidates_in_scope'];

            return self::STATUS_KEEP_LEGACY;
        }

        if (! (bool) $applySafety['canonical_coverage_sufficient']) {
            $blocked[] = 'canonical_coverage_is_not_sufficient_for_legacy_scope';
            $applySafety['blocked_reasons'] = $blocked;

            return self::STATUS_INSUFFICIENT_CANONICAL_COVERAGE;
        }

        if ((int) $applySafety['duplicate_open_action_risk_count'] > 0) {
            $blocked[] = 'duplicate_open_legacy_action_risk_exists';
            $applySafety['blocked_reasons'] = $blocked;

            return self::STATUS_DUPLICATE_RISK;
        }

        if ((int) $applySafety['phase_3i_continuity_risk_count'] > 0) {
            $blocked[] = 'phase_3i_continuity_risk_exists';
            $applySafety['blocked_reasons'] = $blocked;

            return self::STATUS_CONTINUITY_RISK;
        }

        if ((int) $applySafety['phase_3j_lifecycle_risk_count'] > 0) {
            $blocked[] = 'phase_3j_lifecycle_risk_exists';
            $applySafety['blocked_reasons'] = $blocked;

            return self::STATUS_LIFECYCLE_RISK;
        }

        if ((int) $applySafety['phase_3h_signature_risk_count'] > 0) {
            $blocked[] = 'phase_3h_signature_risk_exists';
            $applySafety['blocked_reasons'] = $blocked;

            return self::STATUS_SIGNATURE_RISK;
        }

        if ((int) $applySafety['phase_3o_risky_count'] > 0) {
            $blocked[] = 'phase_3o_risky_rows_exist';
            $applySafety['blocked_reasons'] = $blocked;

            return self::STATUS_AUDIT_RISK;
        }

        if ((int) $applySafety['phase_3l_canonical_readiness_regression_count'] > 0) {
            $blocked[] = 'canonical_proposed_candidate_is_not_phase_3l_ready';
            $applySafety['blocked_reasons'] = $blocked;

            return self::STATUS_PREVIEW_BLOCKED;
        }

        if (! (bool) $applySafety['phase_3p_continue_shadow']) {
            $blocked[] = 'phase_3p_shadow_recommendation_is_not_continue_shadow';
            $applySafety['blocked_reasons'] = $blocked;

            return self::STATUS_SHADOW_REGRESSED;
        }

        if (! (bool) $applySafety['exact_order_match'] || (int) $applySafety['canonical_only_count'] > 0) {
            $blocked[] = 'canonical_default_selection_would_change_legacy_output_order_or_scope';
            $applySafety['blocked_reasons'] = $blocked;

            return self::STATUS_SHADOW_REGRESSED;
        }

        $applySafety['safe'] = true;
        $applySafety['blocked_reasons'] = [];

        return self::STATUS_PREVIEW_SAFE;
    }

    private function blockedStatus(string $status): bool
    {
        return ! in_array($status, [
            self::STATUS_KEEP_LEGACY,
            self::STATUS_PREVIEW_SAFE,
        ], true);
    }

    private function recommendation(string $status): string
    {
        return match ($status) {
            self::STATUS_PREVIEW_SAFE => 'eligible for Phase 3R scoped default experiment',
            self::STATUS_KEEP_LEGACY => 'keep legacy',
            default => 'blocked',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyReport(AgenticMarketingObjective $objective, int $limit, array $reasons): array
    {
        return [
            'objective_id' => (string) $objective->id,
            'workspace_id' => $this->stringValue($objective->workspace_id),
            'site_id' => $this->stringValue($objective->client_site_id),
            'legacy_candidate_order' => [],
            'canonical_proposed_default_order' => [],
            'exact_order_match' => true,
            'order_differences' => [],
            'canonical_only_candidates' => [],
            'legacy_only_candidates' => [],
            'blocked_candidates' => [],
            'excluded_reasons' => [],
            'apply_safety' => [
                'safe' => false,
                'limit' => $limit,
                'metadata_only_action_ownership_approved' => false,
                'blocked_reasons' => $reasons,
            ],
            'default_selection_preview_status' => self::STATUS_KEEP_LEGACY,
            'summary' => [
                'legacy_candidate_count' => 0,
                'canonical_proposed_count' => 0,
                'coverage_percentage' => 100.0,
                'exact_order_match_count' => 1,
                'order_difference_count' => 0,
                'blocked_candidate_count' => 0,
                'phase_3o_risky_count' => 0,
                'readiness_regression_count' => 0,
                'duplicate_risk_count' => 0,
                'continuity_risk_count' => 0,
                'lifecycle_risk_count' => 0,
                'signature_risk_count' => 0,
                'preview_safe_count' => 0,
                'preview_blocked_count' => 0,
                'recommendation' => 'keep legacy',
            ],
            'phase_3p_shadow_recommendation' => 'keep legacy',
            'phase_3o_audit_rows' => [],
            'phase_3o_risky_rows' => [],
            'phase_3l_readiness_rows' => [],
            'phase_3h_signature_equivalence' => [],
            'phase_3p_shadow_report' => [],
        ];
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
