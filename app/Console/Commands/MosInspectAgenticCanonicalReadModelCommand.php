<?php

namespace App\Console\Commands;

use App\Models\AgenticMarketingOpportunity;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalReadModel;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalReadService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MosInspectAgenticCanonicalReadModelCommand extends Command
{
    protected $signature = 'mos:inspect-agentic-canonical-read-model
        {--workspace= : Limit to a workspace id}
        {--objective= : Limit to one AgenticMarketingObjective id}
        {--site= : Limit to one client site id}
        {--source-id= : Limit to one AgenticMarketingOpportunity id}
        {--status= : Limit to one AgenticMarketingOpportunity status}
        {--detector= : Limit to one detector key}
        {--limit=100 : Maximum existing AgenticMarketingOpportunity rows to inspect}';

    protected $description = 'Read-only diagnostics for the Agentic Marketing canonical dual-read model.';

    public function handle(AgenticOpportunityCanonicalReadService $readService): int
    {
        $this->components->info('Read-only Agentic Marketing canonical read-model diagnostics.');
        $this->components->warn('No Agentic opportunities, canonical opportunities, signals, actions, queues, or execution rows will be updated.');

        $detectorFilter = $this->detectorFilter();
        $limit = max(1, (int) $this->option('limit'));
        $reads = collect();

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (AgenticMarketingOpportunity $opportunity) use ($readService, $detectorFilter, $reads): void {
                $read = $readService->read($opportunity);
                if ($detectorFilter && $read->detectorKey !== $detectorFilter) {
                    return;
                }

                $reads->push($read);
            });

        $this->renderSummary($reads);
        $this->renderSamples($reads);
        $this->renderRows($reads);

        if ($reads->isEmpty()) {
            $this->line('No Agentic Marketing canonical read models matched the filters.');
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
     * @param  Collection<int, AgenticOpportunityCanonicalReadModel>  $reads
     */
    private function renderSummary(Collection $reads): void
    {
        $summary = [
            'total_inspected' => $reads->count(),
            'linked_canonical' => $reads->whereNotNull('canonicalOpportunityId')->count(),
            'legacy_only' => $reads->whereNull('canonicalOpportunityId')->count(),
            'canonical_enriched' => $reads->filter(fn (AgenticOpportunityCanonicalReadModel $read): bool => $read->isCanonicalEnriched())->count(),
            'fallback' => $reads->filter(fn (AgenticOpportunityCanonicalReadModel $read): bool => $read->hasFallbacks())->count(),
            'blocked' => $reads->filter(fn (AgenticOpportunityCanonicalReadModel $read): bool => $read->blockedReasons !== [])->count(),
        ];

        $this->newLine();
        $this->table(
            ['total', 'linked canonical', 'legacy only', 'canonical enriched', 'fallback', 'blocked'],
            [[
                $summary['total_inspected'],
                $summary['linked_canonical'],
                $summary['legacy_only'],
                $summary['canonical_enriched'],
                $summary['fallback'],
                $summary['blocked'],
            ]]
        );

        foreach ($summary as $label => $value) {
            $this->line(str_replace('_', ' ', $label).' count: '.$value);
        }
    }

    /**
     * @param  Collection<int, AgenticOpportunityCanonicalReadModel>  $reads
     */
    private function renderSamples(Collection $reads): void
    {
        $provenanceSamples = $reads
            ->take(5)
            ->map(fn (AgenticOpportunityCanonicalReadModel $read): string => $read->legacyAgenticOpportunityId.': '.json_encode(array_slice($read->provenance, 0, 8), JSON_UNESCAPED_SLASHES))
            ->values()
            ->all();

        $executionSamples = $reads
            ->filter(fn (AgenticOpportunityCanonicalReadModel $read): bool => (int) ($read->executionStateSummary['agentic_actions_count'] ?? 0) > 0
                || (int) ($read->executionStateSummary['execution_pipeline_count'] ?? 0) > 0)
            ->take(5)
            ->map(fn (AgenticOpportunityCanonicalReadModel $read): string => $read->legacyAgenticOpportunityId
                .' actions='.(int) ($read->executionStateSummary['agentic_actions_count'] ?? 0)
                .' pipelines='.(int) ($read->executionStateSummary['execution_pipeline_count'] ?? 0))
            ->values()
            ->all();

        $blockedReasons = $reads
            ->flatMap(fn (AgenticOpportunityCanonicalReadModel $read): array => $read->blockedReasons)
            ->unique()
            ->take(8)
            ->values()
            ->all();

        $this->line('field provenance samples: '.($provenanceSamples === [] ? 'none' : implode(' | ', $provenanceSamples)));
        $this->line('execution-state dependency samples: '.($executionSamples === [] ? 'none' : implode(' | ', $executionSamples)));
        $this->line('blocked reason samples: '.($blockedReasons === [] ? 'none' : implode(', ', $blockedReasons)));
    }

    /**
     * @param  Collection<int, AgenticOpportunityCanonicalReadModel>  $reads
     */
    private function renderRows(Collection $reads): void
    {
        if ($reads->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->info('Canonical read-model rows');
        $this->table(
            ['agentic id', 'canonical id', 'detector', 'status', 'title src', 'priority src', 'actions', 'pipelines', 'blocked'],
            $reads
                ->map(fn (AgenticOpportunityCanonicalReadModel $read): array => [
                    $read->legacyAgenticOpportunityId,
                    $read->canonicalOpportunityId ?: 'none',
                    $read->detectorKey ?: 'unknown',
                    $read->status,
                    $read->provenance['title'] ?? 'unknown',
                    $read->provenance['priority_score'] ?? 'unknown',
                    (int) ($read->executionStateSummary['agentic_actions_count'] ?? 0),
                    (int) ($read->executionStateSummary['execution_pipeline_count'] ?? 0),
                    $read->blockedReasons === [] ? 'none' : implode(', ', array_slice($read->blockedReasons, 0, 3)),
                ])
                ->all()
        );
    }

    private function detectorFilter(): ?string
    {
        $detector = trim((string) ($this->option('detector') ?: ''));

        return $detector !== '' ? $detector : null;
    }
}
