<?php

namespace App\Console\Commands;

use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Services\Mos\Opportunity\ContentOpportunityLifecycleMap;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MosCompareContentOpportunityLifecycleCommand extends Command
{
    protected $signature = 'mos:compare-content-opportunity-lifecycle
        {--workspace= : Limit to a workspace id}
        {--site= : Limit to a client site id}
        {--source-id= : Inspect one ContentOpportunity id}
        {--status= : Limit to a legacy content opportunity status}
        {--limit=100 : Maximum records to inspect}';

    protected $description = 'Read-only comparison of legacy ContentOpportunity and canonical Opportunity lifecycle status.';

    public function handle(ContentOpportunityLifecycleMap $map): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $summary = [
            'seen' => 0,
            'aligned' => 0,
            'conflicts' => 0,
            'unmapped' => 0,
            'missing_links' => 0,
        ];
        $issues = [];

        $this->components->info('Read-only lifecycle comparison. No records will be updated.');

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (ContentOpportunity $legacy) use ($map, &$summary, &$issues): void {
                $summary['seen']++;
                $canonical = Opportunity::query()->where('content_opportunity_id', $legacy->id)->first();
                $comparison = $map->compare($legacy, $canonical);

                if ($comparison['aligned']) {
                    $summary['aligned']++;
                }

                if ($comparison['conflict']) {
                    $summary['conflicts']++;
                }

                if ($comparison['missing_canonical_link']) {
                    $summary['missing_links']++;
                }

                if ($comparison['unmapped_legacy_status'] || $comparison['unmapped_canonical_status']) {
                    $summary['unmapped']++;
                }

                if ($comparison['conflict'] || $comparison['missing_canonical_link'] || $comparison['unmapped_legacy_status'] || $comparison['unmapped_canonical_status']) {
                    $issues[] = [
                        $comparison['legacy_content_opportunity_id'],
                        $comparison['canonical_opportunity_id'] ?? 'missing',
                        $comparison['legacy_status'],
                        $comparison['canonical_status'] ?? 'missing',
                        $comparison['mapped_canonical_status'] ?? 'unmapped',
                        $this->state($comparison),
                    ];
                }
            });

        $this->newLine();
        $this->table(['seen', 'aligned', 'conflicts', 'unmapped', 'missing links'], [[
            $summary['seen'],
            $summary['aligned'],
            $summary['conflicts'],
            $summary['unmapped'],
            $summary['missing_links'],
        ]]);

        $this->line($map->authorityExplanation());

        if ($issues !== []) {
            $this->newLine();
            $this->components->warn('Lifecycle diagnostics');
            $this->table(['legacy id', 'canonical id', 'legacy', 'canonical', 'mapped canonical', 'state'], $issues);
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<ContentOpportunity>
     */
    private function query(): Builder
    {
        return ContentOpportunity::query()
            ->when($this->option('workspace'), fn (Builder $query, string $workspace): Builder => $query->where('workspace_id', $workspace))
            ->when($this->option('site'), fn (Builder $query, string $site): Builder => $query->where('client_site_id', $site))
            ->when($this->option('source-id'), fn (Builder $query, string $sourceId): Builder => $query->whereKey($sourceId))
            ->when($this->option('status'), fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->orderByDesc('priority_score')
            ->orderBy('id');
    }

    /**
     * @param  array<string, mixed>  $comparison
     */
    private function state(array $comparison): string
    {
        if ($comparison['missing_canonical_link']) {
            return 'missing_canonical_link';
        }

        if ($comparison['unmapped_legacy_status']) {
            return 'unmapped_legacy_status';
        }

        if ($comparison['unmapped_canonical_status']) {
            return 'unmapped_canonical_status';
        }

        return $comparison['conflict'] ? 'conflict' : 'aligned';
    }
}
