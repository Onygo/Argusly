<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticMarketingObjective;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AgenticPlannerDefaultSelectionRolloutReadinessService
{
    public const STATUS_READY_FOR_SCOPED_EXPANSION = 'ready_for_scoped_expansion';

    public const STATUS_KEEP_SINGLE_OBJECTIVE_SCOPE = 'keep_single_objective_scope';

    public const STATUS_BLOCKED_BY_PHASE_3S = 'blocked_by_phase_3s';

    public const STATUS_BLOCKED_BY_PREVIEW = 'blocked_by_preview';

    public const STATUS_BLOCKED_BY_SHADOW = 'blocked_by_shadow';

    public const STATUS_BLOCKED_BY_PHASE_3O = 'blocked_by_phase_3o';

    public const STATUS_BLOCKED_BY_READINESS = 'blocked_by_readiness';

    public const STATUS_BLOCKED_BY_SIGNATURE = 'blocked_by_signature';

    public const STATUS_BLOCKED_BY_CONTINUITY = 'blocked_by_continuity';

    public const STATUS_BLOCKED_BY_LIFECYCLE = 'blocked_by_lifecycle';

    public const STATUS_BLOCKED_BY_DUPLICATE_RISK = 'blocked_by_duplicate_risk';

    public const STATUS_INSUFFICIENT_CANONICAL_COVERAGE = 'insufficient_canonical_coverage';

    public const STATUS_ORDER_MISMATCH = 'order_mismatch';

    public const STATUS_NO_CANDIDATE_SCOPE = 'no_candidate_scope';

    public function __construct(
        private readonly AgenticCanonicalPlannerDefaultSelectionPreviewService $preview,
        private readonly AgenticPlannerDefaultSelectionExperimentAuditService $phase3sAudit,
        private readonly AgenticPlannerApplyExperimentAuditService $phase3oAudit,
    ) {}

    /**
     * @param  array{workspace?:string|null,site?:string|null,detector?:string|null,objectives?:array<int,string>|string|null,objective_group?:array<string,mixed>|string|null,limit?:int|null,include_metadata_only_ok?:bool|null}  $input
     * @return array<string,mixed>
     */
    public function inspect(array $input): array
    {
        $workspace = $this->stringValue($input['workspace'] ?? null);
        $site = $this->stringValue($input['site'] ?? null);
        $detector = $this->stringValue($input['detector'] ?? null);
        $limit = max(1, (int) ($input['limit'] ?? 0));
        $objectiveIds = $this->objectiveIds($input['objectives'] ?? null);
        $objectiveGroup = $input['objective_group'] ?? null;

        if ($workspace === null || $limit < 1 || ($objectiveIds === [] && ! $this->hasObjectiveGroupFilter($objectiveGroup))) {
            return $this->emptyReport($workspace, $site, $detector, $limit);
        }

        $objectives = $this->objectives($workspace, $site, $objectiveIds, $objectiveGroup);
        if ($objectives->isEmpty()) {
            return $this->emptyReport($workspace, $site, $detector, $limit);
        }

        $rows = $objectives
            ->map(fn (AgenticMarketingObjective $objective): array => $this->inspectObjective($objective, $workspace, $site, $detector, $limit))
            ->values();

        return [
            'workspace_id' => $workspace,
            'site_id' => $site,
            'detector_key' => $detector,
            'limit_per_objective' => $limit,
            'rollout_readiness_status' => $this->overallStatus($rows),
            'summary' => $this->summary($rows),
            'objective_rows' => $rows->all(),
            'recommendation' => $this->recommendation($rows),
        ];
    }

    private function inspectObjective(AgenticMarketingObjective $objective, string $workspace, ?string $site, ?string $detector, int $limit): array
    {
        $preview = $this->preview->preview($objective, [
            'site' => $site,
            'detector' => $detector,
            'limit' => $limit,
        ]);
        $phase3s = $this->phase3sAudit->audit([
            'workspace' => $workspace,
            'objective' => (string) $objective->id,
            'site' => $site,
            'detector' => $detector,
            'limit' => $limit,
        ]);
        $phase3o = $this->phase3oAudit->audit([
            'workspace' => $workspace,
            'objective' => (string) $objective->id,
            'site' => $site,
            'detector' => $detector,
            'limit' => $limit,
        ]);

        $applySafety = (array) ($preview['apply_safety'] ?? []);
        $summary = (array) ($preview['summary'] ?? []);
        $phase3sRows = collect((array) ($phase3s['rows'] ?? []));
        $phase3oRows = collect((array) ($phase3o['rows'] ?? ($preview['phase_3o_audit_rows'] ?? [])));
        $readinessRows = collect((array) ($preview['phase_3l_readiness_rows'] ?? []));
        $signatureRows = collect((array) ($preview['phase_3h_signature_equivalence'] ?? []));
        $canonicalRows = collect((array) ($preview['canonical_proposed_default_order'] ?? []));
        $legacyRows = collect((array) ($preview['legacy_candidate_order'] ?? []));
        $phase3sRiskyRows = $this->riskyPhase3sRows($phase3sRows);
        $phase3oRiskyRows = $this->riskyPhase3oRows($phase3oRows, collect((array) ($preview['phase_3o_risky_rows'] ?? [])));
        $missingPreviewDiagnostics = $this->missingPreviewDiagnosticReasons($applySafety);
        $metadataOnlyOkCount = $phase3sRows
            ->where('audit_status', AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_METADATA_ONLY_OK)
            ->count()
            + $phase3oRows
                ->where('audit_status', AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK)
                ->count();

        [$status, $blockedReasons] = $this->objectiveStatus(
            previewStatus: $this->stringValue($preview['default_selection_preview_status'] ?? null),
            missingPreviewDiagnostics: $missingPreviewDiagnostics,
            shadowRecommendation: $this->shadowRecommendation($preview, $applySafety),
            phase3sRiskyCount: $phase3sRiskyRows->count(),
            phase3oRiskyCount: $phase3oRiskyRows->count(),
            readinessRiskCount: $this->readinessRiskCount($readinessRows, $canonicalRows, $summary, $applySafety),
            signatureRiskCount: $this->signatureRiskCount($signatureRows, $summary, $applySafety),
            continuityRiskCount: $this->numeric($applySafety, $summary, 'phase_3i_continuity_risk_count', 'continuity_risk_count'),
            lifecycleRiskCount: $this->numeric($applySafety, $summary, 'phase_3j_lifecycle_risk_count', 'lifecycle_risk_count'),
            duplicateRiskCount: $this->numeric($applySafety, $summary, 'duplicate_open_action_risk_count', 'duplicate_risk_count'),
            canonicalCoverageSufficient: $this->canonicalCoverageSufficient($applySafety, $legacyRows, $canonicalRows),
            orderMatches: $this->orderMatches($preview, $applySafety),
            candidateCount: $canonicalRows->count(),
        );

        return [
            'objective_id' => (string) $objective->id,
            'workspace_id' => $this->stringValue($objective->workspace_id),
            'site_id' => $this->stringValue($objective->client_site_id),
            'phase_3q_default_selection_preview_status' => $this->stringValue($preview['default_selection_preview_status'] ?? null),
            'phase_3p_shadow_recommendation' => $this->shadowRecommendation($preview, $applySafety),
            'phase_3s_audit_status' => $phase3sRiskyRows->isEmpty() ? 'clean_or_metadata_only_ok' : 'risky',
            'phase_3o_audit_status' => $phase3oRiskyRows->isEmpty() ? 'clean_or_metadata_only_ok' : 'risky',
            'phase_3l_readiness_status' => $this->readinessStatus($readinessRows, $canonicalRows),
            'phase_3h_signature_status' => $this->signatureRiskCount($signatureRows, $summary, $applySafety) === 0 ? 'match' : 'mismatch',
            'phase_3i_continuity_status' => $this->numeric($applySafety, $summary, 'phase_3i_continuity_risk_count', 'continuity_risk_count') === 0 ? 'no_blockers' : 'blocked',
            'phase_3j_lifecycle_status' => $this->numeric($applySafety, $summary, 'phase_3j_lifecycle_risk_count', 'lifecycle_risk_count') === 0 ? 'no_ambiguity_or_conflict' : 'ambiguous_or_conflicting',
            'duplicate_open_action_risk_count' => $this->numeric($applySafety, $summary, 'duplicate_open_action_risk_count', 'duplicate_risk_count'),
            'canonical_coverage_sufficient' => $this->canonicalCoverageSufficient($applySafety, $legacyRows, $canonicalRows),
            'legacy_candidate_count' => $legacyRows->count(),
            'canonical_candidate_count' => $canonicalRows->count(),
            'candidate_action_count' => $canonicalRows->sum(fn (array $row): int => count((array) ($row['action_types'] ?? []))),
            'canonical_legacy_order_exact_match' => $this->orderMatches($preview, $applySafety),
            'existing_default_selection_experiment_metadata_count' => $phase3sRows->count(),
            'existing_planner_experiment_metadata_count' => $phase3oRows->count(),
            'metadata_only_ok_count' => $metadataOnlyOkCount,
            'missing_preview_diagnostic_count' => count($missingPreviewDiagnostics),
            'metadata_only_action_ownership_approved' => false,
            'rollout_readiness_status' => $status,
            'blocked_reasons' => $blockedReasons,
            'phase_3s_risky_rows' => $phase3sRiskyRows->take(5)->values()->all(),
            'phase_3o_risky_rows' => $phase3oRiskyRows->take(5)->values()->all(),
            'metadata_only_ok_rows' => $phase3sRows
                ->where('audit_status', AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_METADATA_ONLY_OK)
                ->merge($phase3oRows->where('audit_status', AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK))
                ->take(5)
                ->values()
                ->all(),
            'phase_3q_preview_report' => $preview,
        ];
    }

    /**
     * @return Collection<int,AgenticMarketingObjective>
     */
    private function objectives(string $workspace, ?string $site, array $objectiveIds, mixed $objectiveGroup): Collection
    {
        return AgenticMarketingObjective::query()
            ->where('workspace_id', $workspace)
            ->when($site, fn (Builder $query, string $siteId): Builder => $query->where('client_site_id', $siteId))
            ->when($objectiveIds !== [], fn (Builder $query): Builder => $query->whereIn('id', $objectiveIds))
            ->when($objectiveIds === [] && $this->hasObjectiveGroupFilter($objectiveGroup), function (Builder $query) use ($objectiveGroup): Builder {
                $filter = is_array($objectiveGroup) ? $objectiveGroup : ['status' => $this->stringValue($objectiveGroup)];

                foreach (['status', 'priority', 'locale', 'target_market'] as $column) {
                    $value = $this->stringValue($filter[$column] ?? null);
                    if ($value !== null) {
                        $query->where($column, $value);
                    }
                }

                return $query;
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{0:string,1:array<int,string>}
     */
    private function objectiveStatus(
        ?string $previewStatus,
        array $missingPreviewDiagnostics,
        ?string $shadowRecommendation,
        int $phase3sRiskyCount,
        int $phase3oRiskyCount,
        int $readinessRiskCount,
        int $signatureRiskCount,
        int $continuityRiskCount,
        int $lifecycleRiskCount,
        int $duplicateRiskCount,
        bool $canonicalCoverageSufficient,
        bool $orderMatches,
        int $candidateCount,
    ): array {
        $blocked = [];

        if ($candidateCount < 1) {
            return [self::STATUS_KEEP_SINGLE_OBJECTIVE_SCOPE, ['no_canonical_candidates_in_objective_scope']];
        }

        if ($phase3sRiskyCount > 0) {
            return [self::STATUS_BLOCKED_BY_PHASE_3S, ['phase_3s_risky_default_selection_experiment_rows_exist']];
        }

        if ($previewStatus !== AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_PREVIEW_SAFE) {
            return [self::STATUS_BLOCKED_BY_PREVIEW, ['phase_3q_preview_status_is_not_preview_safe']];
        }

        if ($missingPreviewDiagnostics !== []) {
            return [self::STATUS_BLOCKED_BY_PREVIEW, $missingPreviewDiagnostics];
        }

        if (! in_array($shadowRecommendation, ['continue shadow', 'continue_shadow'], true)) {
            return [self::STATUS_BLOCKED_BY_SHADOW, ['phase_3p_shadow_recommendation_is_not_continue_shadow']];
        }

        if ($phase3oRiskyCount > 0) {
            return [self::STATUS_BLOCKED_BY_PHASE_3O, ['phase_3o_risky_planner_experiment_rows_exist']];
        }

        if ($readinessRiskCount > 0) {
            return [self::STATUS_BLOCKED_BY_READINESS, ['phase_3l_candidate_is_not_planner_candidate_ready_for_guarded_experiment']];
        }

        if ($signatureRiskCount > 0) {
            return [self::STATUS_BLOCKED_BY_SIGNATURE, ['phase_3h_signatures_do_not_match']];
        }

        if ($continuityRiskCount > 0) {
            return [self::STATUS_BLOCKED_BY_CONTINUITY, ['phase_3i_continuity_has_blockers']];
        }

        if ($lifecycleRiskCount > 0) {
            return [self::STATUS_BLOCKED_BY_LIFECYCLE, ['phase_3j_lifecycle_has_ambiguity_or_conflict']];
        }

        if ($duplicateRiskCount > 0) {
            return [self::STATUS_BLOCKED_BY_DUPLICATE_RISK, ['duplicate_open_action_risk_exists']];
        }

        if (! $canonicalCoverageSufficient) {
            $blocked[] = 'canonical_coverage_is_not_sufficient_per_objective';

            return [self::STATUS_INSUFFICIENT_CANONICAL_COVERAGE, $blocked];
        }

        if (! $orderMatches) {
            $blocked[] = 'canonical_proposed_order_does_not_exactly_match_legacy_order_per_objective';

            return [self::STATUS_ORDER_MISMATCH, $blocked];
        }

        return [self::STATUS_READY_FOR_SCOPED_EXPANSION, $blocked];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function summary(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [
                'inspected_objective_count' => 0,
                'ready_objective_count' => 0,
                'blocked_objective_count' => 0,
                'keep_single_objective_count' => 0,
                'phase_3s_clean_count' => 0,
                'phase_3s_risky_count' => 0,
                'metadata_only_ok_count' => 0,
                'phase_3o_risky_count' => 0,
                'preview_blocked_count' => 0,
                'shadow_blocked_count' => 0,
                'readiness_blocked_count' => 0,
                'signature_risk_count' => 0,
                'continuity_risk_count' => 0,
                'lifecycle_risk_count' => 0,
                'duplicate_risk_count' => 0,
                'insufficient_coverage_count' => 0,
                'order_mismatch_count' => 0,
                'candidate_action_count' => 0,
                'existing_phase_3r_metadata_count' => 0,
                'existing_phase_3n_metadata_count' => 0,
            ];
        }

        return [
            'inspected_objective_count' => $rows->count(),
            'ready_objective_count' => $rows->where('rollout_readiness_status', self::STATUS_READY_FOR_SCOPED_EXPANSION)->count(),
            'blocked_objective_count' => $rows->reject(fn (array $row): bool => in_array($row['rollout_readiness_status'], [
                self::STATUS_READY_FOR_SCOPED_EXPANSION,
                self::STATUS_KEEP_SINGLE_OBJECTIVE_SCOPE,
            ], true))->count(),
            'keep_single_objective_count' => $rows->where('rollout_readiness_status', self::STATUS_KEEP_SINGLE_OBJECTIVE_SCOPE)->count(),
            'phase_3s_clean_count' => $rows->where('phase_3s_audit_status', 'clean_or_metadata_only_ok')->count(),
            'phase_3s_risky_count' => $rows->where('phase_3s_audit_status', 'risky')->count(),
            'metadata_only_ok_count' => $rows->sum('metadata_only_ok_count'),
            'phase_3o_risky_count' => $rows->where('phase_3o_audit_status', 'risky')->count(),
            'preview_blocked_count' => $rows->where('rollout_readiness_status', self::STATUS_BLOCKED_BY_PREVIEW)->count(),
            'shadow_blocked_count' => $rows->where('rollout_readiness_status', self::STATUS_BLOCKED_BY_SHADOW)->count(),
            'readiness_blocked_count' => $rows->where('rollout_readiness_status', self::STATUS_BLOCKED_BY_READINESS)->count(),
            'signature_risk_count' => $rows->where('rollout_readiness_status', self::STATUS_BLOCKED_BY_SIGNATURE)->count(),
            'continuity_risk_count' => $rows->where('rollout_readiness_status', self::STATUS_BLOCKED_BY_CONTINUITY)->count(),
            'lifecycle_risk_count' => $rows->where('rollout_readiness_status', self::STATUS_BLOCKED_BY_LIFECYCLE)->count(),
            'duplicate_risk_count' => $rows->where('rollout_readiness_status', self::STATUS_BLOCKED_BY_DUPLICATE_RISK)->count(),
            'insufficient_coverage_count' => $rows->where('rollout_readiness_status', self::STATUS_INSUFFICIENT_CANONICAL_COVERAGE)->count(),
            'order_mismatch_count' => $rows->where('rollout_readiness_status', self::STATUS_ORDER_MISMATCH)->count(),
            'candidate_action_count' => $rows->sum('candidate_action_count'),
            'existing_phase_3r_metadata_count' => $rows->sum('existing_default_selection_experiment_metadata_count'),
            'existing_phase_3n_metadata_count' => $rows->sum('existing_planner_experiment_metadata_count'),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function overallStatus(Collection $rows): string
    {
        if ($rows->isEmpty()) {
            return self::STATUS_NO_CANDIDATE_SCOPE;
        }

        if ($rows->every(fn (array $row): bool => $row['rollout_readiness_status'] === self::STATUS_READY_FOR_SCOPED_EXPANSION)) {
            return self::STATUS_READY_FOR_SCOPED_EXPANSION;
        }

        if ($rows->every(fn (array $row): bool => in_array($row['rollout_readiness_status'], [
            self::STATUS_READY_FOR_SCOPED_EXPANSION,
            self::STATUS_KEEP_SINGLE_OBJECTIVE_SCOPE,
        ], true))) {
            return self::STATUS_KEEP_SINGLE_OBJECTIVE_SCOPE;
        }

        return (string) $rows
            ->first(fn (array $row): bool => ! in_array($row['rollout_readiness_status'], [
                self::STATUS_READY_FOR_SCOPED_EXPANSION,
                self::STATUS_KEEP_SINGLE_OBJECTIVE_SCOPE,
            ], true))['rollout_readiness_status'];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function recommendation(Collection $rows): string
    {
        $status = $this->overallStatus($rows);

        return match ($status) {
            self::STATUS_READY_FOR_SCOPED_EXPANSION => 'eligible for limited multi-objective Phase 3U',
            self::STATUS_KEEP_SINGLE_OBJECTIVE_SCOPE => 'continue single-objective experiment',
            self::STATUS_NO_CANDIDATE_SCOPE => 'keep legacy',
            default => 'blocked',
        };
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $phase3sRows
     * @return Collection<int,array<string,mixed>>
     */
    private function riskyPhase3sRows(Collection $phase3sRows): Collection
    {
        return $phase3sRows
            ->reject(fn (array $row): bool => in_array($row['audit_status'] ?? null, [
                AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_CLEAN,
                AgenticPlannerDefaultSelectionExperimentAuditService::STATUS_METADATA_ONLY_OK,
            ], true))
            ->values();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $phase3oRows
     * @param  Collection<int,array<string,mixed>>  $previewRiskyRows
     * @return Collection<int,array<string,mixed>>
     */
    private function riskyPhase3oRows(Collection $phase3oRows, Collection $previewRiskyRows): Collection
    {
        return $phase3oRows
            ->merge($previewRiskyRows)
            ->reject(fn (array $row): bool => in_array($row['audit_status'] ?? null, [
                AgenticPlannerApplyExperimentAuditService::STATUS_CLEAN,
                AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK,
            ], true))
            ->values();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $readinessRows
     * @param  Collection<int,array<string,mixed>>  $canonicalRows
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>  $applySafety
     */
    private function readinessRiskCount(Collection $readinessRows, Collection $canonicalRows, array $summary, array $applySafety): int
    {
        $canonicalLegacyIds = $canonicalRows
            ->pluck('legacy_opportunity_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->filter()
            ->values();
        $readinessByLegacyId = $readinessRows->keyBy('legacy_agentic_opportunity_id');
        $canonicalNotReady = $canonicalLegacyIds
            ->filter(fn (string $id): bool => ($readinessByLegacyId[$id]['readiness_status'] ?? null) !== AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY)
            ->count();

        return max(
            $canonicalNotReady,
            (int) ($applySafety['phase_3l_canonical_readiness_regression_count'] ?? 0),
            (int) ($summary['readiness_regression_count'] ?? 0),
        );
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $signatureRows
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>  $applySafety
     */
    private function signatureRiskCount(Collection $signatureRows, array $summary, array $applySafety): int
    {
        return max(
            $signatureRows->where('equivalent', false)->count(),
            (int) ($applySafety['phase_3h_signature_risk_count'] ?? 0),
            (int) ($summary['signature_risk_count'] ?? $summary['signature_mismatch_count'] ?? 0),
        );
    }

    /**
     * @param  array<string,mixed>  $applySafety
     * @param  array<string,mixed>  $summary
     */
    private function numeric(array $applySafety, array $summary, string $applyKey, string $summaryKey): int
    {
        return max((int) ($applySafety[$applyKey] ?? 0), (int) ($summary[$summaryKey] ?? 0));
    }

    /**
     * @param  array<string,mixed>  $applySafety
     * @param  Collection<int,array<string,mixed>>  $legacyRows
     * @param  Collection<int,array<string,mixed>>  $canonicalRows
     */
    private function canonicalCoverageSufficient(array $applySafety, Collection $legacyRows, Collection $canonicalRows): bool
    {
        if (array_key_exists('canonical_coverage_sufficient', $applySafety)) {
            return (bool) $applySafety['canonical_coverage_sufficient'];
        }

        if ($legacyRows->isEmpty()) {
            return $canonicalRows->isNotEmpty();
        }

        $canonicalLegacyIds = $canonicalRows->pluck('legacy_opportunity_id')->map(fn (mixed $id): string => (string) $id)->all();

        return $legacyRows
            ->pluck('legacy_opportunity_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->every(fn (string $id): bool => in_array($id, $canonicalLegacyIds, true));
    }

    /**
     * @param  array<string,mixed>  $preview
     * @param  array<string,mixed>  $applySafety
     */
    private function orderMatches(array $preview, array $applySafety): bool
    {
        if (array_key_exists('exact_order_match', $applySafety)) {
            return (bool) $applySafety['exact_order_match'];
        }

        return (bool) ($preview['exact_order_match'] ?? false);
    }

    /**
     * @param  array<string,mixed>  $applySafety
     * @return array<int,string>
     */
    private function missingPreviewDiagnosticReasons(array $applySafety): array
    {
        $requiredKeys = [
            'phase_3p_recommendation',
            'phase_3o_risky_count',
            'phase_3l_canonical_readiness_regression_count',
            'phase_3h_signature_risk_count',
            'phase_3i_continuity_risk_count',
            'phase_3j_lifecycle_risk_count',
            'duplicate_open_action_risk_count',
            'canonical_coverage_sufficient',
            'exact_order_match',
        ];

        return collect($requiredKeys)
            ->reject(fn (string $key): bool => array_key_exists($key, $applySafety))
            ->map(fn (string $key): string => 'phase_3q_apply_safety_missing_'.$key)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $readinessRows
     * @param  Collection<int,array<string,mixed>>  $canonicalRows
     */
    private function readinessStatus(Collection $readinessRows, Collection $canonicalRows): string
    {
        return $this->readinessRiskCount($readinessRows, $canonicalRows, [], []) === 0
            ? AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY
            : 'not_ready';
    }

    /**
     * @param  array<string,mixed>  $preview
     * @param  array<string,mixed>  $applySafety
     */
    private function shadowRecommendation(array $preview, array $applySafety): ?string
    {
        return $this->stringValue($preview['phase_3p_shadow_recommendation'] ?? $applySafety['phase_3p_recommendation'] ?? null);
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyReport(?string $workspace, ?string $site, ?string $detector, int $limit): array
    {
        $rows = collect();

        return [
            'workspace_id' => $workspace,
            'site_id' => $site,
            'detector_key' => $detector,
            'limit_per_objective' => $limit,
            'rollout_readiness_status' => self::STATUS_NO_CANDIDATE_SCOPE,
            'summary' => $this->summary($rows),
            'objective_rows' => [],
            'recommendation' => 'keep legacy',
        ];
    }

    private function hasObjectiveGroupFilter(mixed $value): bool
    {
        if (is_array($value)) {
            return collect($value)->filter(fn (mixed $item): bool => $this->stringValue($item) !== null)->isNotEmpty();
        }

        return $this->stringValue($value) !== null;
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
}
