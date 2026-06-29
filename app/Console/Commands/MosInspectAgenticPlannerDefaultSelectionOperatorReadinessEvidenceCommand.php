<?php

namespace App\Console\Commands;

use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService;
use Illuminate\Console\Command;

class MosInspectAgenticPlannerDefaultSelectionOperatorReadinessEvidenceCommand extends Command
{
    protected $signature = 'mos:inspect-agentic-planner-default-selection-operator-readiness-evidence
        {--workspace= : Required workspace id}
        {--objectives= : Comma-separated AgenticMarketingObjective ids}
        {--site= : Limit to one client site id}
        {--detector= : Limit inspected rows by detector key}
        {--limit= : Required candidate limit per objective}
        {--ack-metadata-only-review : Acknowledge operator review of metadata_only_ok rows for this inspection}
        {--ack-runtime-switch-contract : Acknowledge the Phase 3Y runtime switch skeleton contract for this inspection}
        {--require-real-scope : Require matching workspace and objective records in the database}
        {--ci : Exit non-zero when evidence is blocked}';

    protected $description = 'Phase 4D operator readiness evidence for MOS Agentic planner default selection.';

    public function handle(AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService $evidence): int
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

        $this->components->info('Phase 4D Agentic planner default-selection operator readiness evidence.');
        $this->components->warn('Evidence report only: no planner switching, action creation, ownership migration, lifecycle sync, payload/status/dedupe mutation, audit write, route/approval change, global migration, percentage rollout, or job dispatch will occur.');

        $report = $evidence->evidence([
            'workspace' => $workspace,
            'objectives' => $objectives,
            'site' => $this->option('site'),
            'detector' => $this->option('detector'),
            'limit' => max(1, (int) $limit),
            'ack_metadata_only_review' => (bool) $this->option('ack-metadata-only-review'),
            'ack_runtime_switch_contract' => (bool) $this->option('ack-runtime-switch-contract'),
            'require_real_scope' => (bool) $this->option('require-real-scope'),
        ]);

        $this->renderReport($report);

        if ((bool) $this->option('ci') && ($report['final_evidence_status'] ?? null) !== AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_READY) {
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
        $this->table(
            ['workspace', 'objectives', 'real scope', 'audit snapshot', 'telemetry complete', 'evidence status'],
            [[
                $report['workspace_id'] ?? 'none',
                count((array) ($report['objective_ids'] ?? [])),
                $this->boolLine((bool) data_get($report, 'real_scope_status.real_scope_detected', false)),
                (string) ($report['audit_snapshot_status'] ?? 'missing'),
                $this->boolLine((bool) ($report['telemetry_complete'] ?? false)),
                (string) ($report['final_evidence_status'] ?? AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_BLOCKED),
            ]]
        );

        $summary = (array) ($report['phase_3t_through_4c_chain_summary'] ?? []);
        foreach (['phase_3t', 'phase_3u', 'phase_3v', 'phase_3w', 'phase_3x', 'phase_3y', 'phase_3z', 'phase_4a', 'phase_4b', 'phase_4c'] as $phase) {
            $this->line($phase.' status: '.data_get($summary, $phase.'.status', 'missing'));
        }

        $this->line('activation flag state: '.($report['activation_flag_state'] ?? 'missing'));
        $this->line('activation_flag_consumed_for_switching: '.$this->boolLine((bool) ($report['activation_flag_consumed_for_switching'] ?? false)));
        $this->line('selected planner remains: '.($report['selected_planner_remains'] ?? 'legacy'));
        $this->line('runtime_behavior_changed: '.$this->boolLine((bool) ($report['runtime_behavior_changed'] ?? false)));
        $this->line('blocked reasons: '.$this->sampleLine((array) ($report['blocked_reasons'] ?? [])));
        $this->line('non-activation checklist: '.$this->checklistLine((array) ($report['non_activation_checklist'] ?? [])));
        $this->line('rollback checklist: '.$this->checklistLine((array) ($report['rollback_checklist'] ?? [])));
        $this->line('operator approval checklist: '.$this->checklistLine((array) ($report['operator_approval_checklist'] ?? [])));
        $this->line('final evidence status: '.($report['final_evidence_status'] ?? AgenticPlannerDefaultSelectionOperatorReadinessEvidenceService::STATUS_BLOCKED));
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     */
    private function checklistLine(array $checks): string
    {
        return $this->sampleLine(collect($checks)->map(function (array $check): string {
            return ((bool) ($check['passed'] ?? false) ? 'pass:' : 'fail:').(string) ($check['id'] ?? 'unknown');
        })->all());
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function sampleLine(array $values): string
    {
        $values = collect($values)
            ->map(fn (mixed $value): string => is_scalar($value) ? (string) $value : $this->jsonLine((array) $value))
            ->filter()
            ->values()
            ->all();

        return $values === [] ? 'none' : implode(' | ', $values);
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function jsonLine(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    private function boolLine(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
