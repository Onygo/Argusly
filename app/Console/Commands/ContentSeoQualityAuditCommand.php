<?php

namespace App\Console\Commands;

use App\Services\Seo\SeoQualityAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ContentSeoQualityAuditCommand extends Command
{
    protected $signature = 'content:seo-quality-audit {--published-only : Only audit published articles}';

    protected $description = 'Run lightweight people-first SEO quality checks for articles.';

    public function handle(SeoQualityAuditService $audit): int
    {
        $result = $audit->audit($this->option('published-only'));
        Cache::put('seo.audit.last_run', now()->toDateTimeString(), now()->addDays(30));

        $this->line('Audited: ' . $result['summary']['audited']);
        $this->line('Articles with issues: ' . $result['summary']['with_issues']);
        $this->line('Total issues: ' . $result['summary']['issues']);

        foreach (array_slice($result['items'], 0, 20) as $item) {
            $this->line(sprintf('- %s [%s]: %d issue(s)', $item['title'], $item['locale'], $item['issue_count']));
        }

        return (int) $result['summary']['issues'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
