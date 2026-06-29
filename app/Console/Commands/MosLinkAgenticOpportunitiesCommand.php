<?php

namespace App\Console\Commands;

use App\Models\AgenticMarketingOpportunity;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityBridgeEligibilityService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityBridgeWriter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MosLinkAgenticOpportunitiesCommand extends Command
{
    protected $signature = 'mos:link-agentic-opportunities
        {--apply : Persist canonical Opportunity records and bridge links}
        {--workspace= : Limit to a workspace id}
        {--objective= : Limit to one AgenticMarketingObjective id}
        {--site= : Limit to one client site id}
        {--source-id= : Link one AgenticMarketingOpportunity id}
        {--status= : Limit to one AgenticMarketingOpportunity status}
        {--detector= : Limit to one detector key}
        {--limit=100 : Maximum records to inspect}';

    protected $description = 'Dry-run-first guarded bridge writer for selected Agentic Marketing opportunities.';

    public function handle(AgenticOpportunityBridgeWriter $writer, AgenticOpportunityBridgeEligibilityService $eligibility): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply && ! config('features.mos_agentic_marketing_opportunity_bridge_writer', false)) {
            $this->components->error('Apply blocked: enable ARGUSLY_FEATURE_MOS_AGENTIC_MARKETING_OPPORTUNITY_BRIDGE_WRITER before writing Agentic canonical bridges.');

            return self::FAILURE;
        }

        $this->components->info($apply
            ? 'Applying guarded Agentic Marketing opportunity canonical bridges.'
            : 'Dry run only. Re-run with --apply and the default-off feature flag enabled to write bridge links.');
        $this->components->warn('This command does not update Agentic Marketing opportunities, actions, execution pipelines or OpportunitySignal rows.');

        $summary = $this->emptySummary();
        $skipped = [];
        $samples = [];
        $detectorFilter = $this->detectorFilter();
        $limit = max(1, (int) $this->option('limit'));

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (AgenticMarketingOpportunity $opportunity) use ($writer, $eligibility, $apply, $detectorFilter, &$summary, &$skipped, &$samples): void {
                if ($detectorFilter && $eligibility->inspect($opportunity)->detectorKey !== $detectorFilter) {
                    return;
                }

                $result = $writer->write($opportunity, apply: $apply, operatorContext: [
                    'command' => $this->getName(),
                    'apply' => $apply,
                ]);

                $summary['inspected']++;
                $summary[$result->status] = ($summary[$result->status] ?? 0) + 1;

                if ($result->canonicalId()) {
                    $samples[] = $result->canonicalId();
                }

                if ($result->reasons !== []) {
                    $skipped[] = [
                        (string) $opportunity->id,
                        (string) $result->eligibility->workspaceId,
                        $result->eligibility->detectorKey,
                        $result->status,
                        implode(', ', array_slice($result->reasons, 0, 4)),
                    ];
                }
            });

        $this->renderSummary($summary, collect($samples)->unique()->take(8)->values());
        $this->renderSkipped($skipped);

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
     * @return array<string,int>
     */
    private function emptySummary(): array
    {
        return [
            'inspected' => 0,
            'would_create' => 0,
            'would_link' => 0,
            'created' => 0,
            'linked' => 0,
            'already_linked' => 0,
            'duplicate_risk' => 0,
            'execution_blocked' => 0,
            'missing_context' => 0,
            'blocked' => 0,
            'failed' => 0,
        ];
    }

    /**
     * @param  Collection<int,string>  $samples
     * @param  array<string,int>  $summary
     */
    private function renderSummary(array $summary, Collection $samples): void
    {
        $this->newLine();
        $this->table(
            ['inspected', 'would create', 'would link', 'created', 'linked', 'already linked', 'duplicate risk', 'execution blocked', 'missing context', 'blocked', 'failed'],
            [[
                $summary['inspected'],
                $summary['would_create'],
                $summary['would_link'],
                $summary['created'],
                $summary['linked'],
                $summary['already_linked'],
                $summary['duplicate_risk'],
                $summary['execution_blocked'],
                $summary['missing_context'],
                $summary['blocked'],
                $summary['failed'],
            ]]
        );

        foreach ($summary as $label => $value) {
            $this->line(str_replace('_', ' ', $label).' count: '.$value);
        }

        $this->line('canonical id samples: '.($samples->isEmpty() ? 'none' : $samples->implode(', ')));
    }

    /**
     * @param  array<int,array<int,string>>  $skipped
     */
    private function renderSkipped(array $skipped): void
    {
        if ($skipped === []) {
            return;
        }

        $this->newLine();
        $this->components->warn('Skipped or blocked records');
        $this->table(['source id', 'workspace id', 'detector', 'state', 'reasons'], $skipped);
    }

    private function detectorFilter(): ?string
    {
        $detector = trim((string) ($this->option('detector') ?: ''));

        return $detector !== '' ? $detector : null;
    }
}
