<?php

namespace App\Console\Commands;

use App\Services\Mos\Opportunity\AgenticMarketing\AgenticPlannerApplyExperimentAuditService;
use Illuminate\Console\Command;

class MosPlanAgenticPlannerApplyExperimentRollbackCommand extends Command
{
    protected $signature = 'mos:plan-agentic-planner-apply-experiment-rollback
        {--workspace= : Limit to a workspace id}
        {--objective= : Limit to one AgenticMarketingObjective id}
        {--site= : Limit to one client site id}
        {--detector= : Limit to one detector key}
        {--status= : Limit to one audit status}
        {--action-status= : Limit to one AgenticMarketingAction status}
        {--limit=100 : Maximum Phase 3N action rows to inspect}';

    protected $description = 'Read-only rollback diagnostics for Phase 3N Agentic planner apply experiment metadata.';

    public function handle(AgenticPlannerApplyExperimentAuditService $audit): int
    {
        $this->components->info('Read-only Phase 3O rollback plan for Agentic planner apply experiment metadata.');
        $this->components->warn('No metadata is removed. Operational rollback is disabling the apply feature flag and ignoring payload.planner_experiment.');

        $report = $audit->audit($this->filters());
        $rollback = (array) $report['rollback'];

        $this->renderSummary($rollback);
        $this->renderRows((array) $rollback['rows']);

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function filters(): array
    {
        return [
            'workspace' => $this->option('workspace'),
            'objective' => $this->option('objective'),
            'site' => $this->option('site'),
            'detector' => $this->option('detector'),
            'status' => $this->option('status'),
            'action_status' => $this->option('action-status'),
            'limit' => max(1, (int) $this->option('limit')),
        ];
    }

    /**
     * @param  array<string,mixed>  $rollback
     */
    private function renderSummary(array $rollback): void
    {
        $this->newLine();
        $this->table(
            ['inspected actions', 'metadata rollback candidates', 'unsafe rollback'],
            [[
                $rollback['inspected_action_count'],
                $rollback['metadata_rollback_candidate_count'],
                $rollback['unsafe_rollback_count'],
            ]]
        );

        $this->line('inspected action count: '.$rollback['inspected_action_count']);
        $this->line('metadata rollback candidate count: '.$rollback['metadata_rollback_candidate_count']);
        $this->line('unsafe rollback count: '.$rollback['unsafe_rollback_count']);
        $this->line('recommendation: '.$rollback['recommendation']);
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    private function renderRows(array $rows): void
    {
        $this->line('action ids: '.$this->sampleLine(collect($rows)->pluck('action_id')->all()));
        $this->line('legacy opportunity ids: '.$this->sampleLine(collect($rows)->pluck('legacy_opportunity_id')->all()));
        $this->line('canonical opportunity ids: '.$this->sampleLine(collect($rows)->pluck('canonical_opportunity_id')->all()));
        $this->line('metadata paths that would be removed: payload.planner_experiment');

        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->table(
            ['action id', 'legacy id', 'canonical id', 'metadata paths', 'rollback safe', 'reasons'],
            collect($rows)->take(20)->map(fn (array $row): array => [
                $row['action_id'],
                $row['legacy_opportunity_id'] ?: 'missing',
                $row['canonical_opportunity_id'] ?: 'missing',
                implode(', ', (array) $row['metadata_paths_that_would_be_removed']),
                $row['rollback_safe'] ? 'yes' : 'no',
                $this->sampleLine((array) $row['reasons']),
            ])->all()
        );
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function sampleLine(array $values): string
    {
        $values = collect($values)
            ->map(fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $values === [] ? 'none' : implode(', ', $values);
    }
}
