<?php

namespace App\Services\WebsiteContentInventory;

use App\Enums\ContentDiscoveryMethod;
use App\Enums\ContentInventorySourceType;
use App\Enums\ContentLifecycleStatus;
use App\Enums\ContentManagementType;
use App\Enums\ContentOriginType;
use App\Enums\ContentPageLinkType;
use App\Enums\ContentReviewStatus;
use App\Enums\ContentSource;
use App\Enums\ContentType;
use App\Enums\SupportedLanguage;
use App\Models\Content;
use App\Models\ContentPageLink;
use App\Models\MonitoredPage;
use App\Models\PageContentExtraction;
use App\Models\PageSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WebsiteContentActivationService
{
    public function __construct(
        private readonly WebsitePageEligibilityService $eligibility,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     */
    public function promote(MonitoredPage $page, array $options = []): WebsiteContentActivationResult
    {
        $page->loadMissing(['latestContentExtraction', 'latestSnapshot']);

        $eligibility = $this->eligibility->evaluate($page);
        $force = (bool) ($options['force'] ?? false);

        if (! $force && ! $eligibility->eligible) {
            throw new InvalidArgumentException('The monitored page is not eligible for content activation: '.implode(', ', $eligibility->reasons));
        }

        if (! $eligibility->normalizedUrl || ! $eligibility->urlHash) {
            throw new InvalidArgumentException('The monitored page does not have a valid public inventory URL.');
        }

        return DB::transaction(function () use ($page, $eligibility, $options): WebsiteContentActivationResult {
            $content = $this->resolveContent($page, $eligibility);
            $contentCreated = ! $content instanceof Content;

            if (! $content instanceof Content) {
                $content = new Content([
                    'workspace_id' => $page->workspace_id,
                    'client_site_id' => $page->client_site_id,
                    'source' => ContentSource::SYSTEM,
                    'origin_type' => ContentOriginType::UNKNOWN,
                    'type' => ContentType::SEO_PAGE,
                    'lifecycle_stage' => ContentLifecycleStatus::IDEA,
                    'status' => ContentLifecycleStatus::IDEA->toLegacyStatus(),
                    'generation_mode' => 'balanced',
                    'delivery_status' => 'pending',
                ]);
            }

            $this->applyInventoryMetadata($content, $page, $eligibility, $contentCreated, $options);
            $content->save();

            ContentPageLink::query()
                ->where('content_id', $content->id)
                ->where('link_type', ContentPageLinkType::OBSERVED_SOURCE->value)
                ->where('is_primary', true)
                ->where('monitored_page_id', '!=', $page->id)
                ->update(['is_primary' => false]);

            $link = ContentPageLink::query()->updateOrCreate(
                [
                    'workspace_id' => $page->workspace_id,
                    'content_id' => $content->id,
                    'monitored_page_id' => $page->id,
                    'link_type' => ContentPageLinkType::OBSERVED_SOURCE->value,
                ],
                [
                    'client_site_id' => $page->client_site_id ?: $content->client_site_id,
                    'is_primary' => true,
                    'confidence_score' => $this->confidenceScore($page),
                    'metadata' => [
                        'activated_from' => 'monitored_page',
                        'eligibility' => $eligibility->toArray(),
                        'latest_snapshot_id' => $page->latestSnapshot?->id,
                        'latest_extraction_id' => $page->latestContentExtraction?->id,
                    ],
                ]
            );
            $linkCreated = $link->wasRecentlyCreated;

            return new WebsiteContentActivationResult(
                content: $content->refresh(),
                link: $link->refresh(),
                contentCreated: $contentCreated,
                linkCreated: $linkCreated,
                eligibility: $eligibility,
            );
        });
    }

    private function resolveContent(MonitoredPage $page, WebsitePageEligibilityResult $eligibility): ?Content
    {
        $linkedContentId = ContentPageLink::query()
            ->where('workspace_id', $page->workspace_id)
            ->where('monitored_page_id', $page->id)
            ->where('link_type', ContentPageLinkType::OBSERVED_SOURCE->value)
            ->value('content_id');

        if (is_string($linkedContentId) && $linkedContentId !== '') {
            $content = Content::query()->withoutGlobalScopes()->find($linkedContentId);
            if ($content instanceof Content) {
                return $content;
            }
        }

        if ($eligibility->urlHash) {
            $content = Content::query()
                ->withoutGlobalScopes()
                ->where('workspace_id', $page->workspace_id)
                ->where('url_hash', $eligibility->urlHash)
                ->first();

            if ($content instanceof Content) {
                return $content;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function applyInventoryMetadata(
        Content $content,
        MonitoredPage $page,
        WebsitePageEligibilityResult $eligibility,
        bool $contentCreated,
        array $options,
    ): void {
        $extraction = $page->latestContentExtraction;
        $snapshot = $page->latestSnapshot;
        $metadata = $this->inventoryMetadata($content, $page, $extraction, $snapshot, $eligibility);
        $sourceType = $this->inventorySourceType($page);
        $discoveryMethod = $this->discoveryMethod($page);
        $managementType = $this->managementType($sourceType);
        $overwriteMarketingMetadata = $contentCreated
            || (bool) ($options['refresh_metadata'] ?? true)
            || in_array($this->rawEnumValue($content->management_type), ['', ContentManagementType::OBSERVED->value, ContentManagementType::EXTERNAL_REFERENCE->value], true);

        $title = $this->firstFilled([
            $extraction?->title,
            $page->title_current,
            $this->titleFromUrl($eligibility->normalizedUrl),
        ]);
        $canonicalUrl = $this->firstFilled([
            $snapshot?->canonical_url,
            $page->canonical_url,
            $eligibility->normalizedUrl,
        ]);

        $content->forceFill([
            'workspace_id' => $content->workspace_id ?: $page->workspace_id,
            'client_site_id' => $content->client_site_id ?: $page->client_site_id,
            'inventory_source_type' => $sourceType,
            'management_type' => $managementType,
            'discovery_method' => $discoveryMethod,
            'original_url' => $page->first_seen_url,
            'normalized_url' => $eligibility->normalizedUrl,
            'canonical_url' => $canonicalUrl,
            'url_hash' => $eligibility->urlHash,
            'content_fingerprint' => $extraction?->main_text_hash ?: $snapshot?->text_hash,
            'http_status' => $snapshot?->http_status,
            'first_seen_at' => $page->first_seen_at,
            'last_seen_at' => $page->last_seen_at,
            'last_fetched_at' => $page->last_fetched_at ?: $snapshot?->fetched_at,
            'external_modified_at' => $this->externalModifiedAt($page, $snapshot),
            'external_changed_at' => $page->last_changed_at,
            'review_status' => $content->review_status ?: ContentReviewStatus::PENDING_REVIEW,
            'campaign_eligible' => $eligibility->campaignEligible,
            'inventory_metadata' => $metadata,
        ]);

        if ($contentCreated || ! $content->published_url) {
            $content->published_url = $eligibility->normalizedUrl;
        }

        if ($contentCreated || ! $content->language) {
            $content->language = SupportedLanguage::fromStringOrDefault($extraction?->language ?: $page->language_current);
        }

        if ($overwriteMarketingMetadata) {
            $content->title = $title;
            $content->seo_title = $extraction?->title ?: $title;
            $content->seo_meta_description = $extraction?->meta_description ?: $content->seo_meta_description;
            $content->seo_h1 = $extraction?->h1 ?: $content->seo_h1;
            $content->seo_canonical = $canonicalUrl;
            $content->seo_og_image = $this->openGraphImage($extraction) ?: $content->seo_og_image;
            $content->schema_type = $this->schemaType($extraction) ?: $content->schema_type;
            $content->robots_index = $content->robots_index ?? $eligibility->eligible;
            $content->robots_follow = $content->robots_follow ?? true;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function inventoryMetadata(
        Content $content,
        MonitoredPage $page,
        ?PageContentExtraction $extraction,
        ?PageSnapshot $snapshot,
        WebsitePageEligibilityResult $eligibility,
    ): array {
        $existing = (array) ($content->inventory_metadata ?? []);

        return array_replace_recursive($existing, [
            'source_monitored_page_id' => $page->id,
            'latest_snapshot_id' => $snapshot?->id,
            'latest_extraction_id' => $extraction?->id,
            'summary' => $extraction?->summary,
            'main_text_hash' => $extraction?->main_text_hash,
            'main_text_path' => $extraction?->main_text_path,
            'word_count' => $extraction?->word_count,
            'quality_score' => $extraction?->quality_score,
            'snapshot' => [
                'raw_html_hash' => $snapshot?->raw_html_hash,
                'text_hash' => $snapshot?->text_hash,
                'content_changed' => $snapshot?->content_changed,
                'fetched_at' => $snapshot?->fetched_at?->toISOString(),
            ],
            'eligibility' => $eligibility->toArray(),
        ]);
    }

    private function inventorySourceType(MonitoredPage $page): ContentInventorySourceType
    {
        return match (strtolower(trim((string) $page->source_type))) {
            'analytics_observed', 'js_tracking', 'tracking' => ContentInventorySourceType::OBSERVED_ANALYTICS,
            'xml_sitemap', 'sitemap' => ContentInventorySourceType::SITEMAP_DISCOVERED,
            'cms_connector', 'wordpress', 'laravel_connector' => ContentInventorySourceType::CMS_CONNECTED,
            'connector_import', 'ga4', 'gsc', 'linkedin' => ContentInventorySourceType::CONNECTOR_IMPORT,
            default => ContentInventorySourceType::MANUAL_EXTERNAL_URL,
        };
    }

    private function discoveryMethod(MonitoredPage $page): ContentDiscoveryMethod
    {
        return match (strtolower(trim((string) $page->source_type))) {
            'analytics_observed', 'js_tracking', 'tracking' => ContentDiscoveryMethod::JS_TRACKING,
            'xml_sitemap', 'sitemap' => ContentDiscoveryMethod::SITEMAP,
            'cms_connector', 'wordpress', 'laravel_connector' => ContentDiscoveryMethod::CMS_CONNECTOR,
            'api' => ContentDiscoveryMethod::API,
            'serp' => ContentDiscoveryMethod::SERP,
            'answer_engine_citation', 'geo_citation' => ContentDiscoveryMethod::GEO_CITATION,
            default => ContentDiscoveryMethod::MANUAL,
        };
    }

    private function managementType(ContentInventorySourceType $sourceType): ContentManagementType
    {
        return match ($sourceType) {
            ContentInventorySourceType::ARGUSLY_MANAGED => ContentManagementType::MANAGED,
            ContentInventorySourceType::CMS_CONNECTED => ContentManagementType::CMS_MANAGED,
            ContentInventorySourceType::MANUAL_EXTERNAL_URL,
            ContentInventorySourceType::CONNECTOR_IMPORT => ContentManagementType::EXTERNAL_REFERENCE,
            ContentInventorySourceType::OBSERVED_ANALYTICS,
            ContentInventorySourceType::SITEMAP_DISCOVERED => ContentManagementType::OBSERVED,
        };
    }

    private function confidenceScore(MonitoredPage $page): float
    {
        if ($page->latestContentExtraction instanceof PageContentExtraction && $page->latestSnapshot instanceof PageSnapshot) {
            return 95.0;
        }

        if ($page->latestSnapshot instanceof PageSnapshot) {
            return 85.0;
        }

        return 70.0;
    }

    /**
     * @param  array<int,mixed>  $values
     */
    private function firstFilled(array $values): string
    {
        foreach ($values as $value) {
            $string = trim((string) $value);
            if ($string !== '') {
                return $string;
            }
        }

        return 'Observed website page';
    }

    private function titleFromUrl(?string $url): string
    {
        $path = trim((string) parse_url((string) $url, PHP_URL_PATH), '/');
        $segment = $path !== '' ? basename($path) : (string) parse_url((string) $url, PHP_URL_HOST);

        return trim(str_replace(['-', '_'], ' ', ucfirst($segment))) ?: 'Observed website page';
    }

    private function externalModifiedAt(MonitoredPage $page, ?PageSnapshot $snapshot): mixed
    {
        $value = data_get($page->metadata_json, 'lastmod')
            ?? data_get($page->metadata_json, 'external_modified_at')
            ?? data_get($snapshot?->response_headers_json, 'last-modified')
            ?? data_get($snapshot?->response_headers_json, 'Last-Modified');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function openGraphImage(?PageContentExtraction $extraction): ?string
    {
        if (! $extraction instanceof PageContentExtraction) {
            return null;
        }

        $projected = trim((string) $extraction->open_graph_image_url);
        if ($projected !== '') {
            return $projected;
        }

        foreach ([
            data_get($extraction->metadata_json, 'open_graph.image'),
            data_get($extraction->metadata_json, 'og_image'),
            data_get($extraction->metadata_json, 'og:image'),
        ] as $value) {
            $image = trim((string) $value);
            if ($image !== '') {
                return $image;
            }
        }

        foreach ((array) $extraction->images_json as $image) {
            $source = strtolower(trim((string) data_get($image, 'source')));
            $property = strtolower(trim((string) data_get($image, 'property')));
            $src = trim((string) (data_get($image, 'src') ?: data_get($image, 'url')));

            if ($src !== '' && ($source === 'open_graph' || $source === 'og' || $property === 'og:image')) {
                return $src;
            }
        }

        return null;
    }

    private function schemaType(?PageContentExtraction $extraction): ?string
    {
        if (! $extraction instanceof PageContentExtraction) {
            return null;
        }

        $projected = collect((array) $extraction->schema_types_json)
            ->map(fn (mixed $type): string => trim((string) $type))
            ->first(fn (string $type): bool => $type !== '');

        if (is_string($projected) && $projected !== '') {
            return $projected;
        }

        return $this->firstSchemaType((array) $extraction->structured_data_json);
    }

    /**
     * @param  array<mixed>  $nodes
     */
    private function firstSchemaType(array $nodes): ?string
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = data_get($node, '@type');
            if (is_array($type)) {
                $type = $type[0] ?? null;
            }

            if (is_string($type) && trim($type) !== '') {
                return trim($type);
            }

            $graphType = $this->firstSchemaType((array) data_get($node, '@graph', []));
            if ($graphType !== null) {
                return $graphType;
            }
        }

        return null;
    }

    private function rawEnumValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : trim((string) $value);
    }
}
