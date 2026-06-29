<?php

namespace App\Console\Commands;

use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerDefaultSelectionScopedRolloutPlanService;
use Illuminate\Console\Command;

class MosInspectAgenticPlannerDefaultSelectionScopedRolloutPlanCommand extends Command
{
    protected $signature = 'mos:inspect-agentic-planner-default-selection-scoped-rollout-plan
        {--workspace= : Required workspace id}
        {--objectives= : Comma-separated AgenticMarketingObjective ids}
        {--site= : Limit to one client site id}
        {--detector= : Limit inspected rows by detector key}
        {--limit= : Required candidate limit per objective}
        {--include-metadata-only-ok : Include metadata_only_ok row samples from Phase 3T}';

    protected $description = 'Read-only Phase 3U scoped rollout planning for Agentic planner default-selection expansion.';

    public function handle(AgenticPlannerDefaultSelectionScopedRolloutPlanService $planner): int
    {
        $workspace = trim((string) $this->option('workspace'));
        $limit = trim((string) $this->option('limit'));
        $objectives = trim((string) $this->option('objectives'));

        if ($workspace === '') {
            $this->components->error('The --workspace option is required.');

            return self::INVALID;
        }

        if ($limit === '') {
            $this->components->error('The --limit option is required.');

            return self::INVALID;
        }

        if ($objectives === '') {
            $this->components->error('The --objectives option is required.');

            return self::INVALID;
        }

        $this->components->info('Read-only Phase 3U Agentic planner default-selection scoped rollout plan.');
        $this->components->warn('No runtime activation, feature flag, action creation, action owner migration, lifecycle sync, status change, dedupe hash mutation, payload mutation, route change, execution parent rewrite, metadata write, or job dispatch will occur.');

        $plan = $planner->plan([
            'workspace' => $workspace,
            'objectives' => $objectives,
            'site' => $this->option('site'),
            'detector' => $this->option('detector'),
            'limit' => max(1, (int) $limit),
            'include_metadata_only_ok' => (bool) $this->option('include-metadata-only-ok'),
        ]);

        $this->renderPlan($plan);

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function renderPlan(array $plan): void
    {
        $this->newLine();
        $this->table(
            [
                'workspace',
                'readiness',
                'eligibility',
                'mode',
                'inspected objectives',
                'included',
                'excluded',
                'runtime activation',
            ],
            [[
                $plan['workspace_id'] ?? 'none',
                $plan['readiness_status'] ?? 'missing',
                $plan['rollout_eligibility'] ?? 'blocked',
                $plan['recommended_rollout_mode'] ?? AgenticPlannerDefaultSelectionScopedRolloutPlanService::ROLLOUT_MODE,
                count((array) ($plan['inspected_objectives'] ?? [])),
                count((array) ($plan['objectives_included'] ?? [])),
                count((array) ($plan['objectives_excluded'] ?? [])),
                (bool) ($plan['runtime_activation_enabled'] ?? false) ? 'enabled' : 'not enabled',
            ]]
        );

        $this->line('phase 3t recommendation: '.($plan['phase_3t_recommendation'] ?? 'missing'));
        $this->line('runtime activation statement: '.($plan['runtime_activation_statement'] ?? 'Runtime activation is still not enabled.'));
        $this->line('legacy action ownership preserved: '.$this->boolLine((bool) ($plan['legacy_action_ownership_preserved'] ?? false)));
        $this->line('canonical ids metadata/selection context only: '.$this->boolLine((bool) ($plan['canonical_ids_metadata_selection_context_only'] ?? false)));
        $this->line('recommended first rollout scope: '.$this->jsonLine((array) ($plan['recommended_first_rollout_scope'] ?? [])));

        $this->line('blocked reasons: '.$this->sampleLine((array) ($plan['blocked_reasons'] ?? [])));
        $this->line('objectives included: '.$this->sampleLine($this->objectiveIds((array) ($plan['objectives_included'] ?? []))));
        $this->line('objectives excluded: '.$this->sampleLine($this->objectiveIds((array) ($plan['objectives_excluded'] ?? []))));

        $metadataReview = (array) ($plan['metadata_only_ok_review_requirement'] ?? []);
        $this->line('metadata_only_ok review required: '.$this->boolLine((bool) ($metadataReview['manual_review_required'] ?? false)));
        $this->line('metadata_only_ok ownership migration approved: '.$this->boolLine((bool) ($metadataReview['ownership_migration_approved'] ?? false)));
        $this->line('order parity confirmation: '.$this->boolLine((bool) data_get($plan, 'order_parity_confirmation.confirmed', false)));
        $this->line('duplicate risk confirmation: '.$this->boolLine((bool) data_get($plan, 'duplicate_risk_confirmation.confirmed', false)));
        $this->line('canonical coverage confirmation: '.$this->boolLine((bool) data_get($plan, 'canonical_coverage_confirmation.confirmed', false)));

        $this->newLine();
        $this->line('Operator checklist');
        foreach ((array) ($plan['operator_checklist'] ?? []) as $item) {
            $this->line('- '.$item);
        }

        $this->newLine();
        $this->line('Rollback checklist');
        foreach ((array) ($plan['rollback_checklist'] ?? []) as $item) {
            $this->line('- '.$item);
        }
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
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,string>
     */
    private function objectiveIds(array $rows): array
    {
        return collect($rows)
            ->map(fn (array $row): string => (string) ($row['objective_id'] ?? 'unknown'))
            ->values()
            ->all();
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
