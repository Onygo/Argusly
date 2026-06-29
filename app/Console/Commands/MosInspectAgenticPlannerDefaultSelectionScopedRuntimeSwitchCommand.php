<?php

namespace App\Console\Commands;

use App\Models\AgenticPlannerDefaultSelectionRuntimeSwitchAudit;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeSwitchService;
use Illuminate\Console\Command;

class MosInspectAgenticPlannerDefaultSelectionScopedRuntimeSwitchCommand extends Command
{
    protected $signature = 'mos:inspect-agentic-planner-default-selection-scoped-runtime-switch
        {--workspace= : Required workspace id}
        {--objectives= : Comma-separated AgenticMarketingObjective ids}
        {--site= : Limit to one client site id}
        {--detector= : Limit inspected rows by detector key}
        {--limit= : Required candidate limit per objective}
        {--ack-metadata-only-review : Acknowledge operator review of metadata_only_ok rows for this inspection}
        {--ack-runtime-switch-contract : Acknowledge the Phase 3Y runtime switch skeleton contract for this inspection}
        {--write-audit-snapshot : Persist exactly one Phase 3Y runtime switch audit snapshot row}';

    protected $description = 'Phase 3Y disabled scoped runtime switch skeleton inspection for Agentic planner default selection.';

    public function handle(AgenticPlannerDefaultSelectionScopedRuntimeSwitchService $switch): int
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

        $this->components->info('Phase 3Y Agentic planner default-selection scoped runtime switch skeleton.');
        $this->components->warn('Skeleton only: planner output remains legacy-first. Default execution is read-only; audit persistence requires --write-audit-snapshot.');

        $decision = $switch->decide([
            'workspace' => $workspace,
            'objectives' => $objectives,
            'site' => $this->option('site'),
            'detector' => $this->option('detector'),
            'limit' => max(1, (int) $limit),
            'ack_metadata_only_review' => (bool) $this->option('ack-metadata-only-review'),
            'ack_runtime_switch_contract' => (bool) $this->option('ack-runtime-switch-contract'),
        ]);

        $audit = null;
        if ((bool) $this->option('write-audit-snapshot')) {
            $audit = AgenticPlannerDefaultSelectionRuntimeSwitchAudit::query()->create([
                'workspace_id' => $decision['workspace_id'] ?? null,
                'objective_ids' => (array) ($decision['objective_ids'] ?? []),
                'phase_3t_status' => $decision['phase_3t_status'] ?? null,
                'phase_3u_eligibility' => $decision['phase_3u_eligibility'] ?? null,
                'phase_3v_guard_allowed' => (bool) ($decision['phase_3v_guard_allowed'] ?? false),
                'phase_3w_selected_planner_remains' => $decision['phase_3w_selected_planner_remains'] ?? null,
                'phase_3x_contract_status' => $decision['phase_3x_contract_status'] ?? null,
                'switch_flag_enabled' => (bool) ($decision['switch_flag_enabled'] ?? false),
                'runtime_guard_flag_enabled' => (bool) ($decision['runtime_guard_flag_enabled'] ?? false),
                'switch_decision' => $decision['switch_decision'] ?? AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED,
                'blocked_reasons' => (array) ($decision['blocked_reasons'] ?? []),
                'operator_acknowledgements' => (array) ($decision['operator_acknowledgements'] ?? []),
                'rollback_mode' => $decision['rollback_mode'] ?? null,
                'selected_planner' => $decision['selected_planner'] ?? AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER,
                'selected_action_ownership_mode' => $decision['selected_action_ownership_mode'] ?? AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_ACTION_OWNERSHIP_MODE,
                'payload_namespace' => $decision['payload_namespace'] ?? AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::AUDIT_PAYLOAD_NAMESPACE,
                'payload_version' => $decision['payload_version'] ?? AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::AUDIT_PAYLOAD_VERSION,
                'payload' => $decision,
                'created_at' => now(),
            ]);
        }

        $this->renderDecision($decision, $audit?->id);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $decision
     */
    private function renderDecision(array $decision, ?string $auditId): void
    {
        $this->newLine();
        $this->table(
            ['workspace', 'objectives', 'switch flag', 'guard flag', 'scope', '3X', '3V', '3W planner', 'decision'],
            [[
                $decision['workspace_id'] ?? 'none',
                count((array) ($decision['objective_ids'] ?? [])),
                (bool) ($decision['switch_flag_enabled'] ?? false) ? 'enabled' : 'disabled',
                (bool) ($decision['runtime_guard_flag_enabled'] ?? false) ? 'enabled' : 'disabled',
                (bool) data_get($decision, 'allowed_scope_status.explicitly_allowed', false) ? 'allowed' : 'blocked',
                $decision['phase_3x_contract_status'] ?? 'missing',
                (bool) ($decision['phase_3v_guard_allowed'] ?? false) ? 'allowed' : 'blocked',
                $decision['phase_3w_selected_planner_remains'] ?? 'missing',
                $decision['switch_decision'] ?? AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED,
            ]]
        );

        $this->line('switch flag status: '.((bool) ($decision['switch_flag_enabled'] ?? false) ? 'enabled' : 'disabled'));
        $this->line('runtime guard flag status: '.((bool) ($decision['runtime_guard_flag_enabled'] ?? false) ? 'enabled' : 'disabled'));
        $this->line('allowed switch scope status: '.$this->jsonLine((array) ($decision['allowed_scope_status'] ?? [])));
        $this->line('phase 3t status: '.($decision['phase_3t_status'] ?? 'missing'));
        $this->line('phase 3u eligibility: '.($decision['phase_3u_eligibility'] ?? 'missing'));
        $this->line('phase 3x contract status: '.($decision['phase_3x_contract_status'] ?? 'missing'));
        $this->line('phase 3v guard allowed: '.((bool) ($decision['phase_3v_guard_allowed'] ?? false) ? 'yes' : 'no'));
        $this->line('phase 3w selected planner remains: '.($decision['phase_3w_selected_planner_remains'] ?? 'missing'));
        $this->line('switch decision: '.($decision['switch_decision'] ?? AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::DECISION_BLOCKED));
        $this->line('blocked reasons: '.$this->sampleLine((array) ($decision['blocked_reasons'] ?? [])));
        $this->line('operator acknowledgements: '.$this->jsonLine((array) ($decision['operator_acknowledgements'] ?? [])));
        $this->line('rollback mode: '.($decision['rollback_mode'] ?? 'legacy_first'));
        $this->line('selected planner: '.($decision['selected_planner'] ?? AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_PLANNER));
        $this->line('selected action ownership mode: '.($decision['selected_action_ownership_mode'] ?? AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::SELECTED_ACTION_OWNERSHIP_MODE));
        $this->line('payload namespace/version: '.($decision['payload_namespace'] ?? AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::AUDIT_PAYLOAD_NAMESPACE).'/'.($decision['payload_version'] ?? AgenticPlannerDefaultSelectionScopedRuntimeSwitchService::AUDIT_PAYLOAD_VERSION));
        $this->line('runtime switching implemented: no');
        $this->line('planner output changed: no');
        $this->line('audit snapshot written: '.($auditId ? 'yes '.$auditId : 'no'));
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
}
