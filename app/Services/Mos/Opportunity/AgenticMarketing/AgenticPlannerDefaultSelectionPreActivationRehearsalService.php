<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

class AgenticPlannerDefaultSelectionPreActivationRehearsalService
{
    public const STATUS_READY = 'rehearsal_ready';

    public const STATUS_BLOCKED = 'rehearsal_blocked';

    public const MODE = 'pre_activation_rehearsal_dry_run_only';

    private const REMEDIATION_GUIDANCE = [
        'exact_real_scope_required' => 'Re-run Phase 4G with explicit existing workspace and objective ids and --require-real-scope. Do not use wildcard, global, inferred, or percentage scope.',
        'phase_4f_package_ready_required' => 'Resolve Phase 4F package blockers, then re-run Phase 4F until package_status=package_ready before rehearsing activation readiness.',
        'selected_planner_must_remain_legacy' => 'Remove any planner switching behavior and confirm selected_planner_remains=legacy before repeating the rehearsal.',
        'runtime_behavior_must_not_change' => 'Remove runtime behavior changes from the inspected path. Phase 4G is dry-run rehearsal only.',
        'rollback_rehearsal_must_be_legacy_first' => 'Restore rollback evidence so legacy planner output and legacy Agentic action ownership remain authoritative without metadata removal.',
        'non_activation_confirmation_required' => 'Remove activation, mutation, migration, audit, route/approval, historical rewrite, rollout, wildcard, feature flag, or job side effects before repeating the rehearsal.',
    ];

    public function __construct(
        private readonly AgenticPlannerDefaultSelectionCiEvidencePackageService $phase4f,
    ) {}

    /**
     * @param  array{workspace?:string|null,site?:string|null,detector?:string|null,objectives?:array<int,string>|string|null,limit?:int|null,ack_metadata_only_review?:bool|null,ack_runtime_switch_contract?:bool|null,ack_operator_signoff?:bool|null,require_real_scope?:bool|null}  $input
     * @return array<string,mixed>
     */
    public function rehearse(array $input): array
    {
        $package = $this->phase4f->package($input);
        $nonActivation = $this->nonActivationConfirmations($package);
        $rollback = $this->rollbackRehearsal($package);

        $blockedReasons = collect((array) ($package['blocked_reasons'] ?? []));

        if (! $this->exactRealScopePresent($input, $package)) {
            $blockedReasons->push('exact_real_scope_required');
        }

        if (($package['package_status'] ?? null) !== AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_READY) {
            $blockedReasons->push('phase_4f_package_ready_required');
        }

        if (($nonActivation['selected_planner_remains'] ?? null) !== AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER) {
            $blockedReasons->push('selected_planner_must_remain_legacy');
        }

        if ((bool) ($nonActivation['runtime_behavior_changed'] ?? true)) {
            $blockedReasons->push('runtime_behavior_must_not_change');
        }

        if (! $this->rollbackPassed($rollback)) {
            $blockedReasons->push('rollback_rehearsal_must_be_legacy_first');
        }

        if (! $this->nonActivationConfirmed($nonActivation)) {
            $blockedReasons->push('non_activation_confirmation_required');
        }

        $blockedReasons = $blockedReasons
            ->unique()
            ->values()
            ->all();

        return [
            'phase' => '4G',
            'mode' => self::MODE,
            'rehearsal_status' => $blockedReasons === [] ? self::STATUS_READY : self::STATUS_BLOCKED,
            'workspace_id' => $package['workspace_id'] ?? $input['workspace'] ?? null,
            'objective_ids' => $this->objectiveIds($package, $input),
            'scope' => $this->scopeSummary($package, $input),
            'phase_4f_package_status' => $package['package_status'] ?? AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_BLOCKED,
            'package_checksum' => $package['package_checksum'] ?? null,
            'package_checksum_algorithm' => $package['package_checksum_algorithm'] ?? 'sha256',
            'package_checksum_scope' => $package['package_checksum_scope'] ?? 'canonical_package_excluding_generated_at_and_checksum_fields',
            'selected_planner_remains' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'runtime_behavior_changed' => false,
            'rehearsal_activation_plan' => $this->activationPlan($package),
            'rollback_rehearsal_result' => $rollback,
            'legacy_first_confirmation' => [
                'legacy_planner_output_authoritative' => true,
                'legacy_agentic_action_ownership_authoritative' => true,
                'selected_planner_after_rehearsal' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
                'canonical_output_rehearsed_as_evidence_only' => true,
            ],
            'blocked_reasons' => $blockedReasons,
            'remediation_guidance' => $this->remediationGuidance((array) ($package['remediation_guidance'] ?? []), $blockedReasons),
            'non_activation_confirmations' => $nonActivation,
            'phase_4f_package_report' => $package,
            'rehearsal_statement' => 'Phase 4G is dry-run pre-activation rehearsal only. It composes Phase 4F package output and does not change runtime behavior.',
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    private function activationPlan(array $package): array
    {
        return [
            'plan_type' => 'dry_run_only',
            'activation_rehearsed' => true,
            'activation_performed' => false,
            'activation_flags_consumed' => false,
            'future_activation_required' => true,
            'scope' => $package['scope'] ?? [],
            'selected_planner_during_rehearsal' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'runtime_behavior_changed' => false,
            'steps' => [
                'confirm_phase_4f_package_ready',
                'confirm_exact_workspace_objective_scope',
                'confirm_legacy_output_remains_authoritative',
                'confirm_rollback_keeps_legacy_selected',
                'stop_before_runtime_activation',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    private function rollbackRehearsal(array $package): array
    {
        $phase4fRollbackMode = data_get($package, 'rollback_confirmation.rollback_mode', AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE);
        $phase4fLegacyFirst = (bool) data_get($package, 'rollback_confirmation.legacy_first', true);
        $phase4fMetadataRemovalRequired = (bool) data_get($package, 'rollback_confirmation.metadata_removal_required_for_rollback', false);
        $phase4fOwnershipMigrationRequired = (bool) data_get($package, 'rollback_confirmation.ownership_migration_required', false);
        $phase4fLifecycleSyncRequired = (bool) data_get($package, 'rollback_confirmation.lifecycle_sync_required', false);
        $phase4fRuntimeMutationRequired = (bool) data_get($package, 'rollback_confirmation.payload_status_dedupe_parent_approval_execution_changes_required', false);
        $phase4fHistoricalRewriteRequired = (bool) data_get($package, 'rollback_confirmation.historical_rewrite_required', false);
        $phase4fRuntimeAuditWriteRequired = (bool) data_get($package, 'rollback_confirmation.runtime_audit_write_required', false);
        $phase4fRouteOrApprovalChangeRequired = (bool) data_get($package, 'rollback_confirmation.route_or_approval_change_required', false);
        $phase4fJobDispatchRequired = (bool) data_get($package, 'rollback_confirmation.job_dispatch_required', false);
        $passed = $phase4fRollbackMode === AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE
            && $phase4fLegacyFirst
            && ! $phase4fMetadataRemovalRequired
            && ! $phase4fOwnershipMigrationRequired
            && ! $phase4fLifecycleSyncRequired
            && ! $phase4fRuntimeMutationRequired
            && ! $phase4fHistoricalRewriteRequired
            && ! $phase4fRuntimeAuditWriteRequired
            && ! $phase4fRouteOrApprovalChangeRequired
            && ! $phase4fJobDispatchRequired;

        return [
            'status' => $passed ? 'rollback_rehearsed_legacy_first' : 'rollback_rehearsal_blocked',
            'passed' => $passed,
            'legacy_planner_output_remains_authoritative' => $passed,
            'legacy_agentic_action_ownership_remains_authoritative' => $passed,
            'future_activation_disable_keeps_legacy_output_selected' => $passed,
            'metadata_removal_required_for_rollback' => $phase4fMetadataRemovalRequired,
            'ownership_migration_required' => $phase4fOwnershipMigrationRequired,
            'lifecycle_sync_required' => $phase4fLifecycleSyncRequired,
            'payload_status_dedupe_parent_approval_execution_changes_required' => $phase4fRuntimeMutationRequired,
            'historical_rewrite_required' => $phase4fHistoricalRewriteRequired,
            'runtime_audit_write_required' => $phase4fRuntimeAuditWriteRequired,
            'route_or_approval_change_required' => $phase4fRouteOrApprovalChangeRequired,
            'job_dispatch_required' => $phase4fJobDispatchRequired,
            'phase_4f_rollback_mode' => $phase4fRollbackMode,
            'phase_4f_legacy_first' => $phase4fLegacyFirst,
        ];
    }

    /**
     * @param  array<string,mixed>  $rollback
     */
    private function rollbackPassed(array $rollback): bool
    {
        return (bool) ($rollback['passed'] ?? false)
            && (bool) ($rollback['legacy_planner_output_remains_authoritative'] ?? false)
            && (bool) ($rollback['legacy_agentic_action_ownership_remains_authoritative'] ?? false)
            && (bool) ($rollback['future_activation_disable_keeps_legacy_output_selected'] ?? false)
            && ! (bool) ($rollback['metadata_removal_required_for_rollback'] ?? true)
            && ! (bool) ($rollback['ownership_migration_required'] ?? true)
            && ! (bool) ($rollback['lifecycle_sync_required'] ?? true)
            && ! (bool) ($rollback['payload_status_dedupe_parent_approval_execution_changes_required'] ?? true)
            && ! (bool) ($rollback['historical_rewrite_required'] ?? true)
            && ! (bool) ($rollback['runtime_audit_write_required'] ?? true)
            && ! (bool) ($rollback['route_or_approval_change_required'] ?? true)
            && ! (bool) ($rollback['job_dispatch_required'] ?? true);
    }

    /**
     * @param  array<string,mixed>  $package
     * @return array<string,mixed>
     */
    private function nonActivationConfirmations(array $package): array
    {
        return array_replace([
            'activation_flag_consumed_for_switching' => false,
            'selected_planner_remains' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'runtime_behavior_changed' => false,
            'planner_switching_activated' => false,
            'percentage_rollout_added' => false,
            'global_default_migration_performed' => false,
            'wildcard_scope_inferred' => false,
            'legacy_planner_output_replaced' => false,
            'agentic_marketing_action_created_or_mutated' => false,
            'ownership_migrated' => false,
            'lifecycle_synced' => false,
            'payload_status_dedupe_parent_approval_execution_mutated' => false,
            'runtime_audit_written' => false,
            'job_dispatched' => false,
            'route_or_approval_changed' => false,
            'historical_records_rewritten' => false,
            'runtime_feature_flags_introduced' => false,
        ], (array) ($package['non_activation_confirmations'] ?? []));
    }

    /**
     * @param  array<string,mixed>  $confirmation
     */
    private function nonActivationConfirmed(array $confirmation): bool
    {
        foreach ($confirmation as $key => $value) {
            if ($key === 'selected_planner_remains') {
                if ($value !== AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER) {
                    return false;
                }

                continue;
            }

            if ((bool) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $package
     */
    private function exactRealScopePresent(array $input, array $package): bool
    {
        $workspace = trim((string) ($input['workspace'] ?? $package['workspace_id'] ?? ''));
        $objectives = $this->objectiveIds($package, $input);

        return $workspace !== ''
            && $objectives !== []
            && (bool) ($input['require_real_scope'] ?? false)
            && (bool) data_get($package, 'scope.real_scope_detected', false)
            && (bool) data_get($package, 'scope.explicit_workspace_objective_scope', false)
            && ! (bool) data_get($package, 'scope.wildcard_scope_inferred', false)
            && ! (bool) data_get($package, 'scope.percentage_scope_used', false)
            && ! (bool) data_get($package, 'scope.global_scope_used', false);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $input
     * @return array<int,string>
     */
    private function objectiveIds(array $package, array $input): array
    {
        $ids = (array) ($package['objective_ids'] ?? $input['objectives'] ?? []);

        if (is_string($input['objectives'] ?? null)) {
            $ids = explode(',', (string) $input['objectives']);
        }

        return collect($ids)
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function scopeSummary(array $package, array $input): array
    {
        return [
            'workspace_id' => $package['workspace_id'] ?? $input['workspace'] ?? null,
            'objective_ids' => $this->objectiveIds($package, $input),
            'objective_count' => count($this->objectiveIds($package, $input)),
            'site_id' => data_get($package, 'scope.site_id') ?: ($input['site'] ?? null),
            'detector' => data_get($package, 'scope.detector') ?: ($input['detector'] ?? null),
            'limit' => data_get($package, 'scope.limit') ?: ($input['limit'] ?? null),
            'require_real_scope' => (bool) ($input['require_real_scope'] ?? false),
            'real_scope_detected' => (bool) data_get($package, 'scope.real_scope_detected', false),
            'explicit_workspace_objective_scope' => (bool) data_get($package, 'scope.explicit_workspace_objective_scope', false),
            'wildcard_scope_inferred' => (bool) data_get($package, 'scope.wildcard_scope_inferred', false),
            'percentage_scope_used' => (bool) data_get($package, 'scope.percentage_scope_used', false),
            'global_scope_used' => (bool) data_get($package, 'scope.global_scope_used', false),
        ];
    }

    /**
     * @param  array<int,array<string,string>>  $phase4fGuidance
     * @param  array<int,string>  $blockedReasons
     * @return array<int,array<string,string>>
     */
    private function remediationGuidance(array $phase4fGuidance, array $blockedReasons): array
    {
        $byReason = collect($phase4fGuidance)
            ->mapWithKeys(fn (array $row): array => [(string) ($row['reason'] ?? 'unknown') => (string) ($row['guidance'] ?? '')]);

        return collect($blockedReasons)
            ->map(fn (string $reason): array => [
                'reason' => $reason,
                'guidance' => $byReason->get($reason) ?: (self::REMEDIATION_GUIDANCE[$reason] ?? 'Review Phase 4F package output, remediate the upstream blocker, and re-run Phase 4G.'),
            ])
            ->values()
            ->all();
    }
}
