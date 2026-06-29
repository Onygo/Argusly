<?php

namespace App\Console\Commands;

use App\Models\AgenticMarketingObjective;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalPlannerDefaultSelectionExperimentService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MosApplyAgenticPlannerDefaultSelectionExperimentCommand extends Command
{
    protected $signature = 'mos:apply-agentic-planner-default-selection-experiment
        {--objective= : Required AgenticMarketingObjective id}
        {--workspace= : Limit to a workspace id}
        {--site= : Limit to one client site id}
        {--detector= : Limit inspected AgenticMarketingOpportunity rows by detector key}
        {--limit= : Required selected candidate limit}
        {--apply : Persist through the existing legacy AgenticMarketingActionPlanner path}';

    protected $description = 'Phase 3R scoped guarded canonical default-selection experiment for Agentic planner candidates.';

    public function handle(AgenticCanonicalPlannerDefaultSelectionExperimentService $experiment): int
    {
        $objectiveId = trim((string) ($this->option('objective') ?? ''));
        $limitOption = $this->option('limit');
        $apply = (bool) $this->option('apply');

        if ($objectiveId === '') {
            $this->components->error('The --objective option is required.');

            return self::INVALID;
        }

        if ($limitOption === null || trim((string) $limitOption) === '' || (int) $limitOption < 1) {
            $this->components->error('The --limit option is required.');

            return self::INVALID;
        }

        if ($apply && ! (bool) config('features.mos_agentic_planner_canonical_default_selection_experiment', false)) {
            $this->components->error('Apply blocked: enable ARGUSLY_FEATURE_MOS_AGENTIC_PLANNER_CANONICAL_DEFAULT_SELECTION_EXPERIMENT.');

            return self::FAILURE;
        }

        $objective = $this->query($objectiveId)->first();
        if (! $objective) {
            $this->components->error('No AgenticMarketingObjective matched the supplied filters.');

            return self::FAILURE;
        }

        $this->components->info($apply
            ? 'Applying Phase 3R Agentic planner default-selection experiment.'
            : 'Dry run only for Phase 3R Agentic planner default-selection experiment.');
        $this->components->warn('Actions remain legacy-owned AgenticMarketingAction rows. Canonical Opportunity ids are selection context and metadata only.');

        $report = $experiment->run($objective, (int) $limitOption, [
            'site' => $this->option('site'),
            'detector' => $this->option('detector'),
        ], $apply);

        $this->renderSummary($report);
        $this->renderSamples($report);

        if ($apply && (int) data_get($report, 'summary.blocked_count', 0) > 0) {
            $this->components->error('Apply blocked: Phase 3R guardrails did not pass.');

            return self::FAILURE;
        }

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
     * @param  array<string,mixed>  $report
     */
    private function renderSummary(array $report): void
    {
        $summary = (array) $report['summary'];

        $this->newLine();
        $this->line('objective id: '.$report['objective_id']);
        $this->line('Phase 3Q preview status: '.$report['phase_3q_preview_status']);
        $this->line('Phase 3P recommendation: '.$report['phase_3p_recommendation']);
        $this->table(
            [
                'legacy candidates',
                'canonical selected',
                'created actions',
                'reused actions',
                'blocked',
                'skipped',
                'would create',
                'would reuse',
                'recommendation',
            ],
            [[
                $summary['legacy_candidate_count'],
                $summary['canonical_selected_count'],
                $summary['created_action_count'],
                $summary['reused_action_count'],
                $summary['blocked_count'],
                $summary['skipped_count'],
                $summary['would_create_action_count'],
                $summary['would_reuse_action_count'],
                $summary['recommendation'],
            ]]
        );

        foreach ($summary as $label => $value) {
            $this->line(str_replace('_', ' ', $label).': '.$value);
        }
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderSamples(array $report): void
    {
        $this->line('selected canonical opportunity ids: '.$this->sampleLine((array) $report['selected_canonical_opportunity_ids']));
        $this->line('resolved legacy Agentic opportunity ids: '.$this->sampleLine((array) $report['resolved_legacy_agentic_opportunity_ids']));
        $this->line('created/reused action ids: '.$this->sampleLine((array) $report['created_or_reused_action_ids']));
        $this->line('signature samples: '.$this->sampleLine((array) $report['signature_samples']));
        $this->line('metadata samples: '.$this->sampleLine((array) $report['metadata_samples']));

        $blockers = collect((array) $report['blocked_rows'])
            ->map(fn (array $row): string => ($row['legacy_opportunity_id'] ?? 'scope').':'.implode(',', (array) ($row['reasons'] ?? [])))
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
