<?php

namespace App\Services\PageIntelligence;

use App\Models\ClientSite;
use App\Models\MonitoredPage;
use App\Models\MonitoredSource;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class SubmitMonitoredPageAction
{
    public function __construct(
        private readonly PageUrlNormalizer $normalizer,
        private readonly PageIdentityResolver $resolver,
        private readonly PageCrawlerSafetyService $safety,
    ) {
    }

    public function execute(
        Workspace $workspace,
        string $url,
        ?ClientSite $site = null,
        string $sourceType = 'manual',
        ?string $pageType = null,
        ?string $canonicalUrl = null,
        ?MonitoredSource $source = null,
        array $extraMetadata = [],
    ): SubmitMonitoredPageResult {
        if ($source !== null && (string) $source->workspace_id !== (string) $workspace->id) {
            throw new InvalidArgumentException('The monitored source does not belong to the selected workspace.');
        }

        $site ??= $source?->client_site_id ? ClientSite::query()->find($source->client_site_id) : null;

        if ($site !== null && (string) $site->workspace_id !== (string) $workspace->id) {
            throw new InvalidArgumentException('The selected site does not belong to the selected workspace.');
        }

        $safeUrl = $this->safety->normalizeAndValidate($url, $source, respectRobots: false);
        $safeCanonicalUrl = trim((string) $canonicalUrl) !== ''
            ? $this->safety->normalizeAndValidate((string) $canonicalUrl, $source, respectRobots: false)
            : null;

        $normalized = $this->normalizer->normalize($safeUrl, $safeCanonicalUrl);
        $now = Carbon::now();
        $page = $this->resolver->resolve($workspace, $normalized);

        if ($page === null) {
            $page = MonitoredPage::query()->create([
                'organization_id' => $workspace->organization_id,
                'workspace_id' => $workspace->id,
                'client_site_id' => $site?->id,
                'monitored_source_id' => $source?->id,
                'canonical_url' => $normalized->canonicalUrl,
                'canonical_url_hash' => $normalized->canonicalUrlHash,
                'first_seen_url' => $normalized->firstSeenUrl,
                'first_seen_url_hash' => $normalized->firstSeenUrlHash,
                'final_url' => $normalized->firstSeenUrl,
                'final_url_hash' => $normalized->firstSeenUrlHash,
                'domain' => $normalized->domain,
                'path' => $normalized->path,
                'source_type' => $sourceType,
                'page_type' => $pageType,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'crawl_status' => MonitoredPage::CRAWL_STATUS_DISCOVERED,
                'metadata_json' => array_replace_recursive([
                    'submitted_manually' => $source === null && $sourceType === 'manual',
                    'last_submitted_url' => $normalized->firstSeenUrl,
                ], $extraMetadata),
            ]);

            return new SubmitMonitoredPageResult($page, true, $normalized);
        }

        $metadata = array_replace_recursive((array) ($page->metadata_json ?? []), $extraMetadata);
        $metadata['submitted_manually'] = (bool) ($metadata['submitted_manually'] ?? false) || ($source === null && $sourceType === 'manual');
        $metadata['last_submitted_url'] = $normalized->firstSeenUrl;

        if ($source !== null) {
            $metadata['last_discovered_by_source_id'] = $source->id;
        }

        $page->forceFill([
            'organization_id' => $page->organization_id ?: $workspace->organization_id,
            'client_site_id' => $page->client_site_id ?: $site?->id,
            'monitored_source_id' => $page->monitored_source_id ?: $source?->id,
            'final_url' => $normalized->firstSeenUrl,
            'final_url_hash' => $normalized->firstSeenUrlHash,
            'last_seen_at' => $now,
            'source_type' => $page->source_type ?: $sourceType,
            'page_type' => $page->page_type ?: $pageType,
            'metadata_json' => $metadata,
        ])->save();

        return new SubmitMonitoredPageResult($page->refresh(), false, $normalized);
    }
}
