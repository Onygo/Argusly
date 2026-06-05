<?php

namespace App\Console\Commands;

use App\Jobs\LinkIntelligence\BuildArticleSignalsJob;
use App\Models\Draft;
use App\Support\FeatureFlags;
use App\Services\LinkIntelligence\BuildArticleSignalsService;
use Illuminate\Console\Command;

class LinkIntelligenceBackfillSignals extends Command
{
    protected $signature = 'link-intelligence:backfill-signals
        {--workspace-id= : Only drafts whose client site belongs to this workspace}
        {--client-site-id= : Only drafts for one client site}
        {--draft-id= : Process one specific draft id}
        {--status=* : Limit to draft statuses (repeatable)}
        {--limit=500 : Max drafts to process}
        {--chunk=100 : Chunk size for processing}
        {--queue=default : Queue name when dispatching jobs}
        {--sync : Process synchronously in this command}
        {--dry-run : Show count without dispatching/processing}';

    protected $description = 'Backfill link intelligence signals (embeddings, entities, metadata, suggestions) for existing drafts.';

    public function handle(BuildArticleSignalsService $service, FeatureFlags $features): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $chunkSize = max(1, min(1000, (int) $this->option('chunk')));
        $sync = (bool) $this->option('sync');
        $dryRun = (bool) $this->option('dry-run');
        $queue = (string) $this->option('queue');

        if (! $sync && ! $features->isEnabled('link_intelligence_jobs')) {
            $this->warn('Link Intelligence job dispatch is disabled by feature flag "link_intelligence_jobs".');
            return self::SUCCESS;
        }

        $query = Draft::query()
            ->select('drafts.*')
            ->join('client_sites', 'client_sites.id', '=', 'drafts.client_site_id')
            ->whereNotNull('drafts.content_html')
            ->where('drafts.content_html', '!=', '');

        $draftId = (string) $this->option('draft-id');
        if ($draftId !== '') {
            $query->where('drafts.id', $draftId);
        }

        $workspaceId = (string) $this->option('workspace-id');
        if ($workspaceId !== '') {
            $query->where('client_sites.workspace_id', $workspaceId);
        }

        $clientSiteId = (string) $this->option('client-site-id');
        if ($clientSiteId !== '') {
            $query->where('drafts.client_site_id', $clientSiteId);
        }

        /** @var array<int, string> $statuses */
        $statuses = (array) $this->option('status');
        $statuses = array_values(array_filter(array_map(static fn ($s) => trim((string) $s), $statuses)));
        if ($statuses !== []) {
            $query->whereIn('drafts.status', $statuses);
        }

        $total = (clone $query)->limit($limit)->count();

        $this->info('Matched drafts: ' . $total);
        $this->line('Mode: ' . ($sync ? 'sync' : 'queue') . ($dryRun ? ' (dry-run)' : ''));

        if ($dryRun || $total === 0) {
            return self::SUCCESS;
        }

        $processed = 0;

        (clone $query)
            ->limit($limit)
            ->chunkById($chunkSize, function ($drafts) use ($sync, $queue, $service, &$processed): void {
                foreach ($drafts as $draft) {
                    if ($sync) {
                        $service->handle($draft);
                    } else {
                        BuildArticleSignalsJob::dispatch((string) $draft->id)->onQueue($queue);
                    }

                    $processed++;

                    if ($processed % 25 === 0) {
                        $this->line('Processed/dispatched: ' . $processed);
                    }
                }
            }, 'drafts.id', 'id');

        $this->info('Done. Processed/dispatched: ' . $processed);

        return self::SUCCESS;
    }
}
