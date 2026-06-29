<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use App\Models\AgenticActionRun;
use App\Models\AgenticMarketingAction;
use App\Models\AgenticMarketingObjective;
use App\Models\AgenticMarketingOpportunity;
use App\Models\Opportunity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AgenticPlannerDefaultSelectionExperimentAuditService
{
    public const STATUS_CLEAN = 'clean';

    public const STATUS_METADATA_ONLY_OK = 'metadata_only_ok';

    public const STATUS_MISSING_LEGACY_PARENT = 'missing_legacy_parent';

    public const STATUS_MISSING_CANONICAL_CONTEXT = 'missing_canonical_context';

    public const STATUS_BRIDGE_MISMATCH = 'bridge_mismatch';

    public const STATUS_PREVIEW_REGRESSED = 'preview_regressed';

    public const STATUS_SHADOW_REGRESSED = 'shadow_regressed';

    public const STATUS_PHASE_3O_AUDIT_RISK = 'phase_3o_audit_risk';

    public const STATUS_READINESS_REGRESSED = 'readiness_regressed';

    public const STATUS_SIGNATURE_MISMATCH = 'signature_mismatch';

    public const STATUS_CONTINUITY_RISK = 'continuity_risk';

    public const STATUS_LIFECYCLE_RISK = 'lifecycle_risk';

    public const STATUS_DUPLICATE_RISK = 'duplicate_risk';

    public const STATUS_OWNERSHIP_RISK = 'ownership_risk';

    public function __construct(
        private readonly AgenticCanonicalPlannerDefaultSelectionPreviewService $preview,
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
        $previewCache = [];

        $this->query($filters)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (AgenticMarketingAction $action) use ($rows, $limit, $detectorFilter, $statusFilter, $filters, &$previewCache): void {
                if ($rows->count() >= $limit) {
                    return;
                }

                $row = $this->inspectAction($action, $filters, $previewCache);

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
            ->where('payload->default_selection_experiment->version', AgenticCanonicalPlannerDefaultSelectionExperimentService::METADATA_VERSION)
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
     * @param  array<string,mixed>  $filters
     * @param  array<string,array<string,mixed>>  $previewCache
     * @return array<string,mixed>
     */
    private function inspectAction(AgenticMarketingAction $action, array $filters, array &$previewCache): array
    {
        $metadata = (array) data_get($action->payload, 'default_selection_experiment', []);
        $metadataLegacyId = $this->stringValue($metadata['legacy_agentic_marketing_opportunity_id'] ?? null);
        $canonicalId = $this->stringValue($metadata['canonical_opportunity_id'] ?? null);
        $legacyId = $this->stringValue($action->opportunity_id);
        $legacy = $legacyId ? AgenticMarketingOpportunity::query()->with('objective')->whereKey($legacyId)->first() : null;
        $canonical = $canonicalId ? Opportunity::withTrashed()->whereKey($canonicalId)->first() : null;
        $canonicalExists = $canonical !== null && ! $canonical->trashed();
        $canonicalBridgeLegacyId = $this->stringValue($canonical?->agentic_marketing_opportunity_id);
        $canonicalBridgePointsBack = $canonicalExists
            && $canonicalBridgeLegacyId === $legacyId
            && $metadataLegacyId === $legacyId;
        $actionRemainsLegacyOwned = $legacy !== null
            && $metadataLegacyId === $legacyId
            && $legacyId !== null
            && $legacyId !== $canonicalId;
        $detectorKey = $legacy ? $this->mapping->mapExisting($legacy)->detectorKey : $this->stringValue(data_get($action->payload, 'detector'));
        $previewReport = $this->previewReport($action, $legacy, $filters, $detectorKey, $previewCache);
        $previewSelectedRow = $this->previewSelectedRow($previewReport, $metadataLegacyId, $canonicalId);
        $phase3qPreviewSafe = ($previewReport['default_selection_preview_status'] ?? null) === AgenticCanonicalPlannerDefaultSelectionPreviewService::STATUS_PREVIEW_SAFE
            && $previewSelectedRow !== null;
        $phase3pContinueShadow = in_array($previewReport['phase_3p_shadow_recommendation'] ?? data_get($previewReport, 'apply_safety.phase_3p_recommendation'), [
            'continue shadow',
            'continue_shadow',
        ], true);
        $phase3o = $this->phase3oStatus($previewReport, $metadataLegacyId, $action);
        $readiness = $legacy ? $this->readiness->inspect($legacy) : null;
        $signature = $this->signatureStatus($action, $canonicalExists ? $canonical : null, $metadata);
        $duplicateRisk = $this->duplicateOpenActionRisk($action, $readiness);
        $phase3iPasses = $this->continuityPassesForAuditedAction($action, $readiness);
        $phase3jRisk = $this->lifecycleRiskForAuditedAction($action, $readiness);
        $phase3jConflict = $readiness
            ? (bool) data_get($readiness, 'phase_3j_lifecycle_action_ownership_status.status_conflict')
            : false;
        $phase3jNonAmbiguous = ! $phase3jRisk && ! $phase3jConflict;
        $readinessPasses = $this->readinessPassesForAuditedAction($action, $readiness);

        [$auditStatus, $blockedReasons, $warningReasons] = $this->auditStatus(
            legacyExists: $legacy !== null,
            actionRemainsLegacyOwned: $actionRemainsLegacyOwned,
            metadataLegacyId: $metadataLegacyId,
            legacyId: $legacyId,
            canonicalExists: $canonicalExists,
            canonicalBridgePointsBack: $canonicalBridgePointsBack,
            phase3qPreviewSafe: $phase3qPreviewSafe,
            phase3pContinueShadow: $phase3pContinueShadow,
            phase3oOk: (bool) $phase3o['ok'],
            phase3oStatus: $this->stringValue($phase3o['status'] ?? null),
            readinessPasses: $readinessPasses,
            signatureMatches: (bool) $signature['matches'],
            phase3iPasses: $phase3iPasses,
            phase3jNonAmbiguous: $phase3jNonAmbiguous,
            duplicateRisk: $duplicateRisk,
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
            'applied_at' => $this->stringValue($metadata['applied_at'] ?? null),
            'legacy_opportunity_exists' => $legacy !== null,
            'canonical_opportunity_exists' => $canonicalExists,
            'canonical_bridge_points_back_to_same_legacy_opportunity' => $canonicalBridgePointsBack,
            'action_remains_legacy_owned' => $actionRemainsLegacyOwned,
            'phase_3q_preview_safe_for_objective_scope' => $phase3qPreviewSafe,
            'phase_3q_preview_status' => $this->stringValue($previewReport['default_selection_preview_status'] ?? null),
            'phase_3p_still_recommends_continue_shadow' => $phase3pContinueShadow,
            'phase_3p_recommendation' => $this->stringValue($previewReport['phase_3p_shadow_recommendation'] ?? data_get($previewReport, 'apply_safety.phase_3p_recommendation')),
            'phase_3o_audit_status_is_clean_or_metadata_only_ok' => (bool) $phase3o['ok'],
            'phase_3o_audit_status' => $phase3o['status'],
            'phase_3l_readiness_still_passes' => $readinessPasses,
            'phase_3l_readiness_status' => $readiness['readiness_status'] ?? null,
            'phase_3h_signature_still_matches' => (bool) $signature['matches'],
            'phase_3h_signature' => $signature,
            'phase_3i_continuity_has_no_blockers' => $phase3iPasses,
            'phase_3j_lifecycle_is_non_ambiguous' => $phase3jNonAmbiguous,
            'duplicate_open_action_risk_now_exists' => $duplicateRisk,
            'audit_status' => $auditStatus,
            'blocked_reasons' => $blockedReasons,
            'warning_reasons' => $warningReasons,
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @param  array<string,array<string,mixed>>  $previewCache
     * @return array<string,mixed>
     */
    private function previewReport(AgenticMarketingAction $action, ?AgenticMarketingOpportunity $legacy, array $filters, ?string $detectorKey, array &$previewCache): array
    {
        $objective = $action->objective;
        if (! $objective instanceof AgenticMarketingObjective && $legacy?->objective instanceof AgenticMarketingObjective) {
            $objective = $legacy->objective;
        }

        if (! $objective instanceof AgenticMarketingObjective) {
            return [];
        }

        $site = $this->stringValue($filters['site'] ?? null) ?: $this->stringValue($objective->client_site_id);
        $detector = $this->stringValue($filters['detector'] ?? null) ?: $detectorKey;
        $limit = max(1, (int) ($filters['limit'] ?? 100));
        $cacheKey = implode('|', [(string) $objective->id, $site ?? '', $detector ?? '', (string) $limit]);

        if (! array_key_exists($cacheKey, $previewCache)) {
            $previewCache[$cacheKey] = $this->preview->preview($objective, [
                'site' => $site,
                'detector' => $detector,
                'limit' => $limit,
            ]);
        }

        return $previewCache[$cacheKey];
    }

    /**
     * @param  array<string,mixed>  $previewReport
     * @return array<string,mixed>|null
     */
    private function previewSelectedRow(array $previewReport, ?string $metadataLegacyId, ?string $canonicalId): ?array
    {
        return collect((array) ($previewReport['canonical_proposed_default_order'] ?? []))
            ->first(fn (array $row): bool => (string) ($row['legacy_opportunity_id'] ?? '') === (string) $metadataLegacyId
                && (string) ($row['canonical_opportunity_id'] ?? '') === (string) $canonicalId);
    }

    /**
     * @param  array<string,mixed>  $previewReport
     * @return array{ok:bool,status:string|null}
     */
    private function phase3oStatus(array $previewReport, ?string $metadataLegacyId, AgenticMarketingAction $action): array
    {
        $allowed = [
            AgenticPlannerApplyExperimentAuditService::STATUS_CLEAN,
            AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK,
        ];
        $auditRows = collect((array) ($previewReport['phase_3o_audit_rows'] ?? []));
        $riskyRows = collect((array) ($previewReport['phase_3o_risky_rows'] ?? []));

        if ($riskyRows->isNotEmpty()) {
            return ['ok' => false, 'status' => $this->stringValue($riskyRows->first()['audit_status'] ?? self::STATUS_PHASE_3O_AUDIT_RISK)];
        }

        $matching = $auditRows->first(fn (array $row): bool => in_array((string) ($row['action_id'] ?? ''), [(string) $action->id, ''], true)
            && in_array((string) ($row['legacy_opportunity_id'] ?? $row['payload_legacy_opportunity_id'] ?? $metadataLegacyId), [(string) $metadataLegacyId, ''], true));

        if ($matching !== null) {
            $status = $this->stringValue($matching['audit_status'] ?? null);

            return ['ok' => in_array($status, $allowed, true), 'status' => $status];
        }

        $bad = $auditRows->first(fn (array $row): bool => ! in_array($row['audit_status'] ?? null, $allowed, true));
        if ($bad !== null) {
            return ['ok' => false, 'status' => $this->stringValue($bad['audit_status'] ?? self::STATUS_PHASE_3O_AUDIT_RISK)];
        }

        return [
            'ok' => true,
            'status' => $auditRows->contains(fn (array $row): bool => ($row['audit_status'] ?? null) === AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK)
                ? AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK
                : AgenticPlannerApplyExperimentAuditService::STATUS_CLEAN,
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

        return collect($this->readinessDuplicateActionIds($readiness))
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

        if (($readiness['readiness_status'] ?? null) === AgenticPlannerReadinessInspectionService::STATUS_PLANNER_CANDIDATE_READY) {
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
        bool $legacyExists,
        bool $actionRemainsLegacyOwned,
        ?string $metadataLegacyId,
        ?string $legacyId,
        bool $canonicalExists,
        bool $canonicalBridgePointsBack,
        bool $phase3qPreviewSafe,
        bool $phase3pContinueShadow,
        bool $phase3oOk,
        ?string $phase3oStatus,
        bool $readinessPasses,
        bool $signatureMatches,
        bool $phase3iPasses,
        bool $phase3jNonAmbiguous,
        bool $duplicateRisk,
    ): array {
        $blocked = [];
        $warnings = [];

        if (! $legacyExists) {
            return [self::STATUS_MISSING_LEGACY_PARENT, ['action_no_longer_resolves_to_legacy_agentic_marketing_opportunity'], $warnings];
        }

        if (! $actionRemainsLegacyOwned || $metadataLegacyId !== $legacyId) {
            return [self::STATUS_OWNERSHIP_RISK, ['action_opportunity_id_no_longer_matches_payload_legacy_agentic_marketing_opportunity_id'], $warnings];
        }

        if (! $canonicalExists) {
            return [self::STATUS_MISSING_CANONICAL_CONTEXT, ['metadata_canonical_opportunity_missing'], $warnings];
        }

        if (! $canonicalBridgePointsBack) {
            return [self::STATUS_BRIDGE_MISMATCH, ['metadata_canonical_bridge_points_to_different_legacy_opportunity'], $warnings];
        }

        if (! $phase3qPreviewSafe) {
            return [self::STATUS_PREVIEW_REGRESSED, ['phase_3q_preview_no_longer_returns_preview_safe_for_action_scope'], $warnings];
        }

        if (! $phase3pContinueShadow) {
            return [self::STATUS_SHADOW_REGRESSED, ['phase_3p_no_longer_recommends_continue_shadow'], $warnings];
        }

        if (! $phase3oOk) {
            return [self::STATUS_PHASE_3O_AUDIT_RISK, ['phase_3o_audit_status_is_not_clean_or_metadata_only_ok'], $warnings];
        }

        if (! $readinessPasses) {
            return [self::STATUS_READINESS_REGRESSED, ['phase_3l_readiness_no_longer_passes'], $warnings];
        }

        if (! $signatureMatches) {
            return [self::STATUS_SIGNATURE_MISMATCH, ['phase_3h_signature_no_longer_matches'], $warnings];
        }

        if (! $phase3iPasses) {
            return [self::STATUS_CONTINUITY_RISK, ['phase_3i_continuity_has_blockers'], $warnings];
        }

        if (! $phase3jNonAmbiguous) {
            return [self::STATUS_LIFECYCLE_RISK, ['phase_3j_lifecycle_is_ambiguous_or_conflicting'], $warnings];
        }

        if ($duplicateRisk) {
            return [self::STATUS_DUPLICATE_RISK, ['duplicate_open_action_risk_now_exists'], $warnings];
        }

        if ($phase3oStatus === AgenticPlannerApplyExperimentAuditService::STATUS_METADATA_ONLY_OK) {
            $warnings[] = 'phase_3o_reports_metadata_only_ok_traceability';

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
            'missing_legacy_parent_count' => $rows->where('audit_status', self::STATUS_MISSING_LEGACY_PARENT)->count(),
            'missing_canonical_context_count' => $rows->where('audit_status', self::STATUS_MISSING_CANONICAL_CONTEXT)->count(),
            'bridge_mismatch_count' => $rows->where('audit_status', self::STATUS_BRIDGE_MISMATCH)->count(),
            'preview_regression_count' => $rows->where('audit_status', self::STATUS_PREVIEW_REGRESSED)->count(),
            'shadow_regression_count' => $rows->where('audit_status', self::STATUS_SHADOW_REGRESSED)->count(),
            'phase_3o_audit_risk_count' => $rows->where('audit_status', self::STATUS_PHASE_3O_AUDIT_RISK)->count(),
            'readiness_regression_count' => $rows->where('audit_status', self::STATUS_READINESS_REGRESSED)->count(),
            'signature_mismatch_count' => $rows->where('audit_status', self::STATUS_SIGNATURE_MISMATCH)->count(),
            'continuity_risk_count' => $rows->where('audit_status', self::STATUS_CONTINUITY_RISK)->count(),
            'lifecycle_risk_count' => $rows->where('audit_status', self::STATUS_LIFECYCLE_RISK)->count(),
            'duplicate_risk_count' => $rows->where('audit_status', self::STATUS_DUPLICATE_RISK)->count(),
            'ownership_risk_count' => $rows->where('audit_status', self::STATUS_OWNERSHIP_RISK)->count(),
            'recommendation' => $this->recommendation($rows),
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
                'metadata_paths_that_would_be_removed' => ['payload.default_selection_experiment'],
                'rollback_safe' => (bool) $row['action_remains_legacy_owned'] && (bool) $row['legacy_opportunity_exists'],
                'reasons' => $row['blocked_reasons'] === []
                    ? array_merge(['metadata_only_removal_would_leave_legacy_action_ownership_intact'], $row['warning_reasons'])
                    : $row['blocked_reasons'],
            ])->values()->all(),
            'recommendation' => $unsafe->isEmpty()
                ? 'Keep rollback flag-first: disable the Phase 3R flag and ignore payload.default_selection_experiment. No metadata removal writer is implemented in Phase 3S.'
                : 'Do not remove metadata until unsafe legacy ownership rows are investigated; rollback remains flag-off plus ignoring payload.default_selection_experiment.',
        ];
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function recommendation(Collection $rows): string
    {
        if ($rows->isEmpty()) {
            return 'No Phase 3R default-selection experiment metadata matched the filters; keep the broader default planner rollout blocked.';
        }

        $risky = $rows->reject(fn (array $row): bool => in_array($row['audit_status'], [
            self::STATUS_CLEAN,
            self::STATUS_METADATA_ONLY_OK,
        ], true));

        if ($risky->isNotEmpty()) {
            return 'blocked for broader rollout';
        }

        return $rows->where('audit_status', self::STATUS_METADATA_ONLY_OK)->isNotEmpty()
            ? 'rollback flag; keep scoped until metadata-only rows are resolved'
            : 'keep scoped';
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
