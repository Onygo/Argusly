<?php

namespace App\Console\Commands;

use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionActivationHandoffService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionPreActivationRehearsalService;
use Illuminate\Console\Command;

class MosHandoffAgenticPlannerDefaultSelectionActivationCommand extends Command
{
    protected $signature = 'mos:handoff-agentic-planner-default-selection-activation
        {--workspace= : Required workspace id}
        {--objectives= : Comma-separated AgenticMarketingObjective ids}
        {--site= : Limit to one client site id}
        {--detector= : Limit inspected rows by detector key}
        {--limit= : Required candidate limit per objective}
        {--ack-metadata-only-review : Acknowledge operator review of metadata_only_ok rows for upstream Phase 4D/4F evidence}
        {--ack-runtime-switch-contract : Acknowledge the Phase 3Y runtime switch skeleton contract for upstream Phase 4D/4F evidence}
        {--ack-operator-signoff : Acknowledge explicit operator review/sign-off intent for upstream Phase 4E/4F evidence}
        {--ack-activation-handoff : Acknowledge operator activation handoff intent as review output only}
        {--require-real-scope : Require matching workspace and objective records in the database}
        {--ci : Exit non-zero when the handoff is blocked}';

    protected $description = 'Phase 4H operator-only activation handoff for MOS Agentic planner default selection.';

    public function handle(AgenticPlannerDefaultSelectionActivationHandoffService $handoff): int
    {
        $workspace = trim((string) $this->option('workspace'));
        $objectives = trim((string) $this->option('objectives'));
        $limit = trim((string) $this->option('limit'));

        if ($workspace === '') {
            $this->components->error('The --workspace option is required.');

            return self::INVALID;
        }

        if ($objectives === '') {
            $this->components->error('The --objectives option is required.');

            return self::INVALID;
        }

        if ($limit === '') {
            $this->components->error('The --limit option is required.');

            return self::INVALID;
        }

        $this->components->info('Phase 4H Agentic planner default-selection operator activation handoff.');
        $this->components->warn('Operator handoff only: Phase 4H does not change runtime behavior, activate planner switching, consume activation flags, create runtime feature flags, replace legacy output, mutate runtime records, write audits, migrate ownership, sync lifecycle, add rollout, infer wildcard scope, perform rollback, or dispatch jobs.');

        $report = $handoff->handoff([
            'workspace' => $workspace,
            'objectives' => $objectives,
            'site' => $this->option('site'),
            'detector' => $this->option('detector'),
            'limit' => max(1, (int) $limit),
            'ack_metadata_only_review' => (bool) $this->option('ack-metadata-only-review'),
            'ack_runtime_switch_contract' => (bool) $this->option('ack-runtime-switch-contract'),
            'ack_operator_signoff' => (bool) $this->option('ack-operator-signoff'),
            'ack_activation_handoff' => (bool) $this->option('ack-activation-handoff'),
            'require_real_scope' => (bool) $this->option('require-real-scope'),
        ]);

        $this->renderReport($report);

        if ((bool) $this->option('ci') && ($report['handoff_status'] ?? null) !== AgenticPlannerDefaultSelectionActivationHandoffService::STATUS_READY) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $this->newLine();
        $this->components->info('Handoff status');
        $this->table(
            ['handoff status', 'Phase 4G rehearsal status', 'workspace', 'objectives', 'Phase 4F package checksum', 'selected planner', 'runtime changed'],
            [[
                (string) ($report['handoff_status'] ?? AgenticPlannerDefaultSelectionActivationHandoffService::STATUS_BLOCKED),
                (string) ($report['phase_4g_rehearsal_status'] ?? AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_BLOCKED),
                (string) ($report['workspace_id'] ?? 'none'),
                count((array) ($report['objective_ids'] ?? [])),
                (string) ($report['phase_4f_package_checksum'] ?? 'missing'),
                (string) ($report['selected_planner_remains'] ?? 'legacy'),
                $this->boolLine((bool) ($report['runtime_behavior_changed'] ?? true)),
            ]]
        );

        $this->components->info('Exact scope summary');
        $this->table(
            ['workspace', 'objective ids', 'site', 'detector', 'real scope', 'wildcard inferred'],
            [[
                (string) data_get($report, 'exact_scope_summary.workspace_id', 'none'),
                $this->sampleLine((array) data_get($report, 'exact_scope_summary.objective_ids', [])),
                data_get($report, 'exact_scope_summary.site_id') ?: 'none',
                data_get($report, 'exact_scope_summary.detector') ?: 'none',
                $this->boolLine((bool) data_get($report, 'exact_scope_summary.real_scope_detected', false)),
                $this->boolLine((bool) data_get($report, 'exact_scope_summary.wildcard_scope_inferred', false)),
            ]]
        );

        $this->components->info('Operator acknowledgement');
        $this->line('status: '.data_get($report, 'operator_handoff_acknowledgement.status', AgenticPlannerDefaultSelectionActivationHandoffService::ACKNOWLEDGEMENT_MISSING));
        $this->line('acknowledged: '.$this->boolLine((bool) data_get($report, 'operator_handoff_acknowledgement.acknowledged', false)));
        $this->line('review_output_only: '.$this->boolLine((bool) data_get($report, 'operator_handoff_acknowledgement.review_output_only', true)));
        $this->line('activation_performed: '.$this->boolLine((bool) data_get($report, 'operator_handoff_acknowledgement.activation_performed', false)));

        $this->components->info('Dry-run activation plan summary');
        $this->line('plan_type: '.data_get($report, 'dry_run_activation_plan_summary.plan_type', 'dry_run_only'));
        $this->line('activation_performed: '.$this->boolLine((bool) data_get($report, 'dry_run_activation_plan_summary.activation_performed', false)));
        $this->line('activation_flags_consumed: '.$this->boolLine((bool) data_get($report, 'dry_run_activation_plan_summary.activation_flags_consumed', false)));
        $this->line('selected_planner_during_rehearsal: '.data_get($report, 'dry_run_activation_plan_summary.selected_planner_during_rehearsal', 'legacy'));

        $this->components->info('Rollback rehearsal summary');
        $this->table(
            ['passed', 'legacy output authoritative', 'legacy ownership authoritative', 'future disable keeps legacy', 'rollback performed', 'runtime mutation required'],
            [[
                $this->boolLine((bool) data_get($report, 'rollback_rehearsal_summary.passed', false)),
                $this->boolLine((bool) data_get($report, 'rollback_rehearsal_summary.legacy_planner_output_remains_authoritative', false)),
                $this->boolLine((bool) data_get($report, 'rollback_rehearsal_summary.legacy_agentic_action_ownership_remains_authoritative', false)),
                $this->boolLine((bool) data_get($report, 'rollback_rehearsal_summary.future_activation_disable_keeps_legacy_output_selected', false)),
                $this->boolLine((bool) data_get($report, 'rollback_rehearsal_summary.rollback_performed', false)),
                $this->boolLine((bool) data_get($report, 'rollback_rehearsal_summary.payload_status_dedupe_parent_approval_execution_changes_required', true)),
            ]]
        );

        $this->components->info('Operator handoff checklist');
        $checklist = collect((array) ($report['operator_handoff_checklist'] ?? []))
            ->map(fn (array $row): array => [
                (string) ($row['item'] ?? 'unknown'),
                $this->boolLine((bool) ($row['ready'] ?? false)),
            ])
            ->all();

        $this->table(['checklist item', 'ready'], $checklist);

        $this->components->info('Non-activation confirmations');
        $this->line('selected planner remains: '.data_get($report, 'non_activation_confirmations.selected_planner_remains', 'legacy'));
        $this->line('runtime_behavior_changed: '.$this->boolLine((bool) ($report['runtime_behavior_changed'] ?? true)));
        $this->line('planner_switching_activated: '.$this->boolLine((bool) data_get($report, 'non_activation_confirmations.planner_switching_activated', false)));
        $this->line('activation_flag_consumed_for_switching: '.$this->boolLine((bool) data_get($report, 'non_activation_confirmations.activation_flag_consumed_for_switching', false)));
        $this->line('runtime_feature_flags_introduced: '.$this->boolLine((bool) data_get($report, 'non_activation_confirmations.runtime_feature_flags_introduced', false)));
        $this->line('legacy_planner_output_replaced: '.$this->boolLine((bool) data_get($report, 'non_activation_confirmations.legacy_planner_output_replaced', false)));
        $this->line('agentic_marketing_action_created_or_mutated: '.$this->boolLine((bool) data_get($report, 'non_activation_confirmations.agentic_marketing_action_created_or_mutated', false)));
        $this->line('rollback_performed: '.$this->boolLine((bool) data_get($report, 'non_activation_confirmations.rollback_performed', false)));
        $this->line('job_dispatched: '.$this->boolLine((bool) data_get($report, 'non_activation_confirmations.job_dispatched', false)));

        $this->components->info('Blocked remediation guidance');
        $guidance = collect((array) ($report['remediation_guidance'] ?? []))
            ->map(fn (array $row): array => [
                (string) ($row['reason'] ?? 'unknown'),
                (string) ($row['guidance'] ?? 'Review Phase 4G rehearsal output and remediate upstream.'),
            ])
            ->all();

        if ($guidance === []) {
            $this->line('none');
        } else {
            $this->table(['blocked reason', 'remediation guidance'], $guidance);
        }

        $this->line('Phase 4F package checksum: '.($report['phase_4f_package_checksum'] ?? 'missing'));
        $this->line('final handoff status: '.($report['handoff_status'] ?? AgenticPlannerDefaultSelectionActivationHandoffService::STATUS_BLOCKED));
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function sampleLine(array $values): string
    {
        $values = collect($values)
            ->map(fn (mixed $value): string => is_scalar($value) ? (string) $value : (json_encode($value, JSON_UNESCAPED_SLASHES) ?: '{}'))
            ->filter()
            ->values()
            ->all();

        return $values === [] ? 'none' : implode(' | ', $values);
    }

    private function boolLine(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
