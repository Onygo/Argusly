<?php

namespace App\Services\Mos\Opportunity\AgenticMarketing;

class AgenticPlannerDefaultSelectionRuntimeSwitchContractService
{
    public const MODE = 'scoped_runtime_switch_contract_only';

    public const STATUS_READY = 'contract_ready';

    public const STATUS_BLOCKED = 'contract_blocked';

    public const REQUIRED_SWITCH_FLAG = 'MOS_AGENTIC_PLANNER_DEFAULT_SELECTION_SCOPED_RUNTIME_SWITCH_ENABLED';

    /**
     * @var array<int,string>
     */
    public const FORBIDDEN_MUTATIONS = [
        'creating AgenticMarketingAction rows',
        'changing AgenticMarketingAction.opportunity_id',
        'changing action status',
        'changing dedupe hashes',
        'changing payloads',
        'syncing lifecycle state',
        'rewriting execution parents',
        'dispatching jobs',
        'changing routes or approvals',
        'enabling global/default planner migration',
        'percentage rollout',
    ];

    public function __construct(
        private readonly AgenticPlannerDefaultSelectionScopedRuntimeGuardService $guard,
    ) {}

    /**
     * @param  array{workspace?:string|null,site?:string|null,detector?:string|null,objectives?:array<int,string>|string|null,limit?:int|null,ack_metadata_only_review?:bool|null}  $input
     * @return array<string,mixed>
     */
    public function report(array $input): array
    {
        $workspace = $this->stringValue($input['workspace'] ?? null);
        $objectiveIds = $this->objectiveIds($input['objectives'] ?? null);
        $limit = max(1, (int) ($input['limit'] ?? 0));

        $guardDecision = $this->guard->decide([
            'workspace' => $workspace,
            'objectives' => $objectiveIds,
            'site' => $input['site'] ?? null,
            'detector' => $input['detector'] ?? null,
            'limit' => $limit,
            'ack_metadata_only_review' => (bool) ($input['ack_metadata_only_review'] ?? false),
        ]);

        $diagnostics = $this->plannerPathDiagnostics();
        $blockedReasons = $this->blockedReasons($guardDecision, $diagnostics);
        $status = $blockedReasons === [] ? self::STATUS_READY : self::STATUS_BLOCKED;

        return [
            'phase' => '3X',
            'workspace_id' => $workspace,
            'objective_ids' => $objectiveIds,
            'phase_3v_guard_decision' => $guardDecision,
            'phase_3w_planner_path_diagnostic_state' => [
                'available' => $diagnostics !== null,
                'diagnostics' => $diagnostics,
                'summary' => $this->diagnosticSummary($diagnostics),
            ],
            'proposed_future_switch_mode' => self::MODE,
            'required_separate_switch_flag' => [
                'name' => self::REQUIRED_SWITCH_FLAG,
                'default_enabled' => false,
                'contract_phase_status' => 'not_registered_or_enabled_by_phase_3x',
                'requirement' => 'A later phase must add a separate disabled-by-default flag before any runtime switching is possible.',
            ],
            'exact_scoped_allowlist_requirement' => [
                'workspace_id' => $workspace,
                'objective_ids' => $objectiveIds,
                'requirement' => 'Future switching must require an exact operator-approved workspace id plus complete objective id allowlist. Wildcards, global defaults, omitted objectives, percentage rollout, and inferred scope are forbidden.',
            ],
            'operator_acknowledgement_requirements' => [
                'metadata_only_ok_review',
                'exact_workspace_objective_scope_review',
                'legacy_rollback_authority_review',
                'zero_duplicate_open_legacy_action_risk_review',
                'zero_lifecycle_ambiguity_or_conflict_review',
            ],
            'ownership_contract' => $this->ownershipContract(),
            'action_creation_contract' => $this->actionCreationContract(),
            'lifecycle_contract' => $this->lifecycleContract(),
            'audit_contract' => $this->auditContract(),
            'rollback_contract' => $this->rollbackContract(),
            'dedupe_contract' => $this->dedupeContract(),
            'payload_contract' => $this->payloadContract(),
            'dispatch_contract' => $this->dispatchContract(),
            'contracts' => [
                'ownership' => $this->ownershipContract(),
                'action_creation' => $this->actionCreationContract(),
                'lifecycle' => $this->lifecycleContract(),
                'audit' => $this->auditContract(),
                'rollback' => $this->rollbackContract(),
                'dedupe' => $this->dedupeContract(),
                'payload' => $this->payloadContract(),
                'dispatch' => $this->dispatchContract(),
            ],
            'forbidden_mutations' => self::FORBIDDEN_MUTATIONS,
            'blocked_reasons' => $blockedReasons,
            'final_status' => $status,
            'runtime_switching_implemented' => false,
            'planner_selection_changed' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $guardDecision
     * @param  array<string,mixed>|null  $diagnostics
     * @return array<int,string>
     */
    private function blockedReasons(array $guardDecision, ?array $diagnostics): array
    {
        $blocked = [];

        if (! (bool) ($guardDecision['allowed'] ?? false)) {
            $blocked[] = 'phase_3v_guard_blocked';
        }

        if ($diagnostics === null) {
            $blocked[] = 'phase_3w_diagnostics_missing';

            return $blocked;
        }

        if (! (bool) ($diagnostics['ok'] ?? false)) {
            $blocked[] = 'phase_3w_diagnostics_failed';
        }

        if (! (bool) ($diagnostics['guard_called'] ?? false)) {
            $blocked[] = 'phase_3w_guard_not_called';
        }

        if (! (bool) ($diagnostics['guard_allowed'] ?? false)) {
            $blocked[] = 'phase_3w_guard_not_allowed';
        }

        if (($this->stringValue($diagnostics['selected_planner_remains'] ?? null) ?? 'missing') !== 'legacy') {
            $blocked[] = 'phase_3w_selected_planner_not_legacy';
        }

        return collect($blocked)->unique()->values()->all();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function plannerPathDiagnostics(): ?array
    {
        if (! app()->bound(AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook::DIAGNOSTICS_KEY)) {
            return null;
        }

        $diagnostics = app(AgenticPlannerDefaultSelectionPlannerPathDiagnosticHook::DIAGNOSTICS_KEY);

        return is_array($diagnostics) ? $diagnostics : null;
    }

    /**
     * @param  array<string,mixed>|null  $diagnostics
     * @return array<string,mixed>
     */
    private function diagnosticSummary(?array $diagnostics): array
    {
        if ($diagnostics === null) {
            return [
                'available' => false,
                'selected_planner_remains' => 'missing',
            ];
        }

        return [
            'available' => true,
            'ok' => (bool) ($diagnostics['ok'] ?? false),
            'guard_called' => (bool) ($diagnostics['guard_called'] ?? false),
            'guard_allowed' => (bool) ($diagnostics['guard_allowed'] ?? false),
            'selected_planner_remains' => $this->stringValue($diagnostics['selected_planner_remains'] ?? null) ?? 'missing',
            'blocked_reasons' => array_values((array) ($diagnostics['blocked_reasons'] ?? [])),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function ownershipContract(): array
    {
        return [
            'legacy AgenticMarketingOpportunity ownership remains rollback authority',
            'no AgenticMarketingAction.opportunity_id rewrite',
            'no historical execution parent rewrite',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function actionCreationContract(): array
    {
        return [
            'no canonical action creation unless future switch flag is enabled and guard is allowed',
            'canonical-created actions must be explicitly distinguishable from legacy-created actions',
            'no duplicate open legacy actions may exist',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function lifecycleContract(): array
    {
        return [
            'no lifecycle sync until lifecycle ambiguity/conflict remains zero',
            'no status mutation as part of contract phase',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function auditContract(): array
    {
        return [
            'future runtime switching must persist explicit audit fields before any real switch',
            'audit must include guard decision',
            'audit must include Phase 3T status',
            'audit must include Phase 3U eligibility',
            'audit must include Phase 3W diagnostic summary',
            'audit must include operator acknowledgement',
            'audit must include rollback mode',
            'audit must include selected planner',
            'audit must include selected action ownership mode',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function rollbackContract(): array
    {
        return [
            'disabling the future switch flag must return behavior to legacy selection without migration',
            'rollback must not require rewriting historical actions',
            'rollback must not mutate dedupe hashes or statuses',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function dedupeContract(): array
    {
        return [
            'duplicate open legacy action risk must be zero',
            'canonical-created candidates must not collide with legacy dedupe signatures',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function payloadContract(): array
    {
        return [
            'no payload rewrite during contract phase',
            'future switch payload additions must be additive and namespaced',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function dispatchContract(): array
    {
        return [
            'no job dispatch during contract phase',
            'future switched actions must not dispatch jobs until explicitly approved by existing execution gates',
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
}
