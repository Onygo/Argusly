<?php

namespace App\Console\Commands;

use App\Models\ContentOpportunity;
use App\Services\Mos\Opportunity\ContentOpportunityRecommendedActionRepairService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MosDedupeContentOpportunityActionsCommand extends Command
{
    protected $signature = 'mos:dedupe-content-opportunity-actions
        {--apply : Annotate duplicate groups with non-destructive metadata}
        {--workspace= : Limit to a workspace id}
        {--site= : Limit to a client site id}
        {--source-id= : Inspect one ContentOpportunity id}
        {--status= : Limit to a legacy content opportunity status}
        {--limit=100 : Maximum records to inspect}';

    protected $description = 'Diagnose and optionally annotate duplicate linked ContentOpportunity/canonical Opportunity recommended actions.';

    public function handle(ContentOpportunityRecommendedActionRepairService $repair): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));
        $summary = [
            'seen' => 0,
            'linked' => 0,
            'duplicates' => 0,
            'safe_candidates' => 0,
            'would_annotate' => 0,
            'annotated' => 0,
            'skipped' => 0,
        ];
        $rows = [];

        if ($apply) {
            $this->components->warn('Apply mode annotates recommended action metadata only. It will not delete, dismiss, relink, or hide actions.');
        } else {
            $this->components->info('Dry run. No recommended actions, briefs, lifecycle statuses, routes, or queues will be changed.');
        }

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (ContentOpportunity $opportunity) use ($repair, $apply, &$summary, &$rows): void {
                $summary['seen']++;
                $result = $apply ? $repair->annotate($opportunity, 'artisan:mos:dedupe-content-opportunity-actions') : $repair->propose($opportunity);

                if ($result['linked']) {
                    $summary['linked']++;
                }

                $summary['duplicates'] += (int) $result['duplicate_count'];
                $summary['safe_candidates'] += (int) $result['safe_candidate_count'];
                $summary['would_annotate'] += $result['would_annotate'] ? count($result['actions']) : 0;
                $summary['annotated'] += (int) $result['annotated_count'];

                if ($result['repair_skipped_reasons'] !== []) {
                    $summary['skipped']++;
                }

                $rows[] = [
                    $result['legacy_content_opportunity_id'],
                    $result['canonical_opportunity_id'] ?? 'missing',
                    $result['workspace_id'] ?? 'missing',
                    $result['client_site_id'] ?? 'missing',
                    $result['existing_action_count'],
                    $result['duplicate_count'],
                    $result['safe_candidate_count'],
                    $result['primary_action_id'] ?? 'none',
                    implode(', ', $result['duplicate_action_ids']),
                    $result['would_annotate'] ? 'yes' : 'no',
                    $result['annotated_count'],
                    implode(', ', $result['repair_skipped_reasons']),
                    $result['canonical_equivalent_signature'],
                ];
            });

        $this->newLine();
        $this->table(
            ['seen', 'linked', 'duplicates', 'safe candidates', 'would annotate', 'annotated', 'skipped'],
            [[$summary['seen'], $summary['linked'], $summary['duplicates'], $summary['safe_candidates'], $summary['would_annotate'], $summary['annotated'], $summary['skipped']]],
        );

        if ($rows !== []) {
            $this->newLine();
            $this->table(
                ['legacy id', 'canonical id', 'workspace id', 'site id', 'actions', 'duplicates', 'safe candidates', 'primary action id', 'duplicate action ids', 'would annotate', 'annotated', 'skipped reasons', 'canonical-equivalent signature'],
                $rows,
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return Builder<ContentOpportunity>
     */
    private function query(): Builder
    {
        return ContentOpportunity::query()
            ->with(['workspace', 'site'])
            ->whereHas('workspace')
            ->when($this->option('workspace'), fn (Builder $query, string $workspace): Builder => $query->where('workspace_id', $workspace))
            ->when($this->option('site'), fn (Builder $query, string $site): Builder => $query->where('client_site_id', $site))
            ->when($this->option('source-id'), fn (Builder $query, string $sourceId): Builder => $query->whereKey($sourceId))
            ->when($this->option('status'), fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->orderByDesc('priority_score')
            ->orderBy('id');
    }
}
