<?php

namespace App\Console\Commands;

use App\Models\AgenticMarketingOpportunity;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalActionOwnershipPlanner;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalMappingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MosPlanAgenticCanonicalActionOwnershipCommand extends Command
{
    protected $signature = 'mos:plan-agentic-canonical-action-ownership
        {--workspace= : Limit to a workspace id}
        {--objective= : Limit to one AgenticMarketingObjective id}
        {--site= : Limit to one client site id}
        {--source-id= : Limit to one AgenticMarketingOpportunity id}
        {--status= : Limit to one AgenticMarketingOpportunity status}
        {--detector= : Limit to one detector key}
        {--limit=100 : Maximum existing AgenticMarketingOpportunity rows to inspect}';

    protected $description = 'Read-only planning diagnostics for future Agentic canonical action ownership.';

    public function handle(
        AgenticOpportunityCanonicalActionOwnershipPlanner $planner,
        AgenticOpportunityCanonicalMappingService $mapping,
    ): int {
        $this->components->info('Read-only Agentic canonical action ownership planning diagnostics.');
        $this->components->warn('No planner selection, actions, canonical recommended actions, payloads, execution parents, routes, approvals, feedback, audit logs, or rollback snapshots will be updated.');

        $detectorFilter = trim((string) ($this->option('detector') ?: ''));
        $rows = collect();

        $this->query()
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->each(function (AgenticMarketingOpportunity $opportunity) use ($planner, $mapping, $detectorFilter, $rows): void {
                if ($detectorFilter !== '' && $mapping->mapExisting($opportunity)->detectorKey !== $detectorFilter) {
                    return;
                }

                $rows->push($planner->plan($opportunity));
            });

        $this->renderSummary($rows);
        $this->renderSamples($rows);
        $this->renderRows($rows);

        if ($rows->isEmpty()) {
            $this->line('No Agentic Marketing action ownership rows matched the filters.');
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
        $summary = [
            'inspected' => $rows->count(),
            'linked_canonical' => $rows->whereNotNull('linked_canonical_opportunity_id')->count(),
            'legacy_only' => $rows->whereNull('linked_canonical_opportunity_id')->count(),
            'canonical_ownership_candidate' => $rows->whereNotNull('future_canonical_owner_candidate')->count(),
            'blocked' => $rows->where('canonical_action_ownership_blocked', true)->count(),
            'open_legacy_action' => (int) $rows->sum('legacy_open_action_count'),
            'duplicate_risk' => $rows->filter(fn (array $row): bool => in_array('canonical_action_would_duplicate_open_legacy_action', $row['blocked_reasons'], true))->count(),
        ];

        $this->newLine();
        $this->table(
            ['inspected', 'linked canonical', 'legacy only', 'canonical ownership candidates', 'blocked', 'open legacy actions', 'duplicate risks'],
            [[
                $summary['inspected'],
                $summary['linked_canonical'],
                $summary['legacy_only'],
                $summary['canonical_ownership_candidate'],
                $summary['blocked'],
                $summary['open_legacy_action'],
                $summary['duplicate_risk'],
            ]]
        );

        foreach ($summary as $label => $value) {
            $this->line(str_replace('_', ' ', $label).' count: '.$value);
        }
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function renderSamples(Collection $rows): void
    {
        $signatureSamples = $rows
            ->flatMap(fn (array $row): array => collect($row['canonical_equivalent_action_signatures'])->pluck('signature.signature')->filter()->all())
            ->unique()
            ->take(5)
            ->values()
            ->all();
        $metadataSamples = $rows
            ->map(fn (array $row): string => json_encode($row['proposed_metadata_for_future_action_payloads']))
            ->filter()
            ->take(3)
            ->values()
            ->all();
        $fallbackRoutes = $rows
            ->pluck('fallback_route')
            ->unique()
            ->take(5)
            ->values()
            ->all();
        $blockedReasons = $rows
            ->flatMap(fn (array $row): array => $row['blocked_reasons'])
            ->unique()
            ->take(10)
            ->values()
            ->all();

        $this->line('signature samples: '.($signatureSamples === [] ? 'none' : implode(', ', $signatureSamples)));
        $this->line('proposed metadata samples: '.($metadataSamples === [] ? 'none' : implode(' | ', $metadataSamples)));
        $this->line('fallback route samples: '.($fallbackRoutes === [] ? 'none' : implode(' | ', $fallbackRoutes)));
        $this->line('blocked reason samples: '.($blockedReasons === [] ? 'none' : implode(', ', $blockedReasons)));
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
            ['agentic id', 'canonical id', 'objective', 'workspace', 'detector', 'type', 'open actions', 'candidate', 'blocked reasons'],
            $rows
                ->map(fn (array $row): array => [
                    $row['legacy_agentic_opportunity_id'],
                    $row['linked_canonical_opportunity_id'] ?: 'none',
                    $row['objective_id'] ?: 'missing',
                    $row['workspace_id'] ?: 'missing',
                    $row['detector_key'] ?: 'unknown',
                    $row['agentic_type'] ?: 'unknown',
                    $row['legacy_open_action_count'],
                    $row['future_canonical_owner_candidate'] ? 'yes' : 'no',
                    $row['blocked_reasons'] === [] ? 'none' : implode(', ', array_slice($row['blocked_reasons'], 0, 3)),
                ])
                ->all()
        );
    }
}
