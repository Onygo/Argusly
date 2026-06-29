<?php

namespace App\Console\Commands;

use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionGuardedActivationDesignService;
use Illuminate\Console\Command;

class MosInspectAgenticPlannerDefaultSelectionGuardedActivationDesignCommand extends Command
{
    protected $signature = 'mos:inspect-agentic-planner-default-selection-guarded-activation-design
        {--workspace= : Required workspace id}
        {--objectives= : Comma-separated AgenticMarketingObjective ids}
        {--site= : Limit to one client site id}
        {--detector= : Limit inspected rows by detector key}
        {--limit= : Required candidate limit per objective}
        {--ack-metadata-only-review : Acknowledge operator review of metadata_only_ok rows for this inspection}
        {--ack-runtime-switch-contract : Acknowledge the Phase 3Y runtime switch skeleton contract for this inspection}';

    protected $description = 'Phase 4A guarded activation design report for MOS Agentic planner default selection.';

    public function handle(AgenticPlannerDefaultSelectionGuardedActivationDesignService $design): int
    {
        $workspace = trim((string) $this->option('workspace'));
        $limit = trim((string) $this->option('limit'));
        $objectives = trim((string) $this->option('objectives'));

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

        $this->components->info('Phase 4A Agentic planner default-selection guarded activation design.');
        $this->components->warn('Design plus diagnostics only: the Phase 4B activation flag contract is disabled and report-only; no planner switching, action creation, ownership migration, lifecycle sync, payload/status/dedupe mutation, audit write, route/approval change, global migration, percentage rollout, or job dispatch will occur.');

        $report = $design->report([
            'workspace' => $workspace,
            'objectives' => $objectives,
            'site' => $this->option('site'),
            'detector' => $this->option('detector'),
            'limit' => max(1, (int) $limit),
            'ack_metadata_only_review' => (bool) $this->option('ack-metadata-only-review'),
            'ack_runtime_switch_contract' => (bool) $this->option('ack-runtime-switch-contract'),
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
            ['workspace', 'objectives', 'activation candidate', 'current planner', 'after 4A', 'runtime changed'],
            [[
                $report['workspace_id'] ?? 'none',
                count((array) ($report['objective_ids'] ?? [])),
                $report['activation_candidate'] ?? 'no',
                $report['selected_planner_current'] ?? 'legacy',
                $report['selected_planner_after_phase_4a'] ?? 'legacy',
                $this->boolLine((bool) ($report['runtime_behavior_changed'] ?? false)),
            ]]
        );

        $chain = (array) ($report['readiness_chain_status'] ?? []);
        foreach (['phase_3t', 'phase_3u', 'phase_3v', 'phase_3w', 'phase_3x', 'phase_3y', 'phase_3z'] as $phase) {
            $this->line($phase.' status: '.data_get($chain, $phase.'.status', 'missing'));
        }

        $this->line('activation candidate: '.($report['activation_candidate'] ?? 'no'));
        $this->line('blocked reasons: '.$this->sampleLine((array) ($report['blocked_reasons'] ?? [])));
        $this->line('selected planner current: '.($report['selected_planner_current'] ?? 'legacy'));
        $this->line('selected planner after Phase 4A: '.($report['selected_planner_after_phase_4a'] ?? 'legacy'));
        $this->line('selected planner after Phase 4B: '.($report['selected_planner_after_phase_4b'] ?? 'legacy'));
        $this->line('runtime_behavior_changed: '.$this->boolLine((bool) ($report['runtime_behavior_changed'] ?? false)));
        $this->line('safe_empty_scope_diagnostic_available: '.$this->boolLine((bool) ($report['safe_empty_scope_diagnostic_available'] ?? false)));
        $this->line('activation_flag_defined: '.$this->boolLine((bool) ($report['activation_flag_defined'] ?? false)));
        $this->line('activation_flag_enabled: '.$this->boolLine((bool) ($report['activation_flag_enabled'] ?? false)));
        $this->line('activation_flag_consumed_for_switching: '.$this->boolLine((bool) ($report['activation_flag_consumed_for_switching'] ?? false)));
        $this->line('empty-candidate observability decision: '.data_get($report, 'required_empty_candidate_observability_decision.decision', 'missing'));
        $this->line('rollback gates: '.$this->sampleLine(collect((array) ($report['required_rollback_gates'] ?? []))->pluck('id')->all()));
        $this->line('audit-before-use gates: '.$this->sampleLine(collect((array) ($report['required_audit_before_use_gates'] ?? []))->pluck('id')->all()));
        $this->line('forbidden runtime effects: '.$this->sampleLine((array) ($report['forbidden_runtime_effects'] ?? [])));
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
