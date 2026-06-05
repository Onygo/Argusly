<?php

namespace App\Console\Commands;

use App\Services\Seo\SeoSiteAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SeoAuditIndexabilityCommand extends Command
{
    protected $signature = 'seo:audit-indexability';

    protected $description = 'Audit public indexability, canonicals, sitemap coverage and robots conflicts.';

    public function handle(SeoSiteAuditService $audit): int
    {
        $result = $audit->indexability();
        Cache::put('seo.audit.last_run', now()->toDateTimeString(), now()->addDays(30));

        foreach ($result as $section => $rows) {
            $this->line(sprintf('%s: %d', str_replace('_', ' ', $section), is_countable($rows) ? count($rows) : 0));
        }

        $issues = collect($result)
            ->except(['indexed_candidates', 'noindex_pages'])
            ->sum(fn ($rows): int => is_countable($rows) ? count($rows) : 0);

        return $issues > 0 ? self::FAILURE : self::SUCCESS;
    }
}
