<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AgenticPlannerApplyExperimentAuditService
{
    public const STATUS_CLEAN = 'clean';

    public const STATUS_METADATA_ONLY_OK = 'metadata_only_ok';

    public const STATUS_STALE_CANONICAL_LINK = 'stale_canonical_link';

    public const STATUS_MISSING_LEGACY_PARENT = 'missing_legacy_parent';

    public const STATUS_MISSING_CANONICAL_CONTEXT = 'missing_canonical_context';

    public const STATUS_BRIDGE_MISMATCH = 'bridge_mismatch';

    public const STATUS_SIGNATURE_MISMATCH = 'signature_mismatch';

    public const STATUS_READINESS_REGRESSED = 'readiness_regressed';

    public const STATUS_DUPLICATE_RISK = 'duplicate_risk';

    public const STATUS_LIFECYCLE_RISK = 'lifecycle_risk';

    public const STATUS_CONTINUITY_RISK = 'continuity_risk';

    public function __construct(
        private readonly AgenticPlannerReadinessInspectionService $readiness,
        private readonly AgenticOpportunityActionSignatureService $signatures,
        private readonly AgenticOpportunityCanonicalMappingService $mapping,
    ) {}

    /**
     * @param  array{workspace?:string|null,objective?:string|null,site?:string|null,detector?:string|null,status?:string|null,action_status?:string|null,limit?:int|null}  $filters
     * @return array{summary:array<string,mixed>,rows:array<int,array<string,mixed>>,rollback:array<string,mixed>}
     */
    public function audit(array $filters = []): array
    {
        $rows = collect();
        $limit = max(1, (int) ($filters['limit'] ?? 100));
        $detectorFilter = $this->stringValue($filters['detector'] ?? null);
        $statusFilter = $this->stringValue($filters['status'] ?? null);

        $this->query($filters)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (AgenticMarketingAction $action) use ($rows, $limit, $detectorFilter, $statusFilter): void {
                if ($rows->count() >= $limit) {
                    return;
                }

                $row = $this->inspectAction($action);

                if ($detectorFilter && ($row['detector_key'] ?? null) !== $detectorFilter) {
                    return;
                }

                if ($statusFilter && $row['audit_status'] !== $statusFilter) {
                    return;
                }

                $rows->push($row);
            });

        return [
            'summary' => $this->summary($rows),
            'rows' => $rows->values()->all(),
            'rollback' => $this->rollbackPlan($rows),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return Builder<AgenticMarketingAction>
     */
    private function query(array $filters): Builder
    {
        return AgenticMarketingAction::query()
            ->with(['objective', 'opportunity'])
            ->where('payload->planner_experiment->version', AgenticCanonicalPlannerApplyExperimentService::METADATA_VERSION)
            ->when($this->stringValue($filters['objective'] ?? null), fn (Builder $query, string $objective): Builder => $query->where('objective_id', $objective))
            ->when($this->stringValue($filters['action_status'] ?? null), fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($this->stringValue($filters['workspace'] ?? null), function (Builder $query, string $workspace): Builder {
                return $query->whereHas('objective', fn (Builder $objective): Builder => $objective->where('workspace_id', $workspace));
            })
            ->when($this->stringValue($filters['site'] ?? null), function (Builder $query, string $site): Builder {
                return $query->where(function (Builder $siteQuery) use ($site): void {
                    $siteQuery->whereHas('objective', fn (Builder $objective): Builder => $objective->where('client_site_id', $site))
                        ->orWhere('payload->client_site_id', $site);
                });
            });
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectAction(AgenticMarketingAction $action): array
    {
        $metadata = (array) data_get($action->payload, 'planner_experiment', []);
        $metadataLegacyId = $this->stringValue($metadata['legacy_agentic_marketing_opportunity_id'] ?? null);
        $canonicalId = $this->stringValue($metadata['canonical_opportunity_id'] ?? null);
        $legacyId = $this->stringValue($action->opportunity_id);
        $legacy = $legacyId ? AgenticMarketingOpportunity::query()->with('objective')->whereKey($legacyId)->first() : null;
        $canonical = $canonicalId ? Opportunity::withTrashed()->whereKey($canonicalId)->first() : null;
        $canonicalExists = $canonical !== null && ! $canonical->trashed();
        $canonicalDeleted = $canonical !== null && $canonical->trashed();
        $canonicalBridgeLegacyId = $this->stringValue($canonical?->agentic_marketing_opportunity_id);
        $canonicalBridgePointsBack = $canonicalExists && $canonicalBridgeLegacyId === $legacyId && $metadataLegacyId === $legacyId;
        $actionRemainsLegacyOwned = $legacy !== null && $metadataLegacyId === $legacyId && $legacyId !== null && $legacyId !== $canonicalId;
        $detectorKey = $legacy ? $this->mapping->mapExisting($legacy)->detectorKey : $this->stringValue(data_get($action->payload, 'detector'));
        $signature = $this->signatureStatus($action, $canonicalExists ? $canonical : null, $metadata);
        $readiness = $legacy ? $this->readiness->inspect($legacy) : null;
        $duplicateRisk = $this->duplicateOpenActionRisk($action, $readiness);
        $phase3iPasses = $this->continuityPassesForAuditedAction($action, $readiness);
        $phase3jRisk = $this->lifecycleRiskForAuditedAction($action, $readiness);
        $phase3jConflict = $readiness
            ? (bool) data_get($readiness, 'phase_3j_lifecycle_action_ownership_status.status_conflict')
            : false;
        $readinessPasses = $this->readinessPassesForAuditedAction($action, $readiness);
        $metadataOnlyOk = $readiness
            && (($readiness['readiness_status'] ?? null) === AgenticPlannerReadinessInspectionService::STATUS_METADATA_READY_ONLY
                || ($phase3jRisk && ! $phase3jConflict && $readinessPasses));

        [$auditStatus, $blockedReasons, $warningReasons] = $this->auditStatus(
            actionRemainsLegacyOwned: $actionRemainsLegacyOwned,
            legacyExists: $legacy !== null,
            canonicalId: $canonicalId,
            canonicalExists: $canonicalExists,
            canonicalDeleted: $canonicalDeleted,
            canonicalBridgeLegacyId: $canonicalBridgeLegacyId,
            canonicalBridgePointsBack: $canonicalBridgePointsBack,
            signatureMatches: (bool) $signature['matches'],
            phase3iPasses: $phase3iPasses,
            phase3jRisk: $phase3jRisk,
            phase3jConflict: $phase3jConflict,
            duplicateRisk: $duplicateRisk,
            readinessPasses: $readinessPasses,
            metadataOnlyOk: $metadataOnlyOk,
        );

        return [
            'action_id' => (string) $action->id,
            'action_status' => (string) $action->status,
            'legacy_opportunity_id' => $legacyId,
            'payload_legacy_opportunity_id' => $metadataLegacyId,
            'canonical_opportunity_id' => $canonicalId,
            'objective_id' => $this->stringValue($action->objective_id),
            'workspace_id' => $this->stringValue($action->objective?->workspace_id) ?: $this->stringValue($metadata['workspace_id'] ?? null),
            'site_id' => $this->stringValue($action->objective?->client_site_id) ?: $this->stringValue(data_get($action->payload, 'client_site_id')),
            'detector_key' => $detectorKey,
            'action_type' => (string) $action->action_type,
            'created_or_reused_at' => $action->created_at?->toIso8601String(),
            'planner_experiment_applied_at' => $this->stringValue($metadata['applied_at'] ?? null),
            'legacy_opportunity_exists' => $legacy !== null,
            'canonical_opportunity_exists' => $canonicalExists,
            'canonical_bridge_points_back_to_same_legacy_opportunity' => $canonicalBridgePointsBack,
            'action_remains_legacy_owned' => $actionRemainsLegacyOwned,
            'phase_3h_source_signature_matches' => (bool) $signature['matches'],
            'phase_3h_source_signature' => $signature,
            'phase_3l_readiness_would_pass_today' => $readinessPasses,
            'phase_3l_readiness_status' => $readiness['readiness_status'] ?? null,
            'phase_3i_continuity_would_pass_today' => $phase3iPasses,
            'phase_3j_lifecycle_has_become_ambiguous_or_conflicting' => $phase3jRisk,
            'duplicate_open_action_risk_now_exists' => $duplicateRisk,
            'audit_status' => $auditStatus,
            'blocked_reasons' => $blockedReasons,
            'warning_reasons' => $warningReasons,
        ];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function signatureStatus(AgenticMarketingAction $action, ?Opportunity $canonical, array $metadata): array
    {
        $stored = $this->stringValue($metadata['phase_3m_signature'] ?? null);
        $current = $canonical
            ? $this->signatures->forCanonicalActionCandidate($canonical, (string) $action->action_type)
            : null;
        $currentSignature = $this->stringValue($current['signature'] ?? null);

        return [
            'stored_signature' => $stored,
            'current_signature' => $currentSignature,
            'signature_version' => AgenticOpportunityActionSignatureService::SIGNATURE_VERSION,
            'matches' => $stored !== null && $currentSignature !== null && hash_equals($stored, $currentSignature),
            'blocked_reasons' => (array) ($current['blocked_reasons'] ?? ($canonical ? [] : ['missing_canonical_context'])),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $readiness
     */
    private function duplicateOpenActionRisk(AgenticMarketingAction $action, ?array $readiness): bool
    {
        $otherOpenActionExists = AgenticMarketingAction::query()
            ->where('opportunity_id', $action->opportunity_id)
            ->where('action_type', $action->action_type)
            ->where('dedupe_hash', $action->dedupe_hash)
            ->whereKeyNot($action->id)
            ->open()
            ->exists();

        if ($otherOpenActionExists) {
            return true;
        }

        $duplicateActionIds = $this->readinessDuplicateActionIds($readiness);

        return collect($duplicateActionIds)
            ->reject(fn (string $id): bool => $id === (string) $action->id)
            ->isNotEmpty();
    }

    /**
     * @param  array<string,mixed>|null  $readiness
     */
    private function readinessPassesForAuditedAction(AgenticMarketingAction $action, ?array $readiness): bool
    {
        if (! $readiness) {
            return false;
        }

        if (in_array($readiness['readiness_status'] ?? null, [
            AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY,
            AgenticPlannerReadinessInspectionService::STATUS_METADATA_READY_ONLY,
        ], true)) {
            return true;
        }

        $continuityPassesForAuditedAction = $this->continuityPassesForAuditedAction($action, $readiness);
        $blockedReasons = collect((array) ($readiness['readiness_blocked_reasons'] ?? []))
            ->reject(fn (string $reason): bool => $reason === 'canonical_action_would_duplicate_open_legacy_action')
            ->reject(fn (string $reason): bool => $continuityPassesForAuditedAction && $reason === 'canonical_parent_only_lookup_would_miss_actions')
            ->reject(fn (string $reason): bool => $continuityPassesForAuditedAction && $reason === 'canonical_parent_only_lookup_would_miss_action_runs')
            ->reject(fn (string $reason): bool => $reason === 'phase_3j_lifecycle_status_ambiguous')
            ->values()
            ->all();

        return $blockedReasons === []
            && collect($this->readinessDuplicateActionIds($readiness))
                ->every(fn (string $id): bool => $id === (string) $action->id);
    }

    /**
     * @param  array<string,mixed>|null  $readiness
     */
    private function continuityPassesForAuditedAction(AgenticMarketingAction $action, ?array $readiness): bool
    {
        if (! $readiness) {
            return false;
        }

        $blockers = (array) data_get($readiness, 'phase_3i_continuity_status.canonical_parent_only_lookup_blockers', []);
        if ($blockers === []) {
            return true;
        }

        $remainingBlockers = collect($blockers)
            ->reject(fn (string $reason): bool => in_array($reason, [
                'canonical_parent_only_lookup_would_miss_actions',
                'canonical_parent_only_lookup_would_miss_action_runs',
            ], true))
            ->values()
            ->all();

        if ($remainingBlockers !== []) {
            return false;
        }

        $actionIds = AgenticMarketingAction::query()
            ->where('opportunity_id', $action->opportunity_id)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        if ($actionIds !== [(string) $action->id]) {
            return false;
        }

        $actionRunIds = AgenticActionRun::query()
            ->where('opportunity_id', $action->opportunity_id)
            ->orWhere('action_id', $action->id)
            ->pluck('action_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();

        return $actionRunIds === [] || $actionRunIds === [(string) $action->id];
    }

    /**
     * @param  array<string,mixed>|null  $readiness
     */
    private function lifecycleRiskForAuditedAction(AgenticMarketingAction $action, ?array $readiness): bool
    {
        if (! $readiness) {
            return false;
        }

        if ((bool) data_get($readiness, 'phase_3j_lifecycle_action_ownership_status.status_conflict')) {
            return true;
        }

        if (! (bool) data_get($readiness, 'phase_3j_lifecycle_action_ownership_status.lifecycle_status_ambiguous')) {
            return false;
        }

        $blockedReasons = (array) data_get($readiness, 'phase_3j_lifecycle_action_ownership_status.blocked_reasons', []);
        $remainingReasons = collect($blockedReasons)
            ->reject(fn (string $reason): bool => in_array($reason, [
                'dismissed_agentic_status_has_open_or_running_actions',
                'canonical_action_would_duplicate_open_legacy_action',
            ], true))
            ->values()
            ->all();

        if ($remainingReasons !== []) {
            return true;
        }

        $openActionIds = AgenticMarketingAction::query()
            ->where('opportunity_id', $action->opportunity_id)
            ->open()
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return $openActionIds !== [(string) $action->id];
    }

    /**
     * @param  array<string,mixed>|null  $readiness
     * @return array<int,string>
     */
    private function readinessDuplicateActionIds(?array $readiness): array
    {
        return collect((array) data_get($readiness, 'duplicate_action_risk.items', []))
            ->flatMap(fn (array $item): array => (array) ($item['action_ids'] ?? []))
            ->map(fn (mixed $id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{0:string,1:array<int,string>,2:array<int,string>}
     */
    private function auditStatus(
        bool $actionRemainsLegacyOwned,
        bool $legacyExists,
        ?string $canonicalId,
        bool $canonicalExists,
        bool $canonicalDeleted,
        ?string $canonicalBridgeLegacyId,
        bool $canonicalBridgePointsBack,
        bool $signatureMatches,
        bool $phase3iPasses,
        bool $phase3jRisk,
        bool $phase3jConflict,
        bool $duplicateRisk,
        bool $readinessPasses,
        bool $metadataOnlyOk,
    ): array {
        $blocked = [];
        $warnings = [];

        if (! $legacyExists || ! $actionRemainsLegacyOwned) {
            $blocked[] = 'action_no_longer_resolves_to_matching_legacy_agentic_marketing_opportunity';

            return [self::STATUS_MISSING_LEGACY_PARENT, $blocked, $warnings];
        }

        if (! $canonicalId || (! $canonicalExists && ! $canonicalDeleted)) {
            $blocked[] = 'metadata_canonical_opportunity_missing';

            return [self::STATUS_MISSING_CANONICAL_CONTEXT, $blocked, $warnings];
        }

        if ($canonicalDeleted || $canonicalBridgeLegacyId === null) {
            $warnings[] = $canonicalDeleted
                ? 'metadata_canonical_opportunity_is_soft_deleted'
                : 'metadata_canonical_opportunity_no_longer_has_agentic_bridge';

            return [self::STATUS_STALE_CANONICAL_LINK, $blocked, $warnings];
        }

        if (! $canonicalBridgePointsBack) {
            $blocked[] = 'metadata_canonical_bridge_points_to_different_legacy_opportunity';

            return [self::STATUS_BRIDGE_MISMATCH, $blocked, $warnings];
        }

        if ($duplicateRisk) {
            $blocked[] = 'duplicate_open_action_risk_now_exists';

            return [self::STATUS_DUPLICATE_RISK, $blocked, $warnings];
        }

        if (! $phase3iPasses) {
            $blocked[] = 'phase_3i_continuity_no_longer_passes';

            return [self::STATUS_CONTINUITY_RISK, $blocked, $warnings];
        }

        if ($phase3jConflict) {
            $blocked[] = 'phase_3j_lifecycle_now_ambiguous_or_conflicting';

            return [self::STATUS_LIFECYCLE_RISK, $blocked, $warnings];
        }

        if (! $readinessPasses) {
            $blocked[] = 'phase_3l_readiness_no_longer_passes';

            return [self::STATUS_READINESS_REGRESSED, $blocked, $warnings];
        }

        if (! $signatureMatches) {
            $blocked[] = 'phase_3h_source_signature_no_longer_matches';

            return [self::STATUS_SIGNATURE_MISMATCH, $blocked, $warnings];
        }

        if ($phase3jRisk && ! $metadataOnlyOk) {
            $blocked[] = 'phase_3j_lifecycle_now_ambiguous_or_conflicting';

            return [self::STATUS_LIFECYCLE_RISK, $blocked, $warnings];
        }

        if ($metadataOnlyOk) {
            $warnings[] = 'phase_3l_reports_metadata_ready_only_not_planner_ready';

            return [self::STATUS_METADATA_ONLY_OK, $blocked, $warnings];
        }

        return [self::STATUS_CLEAN, $blocked, $warnings];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function summary(Collection $rows): array
    {
        return [
            'inspected_action_count' => $rows->count(),
            'clean_count' => $rows->where('audit_status', self::STATUS_CLEAN)->count(),
            'metadata_only_ok_count' => $rows->where('audit_status', self::STATUS_METADATA_ONLY_OK)->count(),
            'stale_canonical_link_count' => $rows->where('audit_status', self::STATUS_STALE_CANONICAL_LINK)->count(),
            'missing_legacy_parent_count' => $rows->where('audit_status', self::STATUS_MISSING_LEGACY_PARENT)->count(),
            'missing_canonical_context_count' => $rows->where('audit_status', self::STATUS_MISSING_CANONICAL_CONTEXT)->count(),
            'bridge_mismatch_count' => $rows->where('audit_status', self::STATUS_BRIDGE_MISMATCH)->count(),
            'signature_mismatch_count' => $rows->where('audit_status', self::STATUS_SIGNATURE_MISMATCH)->count(),
            'readiness_regression_count' => $rows->where('audit_status', self::STATUS_READINESS_REGRESSED)->count(),
            'duplicate_risk_count' => $rows->where('audit_status', self::STATUS_DUPLICATE_RISK)->count(),
            'lifecycle_risk_count' => $rows->where('audit_status', self::STATUS_LIFECYCLE_RISK)->count(),
            'continuity_risk_count' => $rows->where('audit_status', self::STATUS_CONTINUITY_RISK)->count(),
            'recommended_next_step' => $this->recommendedNextStep($rows),
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function rollbackPlan(Collection $rows): array
    {
        $candidates = $rows->filter(fn (array $row): bool => (bool) $row['action_remains_legacy_owned'] && (bool) $row['legacy_opportunity_exists']);
        $unsafe = $rows->reject(fn (array $row): bool => (bool) $row['action_remains_legacy_owned'] && (bool) $row['legacy_opportunity_exists']);

        return [
            'inspected_action_count' => $rows->count(),
            'metadata_rollback_candidate_count' => $candidates->count(),
            'unsafe_rollback_count' => $unsafe->count(),
            'rows' => $rows->map(fn (array $row): array => [
                'action_id' => $row['action_id'],
                'legacy_opportunity_id' => $row['legacy_opportunity_id'],
                'canonical_opportunity_id' => $row['canonical_opportunity_id'],
                'metadata_paths_that_would_be_removed' => ['payload.planner_experiment'],
                'rollback_safe' => (bool) $row['action_remains_legacy_owned'] && (bool) $row['legacy_opportunity_exists'],
                'reasons' => $row['blocked_reasons'] === []
                    ? array_merge(['metadata_only_removal_would_leave_legacy_action_ownership_intact'], $row['warning_reasons'])
                    : $row['blocked_reasons'],
            ])->values()->all(),
            'recommendation' => $unsafe->isEmpty()
                ? 'Default rollback remains flag-off plus ignoring payload.planner_experiment; no metadata removal writer is implemented in Phase 3O.'
                : 'Do not remove metadata until unsafe legacy ownership rows are investigated; default rollback remains flag-off plus ignoring payload.planner_experiment.',
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function recommendedNextStep(Collection $rows): string
    {
        if ($rows->isEmpty()) {
            return 'No Phase 3N planner apply metadata matched the filters.';
        }

        $risky = $rows->reject(fn (array $row): bool => in_array($row['audit_status'], [
            self::STATUS_CLEAN,
            self::STATUS_METADATA_ONLY_OK,
        ], true));

        return $risky->isEmpty()
            ? 'Keep default planner legacy-owned; continue scoped observation before any Phase 3P shadow rollout.'
            : 'Resolve risky rows before Phase 3P; keep default planner legacy-owned and leave apply experiment metadata operationally ignored.';
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
