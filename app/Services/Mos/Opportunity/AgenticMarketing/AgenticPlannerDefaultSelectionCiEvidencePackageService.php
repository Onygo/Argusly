<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

use Illuminate\Support\Arr;

class AgenticPlannerDefaultSelectionCiEvidencePackageService
{
    public const STATUS_READY = 'package_ready';

    public const STATUS_BLOCKED = 'package_blocked';

    public const MODE = 'ci_review_evidence_packaging_only';

    private const REMEDIATION_GUIDANCE = [
        'exact_real_scope_required' => 'Re-run Phase 4F with explicit existing workspace and objective ids and --require-real-scope. Do not use wildcard, global, inferred, or percentage scope.',
        'phase_4d_evidence_ready_required' => 'Re-run Phase 4D remediation until final_evidence_status=evidence_ready before packaging can pass.',
        'phase_4e_signoff_ready_required' => 'Re-run Phase 4E with Phase 4D evidence_ready and explicit operator sign-off until signoff_readiness.status=signoff_ready.',
        'selected_planner_must_remain_legacy' => 'Restore selected_planner_remains=legacy before packaging evidence.',
        'runtime_behavior_must_not_change' => 'Remove runtime behavior changes before packaging evidence.',
        'non_activation_confirmation_required' => 'Restore the non-activation confirmation to all false side-effect indicators before packaging evidence.',
        'rollback_must_remain_legacy_first' => 'Confirm rollback_mode=legacy_first and legacy output remains authoritative before packaging evidence.',
    ];

    public function __construct(
        private readonly AgenticPlannerDefaultSelectionOperatorSignOffRunbookService $phase4e,
    ) {}

    /**
     * @param  array{workspace?:string|null,site?:string|null,detector?:string|null,objectives?:array<int,string>|string|null,limit?:int|null,ack_metadata_only_review?:bool|null,ack_runtime_switch_contract?:bool|null,ack_operator_signoff?:bool|null,require_real_scope?:bool|null}  $input
     * @return array<string,mixed>
     */
    public function package(array $input): array
    {
        $phase4e = $this->phase4e->inspect($input);
        $phase4d = (array) ($phase4e['phase_4d_evidence_report'] ?? []);

        $phase4dStatus = (string) ($phase4d['final_evidence_status'] ?? data_get($phase4e, 'evidence_status.phase_4d_final_evidence_status', AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_BLOCKED));
        $phase4eStatus = (string) data_get($phase4e, 'signoff_readiness.status', AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::STATUS_BLOCKED);
        $nonActivation = $this->nonActivationConfirmation($phase4e);
        $rollback = (array) ($phase4e['rollback_confirmation'] ?? []);

        $blockedReasons = collect((array) data_get($phase4e, 'signoff_readiness.blocked_reasons', []));

        if (! $this->exactRealScopePresent($input, $phase4d)) {
            $blockedReasons->push('exact_real_scope_required');
        }

        if ($phase4dStatus !== AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_READY) {
            $blockedReasons->push('phase_4d_evidence_ready_required');
        }

        if ($phase4eStatus !== AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::STATUS_READY) {
            $blockedReasons->push('phase_4e_signoff_ready_required');
        }

        if (($nonActivation['selected_planner_remains'] ?? null) !== AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER) {
            $blockedReasons->push('selected_planner_must_remain_legacy');
        }

        if ((bool) ($nonActivation['runtime_behavior_changed'] ?? true)) {
            $blockedReasons->push('runtime_behavior_must_not_change');
        }

        if (! $this->nonActivationConfirmed($nonActivation)) {
            $blockedReasons->push('non_activation_confirmation_required');
        }

        if (($rollback['rollback_mode'] ?? null) !== AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE || ! (bool) ($rollback['legacy_first'] ?? false)) {
            $blockedReasons->push('rollback_must_remain_legacy_first');
        }

        $blockedReasons = $blockedReasons
            ->unique()
            ->values()
            ->all();

        $package = [
            'phase' => '4F',
            'mode' => self::MODE,
            'package_status' => $blockedReasons === [] ? self::STATUS_READY : self::STATUS_BLOCKED,
            'workspace_id' => $phase4d['workspace_id'] ?? $phase4e['workspace_id'] ?? $input['workspace'] ?? null,
            'objective_ids' => $this->objectiveIds($phase4d, $phase4e, $input),
            'scope' => $this->scopeSummary($input, $phase4d, $phase4e),
            'phase_4d_final_evidence_status' => $phase4dStatus,
            'phase_4e_signoff_readiness' => $phase4eStatus,
            'blocked_reasons' => $blockedReasons,
            'remediation_guidance' => $this->remediationGuidance((array) ($phase4e['blocked_remediation_guidance'] ?? []), $blockedReasons),
            'rollback_confirmation' => $rollback,
            'non_activation_confirmations' => $nonActivation,
            'selected_planner_remains' => AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
            'runtime_behavior_changed' => false,
            'generated_at' => now()->toIso8601String(),
            'phase_4d_evidence_report' => $phase4d,
            'phase_4e_signoff_report' => $phase4e,
            'package_statement' => 'Phase 4F packages Phase 4D evidence and Phase 4E sign-off output for CI/review only. It does not activate planner switching or change runtime behavior.',
        ];

        $package['package_checksum_algorithm'] = 'sha256';
        $package['package_checksum'] = $this->checksum($package);
        $package['package_checksum_scope'] = 'canonical_package_excluding_generated_at_and_checksum_fields';

        return $package;
    }

    /**
     * @param  array<string,mixed>  $phase4d
     * @param  array<string,mixed>  $phase4e
     * @param  array<string,mixed>  $input
     * @return array<int,string>
     */
    private function objectiveIds(array $phase4d, array $phase4e, array $input): array
    {
        $ids = (array) ($phase4d['objective_ids'] ?? $phase4e['objective_ids'] ?? $input['objectives'] ?? []);

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
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $phase4d
     */
    private function exactRealScopePresent(array $input, array $phase4d): bool
    {
        $workspace = trim((string) ($input['workspace'] ?? $phase4d['workspace_id'] ?? ''));
        $objectives = $this->objectiveIds($phase4d, [], $input);

        return $workspace !== ''
            && $objectives !== []
            && (bool) ($input['require_real_scope'] ?? false)
            && (bool) data_get($phase4d, 'real_scope_status.real_scope_detected', false)
            && (bool) data_get($phase4d, 'real_scope_status.explicit_workspace_objective_scope', false)
            && ! (bool) data_get($phase4d, 'real_scope_status.wildcard_scope_rejected', false);
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $phase4d
     * @param  array<string,mixed>  $phase4e
     * @return array<string,mixed>
     */
    private function scopeSummary(array $input, array $phase4d, array $phase4e): array
    {
        $objectiveIds = $this->objectiveIds($phase4d, $phase4e, $input);

        return [
            'workspace_id' => $phase4d['workspace_id'] ?? $phase4e['workspace_id'] ?? $input['workspace'] ?? null,
            'objective_ids' => $objectiveIds,
            'objective_count' => count($objectiveIds),
            'site_id' => filled($input['site'] ?? null) ? (string) $input['site'] : null,
            'detector' => filled($input['detector'] ?? null) ? (string) $input['detector'] : null,
            'limit' => isset($input['limit']) ? (int) $input['limit'] : null,
            'require_real_scope' => (bool) ($input['require_real_scope'] ?? false),
            'real_scope_detected' => (bool) data_get($phase4d, 'real_scope_status.real_scope_detected', false),
            'explicit_workspace_objective_scope' => (bool) data_get($phase4d, 'real_scope_status.explicit_workspace_objective_scope', false),
            'wildcard_scope_inferred' => false,
            'percentage_scope_used' => false,
            'global_scope_used' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $phase4e
     * @return array<string,mixed>
     */
    private function nonActivationConfirmation(array $phase4e): array
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
        ], (array) ($phase4e['non_activation_confirmation'] ?? []));
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
     * @param  array<int,array<string,string>>  $phase4eGuidance
     * @param  array<int,string>  $blockedReasons
     * @return array<int,array<string,string>>
     */
    private function remediationGuidance(array $phase4eGuidance, array $blockedReasons): array
    {
        $byReason = collect($phase4eGuidance)
            ->mapWithKeys(fn (array $row): array => [(string) ($row['reason'] ?? 'unknown') => (string) ($row['guidance'] ?? '')]);

        return collect($blockedReasons)
            ->map(fn (string $reason): array => [
                'reason' => $reason,
                'guidance' => $byReason->get($reason) ?: (self::REMEDIATION_GUIDANCE[$reason] ?? 'Review Phase 4D and Phase 4E evidence, remediate the upstream blocker, and re-run Phase 4F.'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $package
     */
    private function checksum(array $package): string
    {
        $payload = Arr::except($package, [
            'generated_at',
            'package_checksum',
            'package_checksum_algorithm',
            'package_checksum_scope',
        ]);

        return hash('sha256', json_encode($this->canonicalize($payload), JSON_UNESCAPED_SLASHES));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return collect($value)
            ->map(fn (mixed $item): mixed => $this->canonicalize($item))
            ->all();
    }
}
