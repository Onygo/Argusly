<?php

namespace App\Console\Commands;

use App\Models\ContentOpportunity;
use App\Models\Opportunity;
use App\Services\Mos\Opportunity\ContentOpportunityCanonicalAutopilotQueueWriter;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MosWriteContentOpportunityAutopilotQueueCommand extends Command
{
    protected $signature = 'mos:write-content-opportunity-autopilot-queue
        {--apply : Create canonical GrowthAutopilot queue references when safe}
        {--workspace= : Limit to a workspace id}
        {--site= : Limit to a client site id}
        {--source-id= : Inspect one ContentOpportunity id}
        {--opportunity-id= : Use one canonical Opportunity id}
        {--growth-program= : Accepted for command parity; not used by queue writing}
        {--limit=100 : Maximum records to inspect}';

    protected $description = 'Dry-run or explicitly write guarded canonical Growth Autopilot queue references for linked ContentOpportunity records.';

    public function handle(ContentOpportunityCanonicalAutopilotQueueWriter $writer): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));
        $summary = [
            'seen' => 0,
            'safe_candidates' => 0,
            'blocked_candidates' => 0,
            'duplicate_execution_risks' => 0,
            'created_references' => 0,
            'skipped' => 0,
        ];
        $rows = [];

        $apply
            ? $this->components->warn('Apply mode is explicit and guarded by features.mos_canonical_content_opportunity_autopilot_writer. Existing queue items will not be rewritten except metadata added to newly upserted canonical references.')
            : $this->components->info('Dry run. No queue items, recommended actions, growth assets, or lifecycle statuses will be changed.');

        $this->query()
            ->limit($limit)
            ->get()
            ->each(function (ContentOpportunity $contentOpportunity) use ($writer, $apply, &$summary, &$rows): void {
                $summary['seen']++;
                $canonical = $this->canonicalOpportunity($contentOpportunity);
                $result = $apply && $canonical
                    ? $writer->apply($contentOpportunity, $canonical)
                    : $writer->dryRun($contentOpportunity, $canonical);

                $summary['safe_candidates'] += $result->safe ? 1 : 0;
                $summary['blocked_candidates'] += $result->safe ? 0 : 1;
                $summary['duplicate_execution_risks'] += count($result->duplicateExecutionRisks);
                $summary['created_references'] += $result->applied ? 1 : 0;
                $summary['skipped'] += $result->applied ? 0 : 1;

                $rows[] = [
                    $result->legacyContentOpportunityId,
                    $result->canonicalOpportunityId ?? 'missing',
                    $result->status,
                    $result->queueItem?->id ? (string) $result->queueItem->id : 'none',
                    $result->recommendedAction?->id ? (string) $result->recommendedAction->id : 'none',
                    implode(', ', $result->duplicateExecutionRisks),
                    implode(', ', $result->blockedReasons),
                    $result->queueSignature ?? 'missing',
                    $result->featureEnabled ? 'yes' : 'no',
                ];
            });

        $this->newLine();
        $this->table(
            ['seen', 'safe candidates', 'blocked candidates', 'duplicate execution risks', 'created references', 'skipped'],
            [[$summary['seen'], $summary['safe_candidates'], $summary['blocked_candidates'], $summary['duplicate_execution_risks'], $summary['created_references'], $summary['skipped']]],
        );

        if ($rows !== []) {
            $this->newLine();
            $this->table(['legacy id', 'canonical id', 'status', 'created queue item', 'recommended action', 'duplicate execution risks', 'skipped reasons', 'queue signature', 'feature enabled'], $rows);
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
            ->orderByDesc('priority_score')
            ->orderBy('id');
    }

    private function canonicalOpportunity(ContentOpportunity $contentOpportunity): ?Opportunity
    {
        return Opportunity::query()
            ->when($this->option('opportunity-id'), fn (Builder $query, string $id): Builder => $query->whereKey($id))
            ->where('content_opportunity_id', (string) $contentOpportunity->id)
            ->first();
    }
}
