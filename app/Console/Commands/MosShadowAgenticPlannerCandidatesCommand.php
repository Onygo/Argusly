<?php

namespace App\Console\Commands;

use App\Models\AgenticMarketingObjective;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalPlannerShadowService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MosShadowAgenticPlannerCandidatesCommand extends Command
{
    protected $signature = 'mos:shadow-agentic-planner-candidates
        {--workspace= : Limit to a workspace id}
        {--objective= : Limit to one AgenticMarketingObjective id}
        {--site= : Limit to one client site id}
        {--detector= : Limit inspected AgenticMarketingOpportunity rows by detector key}
        {--limit=50 : Maximum objectives to inspect}';

    protected $description = 'Read-only Phase 3P shadow diagnostics for canonical-linked Agentic planner candidates.';

    public function handle(AgenticCanonicalPlannerShadowService $shadow): int
    {
        $this->components->info('Read-only Phase 3P Agentic planner canonical shadow diagnostics.');
        $this->components->warn('Default planner output remains legacy. No Agentic actions, canonical recommended actions, run items, audit logs, execution parents, lifecycle states, routes, payloads or historical rows will be written.');

        $reports = $this->query()
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->map(fn (AgenticMarketingObjective $objective): array => $shadow->compare($objective, [
                'detector' => $this->option('detector'),
                'limit' => 100,
            ]))
            ->filter(fn (array $report): bool => (int) data_get($report, 'summary.legacy_candidate_count', 0) > 0
                || (int) data_get($report, 'summary.shadow_canonical_candidate_count', 0) > 0
                || (int) data_get($report, 'summary.blocked_canonical_candidate_count', 0) > 0
                || (int) data_get($report, 'summary.phase_3o_clean_count', 0) > 0
                || (int) data_get($report, 'summary.phase_3o_risky_count', 0) > 0)
            ->values();

        $summary = $this->summary($reports);
        $this->renderSummary($summary);
        $this->renderSamples($reports);

        if ($reports->isEmpty()) {
            $this->line('No Agentic Marketing objectives matched shadow planner filters.');
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
            ->whereHas('opportunities')
            ->orderBy('id');
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $reports
     * @return array<string,mixed>
     */
    private function summary(Collection $reports): array
    {
        $summary = [
            'inspected_objectives' => 0,
            'legacy_candidate_count' => 0,
            'shadow_canonical_candidate_count' => 0,
            'exact_order_match_count' => 0,
            'priority_order_difference_count' => 0,
            'skipped_legacy_only_count' => 0,
            'blocked_canonical_candidate_count' => 0,
            'readiness_regression_count' => 0,
            'phase_3o_clean_count' => 0,
            'phase_3o_risky_count' => 0,
            'duplicate_risk_count' => 0,
            'continuity_risk_count' => 0,
            'lifecycle_risk_count' => 0,
            'signature_mismatch_count' => 0,
            'shadow_safe_objective_count' => 0,
            'blocked_objective_count' => 0,
            'recommendation' => 'keep legacy',
        ];

        foreach ($reports as $report) {
            foreach (array_keys($summary) as $key) {
                if ($key === 'recommendation') {
                    continue;
                }

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
                'shadow canonical candidate count',
                'exact order matches',
                'priority order differences',
                'skipped legacy-only',
                'blocked canonical candidates',
                'readiness regressions',
                'Phase 3O clean',
                'Phase 3O risky',
                'duplicate risks',
                'continuity risks',
                'lifecycle risks',
                'signature mismatches',
                'shadow-safe objectives',
                'blocked objectives',
                'recommendation',
            ],
            [[
                $summary['inspected_objectives'],
                $summary['legacy_candidate_count'],
                $summary['shadow_canonical_candidate_count'],
                $summary['exact_order_match_count'],
                $summary['priority_order_difference_count'],
                $summary['skipped_legacy_only_count'],
                $summary['blocked_canonical_candidate_count'],
                $summary['readiness_regression_count'],
                $summary['phase_3o_clean_count'],
                $summary['phase_3o_risky_count'],
                $summary['duplicate_risk_count'],
                $summary['continuity_risk_count'],
                $summary['lifecycle_risk_count'],
                $summary['signature_mismatch_count'],
                $summary['shadow_safe_objective_count'],
                $summary['blocked_objective_count'],
                $summary['recommendation'],
            ]]
        );

        foreach ($summary as $label => $value) {
            $this->line(str_replace('_', ' ', $label).': '.$value);
        }
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

        $shadowOrder = $reports
            ->flatMap(fn (array $report): array => $report['shadow_canonical_order'])
            ->map(fn (array $row): string => $row['legacy_opportunity_id'].'->'.$row['canonical_opportunity_id'].':'.$row['rank'].':'.$row['canonical_priority_score'])
            ->take(10)
            ->values()
            ->all();

        $differences = $reports
            ->flatMap(fn (array $report): array => $report['sample_differences'])
            ->map(fn (array $row): string => (string) ($row['type'] ?? 'difference').':'.(string) ($row['legacy_opportunity_id'] ?? $row['action_id'] ?? 'unknown'))
            ->take(10)
            ->values()
            ->all();

        $this->line('sample legacy order: '.$this->sampleLine($legacyOrder));
        $this->line('sample shadow order: '.$this->sampleLine($shadowOrder));
        $this->line('sample differences: '.$this->sampleLine($differences));
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function recommendation(array $summary): string
    {
        if ((int) $summary['blocked_objective_count'] > 0) {
            return 'blocked';
        }

        if ((int) $summary['shadow_safe_objective_count'] > 0) {
            return 'continue shadow';
        }

        return 'keep legacy';
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function sampleLine(array $values): string
    {
        $values = collect($values)
            ->map(fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES))
            ->filter()
            ->values()
            ->all();

        return $values === [] ? 'none' : implode(' | ', $values);
    }
}
