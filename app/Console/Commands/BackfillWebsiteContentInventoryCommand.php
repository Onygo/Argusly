<?php

namespace App\Console\Commands;

use App\Enums\ContentDiscoveryMethod;
use App\Enums\ContentInventorySourceType;
use App\Enums\ContentManagementType;
use App\Enums\ContentPageLinkType;
use App\Enums\ContentReviewStatus;
use App\Models\Content;
use App\Models\ContentPageLink;
use App\Models\MonitoredPage;
use App\Services\WebsiteContentInventory\WebsiteContentActivationService;
use App\Services\WebsiteContentInventory\WebsitePageEligibilityService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

class BackfillWebsiteContentInventoryCommand extends Command
{
    protected $signature = 'website-content:backfill-inventory
        {--workspace= : Optional workspace UUID}
        {--site= : Optional client_site UUID}
        {--chunk= : Batch size}
        {--resume-after= : Resume monitored page processing after this UUID}
        {--limit= : Maximum monitored pages to process}
        {--dry-run : Compute changes without writing}
        {--promote : Promote eligible monitored pages into Content}';

    protected $description = 'Backfill website content inventory links and optional activation shells from monitored pages.';

    public function __construct(
        private readonly WebsitePageEligibilityService $eligibility,
        private readonly WebsiteContentActivationService $activation,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $promote = (bool) $this->option('promote');
        $chunkSize = $this->chunkSize();

        $stats = [
            'content_processed' => 0,
            'content_inventory_updates' => 0,
            'publication_links' => 0,
            'publication_link_candidates' => 0,
            'pages_processed' => 0,
            'eligible_pages' => 0,
            'ineligible_pages' => 0,
            'excluded_pages' => 0,
            'promotion_candidates' => 0,
            'promoted_assets' => 0,
            'already_linked_pages' => 0,
            'failed_promotions' => 0,
            'last_page_id' => null,
        ];

        $this->line('Website content inventory backfill');
        $this->line('Mode: '.($dryRun ? 'dry-run' : 'write'));
        $this->line('Promotion: '.($promote ? 'enabled' : 'disabled'));

        $this->backfillExistingContent($stats, $chunkSize, $dryRun);
        $this->processMonitoredPages($stats, $chunkSize, $dryRun, $promote);

        $this->newLine();
        $this->table(['stat', 'value'], collect($stats)->map(fn (mixed $value, string $key): array => [
            $key,
            $value === null ? '' : (string) $value,
        ])->values()->all());

        if (! $promote) {
            $this->line('No monitored pages were promoted. Pass --promote to create or update Content activation shells.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $stats
     */
    private function backfillExistingContent(array &$stats, int $chunkSize, bool $dryRun): void
    {
        $query = Content::query()
            ->withoutGlobalScopes()
            ->whereNotNull('published_url')
            ->orderBy('id');

        $this->applyContentScope($query);

        $query->chunkById($chunkSize, function ($contents) use (&$stats, $dryRun): void {
            foreach ($contents as $content) {
                $stats['content_processed']++;

                $normalizedUrl = $this->eligibility->normalizeUrl($content->published_url ?: $content->seo_canonical);
                if (! $normalizedUrl) {
                    continue;
                }

                $urlHash = $this->eligibility->urlHash($normalizedUrl);
                $payload = [
                    'inventory_source_type' => $content->inventory_source_type ?: ContentInventorySourceType::ARGUSLY_MANAGED,
                    'management_type' => $content->management_type ?: ContentManagementType::MANAGED,
                    'discovery_method' => $content->discovery_method ?: ContentDiscoveryMethod::ARGUSLY_CREATED,
                    'original_url' => $content->original_url ?: $content->published_url,
                    'normalized_url' => $content->normalized_url ?: $normalizedUrl,
                    'canonical_url' => $content->canonical_url ?: ($content->seo_canonical ?: $normalizedUrl),
                    'url_hash' => $content->url_hash ?: $urlHash,
                    'review_status' => $content->review_status ?: ContentReviewStatus::REVIEWED,
                    'campaign_eligible' => $content->campaign_eligible ?? true,
                    'inventory_metadata' => array_replace_recursive((array) ($content->inventory_metadata ?? []), [
                        'backfill' => [
                            'source' => 'existing_content_published_url',
                        ],
                    ]),
                ];

                $dirty = collect($payload)->contains(
                    fn (mixed $value, string $key): bool => $this->rawComparable($content->{$key}) !== $this->rawComparable($value)
                );

                if ($dirty) {
                    $stats['content_inventory_updates']++;

                    if (! $dryRun) {
                        $content->forceFill($payload)->save();
                    }
                }

                $page = $this->matchingMonitoredPage($content, $urlHash, $normalizedUrl);
                if (! $page instanceof MonitoredPage) {
                    continue;
                }

                $stats['publication_link_candidates']++;

                if ($dryRun) {
                    continue;
                }

                $link = ContentPageLink::query()->firstOrCreate(
                    [
                        'workspace_id' => $content->workspace_id,
                        'content_id' => $content->id,
                        'monitored_page_id' => $page->id,
                        'link_type' => ContentPageLinkType::PUBLICATION_URL->value,
                    ],
                    [
                        'client_site_id' => $content->client_site_id ?: $page->client_site_id,
                        'is_primary' => false,
                        'confidence_score' => 90.0,
                        'metadata' => [
                            'backfilled_from' => 'content_published_url',
                            'normalized_url' => $normalizedUrl,
                        ],
                    ]
                );

                if ($link->wasRecentlyCreated) {
                    $stats['publication_links']++;
                }
            }
        }, 'id');
    }

    /**
     * @param  array<string,mixed>  $stats
     */
    private function processMonitoredPages(array &$stats, int $chunkSize, bool $dryRun, bool $promote): void
    {
        $query = MonitoredPage::query()
            ->withoutGlobalScopes()
            ->with(['latestSnapshot', 'latestContentExtraction'])
            ->orderBy('id');

        $this->applyMonitoredPageScope($query);

        $resumeAfter = trim((string) $this->option('resume-after'));
        if ($resumeAfter !== '') {
            $query->where('id', '>', $resumeAfter);
        }

        $limit = (int) $this->option('limit');

        $query->chunkById($chunkSize, function ($pages) use (&$stats, $dryRun, $promote, $limit): bool {
            foreach ($pages as $page) {
                if ($limit > 0 && $stats['pages_processed'] >= $limit) {
                    return false;
                }

                $stats['pages_processed']++;
                $stats['last_page_id'] = (string) $page->id;

                $result = $this->eligibility->evaluate($page);
                if ($result->eligible) {
                    $stats['eligible_pages']++;
                } else {
                    $stats['ineligible_pages']++;
                }

                if (in_array('excluded_path', $result->reasons, true) || in_array('review_excluded', $result->reasons, true)) {
                    $stats['excluded_pages']++;
                }

                if (! $result->eligible) {
                    continue;
                }

                if ($page->contentPageLinks()->exists()) {
                    $stats['already_linked_pages']++;
                }

                $stats['promotion_candidates']++;

                if (! $promote || $dryRun) {
                    continue;
                }

                try {
                    $this->activation->promote($page);
                    $stats['promoted_assets']++;
                } catch (\Throwable $exception) {
                    $stats['failed_promotions']++;
                    $this->warn('Failed to promote '.$page->id.': '.$exception->getMessage());
                }
            }

            $this->line('Processed monitored pages: '.$stats['pages_processed'].'; last_page_id='.$stats['last_page_id']);

            return true;
        }, 'id');
    }

    private function chunkSize(): int
    {
        $configured = (int) config('website_content_inventory.backfill.chunk_size', 100);
        $requested = (int) ($this->option('chunk') ?: $configured);
        $max = max(1, (int) config('website_content_inventory.backfill.max_chunk_size', 1000));

        return max(1, min($requested, $max));
    }

    private function applyContentScope($query): void
    {
        $workspace = trim((string) $this->option('workspace'));
        $site = trim((string) $this->option('site'));

        if ($workspace !== '') {
            $query->where('workspace_id', $workspace);
        }

        if ($site !== '') {
            $query->where('client_site_id', $site);
        }
    }

    private function applyMonitoredPageScope($query): void
    {
        $workspace = trim((string) $this->option('workspace'));
        $site = trim((string) $this->option('site'));

        if ($workspace !== '') {
            $query->where('workspace_id', $workspace);
        }

        if ($site !== '') {
            $query->where('client_site_id', $site);
        }
    }

    private function matchingMonitoredPage(Content $content, string $urlHash, string $normalizedUrl): ?MonitoredPage
    {
        return MonitoredPage::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $content->workspace_id)
            ->where(function ($query) use ($urlHash, $normalizedUrl): void {
                $query->where('canonical_url_hash', $urlHash)
                    ->orWhere('first_seen_url_hash', $urlHash)
                    ->orWhere('final_url_hash', $urlHash)
                    ->orWhere('canonical_url', $normalizedUrl)
                    ->orWhere('first_seen_url', $normalizedUrl)
                    ->orWhere('final_url', $normalizedUrl);
            })
            ->first();
    }

    private function rawComparable(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateTimeString();
        }

        return $value;
    }
}
