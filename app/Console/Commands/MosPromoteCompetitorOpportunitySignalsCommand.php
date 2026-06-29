<?php

namespace App\Console\Commands;

use App\Models\CompetitorContentOpportunity;
use App\Services\OpportunityIntelligence\CompetitorContentOpportunitySignalPromotionService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MosPromoteCompetitorOpportunitySignalsCommand extends Command
{
    protected $signature = 'mos:promote-competitor-opportunity-signals
        {--apply : Persist canonical OpportunitySignal records}
        {--workspace= : Limit to a workspace id}
        {--site= : Limit to a client site id}
        {--source-id= : Promote one CompetitorContentOpportunity id}
        {--limit=100 : Maximum records to inspect}';

    protected $description = 'Promote legacy competitor content opportunities into canonical MOS opportunity signals.';

    public function handle(CompetitorContentOpportunitySignalPromotionService $promotion): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));

        $summary = [
            'seen' => 0,
            'would_create' => 0,
            'created' => 0,
            'updated' => 0,
            'duplicate' => 0,
            'skipped' => 0,
        ];
        $skipped = [];

        $this->components->info($apply
            ? 'Applying competitor opportunity signal promotion.'
            : 'Dry run only. Re-run with --apply to persist OpportunitySignal records.');

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (CompetitorContentOpportunity $opportunity) use ($promotion, $apply, &$summary, &$skipped): void {
                $summary['seen']++;

                $result = $promotion->promote($opportunity, dryRun: ! $apply);
                $summary[$result->status] = ($summary[$result->status] ?? 0) + 1;

                if ($result->skipped()) {
                    $skipped[] = [
                        (string) $opportunity->id,
                        (string) $opportunity->workspace_id,
                        implode(', ', $result->reasons),
                    ];
                }
            });

        $this->newLine();
        $this->table(
            ['seen', 'would create', 'created', 'updated', 'duplicates', 'skipped'],
            [[
                $summary['seen'],
                $summary['would_create'],
                $summary['created'],
                $summary['updated'],
                $summary['duplicate'],
                $summary['skipped'],
            ]]
        );

        if ($skipped !== []) {
            $this->newLine();
            $this->components->warn('Skipped records');
            $this->table(['source id', 'workspace id', 'reasons'], $skipped);
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<CompetitorContentOpportunity>
     */
    private function query(): Builder
    {
        return CompetitorContentOpportunity::query()
            ->with(['workspace', 'site', 'competitor', 'run'])
            ->when($this->option('workspace'), fn (Builder $query, string $workspace): Builder => $query->where('workspace_id', $workspace))
            ->when($this->option('site'), fn (Builder $query, string $site): Builder => $query->where('client_site_id', $site))
            ->when($this->option('source-id'), fn (Builder $query, string $sourceId): Builder => $query->whereKey($sourceId))
            ->orderBy('id');
    }
}
