<?php

namespace App\Console\Commands;

use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionOperatorSignOffRunbookService;
use Illuminate\Console\Command;

class MosInspectAgenticPlannerDefaultSelectionOperatorSignOffRunbookCommand extends Command
{
    protected $signature = 'mos:inspect-agentic-planner-default-selection-operator-signoff-runbook
        {--workspace= : Required workspace id}
        {--objectives= : Comma-separated AgenticMarketingObjective ids}
        {--site= : Limit to one client site id}
        {--detector= : Limit inspected rows by detector key}
        {--limit= : Required candidate limit per objective}
        {--ack-metadata-only-review : Acknowledge operator review of metadata_only_ok rows for the Phase 4D inspection}
        {--ack-runtime-switch-contract : Acknowledge the Phase 3Y runtime switch skeleton contract for the Phase 4D inspection}
        {--ack-operator-signoff : Acknowledge explicit operator review/sign-off intent as review evidence only}
        {--require-real-scope : Require matching workspace and objective records in the database}
        {--ci : Exit non-zero when sign-off readiness is blocked}';

    protected $description = 'Phase 4E operator sign-off and runbook readiness for MOS Agentic planner default selection.';

    public function handle(AgenticPlannerDefaultSelectionOperatorSignOffRunbookService $runbook): int
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

        $this->components->info('Phase 4E Agentic planner default-selection operator sign-off runbook readiness.');
        $this->components->warn('Review evidence only: no planner switching, activation flag consumption, action creation, ownership migration, lifecycle sync, payload/status/dedupe mutation, runtime audit write, route/approval change, historical rewrite, global migration, percentage rollout, or job dispatch will occur.');

        $report = $runbook->inspect([
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

        if ((bool) $this->option('ci') && data_get($report, 'signoff_readiness.status') !== AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::STATUS_READY) {
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
        $this->components->info('Evidence status');
        $this->table(
            ['workspace', 'objectives', 'phase 4D evidence status', 'required phase 4D status', 'evidence ready'],
            [[
                $report['workspace_id'] ?? 'none',
                count((array) ($report['objective_ids'] ?? [])),
                (string) data_get($report, 'evidence_status.phase_4d_final_evidence_status', AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_BLOCKED),
                (string) data_get($report, 'evidence_status.required_phase_4d_status', AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_READY),
                $this->boolLine((bool) data_get($report, 'evidence_status.phase_4d_evidence_ready', false)),
            ]]
        );

        $this->components->info('Operator review status');
        $this->table(
            ['operator review status', 'operator sign-off acknowledged', 'review evidence only'],
            [[
                (string) data_get($report, 'operator_review_status.status', AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::REVIEW_MISSING),
                $this->boolLine((bool) data_get($report, 'operator_review_status.operator_signoff_acknowledged', false)),
                $this->boolLine((bool) data_get($report, 'operator_review_status.review_evidence_only', true)),
            ]]
        );

        $this->components->info('Sign-off readiness');
        $this->table(
            ['sign-off readiness', 'ready', 'blocked reasons'],
            [[
                (string) data_get($report, 'signoff_readiness.status', AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::STATUS_BLOCKED),
                $this->boolLine((bool) data_get($report, 'signoff_readiness.ready', false)),
                $this->sampleLine((array) data_get($report, 'signoff_readiness.blocked_reasons', [])),
            ]]
        );

        $this->components->info('Blocked remediation guidance');
        $guidance = collect((array) ($report['blocked_remediation_guidance'] ?? []))
            ->map(fn (array $row): array => [
                (string) ($row['reason'] ?? 'unknown'),
                (string) ($row['guidance'] ?? 'Review Phase 4D evidence and remediate upstream.'),
            ])
            ->all();

        if ($guidance === []) {
            $this->line('none');
        } else {
            $this->table(['blocked reason', 'remediation guidance'], $guidance);
        }

        $this->components->info('Rollback confirmation');
        $this->table(
            ['rollback mode', 'legacy first', 'legacy output authoritative', 'additive metadata review only', 'metadata required for rollback'],
            [[
                (string) data_get($report, 'rollback_confirmation.rollback_mode', 'legacy_first'),
                $this->boolLine((bool) data_get($report, 'rollback_confirmation.legacy_first', true)),
                $this->boolLine((bool) data_get($report, 'rollback_confirmation.legacy_output_remains_authoritative', true)),
                $this->boolLine((bool) data_get($report, 'rollback_confirmation.additive_metadata_review_evidence_only', true)),
                $this->boolLine((bool) data_get($report, 'rollback_confirmation.additive_metadata_required_for_rollback', false)),
            ]]
        );

        $this->line('selected planner remains: '.data_get($report, 'non_activation_confirmation.selected_planner_remains', 'legacy'));
        $this->line('activation_flag_consumed_for_switching: '.$this->boolLine((bool) data_get($report, 'non_activation_confirmation.activation_flag_consumed_for_switching', false)));
        $this->line('runtime_behavior_changed: '.$this->boolLine((bool) data_get($report, 'non_activation_confirmation.runtime_behavior_changed', false)));
        $this->line('final sign-off readiness: '.data_get($report, 'signoff_readiness.status', AgenticPlannerDefaultSelectionOperatorSignOffRunbookService::STATUS_BLOCKED));
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
