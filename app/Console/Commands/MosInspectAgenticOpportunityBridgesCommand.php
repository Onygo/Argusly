<?php

namespace App\Console\Commands;

use App\Models\AgenticMarketingOpportunity;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityBridgeEligibilityResult;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityBridgeEligibilityService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MosInspectAgenticOpportunityBridgesCommand extends Command
{
    protected $signature = 'mos:inspect-agentic-opportunity-bridges
        {--workspace= : Limit to a workspace id}
        {--objective= : Limit to one AgenticMarketingObjective id}
        {--site= : Limit to one client site id}
        {--source-id= : Limit to one AgenticMarketingOpportunity id}
        {--status= : Limit to one AgenticMarketingOpportunity status}
        {--detector= : Limit to one detector key}
        {--limit=100 : Maximum existing AgenticMarketingOpportunity rows to inspect}';

    protected $description = 'Read-only diagnostics for Agentic Marketing opportunity canonical bridge eligibility.';

    public function handle(AgenticOpportunityBridgeEligibilityService $eligibility): int
    {
        $this->components->info('Read-only Agentic Marketing opportunity bridge eligibility diagnostics.');
        $this->components->warn('No canonical opportunities, opportunity signals, bridge links, queues, detectors, actions, or executions will be created.');

        $results = collect();
        $detectorFilter = $this->detectorFilter();
        $limit = max(1, (int) $this->option('limit'));

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (AgenticMarketingOpportunity $opportunity) use ($eligibility, $detectorFilter, $results): void {
                $result = $eligibility->inspect($opportunity);
                if ($detectorFilter && $result->detectorKey !== $detectorFilter) {
                    return;
                }

                $results->push($result);
            });

        $this->renderSummary($results);
        $this->renderRows($results);

        if ($results->isEmpty()) {
            $this->line('No Agentic Marketing opportunity bridge diagnostics matched the filters.');
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
     * @param  Collection<int,AgenticOpportunityBridgeEligibilityResult>  $results
     */
    private function renderSummary(Collection $results): void
    {
        $summary = [
            'total_inspected' => $results->count(),
            'signal_ready' => $results->where('eligibilityStatus', AgenticOpportunityBridgeEligibilityService::STATUS_SIGNAL_READY)->count(),
            'canonical_link_ready' => $results->where('eligibilityStatus', AgenticOpportunityBridgeEligibilityService::STATUS_CANONICAL_LINK_READY)->count(),
            'signal_and_canonical_ready' => $results->where('eligibilityStatus', AgenticOpportunityBridgeEligibilityService::STATUS_SIGNAL_AND_CANONICAL_READY)->count(),
            'execution_blocked' => $results->where('eligibilityStatus', AgenticOpportunityBridgeEligibilityService::STATUS_EXECUTION_BLOCKED)->count(),
            'duplicate_risk' => $results->where('eligibilityStatus', AgenticOpportunityBridgeEligibilityService::STATUS_DUPLICATE_RISK)->count(),
            'missing_context' => $results->where('eligibilityStatus', AgenticOpportunityBridgeEligibilityService::STATUS_MISSING_CONTEXT)->count(),
            'blocked' => $results->where('eligibilityStatus', AgenticOpportunityBridgeEligibilityService::STATUS_BLOCKED)->count(),
            'existing_canonical_link' => $results->sum(fn (AgenticOpportunityBridgeEligibilityResult $result): int => count($result->existingLinkedCanonicalOpportunityIds)),
            'open_actions' => $results->sum('openAgenticActionsCount'),
            'execution_pipeline' => $results->sum('executionPipelineCount'),
        ];

        $this->newLine();
        $this->table(
            ['total', 'signal ready', 'canonical ready', 'signal+canonical', 'execution-blocked', 'duplicate-risk', 'missing-context', 'blocked', 'existing links', 'open actions', 'pipelines'],
            [[
                $summary['total_inspected'],
                $summary['signal_ready'],
                $summary['canonical_link_ready'],
                $summary['signal_and_canonical_ready'],
                $summary['execution_blocked'],
                $summary['duplicate_risk'],
                $summary['missing_context'],
                $summary['blocked'],
                $summary['existing_canonical_link'],
                $summary['open_actions'],
                $summary['execution_pipeline'],
            ]]
        );

        foreach ($summary as $label => $value) {
            $this->line(str_replace('_', ' ', $label).' count: '.$value);
        }

        $this->renderSamples($results);
    }

    /**
     * @param  Collection<int,AgenticOpportunityBridgeEligibilityResult>  $results
     */
    private function renderSamples(Collection $results): void
    {
        $blockedReasons = $results
            ->flatMap(fn (AgenticOpportunityBridgeEligibilityResult $result): array => $result->blockedReasons)
            ->unique()
            ->take(8)
            ->values()
            ->all();

        $dedupeKeys = $results
            ->map(fn (AgenticOpportunityBridgeEligibilityResult $result): string => $result->mappingResult->dedupeKey)
            ->filter()
            ->unique()
            ->take(8)
            ->map(fn (string $dedupeKey): string => substr($dedupeKey, 0, 16))
            ->values()
            ->all();

        $this->line('sample blocked reasons: '.($blockedReasons === [] ? 'none' : implode(', ', $blockedReasons)));
        $this->line('dedupe key samples: '.($dedupeKeys === [] ? 'none' : implode(', ', $dedupeKeys)));
    }

    /**
     * @param  Collection<int,AgenticOpportunityBridgeEligibilityResult>  $results
     */
    private function renderRows(Collection $results): void
    {
        if ($results->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->components->info('Bridge eligibility rows');
        $this->table(
            ['agentic id', 'detector', 'classification', 'eligibility', 'signal', 'canonical', 'links', 'dedupe candidates', 'actions', 'pipelines', 'exec state', 'reasons'],
            $results
                ->map(fn (AgenticOpportunityBridgeEligibilityResult $result): array => [
                    $result->legacyAgenticOpportunityId,
                    $result->detectorKey,
                    $result->phase3bDetectorClassification->value,
                    $result->eligibilityStatus,
                    $result->signalEligibility ? 'yes' : 'no',
                    $result->canonicalOpportunityEligibility ? 'yes' : 'no',
                    count($result->existingLinkedCanonicalOpportunityIds),
                    count($result->dedupeMatchedCanonicalOpportunityCandidates),
                    $result->openAgenticActionsCount,
                    $result->executionPipelineCount,
                    $result->executionBlockerStatus,
                    $result->blockedReasons === [] ? 'none' : implode(', ', array_slice($result->blockedReasons, 0, 3)),
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
