<?php

namespace App\Console\Commands;

use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRuntimeGuardService;
use Illuminate\Console\Command;

class MosInspectAgenticPlannerDefaultSelectionScopedRuntimeGuardCommand extends Command
{
    protected $signature = 'mos:inspect-agentic-planner-default-selection-scoped-runtime-guard
        {--workspace= : Required workspace id}
        {--objectives= : Comma-separated AgenticMarketingObjective ids}
        {--site= : Limit to one client site id}
        {--detector= : Limit inspected rows by detector key}
        {--limit= : Required candidate limit per objective}
        {--ack-metadata-only-review : Acknowledge operator review of metadata_only_ok rows for this inspection}';

    protected $description = 'Guarded Phase 3V scoped runtime inspection for Agentic planner default-selection eligibility.';

    public function handle(AgenticPlannerDefaultSelectionScopedRuntimeGuardService $guard): int
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

        $this->components->info('Guarded Phase 3V Agentic planner default-selection scoped runtime guard.');
        $this->components->warn('Inspection only: default planner behavior remains legacy-first and no production data, actions, metadata, statuses, dedupe hashes, lifecycle state, routes, execution parents, ownership, or jobs are mutated.');

        $decision = $guard->decide([
            'workspace' => $workspace,
            'objectives' => $objectives,
            'site' => $this->option('site'),
            'detector' => $this->option('detector'),
            'limit' => max(1, (int) $limit),
            'ack_metadata_only_review' => (bool) $this->option('ack-metadata-only-review'),
        ]);

        $this->renderDecision($decision);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $decision
     */
    private function renderDecision(array $decision): void
    {
        $this->newLine();
        $this->table(
            ['workspace', 'objectives', 'flag', 'allowed scope', 'phase 3t', 'phase 3u', 'decision', 'rollback'],
            [[
                $decision['workspace_id'] ?? 'none',
                count((array) ($decision['objective_ids'] ?? [])),
                (bool) data_get($decision, 'config.scoped_runtime_enabled', false) ? 'enabled' : 'disabled',
                (bool) data_get($decision, 'allowed_scope_status.explicitly_allowed', false) ? 'allowed' : 'blocked',
                $decision['phase_3t_status'] ?? 'missing',
                $decision['phase_3u_eligibility'] ?? 'missing',
                (bool) ($decision['allowed'] ?? false) ? 'allowed' : 'blocked',
                $decision['rollback_mode'] ?? AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE,
            ]]
        );

        $this->line('config flag status: '.((bool) data_get($decision, 'config.scoped_runtime_enabled', false) ? 'enabled' : 'disabled'));
        $this->line('allowed scope status: '.($this->jsonLine((array) ($decision['allowed_scope_status'] ?? []))));
        $this->line('phase 3t status: '.($decision['phase_3t_status'] ?? 'missing'));
        $this->line('phase 3u eligibility: '.($decision['phase_3u_eligibility'] ?? 'missing'));
        $this->line('final runtime guard decision: '.((bool) ($decision['allowed'] ?? false) ? 'allowed' : 'blocked'));
        $this->line('blocked reasons: '.$this->sampleLine((array) ($decision['blocked_reasons'] ?? [])));
        $this->line('required operator acknowledgements: '.$this->sampleLine((array) ($decision['required_operator_acknowledgements'] ?? [])));
        $this->line('rollback mode: '.($decision['rollback_mode'] ?? AgenticPlannerDefaultSelectionScopedRuntimeGuardService::ROLLBACK_MODE));
        $this->line('runtime activation statement: '.($decision['runtime_activation_statement'] ?? 'Default selection remains legacy-first.'));
        $this->line('runtime use would still remain legacy-first: yes');
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
