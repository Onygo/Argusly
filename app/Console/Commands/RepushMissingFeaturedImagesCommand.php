<?php

namespace App\Console\Commands;

use App\Models\Content;
use App\Services\DraftDelivery\PushContentFeaturedImageToWordPress;
use Illuminate\Console\Command;

class RepushMissingFeaturedImagesCommand extends Command
{
    protected $signature = 'content:repush-missing-featured-images
        {--site= : Filter by client_site_id}
        {--limit=100 : Max contents to process}
        {--dry-run : Show candidates without pushing}';

    protected $description = 'Repush featured images for WordPress posts that are missing remote featured-image metadata.';

    public function handle(PushContentFeaturedImageToWordPress $pusher): int
    {
        $siteId = trim((string) $this->option('site'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $query = Content::query()
            ->with(['featuredImage', 'clientSite', 'drafts'])
            ->whereNotNull('client_site_id')
            ->whereNotNull('wp_post_id')
            ->whereHas('featuredImage', fn ($q) => $q->where('status', 'ready')->where('is_active', true))
            ->orderBy('updated_at');

        if ($siteId !== '') {
            $query->where('client_site_id', $siteId);
        }

        $candidates = $query->limit($limit)->get()->filter(function (Content $content): bool {
            $featured = $content->featuredImage;
            if (! $featured) {
                return false;
            }

            $wpMeta = is_array($featured->metadata) ? (array) data_get($featured->metadata, 'wp', []) : [];

            return trim((string) ($wpMeta['featured_image_id'] ?? '')) === '';
        })->values();

        $this->info('Candidates: ' . $candidates->count());

        if ($dryRun) {
            foreach ($candidates as $content) {
                $this->line(sprintf(
                    '- content=%s site=%s wp_post_id=%s',
                    (string) $content->id,
                    (string) $content->client_site_id,
                    (string) $content->wp_post_id
                ));
            }

            return self::SUCCESS;
        }

        $pushed = 0;
        $failed = 0;

        foreach ($candidates as $content) {
            $result = $pusher->push($content);

            if (($result['ok'] ?? false) === true) {
                $pushed++;
                continue;
            }

            $failed++;
            $this->warn(sprintf(
                'Failed content=%s wp_post_id=%s error=%s',
                (string) $content->id,
                (string) $content->wp_post_id,
                (string) ($result['error'] ?? 'unknown')
            ));
        }

        $this->table(['metric', 'count'], [
            ['candidates', $candidates->count()],
            ['pushed', $pushed],
            ['failed', $failed],
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
