<?php

namespace App\Console\Commands;

use App\Services\Content\ContentCacheInvalidationService;
use Illuminate\Console\Command;

class ClearSeoXmlCacheCommand extends Command
{
    protected $signature = 'seo:clear-xml-cache {--dry-run : Show what would be invalidated without clearing cache}';

    protected $description = 'Clear cached sitemap and RSS XML payloads for the public site.';

    public function handle(ContentCacheInvalidationService $cacheInvalidation): int
    {
        if ((bool) $this->option('dry-run')) {
            $this->warn('Dry run only. XML cache was not cleared.');

            return self::SUCCESS;
        }

        $result = $cacheInvalidation->invalidatePublicContent('seo.xml_cache_cleared');

        $this->info('Cleared public XML cache.');
        $this->line(sprintf('Reason: %s', (string) ($result['reason'] ?? 'seo.xml_cache_cleared')));
        $this->line(sprintf('Hosts: %d', count((array) ($result['hosts'] ?? []))));
        $this->line(sprintf('Locales: %s', implode(', ', (array) ($result['locales'] ?? []))));
        $this->line(sprintf('Sitemap scopes cleared: %d', count((array) data_get($result, 'invalidated.sitemap', []))));

        return self::SUCCESS;
    }
}
