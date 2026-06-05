<?php

namespace App\Console\Commands;

use App\Models\ClientSite;
use App\Models\Content;
use App\Services\DraftDelivery\DeliverDraftToWordPress;
use Illuminate\Console\Command;

class BackfillWordPressPostIdsCommand extends Command
{
    protected $signature = 'content:backfill-wp-post-ids
        {--site= : Filter by client_site_id}
        {--limit=250 : Max contents to process}
        {--dry-run : List candidates only}';

    protected $description = 'Backfill missing wp_post_id values for WordPress content records.';

    public function handle(DeliverDraftToWordPress $delivery): int
    {
        $siteId = trim((string) $this->option('site'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $query = Content::query()
            ->with('clientSite')
            ->whereNotNull('client_site_id')
            ->where(function ($builder): void {
                $builder
                    ->whereNull('wp_post_id')
                    ->orWhere('wp_post_id', '');
            })
            ->whereHas('clientSite', fn ($builder) => $builder->where('type', ClientSite::TYPE_WORDPRESS))
            ->orderBy('updated_at');

        if ($siteId !== '') {
            $query->where('client_site_id', $siteId);
        }

        $candidates = $query->limit($limit)->get();
        $this->info('Candidates: ' . $candidates->count());

        if ($dryRun) {
            foreach ($candidates as $content) {
                $this->line(sprintf(
                    '- content=%s site=%s external_key=%s',
                    (string) $content->id,
                    (string) $content->client_site_id,
                    (string) ($content->external_key ?? '')
                ));
            }

            return self::SUCCESS;
        }

        $ensured = 0;
        $failed = 0;

        foreach ($candidates as $content) {
            $result = $delivery->ensureWpPostIdForContent($content);
            if (($result['ok'] ?? false) === true) {
                $ensured++;
                continue;
            }

            $failed++;
            $this->warn(sprintf(
                'Failed content=%s site=%s error=%s',
                (string) $content->id,
                (string) $content->client_site_id,
                (string) ($result['error'] ?? 'unknown')
            ));
        }

        $this->table(['metric', 'count'], [
            ['candidates', $candidates->count()],
            ['ensured', $ensured],
            ['failed', $failed],
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

