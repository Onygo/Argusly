<?php

namespace App\Console\Commands;

use App\Models\AgenticMarketingObjective;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalPlannerExperimentService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MosCompareAgenticPlannerCandidatesCommand extends Command
{
    protected $signature = 'mos:compare-agentic-planner-candidates
        {--workspace= : Limit to a workspace id}
        {--objective= : Limit to one AgenticMarketingObjective id}
        {--site= : Limit to one client site id}
        {--status= : Limit inspected AgenticMarketingOpportunity rows by status}
        {--detector= : Limit inspected AgenticMarketingOpportunity rows by detector key}
        {--limit=50 : Maximum objectives to inspect}';

    protected $description = 'Read-only Phase 3M comparison of legacy and canonical-linked Agentic planner candidates.';

    public function handle(AgenticCanonicalPlannerExperimentService $experiment): int
    {
        $this->components->info('Read-only Phase 3M Agentic planner candidate comparison.');
        $this->components->warn('Default planner selection remains legacy. No Agentic actions, canonical recommended actions, run items, audit logs, execution parents, lifecycle states, routes, payloads or historical rows will be written.');

        $reports = $this->query()
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->map(fn (AgenticMarketingObjective $objective): array => $experiment->compare($objective, [
                'status' => $this->option('status'),
                'detector' => $this->option('detector'),
            ]))
            ->filter(fn (array $report): bool => (int) data_get($report, 'summary.inspected_rows') > 0)
            ->values();

        $summary = $this->summary($reports);
        $this->renderSummary($summary);
        $this->renderSamples($reports);

        if ($reports->isEmpty()) {
            $this->line('No Agentic Marketing objectives matched planner comparison filters.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<AgenticMarketingObjective>
     */
    private function query(): Builder
    {
        return AgenticMarketingObjective::query()
            ->when($this->option('objective'), fn (Builder $query, string $objective): Builder => $query->whereKey($objective))
            ->when($this->option('workspace'), fn (Builder $query, string $workspace): Builder => $query->where('workspace_id', $workspace))
            ->when($this->option('site'), fn (Builder $query, string $site): Builder => $query->where('client_site_id', $site))
            ->whereHas('opportunities', function (Builder $query): void {
                $query->when($this->option('status'), fn (Builder $opportunityQuery, string $status): Builder => $opportunityQuery->where('status', $status));
            })
            ->orderBy('id');
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $reports
     * @return array<string,mixed>
     */
    private function summary(Collection $reports): array
    {
        $summary = [
            'inspected_objectives' => $reports->count(),
            'legacy_candidate_count' => 0,
            'canonical_ready_candidate_count' => 0,
            'blocked_candidate_count' => 0,
            'priority_order_difference_count' => 0,
            'duplicate_risk_count' => 0,
            'signature_blocker_count' => 0,
            'continuity_blocker_count' => 0,
            'lifecycle_blocker_count' => 0,
            'expected_noop_count' => 0,
            'recommendation' => 'keep legacy',
            'feature_enabled' => (bool) config('features.mos_agentic_planner_canonical_experiment', false),
        ];

        foreach ($reports as $report) {
            foreach ([
                'legacy_candidate_count',
                'canonical_ready_candidate_count',
                'blocked_candidate_count',
                'priority_order_difference_count',
                'duplicate_risk_count',
                'signature_blocker_count',
                'continuity_blocker_count',
                'lifecycle_blocker_count',
                'expected_noop_count',
            ] as $key) {
                $summary[$key] += (int) data_get($report, 'summary.'.$key, 0);
            }
        }

        $summary['recommendation'] = $this->recommendation($summary);

        return $summary;
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
                'legacy candidate count',
                'canonical-ready candidate count',
                'blocked candidate count',
                'priority order differences',
                'duplicate risk count',
                'signature blocker count',
                'continuity blocker count',
                'lifecycle blocker count',
                'expected no-op rows',
                'feature enabled',
                'recommendation',
            ],
            [[
                $summary['inspected_objectives'],
                $summary['legacy_candidate_count'],
                $summary['canonical_ready_candidate_count'],
                $summary['blocked_candidate_count'],
                $summary['priority_order_difference_count'],
                $summary['duplicate_risk_count'],
                $summary['signature_blocker_count'],
                $summary['continuity_blocker_count'],
                $summary['lifecycle_blocker_count'],
                $summary['expected_noop_count'],
                $summary['feature_enabled'] ? 'yes' : 'no',
                $summary['recommendation'],
            ]]
        );

        $this->line('inspected objectives: '.$summary['inspected_objectives']);
        $this->line('legacy candidate count: '.$summary['legacy_candidate_count']);
        $this->line('canonical-ready candidate count: '.$summary['canonical_ready_candidate_count']);
        $this->line('blocked candidate count: '.$summary['blocked_candidate_count']);
        $this->line('priority order differences: '.$summary['priority_order_difference_count']);
        $this->line('duplicate risk count: '.$summary['duplicate_risk_count']);
        $this->line('signature blocker count: '.$summary['signature_blocker_count']);
        $this->line('continuity blocker count: '.$summary['continuity_blocker_count']);
        $this->line('lifecycle blocker count: '.$summary['lifecycle_blocker_count']);
        $this->line('expected no-op rows: '.$summary['expected_noop_count']);
        $this->line('recommendation: '.$summary['recommendation']);
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $reports
     */
    private function renderSamples(Collection $reports): void
    {
        $legacyOrder = $reports
            ->flatMap(fn (array $report): array => $report['legacy_order'])
            ->map(fn (array $row): string => $row['legacy_opportunity_id'].':'.$row['rank'].':'.$row['priority_score'])
            ->take(10)
            ->values()
            ->all();

        $canonicalOrder = $reports
            ->flatMap(fn (array $report): array => $report['canonical_experiment_order'])
            ->map(fn (array $row): string => $row['legacy_opportunity_id'].'->'.$row['canonical_opportunity_id'].':'.$row['rank'].':'.$row['canonical_priority_score'])
            ->take(10)
            ->values()
            ->all();

        $excluded = $reports
            ->flatMap(fn (array $report): array => $report['excluded_rows'])
            ->map(fn (array $row): string => $row['legacy_opportunity_id'].':'.$row['readiness_status'].':'.implode(',', array_slice($row['blocked_reasons'], 0, 3)))
            ->take(10)
            ->values()
            ->all();

        $priorityDifferences = $reports
            ->flatMap(fn (array $report): array => $report['priority_order_differences'])
            ->map(fn (array $row): string => $row['legacy_opportunity_id'].' legacy='.$row['legacy_rank'].' canonical='.$row['canonical_rank'])
            ->take(10)
            ->values()
            ->all();

        $this->line('sample legacy order: '.($legacyOrder === [] ? 'none' : implode(' | ', $legacyOrder)));
        $this->line('sample canonical experiment order: '.($canonicalOrder === [] ? 'none' : implode(' | ', $canonicalOrder)));
        $this->line('excluded row samples: '.($excluded === [] ? 'none' : implode(' | ', $excluded)));
        $this->line('priority order difference samples: '.($priorityDifferences === [] ? 'none' : implode(' | ', $priorityDifferences)));
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function recommendation(array $summary): string
    {
        if ((int) $summary['duplicate_risk_count'] > 0
            || (int) $summary['signature_blocker_count'] > 0
            || (int) $summary['continuity_blocker_count'] > 0
            || (int) $summary['lifecycle_blocker_count'] > 0) {
            return 'blocked';
        }

        if ((int) $summary['canonical_ready_candidate_count'] > 0) {
            return 'safe for scoped dry-run';
        }

        return 'keep legacy';
    }
}
