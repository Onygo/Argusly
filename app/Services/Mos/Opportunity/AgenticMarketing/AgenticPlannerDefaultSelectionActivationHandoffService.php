<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

class AgenticPlannerDefaultSelectionActivationHandoffService
{
    public const STATUS_READY = 'handoff_ready';

    public const STATUS_BLOCKED = 'handoff_blocked';

    public const ACKNOWLEDGED = 'activation_handoff_acknowledged';

    public const ACKNOWLEDGEMENT_MISSING = 'activation_handoff_acknowledgement_missing';

    public const MODE = 'operator_activation_handoff_review_only';

    private const REMEDIATION_GUIDANCE = [
        'exact_real_scope_required' => 'Re-run Phase 4H with explicit existing workspace and objective ids and --require-real-scope. Do not use wildcard, global, inferred, or percentage scope.',
        'phase_4g_rehearsal_ready_required' => 'Resolve Phase 4G rehearsal blockers, then re-run Phase 4G until rehearsal_status=rehearsal_ready before preparing the operator handoff packet.',
        'phase_4f_package_checksum_required' => 'Re-run Phase 4G from a Phase 4F package that includes the preserved package checksum.',
        'activation_handoff_acknowledgement_missing' => 'Have the operator explicitly acknowledge activation handoff intent and re-run with --ack-activation-handoff. This acknowledgement is review output only and does not activate switching.',
        'selected_planner_must_remain_legacy' => 'Remove any planner switching behavior and confirm selected_planner_remains=legacy before repeating the handoff.',
        'runtime_behavior_must_not_change' => 'Remove runtime behavior changes from the inspected path. Phase 4H is operator handoff only.',
        'dry_run_activation_plan_must_not_activate' => 'Restore the Phase 4G dry-run activation summary so no activation is performed and no activation flags are consumed.',
        'rollback_rehearsal_must_be_legacy_first' => 'Restore rollback rehearsal evidence so legacy planner output and legacy Agentic action ownership remain authoritative without runtime mutation.',
        'non_activation_confirmation_required' => 'Remove activation, mutation, migration, audit, route/approval, historical rewrite, rollout, wildcard, feature flag, rollback, or job side effects before repeating the handoff.',
    ];

    public function __construct(
        private readonly AgenticPlannerDefaultSelectionPreActivationRehearsalService $phase4g,
    ) {}

    /**
     * @param  array{workspace?:string|null,site?:string|null,detector?:string|null,objectives?:array<int,string>|string|null,limit?:int|null,ack_metadata_only_review?:bool|null,ack_runtime_switch_contract?:bool|null,ack_operator_signoff?:bool|null,ack_activation_handoff?:bool|null,require_real_scope?:bool|null}  $input
     * @return array<string,mixed>
     */
    public function handoff(array $input): array
    {
        $rehearsal = $this->phase4g->rehearse($input);
        $nonActivation = $this->nonActivationConfirmations($rehearsal);
        $operatorAcknowledged = (bool) ($input['ack_activation_handoff'] ?? false);

        $blockedReasons = collect((array) ($rehearsal['blocked_reasons'] ?? []));

        if (! $this->exactRealScopePresent($input, $rehearsal)) {
            $blockedReasons->push('exact_real_scope_required');
        }

        if (($rehearsal['rehearsal_status'] ?? null) !== AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_READY) {
            $blockedReasons->push('phase_4g_rehearsal_ready_required');
        }

        if (! filled($rehearsal['package_checksum'] ?? null)) {
            $blockedReasons->push('phase_4f_package_checksum_required');
        }

        if (! $operatorAcknowledged) {
            $blockedReasons->push(self::ACKNOWLEDGEMENT_MISSING);
        }

        if (($nonActivation['selected_planner_remains'] ?? null) !== AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER) {
            $blockedReasons->push('selected_planner_must_remain_legacy');
        }

        if ((bool) ($nonActivation['runtime_behavior_changed'] ?? true)) {
            $blockedReasons->push('runtime_behavior_must_not_change');
        }

        if (! $this->activationPlanRemainsDryRun($rehearsal)) {
            $blockedReasons->push('dry_run_activation_plan_must_not_activate');
        }

        if (! $this->rollbackRehearsalLegacyFirst($rehearsal)) {
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
            'phase' => '4H',
            'mode' => self::MODE,
            'handoff_status' => $blockedReasons === [] ? self::STATUS_READY : self::STATUS_BLOCKED,
            'workspace_id' => $rehearsal['workspace_id'] ?? $input['workspace'] ?? null,
            'objective_ids' => $this->objectiveIds($rehearsal, $input),
            'exact_scope_summary' => $this->scopeSummary($rehearsal, $input),
            'phase_4g_rehearsal_status' => $rehearsal['rehearsal_status'] ?? AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_BLOCKED,
            'phase_4f_package_checksum' => $rehearsal['package_checksum'] ?? null,
            'phase_4f_package_checksum_algorithm' => $rehearsal['package_checksum_algorithm'] ?? 'sha256',
            'phase_4f_package_checksum_scope' => $rehearsal['package_checksum_scope'] ?? 'canonical_package_excluding_generated_at_and_checksum_fields',
            'selected_planner_remains' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'runtime_behavior_changed' => false,
            'dry_run_activation_plan_summary' => $this->activationPlanSummary($rehearsal),
            'rollback_rehearsal_summary' => $this->rollbackSummary($rehearsal),
            'legacy_first_confirmation' => $rehearsal['legacy_first_confirmation'] ?? $this->legacyFirstConfirmation(),
            'operator_handoff_acknowledgement' => [
                'status' => $operatorAcknowledged ? self::ACKNOWLEDGED : self::ACKNOWLEDGEMENT_MISSING,
                'acknowledged' => $operatorAcknowledged,
                'review_output_only' => true,
                'activation_performed' => false,
            ],
            'blocked_reasons' => $blockedReasons,
            'remediation_guidance' => $this->remediationGuidance((array) ($rehearsal['remediation_guidance'] ?? []), $blockedReasons),
            'operator_handoff_checklist' => $this->operatorChecklist($operatorAcknowledged, $rehearsal, $nonActivation),
            'non_activation_confirmations' => $nonActivation,
            'phase_4g_rehearsal_report' => $rehearsal,
            'handoff_statement' => 'Phase 4H is operator activation handoff review output only. It prepares a final human/operator packet after Phase 4G readiness and does not change runtime behavior.',
        ];
    }

    /**
     * @param  array<string,mixed>  $rehearsal
     * @param  array<string,mixed>  $input
     */
    private function exactRealScopePresent(array $input, array $rehearsal): bool
    {
        $workspace = trim((string) ($input['workspace'] ?? $rehearsal['workspace_id'] ?? ''));
        $objectives = $this->objectiveIds($rehearsal, $input);

        return $workspace !== ''
            && $objectives !== []
            && (bool) ($input['require_real_scope'] ?? false)
            && (bool) data_get($rehearsal, 'scope.real_scope_detected', false)
            && (bool) data_get($rehearsal, 'scope.explicit_workspace_objective_scope', false)
            && ! (bool) data_get($rehearsal, 'scope.wildcard_scope_inferred', false)
            && ! (bool) data_get($rehearsal, 'scope.percentage_scope_used', false)
            && ! (bool) data_get($rehearsal, 'scope.global_scope_used', false);
    }

    /**
     * @param  array<string,mixed>  $rehearsal
     * @param  array<string,mixed>  $input
     * @return array<int,string>
     */
    private function objectiveIds(array $rehearsal, array $input): array
    {
        $ids = (array) ($rehearsal['objective_ids'] ?? $input['objectives'] ?? []);

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
     * @param  array<string,mixed>  $rehearsal
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function scopeSummary(array $rehearsal, array $input): array
    {
        $objectiveIds = $this->objectiveIds($rehearsal, $input);

        return [
            'workspace_id' => $rehearsal['workspace_id'] ?? $input['workspace'] ?? null,
            'objective_ids' => $objectiveIds,
            'objective_count' => count($objectiveIds),
            'site_id' => data_get($rehearsal, 'scope.site_id') ?: ($input['site'] ?? null),
            'detector' => data_get($rehearsal, 'scope.detector') ?: ($input['detector'] ?? null),
            'limit' => data_get($rehearsal, 'scope.limit') ?: ($input['limit'] ?? null),
            'require_real_scope' => (bool) ($input['require_real_scope'] ?? false),
            'real_scope_detected' => (bool) data_get($rehearsal, 'scope.real_scope_detected', false),
            'explicit_workspace_objective_scope' => (bool) data_get($rehearsal, 'scope.explicit_workspace_objective_scope', false),
            'wildcard_scope_inferred' => (bool) data_get($rehearsal, 'scope.wildcard_scope_inferred', false),
            'percentage_scope_used' => (bool) data_get($rehearsal, 'scope.percentage_scope_used', false),
            'global_scope_used' => (bool) data_get($rehearsal, 'scope.global_scope_used', false),
        ];
    }

    /**
     * @param  array<string,mixed>  $rehearsal
     * @return array<string,mixed>
     */
    private function activationPlanSummary(array $rehearsal): array
    {
        $plan = (array) ($rehearsal['rehearsal_activation_plan'] ?? []);

        return [
            'plan_type' => $plan['plan_type'] ?? 'dry_run_only',
            'activation_rehearsed' => (bool) ($plan['activation_rehearsed'] ?? true),
            'activation_performed' => false,
            'activation_flags_consumed' => false,
            'selected_planner_during_rehearsal' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'runtime_behavior_changed' => false,
            'summary' => 'Dry-run activation plan was reviewed for handoff only; no activation was performed and no activation flags were consumed.',
            'steps' => (array) ($plan['steps'] ?? []),
        ];
    }

    /**
     * @param  array<string,mixed>  $rehearsal
     * @return array<string,mixed>
     */
    private function rollbackSummary(array $rehearsal): array
    {
        $rollback = (array) ($rehearsal['rollback_rehearsal_result'] ?? []);

        return array_replace([
            'status' => 'rollback_rehearsal_blocked',
            'passed' => false,
            'legacy_planner_output_remains_authoritative' => false,
            'legacy_agentic_action_ownership_remains_authoritative' => false,
            'future_activation_disable_keeps_legacy_output_selected' => false,
            'metadata_removal_required_for_rollback' => true,
            'ownership_migration_required' => true,
            'lifecycle_sync_required' => true,
            'payload_status_dedupe_parent_approval_execution_changes_required' => true,
            'historical_rewrite_required' => true,
            'runtime_audit_write_required' => true,
            'route_or_approval_change_required' => true,
            'job_dispatch_required' => true,
            'rollback_performed' => false,
        ], $rollback, [
            'rollback_performed' => false,
        ]);
    }

    /**
     * @param  array<string,mixed>  $rehearsal
     * @return array<string,mixed>
     */
    private function nonActivationConfirmations(array $rehearsal): array
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
            'rollback_performed' => false,
        ], (array) ($rehearsal['non_activation_confirmations'] ?? []), [
            'rollback_performed' => false,
        ]);
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
     * @param  array<string,mixed>  $rehearsal
     */
    private function activationPlanRemainsDryRun(array $rehearsal): bool
    {
        $plan = (array) ($rehearsal['rehearsal_activation_plan'] ?? []);

        return ! (bool) ($plan['activation_performed'] ?? true)
            && ! (bool) ($plan['activation_flags_consumed'] ?? true)
            && ! (bool) ($plan['runtime_behavior_changed'] ?? true)
            && ($plan['selected_planner_during_rehearsal'] ?? AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER) === AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER;
    }

    /**
     * @param  array<string,mixed>  $rehearsal
     */
    private function rollbackRehearsalLegacyFirst(array $rehearsal): bool
    {
        $rollback = (array) ($rehearsal['rollback_rehearsal_result'] ?? []);

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
            && ! (bool) ($rollback['job_dispatch_required'] ?? true)
            && ! (bool) ($rollback['rollback_performed'] ?? false);
    }

    /**
     * @param  array<int,array<string,string>>  $phase4gGuidance
     * @param  array<int,string>  $blockedReasons
     * @return array<int,array<string,string>>
     */
    private function remediationGuidance(array $phase4gGuidance, array $blockedReasons): array
    {
        $byReason = collect($phase4gGuidance)
            ->mapWithKeys(fn (array $row): array => [(string) ($row['reason'] ?? 'unknown') => (string) ($row['guidance'] ?? '')]);

        return collect($blockedReasons)
            ->map(fn (string $reason): array => [
                'reason' => $reason,
                'guidance' => $byReason->get($reason) ?: (self::REMEDIATION_GUIDANCE[$reason] ?? 'Review Phase 4G rehearsal output, remediate the upstream blocker, and re-run Phase 4H.'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $rehearsal
     * @param  array<string,mixed>  $nonActivation
     * @return array<int,array<string,mixed>>
     */
    private function operatorChecklist(bool $operatorAcknowledged, array $rehearsal, array $nonActivation): array
    {
        return [
            [
                'item' => 'exact_real_scope_confirmed',
                'ready' => (bool) data_get($rehearsal, 'scope.real_scope_detected', false)
                    && (bool) data_get($rehearsal, 'scope.explicit_workspace_objective_scope', false)
                    && ! (bool) data_get($rehearsal, 'scope.wildcard_scope_inferred', false)
                    && ! (bool) data_get($rehearsal, 'scope.percentage_scope_used', false)
                    && ! (bool) data_get($rehearsal, 'scope.global_scope_used', false),
            ],
            [
                'item' => 'phase_4g_rehearsal_ready',
                'ready' => ($rehearsal['rehearsal_status'] ?? null) === AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_READY,
            ],
            [
                'item' => 'operator_activation_handoff_acknowledged',
                'ready' => $operatorAcknowledged,
            ],
            [
                'item' => 'phase_4f_package_checksum_preserved',
                'ready' => filled($rehearsal['package_checksum'] ?? null),
            ],
            [
                'item' => 'selected_planner_remains_legacy',
                'ready' => ($nonActivation['selected_planner_remains'] ?? null) === AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            ],
            [
                'item' => 'runtime_behavior_unchanged',
                'ready' => ! (bool) ($nonActivation['runtime_behavior_changed'] ?? true),
            ],
            [
                'item' => 'rollback_rehearsal_legacy_first',
                'ready' => (bool) data_get($rehearsal, 'rollback_rehearsal_result.passed', false)
                    && (bool) data_get($rehearsal, 'rollback_rehearsal_result.legacy_planner_output_remains_authoritative', false),
            ],
            [
                'item' => 'non_activation_confirmations_clear',
                'ready' => $this->nonActivationConfirmed($nonActivation),
            ],
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function legacyFirstConfirmation(): array
    {
        return [
            'legacy_planner_output_authoritative' => true,
            'legacy_agentic_action_ownership_authoritative' => true,
            'selected_planner_after_handoff' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'canonical_output_handoff_as_evidence_only' => true,
        ];
    }
}
