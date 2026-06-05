<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\PublicBlog\PublicBlogPerformanceDataService;
use Illuminate\Console\Command;

class RepairPublicBlogPerformanceDataCommand extends Command
{
    protected $signature = 'blog:repair-performance-data
        {--workspace= : Limit to a single workspace id}
        {--site= : Limit to a single site id}
        {--limit=0 : Maximum number of contents to process}
        {--only-missing : Only update rows missing one or more public blog fields}
        {--dry-run : Preview changes without writing}';

    protected $description = 'Backfill blog overview performance fields such as excerpt, reading time, categories, tags, and thumbnail metadata.';

    public function handle(PublicBlogPerformanceDataService $service, ContentCacheInvalidationService $cacheInvalidation): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $processed = 0;
        $updated = 0;

        $query = Content::query()
            ->with([
                'currentVersion:id,content_id,body,meta',
                'featuredImage',
            ])
            ->where('type', 'article')
            ->orderBy('id');

        if ($workspaceId = trim((string) $this->option('workspace'))) {
            $query->where('workspace_id', $workspaceId);
        }

        if ($siteId = trim((string) $this->option('site'))) {
            $query->where('client_site_id', $siteId);
        }

        if ((bool) $this->option('only-missing')) {
            $query->where(function ($missingQuery): void {
                $missingQuery
                    ->whereNull('public_blog_excerpt')
                    ->orWhereNull('public_blog_reading_time_minutes')
                    ->orWhereNull('public_blog_category')
                    ->orWhereNull('public_blog_featured_image_url');
            });
        }

        $query->chunkById(100, function ($contents) use ($service, $dryRun, $limit, &$processed, &$updated): bool {
            foreach ($contents as $content) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }

                $processed++;
                $payload = $service->syncContent($content, persist: ! $dryRun);
                $changed = collect($payload)->contains(function ($value, string $key) use ($content): bool {
                    return json_encode($content->getOriginal($key)) !== json_encode($value);
                });

                if ($changed) {
                    $updated++;
                }

                if ($dryRun && $changed) {
                    $this->line(sprintf('Would update %s (%s)', (string) $content->id, (string) $content->title));
                }
            }

            return $limit === 0 || $processed < $limit;
        }, 'id');

        $this->table(
            ['processed', 'updated', 'dry_run'],
            [[(string) $processed, (string) $updated, $dryRun ? 'yes' : 'no']]
        );

        if (! $dryRun && $updated > 0) {
            $cacheInvalidation->invalidatePublicContent('blog.repair-performance-data');
        }

        return self::SUCCESS;
    }
}
