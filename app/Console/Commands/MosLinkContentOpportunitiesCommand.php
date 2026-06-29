<?php

namespace App\Console\Commands;

use App\Models\ContentOpportunity;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalLinkService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MosLinkContentOpportunitiesCommand extends Command
{
    protected $signature = 'mos:link-content-opportunities
        {--apply : Persist canonical Opportunity records and bridge links}
        {--workspace= : Limit to a workspace id}
        {--site= : Limit to a client site id}
        {--source-id= : Link one ContentOpportunity id}
        {--status= : Limit to a legacy content opportunity status}
        {--min-priority= : Minimum legacy priority score}
        {--limit=100 : Maximum records to inspect}';

    protected $description = 'Link selected legacy content opportunities to canonical MOS opportunities.';

    public function handle(ContentOpportunityCanonicalLinkService $linker): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));

        $summary = [
            'seen' => 0,
            'would_create' => 0,
            'would_link' => 0,
            'created' => 0,
            'linked' => 0,
            'duplicate' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];
        $skipped = [];

        $this->components->info($apply
            ? 'Applying content opportunity canonical links.'
            : 'Dry run only. Re-run with --apply to create or link canonical Opportunity records.');

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (ContentOpportunity $opportunity) use ($linker, $apply, &$summary, &$skipped): void {
                $summary['seen']++;

                $result = $linker->link($opportunity, apply: $apply);
                $summary[$result->status] = ($summary[$result->status] ?? 0) + 1;

                if ($result->skipped() || $result->duplicate() || $result->failed()) {
                    $skipped[] = [
                        (string) $opportunity->id,
                        (string) $opportunity->workspace_id,
                        $result->status,
                        implode(', ', $result->reasons),
                    ];
                }
            });

        $this->newLine();
        $this->table(
            ['seen', 'would create', 'would link', 'created', 'already linked', 'duplicates', 'skipped', 'failed'],
            [[
                $summary['seen'],
                $summary['would_create'],
                $summary['would_link'],
                $summary['created'],
                $summary['linked'],
                $summary['duplicate'],
                $summary['skipped'],
                $summary['failed'],
            ]]
        );

        if ($skipped !== []) {
            $this->newLine();
            $this->components->warn('Skipped records');
            $this->table(['source id', 'workspace id', 'state', 'reasons'], $skipped);
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<ContentOpportunity>
     */
    private function query(): Builder
    {
        return ContentOpportunity::query()
            ->with(['workspace', 'site', 'run'])
            ->when($this->option('workspace'), fn (Builder $query, string $workspace): Builder => $query->where('workspace_id', $workspace))
            ->when($this->option('site'), fn (Builder $query, string $site): Builder => $query->where('client_site_id', $site))
            ->when($this->option('source-id'), fn (Builder $query, string $sourceId): Builder => $query->whereKey($sourceId))
            ->when($this->option('status'), fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($this->option('min-priority'), fn (Builder $query, string $priority): Builder => $query->where('priority_score', '>=', (float) $priority))
            ->orderByDesc('priority_score')
            ->orderBy('id');
    }
}
