<?php

namespace App\Console\Commands;

use App\Models\AgenticMarketingObjective;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalPlannerApplyExperimentService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MosApplyAgenticPlannerCanonicalExperimentCommand extends Command
{
    protected $signature = 'mos:apply-agentic-planner-canonical-experiment
        {--objective= : Required AgenticMarketingObjective id}
        {--workspace= : Limit to a workspace id}
        {--site= : Limit to one client site id}
        {--detector= : Limit inspected AgenticMarketingOpportunity rows by detector key}
        {--limit= : Required maximum canonical experiment candidates to inspect}
        {--apply : Persist through the existing legacy AgenticMarketingActionPlanner path}';

    protected $description = 'Phase 3N guarded apply experiment for canonical-linked Agentic planner candidates.';

    public function handle(AgenticCanonicalPlannerApplyExperimentService $experiment): int
    {
        $objectiveId = trim((string) ($this->option('objective') ?? ''));
        $limitOption = $this->option('limit');
        $apply = (bool) $this->option('apply');

        if ($objectiveId === '') {
            $this->components->error('Objective filter required: pass --objective=<agentic-marketing-objective-id>.');

            return self::FAILURE;
        }

        if ($limitOption === null || trim((string) $limitOption) === '' || (int) $limitOption < 1) {
            $this->components->error('Limit required: pass --limit=<positive candidate limit>.');

            return self::FAILURE;
        }

        if ($apply && ! (bool) config('features.mos_agentic_planner_canonical_apply_experiment', false)) {
            $this->components->error('Apply blocked: enable ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_APPLY_EXPERIMENT before writing Phase 3N planner experiment metadata.');

            return self::FAILURE;
        }

        $objective = $this->query($objectiveId)->first();
        if (! $objective) {
            $this->components->error('No Agentic Marketing objective matched the required filters.');

            return self::FAILURE;
        }

        $this->components->info($apply
            ? 'Applying Phase 3N Agentic canonical planner experiment.'
            : 'Dry run only for Phase 3N Agentic canonical planner experiment.');
        $this->components->warn('This is an experiment. Actions remain legacy-owned AgenticMarketingAction rows against AgenticMarketingOpportunity ids. Canonical Opportunity ids are payload metadata only.');

        $report = $experiment->run($objective, [
            'detector' => $this->option('detector'),
        ], (int) $limitOption, $apply);

        $this->renderSummary((array) $report['summary']);
        $this->renderSamples($report);

        return self::SUCCESS;
    }

    /**
     * @return Builder<AgenticMarketingObjective>
     */
    private function query(string $objectiveId): Builder
    {
        return AgenticMarketingObjective::query()
            ->whereKey($objectiveId)
            ->when($this->option('workspace'), fn (Builder $query, string $workspace): Builder => $query->where('workspace_id', $workspace))
            ->when($this->option('site'), fn (Builder $query, string $site): Builder => $query->where('client_site_id', $site));
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function renderSummary(array $summary): void
    {
        $this->newLine();
        $this->table(
            [
                'inspected objectives',
                'legacy candidates',
                'canonical experiment candidates',
                'eligible apply candidates',
                'skipped candidates',
                'created actions',
                'reused actions',
                'blocked',
            ],
            [[
                $summary['inspected_objectives'],
                $summary['legacy_candidate_count'],
                $summary['canonical_experiment_candidate_count'],
                $summary['eligible_apply_candidate_count'],
                $summary['skipped_candidate_count'],
                $summary['created_action_count'],
                $summary['reused_action_count'],
                $summary['blocked_count'],
            ]]
        );

        $this->line('inspected objectives: '.$summary['inspected_objectives']);
        $this->line('legacy candidate count: '.$summary['legacy_candidate_count']);
        $this->line('canonical experiment candidate count: '.$summary['canonical_experiment_candidate_count']);
        $this->line('eligible apply candidate count: '.$summary['eligible_apply_candidate_count']);
        $this->line('skipped candidate count: '.$summary['skipped_candidate_count']);
        $this->line('created action count: '.$summary['created_action_count']);
        $this->line('reused action count: '.$summary['reused_action_count']);
        $this->line('blocked count: '.$summary['blocked_count']);
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderSamples(array $report): void
    {
        $this->line('created action ids: '.$this->sampleLine((array) $report['created_action_ids']));
        $this->line('reused action ids: '.$this->sampleLine((array) $report['reused_action_ids']));
        $this->line('planned action ids: '.$this->sampleLine((array) $report['planned_action_ids']));
        $this->line('legacy opportunity ids: '.$this->sampleLine((array) $report['legacy_opportunity_ids']));
        $this->line('linked canonical opportunity ids: '.$this->sampleLine((array) $report['linked_canonical_opportunity_ids']));
        $this->line('source signatures: '.$this->sampleLine((array) $report['source_signatures']));

        $blockers = collect((array) $report['blocker_samples'])
            ->map(fn (array $row): string => ($row['legacy_opportunity_id'] ?? 'unknown').':'.implode(',', (array) ($row['reasons'] ?? [])))
            ->values()
            ->all();
        $this->line('blocker samples: '.$this->sampleLine($blockers));

        foreach ((array) $report['rollback_notes'] as $note) {
            $this->line('rollback note: '.$note);
        }
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function sampleLine(array $values): string
    {
        $values = collect($values)
            ->map(fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES))
            ->filter()
            ->take(10)
            ->values()
            ->all();

        return $values === [] ? 'none' : implode(', ', $values);
    }
}
