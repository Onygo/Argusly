<?php

namespace App\Console\Commands;

use App\Models\AgenticMarketingOpportunity;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalMappingService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityLifecycleInspectionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MosInspectAgenticLifecycleMapCommand extends Command
{
    protected $signature = 'mos:inspect-agentic-lifecycle-map
        {--workspace= : Limit to a workspace id}
        {--objective= : Limit to one AgenticMarketingObjective id}
        {--site= : Limit to one client site id}
        {--source-id= : Limit to one AgenticMarketingOpportunity id}
        {--status= : Limit to one AgenticMarketingOpportunity status}
        {--detector= : Limit to one detector key}
        {--limit=100 : Maximum existing AgenticMarketingOpportunity rows to inspect}';

    protected $description = 'Read-only diagnostics for Agentic opportunity lifecycle candidate mapping.';

    public function handle(
        AgenticOpportunityLifecycleInspectionService $inspection,
        AgenticOpportunityCanonicalMappingService $mapping,
    ): int {
        $this->components->info('Read-only Agentic lifecycle mapping diagnostics.');
        $this->components->warn('No Agentic or canonical statuses, actions, pipelines, routes, payloads, approvals, feedback, audit logs, or rollback snapshots will be updated.');

        $detectorFilter = trim((string) ($this->option('detector') ?: ''));
        $rows = collect();

        $this->query()
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->each(function (AgenticMarketingOpportunity $opportunity) use ($inspection, $mapping, $detectorFilter, $rows): void {
                if ($detectorFilter !== '' && $mapping->mapExisting($opportunity)->detectorKey !== $detectorFilter) {
                    return;
                }

                $rows->push($inspection->inspect($opportunity));
            });

        $this->renderSummary($rows);
        $this->renderSamples($rows);
        $this->renderRows($rows);

        if ($rows->isEmpty()) {
            $this->line('No Agentic Marketing lifecycle rows matched the filters.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<AgenticMarketingOpportunity>
     */
    private function query(): Builder
    {
        return AgenticMarketingOpportunity::query()
            ->with('objective')
            ->when($this->option('source-id'), fn (Builder $query, string $sourceId): Builder => $query->whereKey($sourceId))
            ->when($this->option('objective'), fn (Builder $query, string $objective): Builder => $query->where('objective_id', $objective))
            ->when($this->option('status'), fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($this->option('workspace'), function (Builder $query, string $workspace): Builder {
                return $query->whereHas('objective', fn (Builder $objectiveQuery): Builder => $objectiveQuery->where('workspace_id', $workspace));
            })
            ->when($this->option('site'), function (Builder $query, string $site): Builder {
                return $query->where(function (Builder $siteQuery) use ($site): void {
                    $siteQuery->whereHas('objective', fn (Builder $objectiveQuery): Builder => $objectiveQuery->where('client_site_id', $site))
                        ->orWhere('payload->client_site_id', $site)
                        ->orWhere('payload->signals->client_site_id', $site);
                });
            })
            ->orderByDesc('priority_score')
            ->orderBy('id');
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function renderSummary(Collection $rows): void
    {
        $aligned = $rows->where('status_alignment', 'aligned_candidate')->count();
        $summary = [
            'inspected' => $rows->count(),
            'linked_canonical' => $rows->whereNotNull('canonical_opportunity_id')->count(),
            'legacy_only' => $rows->whereNull('canonical_opportunity_id')->count(),
            'aligned' => $aligned,
            'conflict' => $rows->where('status_conflict', true)->count(),
            'unmapped' => $rows->where('status_alignment', 'unmapped')->count(),
            'blocked' => $rows->where('blocked', true)->count(),
        ];

        $this->newLine();
        $this->table(
            ['inspected', 'linked canonical', 'legacy only', 'aligned', 'conflict', 'unmapped', 'blocked'],
            [[
                $summary['inspected'],
                $summary['linked_canonical'],
                $summary['legacy_only'],
                $summary['aligned'],
                $summary['conflict'],
                $summary['unmapped'],
                $summary['blocked'],
            ]]
        );

        foreach ($summary as $label => $value) {
            $this->line(str_replace('_', ' ', $label).' count: '.$value);
        }

        $this->line('status breakdown: '.$this->statusBreakdown($rows));
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function renderSamples(Collection $rows): void
    {
        $blockedReasons = $rows
            ->flatMap(fn (array $row): array => $row['blocked_reasons'])
            ->unique()
            ->take(10)
            ->values()
            ->all();
        $routeSamples = $rows
            ->filter(fn (array $row): bool => ($row['open_already_has_completed_execution'] ?? false) || ($row['dismissed_still_has_open_or_running_actions'] ?? false))
            ->map(fn (array $row): string => 'legacy opportunity '.$row['legacy_agentic_opportunity_id'].' has lifecycle/execution dependency')
            ->unique()
            ->take(8)
            ->values()
            ->all();

        $this->line('blocked reason samples: '.($blockedReasons === [] ? 'none' : implode(', ', $blockedReasons)));
        $this->line('route or execution dependency samples: '.($routeSamples === [] ? 'none' : implode(' | ', $routeSamples)));
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function renderRows(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->table(
            ['agentic id', 'canonical id', 'legacy', 'canonical', 'candidate', 'alignment', 'actions', 'pipelines', 'blocked reasons'],
            $rows
                ->map(fn (array $row): array => [
                    $row['legacy_agentic_opportunity_id'],
                    $row['canonical_opportunity_id'] ?: 'none',
                    $row['legacy_status'],
                    $row['canonical_status'] ?: 'none',
                    implode('|', $row['candidate_mapped_canonical_status']),
                    $row['status_alignment'],
                    array_sum($row['existing_action_counts_by_status']),
                    array_sum($row['existing_pipeline_counts_by_status']),
                    $row['blocked_reasons'] === [] ? 'none' : implode(', ', array_slice($row['blocked_reasons'], 0, 3)),
                ])
                ->all()
        );
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function statusBreakdown(Collection $rows): string
    {
        $breakdown = $rows
            ->groupBy('legacy_status')
            ->map(fn (Collection $group, string $status): string => $status.': '.$group->count())
            ->values()
            ->all();

        return $breakdown === [] ? 'none' : implode(', ', $breakdown);
    }
}
