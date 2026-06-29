<?php

namespace App\Console\Commands;

use App\Models\ContentOpportunity;
use App\Services\Mos\Opportunity\ContentOpportunityBriefHandoffPlanner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MosPlanContentOpportunityBriefHandoffCommand extends Command
{
    protected $signature = 'mos:plan-content-opportunity-brief-handoff
        {--workspace= : Limit to a workspace id}
        {--site= : Limit to a client site id}
        {--source-id= : Inspect one ContentOpportunity id}
        {--status= : Limit to a legacy content opportunity status}
        {--limit=100 : Maximum records to inspect}';

    protected $description = 'Dry-run only plan for future canonical ContentOpportunity brief handoff.';

    public function handle(ContentOpportunityBriefHandoffPlanner $planner): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $summary = [
            'seen' => 0,
            'safe' => 0,
            'blocked' => 0,
        ];
        $rows = [];

        $this->components->info('Dry-run only. No briefs, statuses, routes, or queues will be changed.');

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (ContentOpportunity $opportunity) use ($planner, &$summary, &$rows): void {
                $summary['seen']++;
                $plan = $planner->plan($opportunity);
                $summary[$plan->safe ? 'safe' : 'blocked']++;

                $rows[] = [
                    $plan->legacyContentOpportunityId,
                    $plan->canonicalOpportunityId ?? 'missing',
                    $plan->targetContext['workspace_id'] ?? 'missing',
                    $plan->targetContext['client_site_id'] ?? 'missing',
                    $plan->safe ? 'safe' : 'blocked',
                    implode(', ', $plan->missingFields),
                    $plan->recommendedBriefTitle ?? 'missing',
                ];
            });

        $this->newLine();
        $this->table(['seen', 'safe', 'blocked'], [[$summary['seen'], $summary['safe'], $summary['blocked']]]);

        if ($rows !== []) {
            $this->newLine();
            $this->table(['legacy id', 'canonical id', 'workspace id', 'site id', 'state', 'missing fields', 'brief title'], $rows);
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<ContentOpportunity>
     */
    private function query(): Builder
    {
        return ContentOpportunity::query()
            ->with(['workspace', 'site'])
            ->whereHas('workspace')
            ->when($this->option('workspace'), fn (Builder $query, string $workspace): Builder => $query->where('workspace_id', $workspace))
            ->when($this->option('site'), fn (Builder $query, string $site): Builder => $query->where('client_site_id', $site))
            ->when($this->option('source-id'), fn (Builder $query, string $sourceId): Builder => $query->whereKey($sourceId))
            ->when($this->option('status'), fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->orderByDesc('priority_score')
            ->orderBy('id');
    }
}
