<?php

namespace App\Console\Commands;

use App\Models\ClientSite;
use App\Services\WebsiteContentInventory\WebsiteSitemapSourceSetupService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class SetupWebsiteContentSitemapSourcesCommand extends Command
{
    protected $signature = 'website-content:setup-sitemaps
        {--workspace= : Optional workspace UUID}
        {--site= : Optional client_site UUID}
        {--dry-run : Compute source changes without writing}
        {--discover : Queue existing Page Intelligence sitemap discovery after setup}';

    protected $description = 'Ensure verified client sites have Page Intelligence XML sitemap sources.';

    public function __construct(private readonly WebsiteSitemapSourceSetupService $setup)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $sites = $this->sites()->get();
        if ($sites->isEmpty()) {
            $this->warn('No client sites matched the requested scope.');

            return self::SUCCESS;
        }

        $rows = [];
        $totals = [
            'sites_processed' => 0,
            'sources_created' => 0,
            'sources_updated' => 0,
            'sources_unchanged' => 0,
            'sources_rejected' => 0,
            'discovery_jobs_queued' => 0,
        ];

        foreach ($sites as $site) {
            $result = $this->setup->ensureForSite($site, [
                'dry_run' => (bool) $this->option('dry-run'),
                'dispatch_discovery' => (bool) $this->option('discover'),
            ]);
            $array = $result->toArray();

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (int) ($array[$key] ?? 0);
            }

            $rows[] = [
                'site' => (string) $site->id,
                'workspace' => (string) $site->workspace_id,
                'created' => (string) $result->sourcesCreated,
                'updated' => (string) $result->sourcesUpdated,
                'unchanged' => (string) $result->sourcesUnchanged,
                'rejected' => (string) $result->sourcesRejected,
                'queued' => (string) $result->discoveryJobsQueued,
                'message' => implode('; ', $result->messages),
            ];
        }

        $this->table(['site', 'workspace', 'created', 'updated', 'unchanged', 'rejected', 'queued', 'message'], $rows);
        $this->table(['stat', 'value'], collect($totals)->map(fn (int $value, string $key): array => [$key, (string) $value])->values()->all());

        return self::SUCCESS;
    }

    private function sites(): Builder
    {
        $query = ClientSite::query()
            ->with(['workspace', 'analyticsSite'])
            ->where('is_active', true)
            ->orderBy('id');

        $workspace = trim((string) $this->option('workspace'));
        if ($workspace !== '') {
            $query->where('workspace_id', $workspace);
        }

        $site = trim((string) $this->option('site'));
        if ($site !== '') {
            $query->whereKey($site);
        }

        return $query;
    }
}
