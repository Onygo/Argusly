<?php

namespace App\Console\Commands;

use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionCiEvidencePackageService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionPreActivationRehearsalService;
use Illuminate\Console\Command;

class MosRehearseAgenticPlannerDefaultSelectionPreActivationCommand extends Command
{
    protected $signature = 'mos:rehearse-agentic-planner-default-selection-pre-activation
        {--workspace= : Required workspace id}
        {--objectives= : Comma-separated AgenticMarketingObjective ids}
        {--site= : Limit to one client site id}
        {--detector= : Limit inspected rows by detector key}
        {--limit= : Required candidate limit per objective}
        {--ack-metadata-only-review : Acknowledge operator review of metadata_only_ok rows for upstream Phase 4D/4F evidence}
        {--ack-runtime-switch-contract : Acknowledge the Phase 3Y runtime switch skeleton contract for upstream Phase 4D/4F evidence}
        {--ack-operator-signoff : Acknowledge explicit operator review/sign-off intent for upstream Phase 4E/4F evidence}
        {--require-real-scope : Require matching workspace and objective records in the database}
        {--ci : Exit non-zero when the rehearsal is blocked}';

    protected $description = 'Phase 4G dry-run pre-activation rehearsal for MOS Agentic planner default selection.';

    public function handle(AgenticPlannerDefaultSelectionPreActivationRehearsalService $rehearsal): int
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

        $this->components->info('Phase 4G Agentic planner default-selection pre-activation rehearsal.');
        $this->components->warn('Dry-run rehearsal only: Phase 4G does not change runtime behavior, activate planner switching, consume activation flags, replace legacy output, mutate runtime records, write audits, migrate ownership, sync lifecycle, add rollout, infer wildcard scope, create runtime feature flags, or dispatch jobs.');

        $report = $rehearsal->rehearse([
            'workspace' => $workspace,
            'objectives' => $objectives,
            'site' => $this->option('site'),
            'detector' => $this->option('detector'),
            'limit' => max(1, (int) $limit),
            'ack_metadata_only_review' => (bool) $this->option('ack-metadata-only-review'),
            'ack_runtime_switch_contract' => (bool) $this->option('ack-runtime-switch-contract'),
            'ack_operator_signoff' => (bool) $this->option('ack-operator-signoff'),
            'require_real_scope' => (bool) $this->option('require-real-scope'),
        ]);

        $this->renderReport($report);

        if ((bool) $this->option('ci') && ($report['rehearsal_status'] ?? null) !== AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_READY) {
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
        $this->components->info('Rehearsal status');
        $this->table(
            ['rehearsal status', 'Phase 4F package status', 'workspace', 'objectives', 'package checksum', 'selected planner', 'runtime changed'],
            [[
                (string) ($report['rehearsal_status'] ?? AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_BLOCKED),
                (string) ($report['phase_4f_package_status'] ?? AgenticPlannerDefaultSelectionCiEvidencePackageService::STATUS_BLOCKED),
                (string) ($report['workspace_id'] ?? 'none'),
                count((array) ($report['objective_ids'] ?? [])),
                (string) ($report['package_checksum'] ?? 'missing'),
                (string) ($report['selected_planner_remains'] ?? 'legacy'),
                $this->boolLine((bool) ($report['runtime_behavior_changed'] ?? true)),
            ]]
        );

        $this->components->info('Exact scope summary');
        $this->table(
            ['workspace', 'objective ids', 'site', 'detector', 'real scope', 'wildcard inferred'],
            [[
                (string) data_get($report, 'scope.workspace_id', 'none'),
                $this->sampleLine((array) data_get($report, 'scope.objective_ids', [])),
                data_get($report, 'scope.site_id') ?: 'none',
                data_get($report, 'scope.detector') ?: 'none',
                $this->boolLine((bool) data_get($report, 'scope.real_scope_detected', false)),
                $this->boolLine((bool) data_get($report, 'scope.wildcard_scope_inferred', false)),
            ]]
        );

        $this->components->info('Dry-run activation plan');
        $this->line('plan_type: '.data_get($report, 'rehearsal_activation_plan.plan_type', 'dry_run_only'));
        $this->line('activation_performed: '.$this->boolLine((bool) data_get($report, 'rehearsal_activation_plan.activation_performed', false)));
        $this->line('activation_flags_consumed: '.$this->boolLine((bool) data_get($report, 'rehearsal_activation_plan.activation_flags_consumed', false)));
        $this->line('selected_planner_during_rehearsal: '.data_get($report, 'rehearsal_activation_plan.selected_planner_during_rehearsal', 'legacy'));

        $this->components->info('Rollback rehearsal result');
        $this->table(
            ['passed', 'legacy output authoritative', 'legacy ownership authoritative', 'future disable keeps legacy', 'metadata removal required', 'runtime mutation required'],
            [[
                $this->boolLine((bool) data_get($report, 'rollback_rehearsal_result.passed', false)),
                $this->boolLine((bool) data_get($report, 'rollback_rehearsal_result.legacy_planner_output_remains_authoritative', false)),
                $this->boolLine((bool) data_get($report, 'rollback_rehearsal_result.legacy_agentic_action_ownership_remains_authoritative', false)),
                $this->boolLine((bool) data_get($report, 'rollback_rehearsal_result.future_activation_disable_keeps_legacy_output_selected', false)),
                $this->boolLine((bool) data_get($report, 'rollback_rehearsal_result.metadata_removal_required_for_rollback', true)),
                $this->boolLine((bool) data_get($report, 'rollback_rehearsal_result.payload_status_dedupe_parent_approval_execution_changes_required', true)),
            ]]
        );

        $this->components->info('Non-activation confirmations');
        $this->line('selected planner remains: '.data_get($report, 'non_activation_confirmations.selected_planner_remains', 'legacy'));
        $this->line('runtime_behavior_changed: '.$this->boolLine((bool) ($report['runtime_behavior_changed'] ?? true)));
        $this->line('planner_switching_activated: '.$this->boolLine((bool) data_get($report, 'non_activation_confirmations.planner_switching_activated', false)));
        $this->line('activation_flag_consumed_for_switching: '.$this->boolLine((bool) data_get($report, 'non_activation_confirmations.activation_flag_consumed_for_switching', false)));
        $this->line('legacy_planner_output_replaced: '.$this->boolLine((bool) data_get($report, 'non_activation_confirmations.legacy_planner_output_replaced', false)));
        $this->line('agentic_marketing_action_created_or_mutated: '.$this->boolLine((bool) data_get($report, 'non_activation_confirmations.agentic_marketing_action_created_or_mutated', false)));
        $this->line('runtime_feature_flags_introduced: '.$this->boolLine((bool) data_get($report, 'non_activation_confirmations.runtime_feature_flags_introduced', false)));
        $this->line('job_dispatched: '.$this->boolLine((bool) data_get($report, 'non_activation_confirmations.job_dispatched', false)));

        $this->components->info('Blocked remediation guidance');
        $guidance = collect((array) ($report['remediation_guidance'] ?? []))
            ->map(fn (array $row): array => [
                (string) ($row['reason'] ?? 'unknown'),
                (string) ($row['guidance'] ?? 'Review Phase 4F package output and remediate upstream.'),
            ])
            ->all();

        if ($guidance === []) {
            $this->line('none');
        } else {
            $this->table(['blocked reason', 'remediation guidance'], $guidance);
        }

        $this->line('package checksum: '.($report['package_checksum'] ?? 'missing'));
        $this->line('final rehearsal status: '.($report['rehearsal_status'] ?? AgenticPlannerDefaultSelectionPreActivationRehearsalService::STATUS_BLOCKED));
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
