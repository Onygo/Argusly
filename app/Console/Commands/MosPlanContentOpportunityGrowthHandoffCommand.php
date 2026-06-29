<?php

namespace App\Console\Commands;

use App\Models\ContentOpportunity;
use App\Services\Mos\Opportunity\ContentOpportunityGrowthHandoffPlanner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MosPlanContentOpportunityGrowthHandoffCommand extends Command
{
    protected $signature = 'mos:plan-content-opportunity-growth-handoff
        {--workspace= : Limit to a workspace id}
        {--site= : Limit to a client site id}
        {--source-id= : Inspect one ContentOpportunity id}
        {--status= : Limit to a legacy content opportunity status}
        {--limit=100 : Maximum records to inspect}';

    protected $description = 'Read-only plan for future canonical ContentOpportunity growth and autopilot handoff.';

    public function handle(ContentOpportunityGrowthHandoffPlanner $planner): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $summary = [
            'seen' => 0,
            'linked' => 0,
            'unlinked' => 0,
            'growth_assets_found' => 0,
            'programmatic_opportunities_found' => 0,
            'autopilot_queue_references_found' => 0,
            'duplicate_execution_risks' => 0,
            'safe_future_handoff_candidates' => 0,
            'skipped' => 0,
        ];
        $rows = [];

        $this->components->info('Read-only growth handoff planning. No growth assets, programmatic opportunities, autopilot queue items, briefs, or lifecycle statuses will be changed.');

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (ContentOpportunity $opportunity) use ($planner, &$summary, &$rows): void {
                $summary['seen']++;
                $plan = $planner->plan($opportunity);

                $plan->canonicalOpportunityId ? $summary['linked']++ : $summary['unlinked']++;
                $summary['growth_assets_found'] += count($plan->growthAssetReferences);
                $summary['programmatic_opportunities_found'] += count($plan->programmaticOpportunityReferences);
                $summary['autopilot_queue_references_found'] += count($plan->autopilotQueueReferences);
                $summary['duplicate_execution_risks'] += count($plan->duplicateExecutionRisks);

                if ($plan->safe) {
                    $summary['safe_future_handoff_candidates']++;
                } else {
                    $summary['skipped']++;
                }

                $rows[] = [
                    $plan->legacyContentOpportunityId,
                    $plan->canonicalOpportunityId ?? 'missing',
                    $plan->safe ? 'safe' : 'skipped',
                    count($plan->growthAssetReferences),
                    count($plan->programmaticOpportunityReferences),
                    count($plan->autopilotQueueReferences),
                    implode(', ', $plan->duplicateExecutionRisks),
                    implode(', ', $plan->missingFields),
                ];
            });

        $this->newLine();
        $this->table(
            ['seen', 'linked records', 'unlinked records', 'growth assets found', 'programmatic opportunities found', 'autopilot queue references found', 'duplicate execution risks', 'safe future handoff candidates', 'skipped records'],
            [[
                $summary['seen'],
                $summary['linked'],
                $summary['unlinked'],
                $summary['growth_assets_found'],
                $summary['programmatic_opportunities_found'],
                $summary['autopilot_queue_references_found'],
                $summary['duplicate_execution_risks'],
                $summary['safe_future_handoff_candidates'],
                $summary['skipped'],
            ]],
        );

        if ($rows !== []) {
            $this->newLine();
            $this->table(['legacy id', 'canonical id', 'state', 'growth assets', 'programmatic refs', 'autopilot refs', 'duplicate execution risks', 'skipped reasons'], $rows);
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<ContentOpportunity>
     */
    private function query(): Builder
    {
        return ContentOpportunity::query()
            ->when($this->option('workspace'), fn (Builder $query, string $workspace): Builder => $query->where('workspace_id', $workspace))
            ->when($this->option('site'), fn (Builder $query, string $site): Builder => $query->where('client_site_id', $site))
            ->when($this->option('source-id'), fn (Builder $query, string $sourceId): Builder => $query->whereKey($sourceId))
            ->when($this->option('status'), fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->orderByDesc('priority_score')
            ->orderBy('id');
    }
}
