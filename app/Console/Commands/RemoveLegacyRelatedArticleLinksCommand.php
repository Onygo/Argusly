<?php

namespace App\Console\Commands;

use App\Models\ContentRevision;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Services\Content\ContentCacheInvalidationService;
use App\Services\Content\ContentRenderNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class RemoveLegacyRelatedArticleLinksCommand extends Command
{
    protected $signature = 'content:remove-legacy-related-links
        {--dry-run : Preview changes without writing}
        {--content= : Restrict cleanup to a single content id}
        {--workspace= : Restrict cleanup to a workspace id}
        {--site= : Restrict cleanup to a client site id}
        {--limit=0 : Maximum records to update per storage type}';

    protected $description = 'Remove generated legacy Related article placeholder link blocks from drafts, revisions, and published versions.';

    public function handle(ContentRenderNormalizer $normalizer, ContentCacheInvalidationService $cacheInvalidation): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $totals = [
            'scanned' => 0,
            'changed' => 0,
            'removed_blocks' => 0,
        ];

        $rows = [];
        $rows[] = $this->cleanModel(
            Draft::query(),
            'draft',
            'content_html',
            $normalizer,
            $dryRun,
            $limit,
            $totals
        );
        $rows[] = $this->cleanModel(
            ContentRevision::query(),
            'revision',
            'content_html',
            $normalizer,
            $dryRun,
            $limit,
            $totals
        );
        $rows[] = $this->cleanModel(
            ContentVersion::query(),
            'version',
            'body',
            $normalizer,
            $dryRun,
            $limit,
            $totals
        );

        $this->table(['storage', 'scanned', 'changed', 'removed_blocks'], $rows);
        $this->line(sprintf(
            'Total: scanned=%d changed=%d removed_blocks=%d dry_run=%s',
            $totals['scanned'],
            $totals['changed'],
            $totals['removed_blocks'],
            $dryRun ? 'yes' : 'no',
        ));

        if ($dryRun) {
            $this->warn('Dry run only. No changes were persisted.');

            return self::SUCCESS;
        }

        if ($totals['changed'] > 0) {
            try {
                $cacheInvalidation->invalidatePublicContent('content.remove-legacy-related-links');
            } catch (Throwable $exception) {
                $this->warn('Cleanup completed, but public cache invalidation failed: ' . $exception->getMessage());
                $this->warn('Run php artisan optimize:clear after fixing storage/framework/cache permissions.');
            }
        }

        $this->info('Legacy related link cleanup completed.');

        return self::SUCCESS;
    }

    /**
     * @param Builder<Model> $query
     * @param array{scanned:int,changed:int,removed_blocks:int} $totals
     * @return array{storage:string,scanned:int,changed:int,removed_blocks:int}
     */
    private function cleanModel(
        Builder $query,
        string $storage,
        string $column,
        ContentRenderNormalizer $normalizer,
        bool $dryRun,
        int $limit,
        array &$totals
    ): array {
        $scanned = 0;
        $changed = 0;
        $removedBlocks = 0;
        $contentId = trim((string) $this->option('content'));
        $workspaceId = trim((string) $this->option('workspace'));
        $siteId = trim((string) $this->option('site'));

        $query
            ->whereNotNull($column)
            ->where($column, 'like', '%Related article%')
            ->orderBy('id');

        if ($contentId !== '') {
            $query->where('content_id', $contentId);
        }

        if ($storage === 'draft' && $siteId !== '') {
            $query->where(function (Builder $siteQuery) use ($siteId): void {
                $siteQuery
                    ->where('client_site_id', $siteId)
                    ->orWhereHas('content', fn (Builder $contentQuery): Builder => $contentQuery->where('client_site_id', $siteId));
            });
        } elseif ($siteId !== '') {
            $query->whereHas('content', fn (Builder $contentQuery): Builder => $contentQuery->where('client_site_id', $siteId));
        }

        if ($workspaceId !== '') {
            $query->whereHas('content', fn (Builder $contentQuery): Builder => $contentQuery->where('workspace_id', $workspaceId));
        }

        $query->chunkById(100, function ($records) use ($column, $normalizer, $dryRun, $limit, &$scanned, &$changed, &$removedBlocks): bool {
            foreach ($records as $record) {
                if ($limit > 0 && $scanned >= $limit) {
                    return false;
                }

                $scanned++;
                $result = $normalizer->removeLegacyPlaceholderResources((string) ($record->{$column} ?? ''));
                if (! (bool) $result['changed']) {
                    continue;
                }

                $changed++;
                $removedBlocks += (int) $result['removed_count'];

                $this->line(sprintf(
                    '%s %s: remove %d legacy block(s)',
                    class_basename($record),
                    (string) $record->getKey(),
                    (int) $result['removed_count'],
                ));

                if (! $dryRun) {
                    $record->forceFill([$column => (string) $result['html']])->save();
                }
            }

            return $limit === 0 || $scanned < $limit;
        }, 'id');

        $totals['scanned'] += $scanned;
        $totals['changed'] += $changed;
        $totals['removed_blocks'] += $removedBlocks;

        return [
            'storage' => $storage,
            'scanned' => $scanned,
            'changed' => $changed,
            'removed_blocks' => $removedBlocks,
        ];
    }
}
