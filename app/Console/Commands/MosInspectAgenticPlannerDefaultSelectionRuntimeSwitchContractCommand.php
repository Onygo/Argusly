<?php

namespace App\Console\Commands;

use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionRuntimeSwitchContractService;
use Illuminate\Console\Command;

class MosInspectAgenticPlannerDefaultSelectionRuntimeSwitchContractCommand extends Command
{
    protected $signature = 'mos:inspect-agentic-planner-default-selection-runtime-switch-contract
        {--workspace= : Required workspace id}
        {--objectives= : Comma-separated AgenticMarketingObjective ids}
        {--site= : Limit to one client site id}
        {--detector= : Limit inspected rows by detector key}
        {--limit= : Required candidate limit per objective}
        {--ack-metadata-only-review : Acknowledge operator review of metadata_only_ok rows for this inspection}';

    protected $description = 'Design-only Phase 3X contract for a future scoped Agentic planner default-selection runtime switch.';

    public function handle(AgenticPlannerDefaultSelectionRuntimeSwitchContractService $contract): int
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

        $this->components->info('Design-only Phase 3X Agentic planner default-selection runtime switch contract.');
        $this->components->warn('Contract only: no runtime switching, action creation, ownership migration, lifecycle sync, status/dedupe/payload mutation, route change, approval change, execution parent rewrite, global migration, percentage rollout, or job dispatch will occur.');

        $report = $contract->report([
            'workspace' => $workspace,
            'objectives' => $objectives,
            'site' => $this->option('site'),
            'detector' => $this->option('detector'),
            'limit' => max(1, (int) $limit),
            'ack_metadata_only_review' => (bool) $this->option('ack-metadata-only-review'),
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
            ['workspace', 'objectives', 'mode', 'future flag', 'Phase 3V', 'Phase 3W', 'status'],
            [[
                $report['workspace_id'] ?? 'none',
                count((array) ($report['objective_ids'] ?? [])),
                $report['proposed_future_switch_mode'] ?? AgenticPlannerDefaultSelectionRuntimeSwitchContractService::MODE,
                data_get($report, 'required_separate_switch_flag.name', AgenticPlannerDefaultSelectionRuntimeSwitchContractService::REQUIRED_SWITCH_FLAG),
                (bool) data_get($report, 'phase_3v_guard_decision.allowed', false) ? 'allowed' : 'blocked',
                (bool) data_get($report, 'phase_3w_planner_path_diagnostic_state.available', false) ? data_get($report, 'phase_3w_planner_path_diagnostic_state.summary.selected_planner_remains', 'available') : 'missing',
                $report['final_status'] ?? AgenticPlannerDefaultSelectionRuntimeSwitchContractService::STATUS_BLOCKED,
            ]]
        );

        $this->line('required separate switch flag: '.data_get($report, 'required_separate_switch_flag.name', AgenticPlannerDefaultSelectionRuntimeSwitchContractService::REQUIRED_SWITCH_FLAG));
        $this->line('future switch flag default enabled: '.$this->boolLine((bool) data_get($report, 'required_separate_switch_flag.default_enabled', false)));
        $this->line('Phase 3V guard decision: '.((bool) data_get($report, 'phase_3v_guard_decision.allowed', false) ? 'allowed' : 'blocked'));
        $this->line('Phase 3V guard blocked reasons: '.$this->sampleLine((array) data_get($report, 'phase_3v_guard_decision.blocked_reasons', [])));
        $this->line('Phase 3W diagnostics available: '.$this->boolLine((bool) data_get($report, 'phase_3w_planner_path_diagnostic_state.available', false)));
        $this->line('Phase 3W diagnostics ok: '.$this->boolLine((bool) data_get($report, 'phase_3w_planner_path_diagnostic_state.summary.ok', false)));
        $this->line('Phase 3W guard called: '.$this->boolLine((bool) data_get($report, 'phase_3w_planner_path_diagnostic_state.summary.guard_called', false)));
        $this->line('Phase 3W guard allowed: '.$this->boolLine((bool) data_get($report, 'phase_3w_planner_path_diagnostic_state.summary.guard_allowed', false)));
        $this->line('Phase 3W selected planner remains: '.data_get($report, 'phase_3w_planner_path_diagnostic_state.summary.selected_planner_remains', 'missing'));
        $this->line('exact scoped allowlist requirement: '.data_get($report, 'exact_scoped_allowlist_requirement.requirement', 'missing'));
        $this->line('operator acknowledgement requirements: '.$this->sampleLine((array) ($report['operator_acknowledgement_requirements'] ?? [])));
        $this->line('blocked reasons: '.$this->sampleLine((array) ($report['blocked_reasons'] ?? [])));
        $this->line('runtime switching implemented: '.$this->boolLine((bool) ($report['runtime_switching_implemented'] ?? false)));
        $this->line('planner selection changed: '.$this->boolLine((bool) ($report['planner_selection_changed'] ?? false)));
        $this->line('production data altered: no');

        $this->newLine();
        $this->line('Contracts');
        foreach ((array) ($report['contracts'] ?? []) as $name => $items) {
            $this->line('- '.$name.': '.$this->sampleLine((array) $items));
        }

        $this->newLine();
        $this->line('Forbidden mutations: '.$this->sampleLine((array) ($report['forbidden_mutations'] ?? [])));
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
