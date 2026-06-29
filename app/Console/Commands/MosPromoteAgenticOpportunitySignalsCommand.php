<?php

namespace App\Console\Commands;

use App\Models\AgenticMarketingOpportunity;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalMappingService;
use App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunitySignalPromotionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MosPromoteAgenticOpportunitySignalsCommand extends Command
{
    protected $signature = 'mos:promote-agentic-opportunity-signals
        {--apply : Persist canonical OpportunitySignal records}
        {--workspace= : Limit to a workspace id}
        {--objective= : Limit to one AgenticMarketingObjective id}
        {--site= : Limit to one client site id}
        {--source-id= : Promote one AgenticMarketingOpportunity id}
        {--status= : Limit to one AgenticMarketingOpportunity status}
        {--detector= : Limit to one detector key}
        {--limit=100 : Maximum records to inspect}';

    protected $description = 'Dry-run-first guarded promotion of Agentic Marketing opportunities into canonical MOS opportunity signals.';

    public function handle(
        AgenticOpportunitySignalPromotionService $promotion,
        AgenticOpportunityCanonicalMappingService $mapper,
    ): int {
        $apply = (bool) $this->option('apply');

        if ($apply && ! config('features.mos_agentic_marketing_opportunity_signal_promotion', false)) {
            $this->components->error('Apply blocked: enable ARGUSLY_FEATURE_MOS_AGENTIC_MARKETING_OPPORTUNITY_SIGNAL_PROMOTION before writing Agentic OpportunitySignal rows.');

            return self::FAILURE;
        }

        $this->components->info($apply
            ? 'Applying guarded Agentic Marketing opportunity signal promotion.'
            : 'Dry run only. Re-run with --apply and the default-off feature flag enabled to write OpportunitySignal rows.');
        $this->components->warn('This command does not create Opportunity records and does not update Agentic opportunities, actions or execution pipelines.');

        $summary = $this->emptySummary();
        $skipped = [];
        $samples = [];
        $detectorFilter = $this->detectorFilter();
        $limit = max(1, (int) $this->option('limit'));

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (AgenticMarketingOpportunity $opportunity) use ($promotion, $mapper, $apply, $detectorFilter, &$summary, &$skipped, &$samples): void {
                $mapping = $mapper->mapExisting($opportunity);

                if ($detectorFilter && $mapping->detectorKey !== $detectorFilter) {
                    return;
                }

                $summary['inspected']++;

                $result = $promotion->promote($opportunity, apply: $apply, operatorContext: [
                    'command' => $this->getName(),
                    'apply' => $apply,
                ]);

                if ($result->signalEligible()) {
                    $summary['signal_eligible']++;
                }

                $summary[$result->status] = ($summary[$result->status] ?? 0) + 1;
                $samples[] = $result->mappingResult->dedupeKey;

                if ($result->reasons !== []) {
                    $skipped[] = [
                        (string) $opportunity->id,
                        (string) ($result->mappingResult->signalPreview?->workspaceId ?: $opportunity->objective?->workspace_id ?: ''),
                        $result->mappingResult->detectorKey,
                        $result->status,
                        implode(', ', array_slice($result->reasons, 0, 4)),
                    ];
                }
            });

        $this->renderSummary($summary, collect($samples)->filter()->unique()->take(8)->values());
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
            'signal_eligible' => 0,
            'would_create' => 0,
            'would_update' => 0,
            'created' => 0,
            'updated' => 0,
            'already_current' => 0,
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
            ['inspected', 'signal eligible', 'would create', 'would update', 'created', 'updated', 'already current', 'missing context', 'blocked', 'failed'],
            [[
                $summary['inspected'],
                $summary['signal_eligible'],
                $summary['would_create'],
                $summary['would_update'],
                $summary['created'],
                $summary['updated'],
                $summary['already_current'],
                $summary['missing_context'],
                $summary['blocked'],
                $summary['failed'],
            ]]
        );

        foreach ($summary as $label => $value) {
            $this->line(str_replace('_', ' ', $label).' count: '.$value);
        }

        $this->line('dedupe samples: '.($samples->isEmpty() ? 'none' : $samples->implode(', ')));
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
