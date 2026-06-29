<?php

namespace App\Console\Commands;

use App\Models\AgenticMarketingOpportunity;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityActionDedupeInspectionService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalMappingService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MosInspectAgenticActionDedupeCommand extends Command
{
    protected $signature = 'mos:inspect-agentic-action-dedupe
        {--workspace= : Limit to a workspace id}
        {--objective= : Limit to one AgenticMarketingObjective id}
        {--site= : Limit to one client site id}
        {--source-id= : Limit to one AgenticMarketingOpportunity id}
        {--status= : Limit to one AgenticMarketingOpportunity status}
        {--detector= : Limit to one detector key}
        {--limit=100 : Maximum existing AgenticMarketingOpportunity rows to inspect}';

    protected $description = 'Read-only diagnostics for Agentic action dedupe and canonical-equivalent signatures.';

    public function handle(
        AgenticOpportunityActionDedupeInspectionService $inspection,
        AgenticOpportunityCanonicalMappingService $mapping,
    ): int {
        $this->components->info('Read-only Agentic action dedupe diagnostics.');
        $this->components->warn('No Agentic opportunities, actions, statuses, payloads, canonical opportunities, queues, or execution rows will be updated.');

        $detectorFilter = trim((string) ($this->option('detector') ?: ''));
        $limit = max(1, (int) $this->option('limit'));
        $rows = collect();

        $this->query()
            ->limit($limit)
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
            $this->line('No Agentic Marketing opportunities matched the filters.');
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

    private function renderSummary($rows): void
    {
        $summary = [
            'inspected_opportunities' => $rows->count(),
            'linked_canonical' => $rows->whereNotNull('canonical_opportunity_id')->count(),
            'legacy_only' => $rows->whereNull('canonical_opportunity_id')->count(),
            'open_actions' => (int) $rows->sum('open_action_count'),
            'duplicate_action_risks' => (int) $rows->sum('duplicate_risk_count'),
            'safe_canonical_equivalent_candidates' => (int) $rows->sum('safe_future_canonical_action_candidate_count'),
            'blocked' => $rows->filter(fn (array $row): bool => $row['blocked'])->count(),
        ];

        $this->newLine();
        $this->table(
            ['inspected opportunities', 'linked canonical', 'legacy only', 'open actions', 'duplicate action risks', 'safe canonical-equivalent candidates', 'blocked'],
            [[
                $summary['inspected_opportunities'],
                $summary['linked_canonical'],
                $summary['legacy_only'],
                $summary['open_actions'],
                $summary['duplicate_action_risks'],
                $summary['safe_canonical_equivalent_candidates'],
                $summary['blocked'],
            ]]
        );

        foreach ($summary as $label => $value) {
            $this->line(str_replace('_', ' ', $label).' count: '.$value);
        }
    }

    private function renderSamples($rows): void
    {
        $signatureSamples = $rows
            ->flatMap(fn (array $row): array => $row['signature_samples'])
            ->unique()
            ->take(5)
            ->values()
            ->all();

        $blockedReasons = $rows
            ->flatMap(fn (array $row): array => $row['blocked_reasons'])
            ->unique()
            ->take(8)
            ->values()
            ->all();

        $this->line('signature samples: '.($signatureSamples === [] ? 'none' : implode(', ', $signatureSamples)));
        $this->line('blocked reasons: '.($blockedReasons === [] ? 'none' : implode(', ', $blockedReasons)));
    }

    private function renderRows($rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->table(
            ['agentic id', 'canonical id', 'objective id', 'workspace id', 'detector', 'type', 'open actions', 'risks', 'safe candidates', 'blocked reasons'],
            $rows
                ->map(fn (array $row): array => [
                    $row['legacy_agentic_opportunity_id'],
                    $row['canonical_opportunity_id'] ?: 'none',
                    $row['objective_id'],
                    $row['workspace_id'] ?: 'missing',
                    $row['detector_key'] ?: 'unknown',
                    $row['agentic_type'] ?: 'unknown',
                    $row['open_action_count'],
                    $row['duplicate_risk_count'],
                    $row['safe_future_canonical_action_candidate_count'],
                    $row['blocked_reasons'] === [] ? 'none' : implode(', ', array_slice($row['blocked_reasons'], 0, 3)),
                ])
                ->all()
        );
    }
}
