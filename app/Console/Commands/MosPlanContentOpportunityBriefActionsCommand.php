<?php

namespace App\Console\Commands;

use App\Models\ContentOpportunity;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalBriefActionPlanner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MosPlanContentOpportunityBriefActionsCommand extends Command
{
    protected $signature = 'mos:plan-content-opportunity-brief-actions
        {--workspace= : Limit to a workspace id}
        {--site= : Limit to a client site id}
        {--source-id= : Inspect one ContentOpportunity id}
        {--status= : Limit to a legacy content opportunity status}
        {--limit=100 : Maximum records to inspect}';

    protected $description = 'Read-only plan for future canonical ContentOpportunity brief action ownership.';

    public function handle(ContentOpportunityCanonicalBriefActionPlanner $planner): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $summary = [
            'linked' => 0,
            'unlinked' => 0,
            'safe' => 0,
            'blocked' => 0,
        ];
        $rows = [];

        $this->components->info('Read-only canonical brief action planning. No briefs, statuses, routes, or queues will be changed.');

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (ContentOpportunity $opportunity) use ($planner, &$summary, &$rows): void {
                $plan = $planner->plan($opportunity);
                $summary[$plan->canonicalOpportunityId ? 'linked' : 'unlinked']++;
                $summary[$plan->safe ? 'safe' : 'blocked']++;

                $rows[] = [
                    $plan->legacyContentOpportunityId,
                    $plan->canonicalOpportunityId ?? 'missing',
                    $plan->workspaceId ?? 'missing',
                    $plan->clientSiteId ?? 'missing',
                    $plan->safetyStatus,
                    implode(', ', $plan->missingFields),
                    $plan->proposedCtaRoute,
                    $plan->proposedSourceLink,
                    $plan->proposedSourceSignature,
                ];
            });

        $this->newLine();
        $this->line('linked records: '.$summary['linked']);
        $this->line('unlinked records: '.$summary['unlinked']);
        $this->line('safe canonical brief action candidates: '.$summary['safe']);
        $this->line('blocked candidates: '.$summary['blocked']);
        $this->line('diagnostic field: missing fields');
        $this->line('diagnostic field: source_evidence');
        $this->line('diagnostic field: proposed CTA route');
        $this->line('diagnostic field: proposed source link');
        $this->line('diagnostic field: proposed source signature');
        $this->newLine();
        $this->table(
            ['linked records', 'unlinked records', 'safe canonical brief action candidates', 'blocked candidates'],
            [[$summary['linked'], $summary['unlinked'], $summary['safe'], $summary['blocked']]]
        );

        if ($rows !== []) {
            $this->newLine();
            $this->table(
                ['legacy id', 'canonical id', 'workspace id', 'site id', 'status', 'missing fields', 'proposed CTA route', 'proposed source link', 'proposed source signature'],
                $rows
            );
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
