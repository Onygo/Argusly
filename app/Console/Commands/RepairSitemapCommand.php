<?php

namespace App\Console\Commands;

use App\Services\Content\ContentCacheInvalidationService;
use Illuminate\Console\Command;

class RepairSitemapCommand extends Command
{
    protected $signature = 'seo:repair-sitemap {--fix : Clear sitemap caches}';

    protected $description = 'Repair sitemap cache state after canonical/indexation changes.';

    public function handle(ContentCacheInvalidationService $cacheInvalidation): int
    {
        if (! (bool) $this->option('fix')) {
            $this->info('Sitemap route validation is deterministic. Re-run with --fix to clear cached XML payloads.');

            return self::SUCCESS;
        }

        $cacheInvalidation->invalidatePublicContent('seo.repair_sitemap');
        $this->info('Sitemap caches cleared.');

        return self::SUCCESS;
    }
}
