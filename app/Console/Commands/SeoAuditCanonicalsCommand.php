<?php

namespace App\Console\Commands;

use App\Services\Seo\SeoSiteAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SeoAuditCanonicalsCommand extends Command
{
    protected $signature = 'seo:audit-canonicals';

    protected $description = 'Audit duplicate canonical URLs and localized hreflang consistency.';

    public function handle(SeoSiteAuditService $audit): int
    {
        $result = $audit->canonicals();
        $issues = 0;

        foreach ($result as $section => $rows) {
            $count = is_countable($rows) ? count($rows) : 0;
            $issues += $count;
            $this->line(sprintf('%s: %d', str_replace('_', ' ', $section), $count));
        }

        Cache::put('seo.audit.last_run', now()->toDateTimeString(), now()->addDays(30));
        Cache::put('seo.audit.canonicals.status', $issues === 0 ? 'Passed' : $issues . ' issue(s)', now()->addDays(30));

        return $issues > 0 ? self::FAILURE : self::SUCCESS;
    }
}
