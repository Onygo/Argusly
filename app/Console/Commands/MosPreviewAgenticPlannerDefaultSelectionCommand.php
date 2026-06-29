<?php

namespace App\Console\Commands;

use App\Models\AgenticMarketingObjective;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticCanonicalPlannerDefaultSelectionPreviewService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MosPreviewAgenticPlannerDefaultSelectionCommand extends Command
{
    protected $signature = 'mos:preview-agentic-planner-default-selection
        {--workspace= : Limit to a workspace id}
        {--objective= : Required AgenticMarketingObjective id}
        {--site= : Limit to one client site id}
        {--detector= : Limit inspected AgenticMarketingOpportunity rows by detector key}
        {--limit= : Required candidate limit}';

    protected $description = 'Read-only Phase 3Q preview for a future canonical Agentic planner default-selection experiment.';

    public function handle(AgenticCanonicalPlannerDefaultSelectionPreviewService $preview): int
    {
        $objectiveId = trim((string) $this->option('objective'));
        $limit = trim((string) $this->option('limit'));

        if ($objectiveId === '') {
            $this->components->error('The --objective option is required.');

            return self::INVALID;
        }

        if ($limit === '') {
            $this->components->error('The --limit option is required.');

            return self::INVALID;
        }

        $objective = $this->query($objectiveId)->first();

        if (! $objective) {
            $this->components->error('No AgenticMarketingObjective matched the supplied filters.');

            return self::FAILURE;
        }

        $this->components->info('Read-only Phase 3Q Agentic planner default-selection preview.');
        $this->components->warn('Default planner output remains legacy. No actions, canonical recommended actions, run items, audit logs, payloads, lifecycle state, dedupe hashes or execution parents will be changed.');

        $report = $preview->preview($objective, [
            'site' => $this->option('site'),
            'detector' => $this->option('detector'),
            'limit' => max(1, (int) $limit),
        ]);

        $this->renderSummary($report);
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
     * @param  array<string,mixed>  $report
     */
    private function renderSummary(array $report): void
    {
        $summary = (array) $report['summary'];

        $this->newLine();
        $this->line('objective id: '.$report['objective_id']);
        $this->table(
            [
                'legacy candidate count',
                'canonical proposed count',
                'coverage %',
                'exact order matches',
                'order differences',
                'blocked candidates',
                'Phase 3O risky',
                'readiness regressions',
                'duplicate risks',
                'continuity risks',
                'lifecycle risks',
                'signature risks',
                'preview-safe',
                'preview-blocked',
                'recommendation',
            ],
            [[
                $summary['legacy_candidate_count'],
                $summary['canonical_proposed_count'],
                $summary['coverage_percentage'],
                $summary['exact_order_match_count'],
                $summary['order_difference_count'],
                $summary['blocked_candidate_count'],
                $summary['phase_3o_risky_count'],
                $summary['readiness_regression_count'],
                $summary['duplicate_risk_count'],
                $summary['continuity_risk_count'],
                $summary['lifecycle_risk_count'],
                $summary['signature_risk_count'],
                $summary['preview_safe_count'],
                $summary['preview_blocked_count'],
                $summary['recommendation'],
            ]]
        );

        foreach ($summary as $label => $value) {
            $this->line(str_replace('_', ' ', $label).': '.$value);
        }

        $this->line('default selection preview status: '.$report['default_selection_preview_status']);
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function renderSamples(array $report): void
    {
        $legacyOrder = collect((array) $report['legacy_candidate_order'])
            ->map(fn (array $row): string => ($row['legacy_opportunity_id'] ?? 'unknown').':'.($row['rank'] ?? 'n/a').':'.($row['priority_score'] ?? $row['legacy_priority_score'] ?? 'n/a'))
            ->take(10)
            ->values()
            ->all();

        $canonicalOrder = collect((array) $report['canonical_proposed_default_order'])
            ->map(fn (array $row): string => ($row['legacy_opportunity_id'] ?? 'unknown').'->'.($row['canonical_opportunity_id'] ?? 'missing').':'.($row['rank'] ?? 'n/a').':'.($row['canonical_priority_score'] ?? 'n/a'))
            ->take(10)
            ->values()
            ->all();

        $differences = collect((array) $report['order_differences'])
            ->map(fn (array $row): string => ($row['legacy_opportunity_id'] ?? 'unknown').':'.($row['legacy_rank'] ?? 'n/a').'->'.($row['canonical_rank'] ?? 'n/a'))
            ->take(10)
            ->values()
            ->all();

        $excluded = collect((array) $report['excluded_reasons'])
            ->map(fn (array $row): string => ($row['legacy_opportunity_id'] ?? 'unknown').':'.($row['readiness_status'] ?? 'unknown').'('.implode(',', array_slice((array) ($row['reasons'] ?? []), 0, 3)).')')
            ->take(10)
            ->values()
            ->all();

        $this->line('sample legacy order: '.$this->sampleLine($legacyOrder));
        $this->line('sample canonical proposed order: '.$this->sampleLine($canonicalOrder));
        $this->line('sample differences: '.$this->sampleLine($differences));
        $this->line('excluded samples: '.$this->sampleLine($excluded));
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
