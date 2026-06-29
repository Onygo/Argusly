<?php

namespace App\Console\Commands;

use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedTelemetryValidationService;
use Illuminate\Console\Command;

class MosInspectAgenticPlannerDefaultSelectionScopedTelemetryValidationCommand extends Command
{
    protected $signature = 'mos:inspect-agentic-planner-default-selection-scoped-telemetry-validation
        {--workspace= : Required workspace id}
        {--objectives= : Comma-separated AgenticMarketingObjective ids}
        {--site= : Limit to one client site id}
        {--detector= : Limit inspected rows by detector key}
        {--limit= : Required candidate limit per objective}
        {--ack-metadata-only-review : Acknowledge operator review of metadata_only_ok rows for this inspection}
        {--ack-runtime-switch-contract : Acknowledge the Phase 3Y runtime switch skeleton contract for this inspection}
        {--require-real-scope : Require matching workspace and objective records in the database}';

    protected $description = 'Phase 4C real scoped telemetry validation for MOS Agentic planner default selection.';

    public function handle(AgenticPlannerDefaultSelectionScopedTelemetryValidationService $validation): int
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

        $this->components->info('Phase 4C Agentic planner default-selection scoped telemetry validation.');
        $this->components->warn('Validation report only: no planner switching, action creation, ownership migration, lifecycle sync, payload/status/dedupe mutation, audit write, route/approval change, global migration, percentage rollout, or job dispatch will occur.');

        $report = $validation->validate([
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

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderReport(array $report): void
    {
        $this->newLine();
        $this->table(
            ['workspace', 'objectives', 'real scope', 'legacy candidates', 'audit snapshot', 'telemetry complete'],
            [[
                $report['workspace_id'] ?? 'none',
                count((array) ($report['objective_ids'] ?? [])),
                $this->boolLine((bool) ($report['real_scope_detected'] ?? false)),
                (string) ($report['legacy_candidate_count'] ?? 0),
                $this->boolLine((bool) ($report['audit_snapshot_present'] ?? false)),
                $this->boolLine((bool) ($report['telemetry_complete'] ?? false)),
            ]]
        );

        $summary = (array) ($report['phase_3t_through_4b_status_summary'] ?? []);
        foreach (['phase_3t', 'phase_3u', 'phase_3v', 'phase_3w', 'phase_3x', 'phase_3y', 'phase_3z', 'phase_4a', 'phase_4b'] as $phase) {
            $this->line($phase.' status: '.data_get($summary, $phase.'.status', 'missing'));
        }

        $this->line('objective records found: '.$this->boolLine((bool) ($report['objective_records_found'] ?? false)));
        $this->line('empty-scope diagnostic status: '.data_get($report, 'empty_scope_diagnostic_status.status', 'missing'));
        $this->line('activation flag state: '.($report['activation_flag_state'] ?? 'missing'));
        $this->line('activation_flag_consumed_for_switching: '.$this->boolLine((bool) ($report['activation_flag_consumed_for_switching'] ?? false)));
        $this->line('selected planner remains: '.($report['selected_planner_remains'] ?? 'legacy'));
        $this->line('runtime_behavior_changed: '.$this->boolLine((bool) ($report['runtime_behavior_changed'] ?? false)));
        $this->line('telemetry_complete: '.$this->boolLine((bool) ($report['telemetry_complete'] ?? false)));
        $this->line('telemetry blocked reasons: '.$this->sampleLine((array) ($report['telemetry_blocked_reasons'] ?? [])));
        $this->line('pre-activation checklist: '.$this->sampleLine(collect((array) ($report['pre_activation_acceptance_checklist'] ?? []))->map(function (array $check): string {
            return ((bool) ($check['passed'] ?? false) ? 'pass:' : 'fail:').(string) ($check['id'] ?? 'unknown');
        })->all()));
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
