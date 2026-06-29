# MOS Agentic Planner Default Selection Operator Sign-Off Runbook

Phase 4E adds operator sign-off and runbook readiness around the Phase 4D evidence report. Phase 4D remains the source of truth for evidence. Phase 4E only reviews that evidence, records explicit operator sign-off intent in command output, and prints remediation guidance for blocked evidence.

Phase 4E is review evidence only. It does not activate planner switching, consume activation flags, add percentage rollout, perform global/default migration, infer wildcard scope, replace legacy planner output, create or mutate `AgenticMarketingAction` rows, migrate ownership, sync lifecycle, mutate payload/status/dedupe/parent/approval/execution records, write runtime audit rows, dispatch jobs, or rewrite historical records.

## Command

```bash
php artisan mos:inspect-agentic-planner-default-selection-operator-signoff-runbook \
  --workspace=<workspace-id> \
  --objectives=<objective-id-a,objective-id-b> \
  --limit=1 \
  --ack-metadata-only-review \
  --ack-runtime-switch-contract \
  --ack-operator-signoff \
  --require-real-scope
```

Optional filters:

- `--site=<client-site-id>`
- `--detector=<detector-key>`

CI mode:

```bash
php artisan mos:inspect-agentic-planner-default-selection-operator-signoff-runbook \
  --workspace=<workspace-id> \
  --objectives=<objective-id> \
  --limit=1 \
  --ack-metadata-only-review \
  --ack-runtime-switch-contract \
  --ack-operator-signoff \
  --require-real-scope \
  --ci
```

Without `--ci`, blocked sign-off is still operator review output and exits `0`. With `--ci`, the command exits `0` only when sign-off readiness is `signoff_ready`; it exits non-zero when readiness is `signoff_blocked`.

## Readiness Rule

Sign-off can pass only when:

- Phase 4D final evidence status is exactly `evidence_ready`;
- the operator passes `--ack-operator-signoff`;
- rollback remains legacy-first;
- additive metadata remains review evidence only and is not required for rollback.

If Phase 4D reports `evidence_blocked`, Phase 4E reports `signoff_blocked` and adds `phase_4d_evidence_ready_required`. If `--ack-operator-signoff` is missing, Phase 4E reports `operator_signoff_acknowledgement_missing`.

## Output Sections

The command separates:

- evidence status: Phase 4D status, required Phase 4D status and evidence-ready boolean;
- operator review status: whether explicit sign-off intent was acknowledged and that the acknowledgement is review evidence only;
- sign-off readiness: `signoff_ready` or `signoff_blocked`;
- blocked remediation guidance: one row for every blocked reason;
- rollback confirmation: rollback mode, legacy-first confirmation and additive metadata rollback requirements.

## Blocked Remediation

Phase 4E remediation must happen upstream in the evidence-producing phase. Phase 4E itself must not fix data by writing runtime state.

Common blocked reasons and paths:

- `phase_3t_ready`, `phase_3t_ready_for_scoped_expansion`: re-run Phase 3T for the explicit scope and resolve preview, duplicate, lifecycle, continuity, signature and order-parity blockers.
- `phase_3u_eligible`: re-run Phase 3U with explicit workspace/objective ids and resolve eligibility, duplicate-risk, order-parity or scope blockers.
- `phase_3v_guard_allowed`: re-run Phase 3V and resolve guard blockers.
- `phase_3w_diagnostics_present_and_legacy`: capture Phase 3W diagnostics and confirm the selected planner remains `legacy`.
- `phase_3x_contract_ready`: re-run Phase 3X and satisfy the non-activation, legacy-output, rollback and acknowledgement gates.
- `phase_3y_switch_ready`: re-run Phase 3Y for the same exact scope and resolve `switch_blocked` reasons without activating switching.
- `matching_audit_snapshot_present`: refresh the required pre-switch evidence snapshot through the existing Phase 3Y/4C evidence path; Phase 4E must not write runtime audit rows.
- `phase_3z_consumption_ready_or_safe_empty_scope_diagnostic_present`: run Phase 3Z diagnostics for the exact scope or capture the safe empty-scope diagnostic.
- `phase_4a_activation_candidate_report_available`: re-run Phase 4A guarded activation design and resolve design-only blockers.
- `phase_4b_empty_scope_diagnostic_available`: re-run Phase 4B diagnostics and keep activation flag behavior report-only.
- `phase_4c_telemetry_incomplete`, `phase_4c_telemetry_complete`: resolve Phase 4C telemetry blockers and re-run Phase 4D until `evidence_ready`.
- `phase_4d_evidence_ready_required`: remediate the Phase 4D blocked reasons first; sign-off cannot override blocked evidence.
- `operator_signoff_acknowledgement_missing`: review the runbook and re-run with `--ack-operator-signoff`.
- `real_scope_required_but_missing`, `real_scope_not_detected`, `no_wildcard_or_inferred_scope`: use explicit existing workspace and objective ids; do not use wildcard, global, inferred or percentage scope.
- `metadata_only_ok_reviewed`: review `metadata_only_ok` rows as evidence only and re-run with `--ack-metadata-only-review`.
- `audit_snapshot_reviewed`: review the matching audit snapshot evidence before sign-off.
- `duplicate_risk_zero`: resolve duplicate open action risk in Phase 3T/3U evidence.
- `order_parity_confirmed`: resolve order mismatches so canonical proposed order matches legacy default order.
- `lifecycle_continuity_blockers_absent`: resolve Phase 3I continuity and Phase 3J lifecycle blockers.
- `activation_flag_disabled_or_non_consuming`, `activation_flag_still_non_consuming`, `activation_flag_report_only_and_non_consuming`: restore activation flag behavior to report-only/non-consuming.
- `selected_planner_remains_legacy`, `planner_output_remains_legacy`, `no_canonical_planner_output_replacing_legacy_output`: restore legacy planner output as authoritative.
- `runtime_behavior_changed_false`, `no_runtime_activation`: remove runtime behavior changes from the evidence path.
- `no_action_creation`, `no_action_ownership_payload_status_dedupe_lifecycle_job_mutation_observed`: remove action creation and action/ownership/payload/status/dedupe/lifecycle/job side effects.
- `no_ownership_migration`, `legacy_action_ownership_remains_authoritative`: keep legacy Agentic action ownership authoritative.
- `no_lifecycle_sync`: remove lifecycle synchronization from this phase.
- `no_payload_status_dedupe_mutation`, `rollback_requires_no_dedupe_status_mutation`: remove payload, status and dedupe writes.
- `no_execution_parent_rewrite`: keep execution parent references unchanged.
- `no_runtime_audit_write`: remove runtime audit writes from this phase.
- `no_job_dispatch`: remove queue dispatches from this phase.
- `no_route_approval_change`: remove route or approval changes from this phase.
- `no_global_default_migration`: remove global/default migration behavior.
- `no_percentage_rollout`: remove percentage rollout behavior.
- `rollback_path_confirmed_legacy_first`, `rollback_remains_legacy_first`: restore `rollback_mode=legacy_first`.
- `rollback_requires_no_migration`: remove migration requirements from rollback.
- `rollback_requires_no_historical_rewrite`: remove historical rewrite requirements from rollback.
- `rollback_is_additive_metadata_only`: keep additive metadata as review evidence only.
- `metadata_removal_not_required_for_rollback`: confirm rollback does not require deleting additive metadata.
- `activation_flag_disable_keeps_legacy_output`: confirm disabling any future activation leaves legacy planner output selected.

Unknown blocked reasons should be remediated by reviewing the Phase 4D report, fixing the upstream phase that produced the blocker, and re-running Phase 4D before Phase 4E.

## Rollback

Rollback remains legacy-first:

- legacy planner output remains authoritative;
- legacy Agentic action ownership remains authoritative;
- disabling any future activation leaves legacy output selected;
- additive metadata is review evidence only;
- additive metadata is not required for rollback;
- metadata removal is not required for rollback.
