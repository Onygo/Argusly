# Website Content Inventory and Activation Audit

Date: 2026-07-10
Scope: architecture and implementation audit only. No runtime implementation, data migration, staging, or commit was performed.

## 1. Existing Implementation Map

### Browser Tracking Flow

- Browser script source: `resources/analytics/argusly.js`.
- Script route: `routes/track.php` exposes `GET /argusly.js`, `GET /api/v1/config`, `POST /api/v1/events`, and `POST /api/tracking/events`.
- Script serving: `App\Http\Controllers\Analytics\AnalyticsScriptController` reads `resource_path('analytics/argusly.js')` directly and returns JavaScript with cache headers. This is not built by Vite or another asset pipeline.
- Track subdomain binding: `bootstrap/app.php` loads `routes/track.php` under the tracking domain.
- Origin and domain guard: `App\Http\Middleware\EnsureAnalyticsOriginAllowed` resolves the site key, loads `AnalyticsSite`, normalizes `allowed_domains` plus the `ClientSite.site_url` host, and rejects mismatched `Origin` or `Referer`.
- Ingestion controller: `App\Http\Controllers\Analytics\AnalyticsEventController`.
- Rate limiting: `App\Providers\AppServiceProvider` defines the `analytics-events` limiter by site key and IP, using `security.rate_limits.analytics_events_per_minute`.
- Storage models: `App\Models\AnalyticsSite`, `App\Models\AnalyticsEvent`, `App\Models\AnalyticsRollupDaily`, `App\Models\ContentMetric`.
- URL normalization for analytics: `App\Support\Analytics\AnalyticsUrlKey`.
- Content resolution: `App\Services\Analytics\AnalyticsContentResolver` matches analytics URL keys against `contents.publish_url_key` and `contents.canonical_url_key`, then falls back to `article_id`.
- Aggregation:
  - `App\Jobs\Analytics\BuildAnalyticsRollupsJob` builds hourly path rollups into `analytics_rollups_daily`.
  - `App\Jobs\Stats\RecalculateContentMetricsJob` and `App\Services\Stats\ContentMetricsCalculator` calculate URL-keyed scroll, read, engagement, read-through, and ROI metrics into `content_metrics`.
  - `routes/console.php` schedules rollups hourly and content metrics daily.
- Backfill convention: `App\Console\Commands\BackfillAnalyticsPageClassificationCommand` has `--dry-run` and syncs `contents` URL keys plus analytics event `url_key`, `content_id`, and `page_type`.

### Page And URL Metadata Already Collected

The browser payload in `resources/analytics/argusly.js` sends:

- `site_key`
- `event_type`
- `session_id`
- `url`
- `canonical_url`
- `referrer`
- `page_title`
- `occurred_at`
- optional `article_id`
- optional `content_type`
- event extras such as `depth` for scroll depth and `seconds` for read time

The script detects canonical URL from `window.Argusly.canonicalUrl`, `window.Argusly.canonical_url`, `<link rel="canonical">`, then `window.location.href`. It prefers canonical URL as page identity. It detects Argusly content id from `window.Argusly.articleId`, `article_id`, `contentId`, `content_id`, or `argusly_content_id` meta tags.

The ingestion controller stores:

- `url`, `canonical_url`, `canonical_url_hash`, `url_key`
- `path`, `path_hash`, `host`
- `title`, `referrer`
- `article_id`, `content_id`, `content_type`, `page_type`
- `event_type`, `event_time`, `received_at`, `event_hash`
- pseudonymous `visitor_hash`, `session_hash`, `ip_hash`
- `user_agent_family`, `device_type`
- `meta`

The current JavaScript tracker does not collect form values, user-entered text, authentication data, personal page content, or full HTML.

### Public JavaScript Build And Deployment

- Current Argusly tracker: `resources/analytics/argusly.js`.
- Served directly by `App\Http\Controllers\Analytics\AnalyticsScriptController`.
- Versioning is controlled by config and snippet URLs, not a JavaScript bundle build.
- There is also `resources/analytics/pl.js`, which appears to be legacy PublishLayer-compatible tracking code. Do not fork this again for inventory.
- Snippet generation and analytics site UI live in `App\Http\Controllers\App\AppAnalyticsSiteController` and `resources/views/app/sites/analytics/show.blade.php`.
- First-party auto-injection helpers exist in `App\Services\Analytics\ArguslyTrackingSiteResolver` and `App\Services\Analytics\PublishLayerTrackingSiteResolver`.

### Domain Models

Site, workspace, organization:

- `App\Models\Organization`
- `App\Models\Workspace`
- `App\Models\ClientSite`
- `App\Models\SiteToken`
- `App\Http\Middleware\VerifyClientDomainMiddleware`

Canonical content:

- `App\Models\Content`
- `App\Models\ContentVersion`
- `App\Models\ContentPublication`
- `App\Models\ContentDestination`
- `App\Observers\ContentObserver`
- `App\Enums\ContentSource`
- `App\Enums\ContentType`
- `App\Enums\ContentOriginType`
- `App\Enums\ContentLifecycleStatus`

`Content` already owns workspace/site, SEO fields, published URL, canonical URL key, publication state, lifecycle state, dedupe fields, intelligence scores, and relationships to campaigns and social variants.

Observed page intelligence:

- `App\Models\MonitoredSource`
- `App\Models\MonitoredPage`
- `App\Models\PageSnapshot`
- `App\Models\PageContentExtraction`
- related Page Intelligence models such as page entities, topics, sentiments, scores, SERP/GEO observations, alerts, PR value, and campaign/brand/competitor matches.

Campaigns:

- `App\Models\Campaign`
- `App\Models\CampaignContent`
- `App\Models\CampaignDistributionPlan`
- `App\Enums\CampaignContentAssetType`
- `App\Enums\DistributionChannelType`

Social:

- `App\Models\SocialPost`
- `App\Models\SocialPostVariant`
- `App\Models\SocialPublication`
- `App\Models\SocialAccount`
- `App\Models\SocialEngagementMetric`
- `App\Services\SocialDistribution\SocialArticleUrlResolver`
- LinkedIn publishing code under `App\Services\Social\LinkedIn` and `App\Services\SocialDistribution\Publishers\LinkedInPublisher`

Email/newsletter:

- `App\Models\EmailMarketingConnection`
- `App\Models\EmailCampaignExport`
- `App\Models\EmailCampaignMetric`
- `App\Services\EmailMarketing\EmailCampaignPayloadBuilder`
- `App\Services\EmailMarketing\EmailCampaignExportService`
- `App\Http\Controllers\Api\V1\EmailCampaignExportController`

Connectors:

- Generic connector models under `App\Models\Connectors`.
- Generic normalized marketing observations: `App\Models\MarketingObservation`, `App\Models\MarketingObservationDimension`, `App\Models\MarketingAttribution`.
- Connector sync engine: `App\Services\DataConnectors\ConnectorSyncEngine`.
- Provider config: `config/data_connectors.php`.
- GA4 adapters: `App\Services\DataConnectors\GoogleAnalytics4\GoogleAnalytics4DatasetDiscoveryAdapter`, `GoogleAnalytics4ReportingSyncAdapter`.
- GSC adapters: `App\Services\DataConnectors\GoogleSearchConsole\GoogleSearchConsoleDatasetDiscoveryAdapter`, `GoogleSearchConsoleSearchAnalyticsSyncAdapter`.
- LinkedIn analytics adapters: `App\Services\DataConnectors\LinkedIn\LinkedInDatasetDiscoveryAdapter`, `LinkedInAnalyticsSyncAdapter`.

### Source Extraction, URL Normalization, Fetch, Fallback, Fingerprint

Reusable URL and fetch layers:

- Analytics URL identity: `App\Support\Analytics\AnalyticsUrlKey`.
- Page Intelligence URL identity: `App\Services\PageIntelligence\PageUrlNormalizer` and `PageIdentityResolver`.
- Public web safety boundary: `App\Services\PageIntelligence\PageCrawlerSafetyService`.
- Legacy wrapper for older source flows: `App\Services\PublicWeb\PublicWebSafetyService`.

Extraction/fetch layers:

- Durable Page Intelligence fetch: `App\Services\PageIntelligence\PageFetcher`.
- Durable Page Intelligence extraction: `App\Services\PageIntelligence\PageContentExtractor`.
- Existing source briefing fetch: `App\Services\SourceBriefing\UrlSourceFetcher`.
- Existing article extraction: `App\Services\SourceBriefing\ArticleContentExtractor`.
- Existing source URL extraction with fallbacks: `App\Services\SourceExtraction\SourceUrlExtractor`.

Fallbacks:

- `SourceUrlExtractor` tries direct fetch, relaxed direct fetch, optional Jina Reader, and optional browser render.
- Browser fallback uses `Spatie\Browsershot\Browsershot` only when `SOURCE_BROWSER_ENABLED` is true.
- New durable website inventory fetches should use Page Intelligence fetch/extraction, not add another fetcher.

Fingerprint/hash functionality:

- `AnalyticsEvent.event_hash` dedupes event rows.
- `AnalyticsUrlKey` creates deterministic host/path URL keys.
- `PageUrlNormalizer` hashes first-seen and canonical URLs.
- `PageFetcher` stores `raw_html_hash`.
- `PageContentExtractor` stores `main_text_hash` and updates `PageSnapshot.text_hash`.
- `Content.dedupe_fingerprint` exists, but it is semantic/content dedupe and should not be overloaded as the only website inventory fingerprint.
- `SourceExtraction.url_hash` caches legacy source extraction results.

### Sitemap Functionality

Public sitemap generation:

- Routes: `routes/marketing.php` exposes `/sitemap.xml`, `/sitemaps/{name}.xml`, and localized sitemap routes.
- Controller: `App\Http\Controllers\SitemapController`.
- Config: `config/sitemap.php`.
- Generator: `App\Services\Sitemap\SitemapGenerator`.
- Manifest/cache/chunks: `SitemapManifestBuilder`, `SitemapCacheManager`, `SitemapIndexGenerator`, `SitemapChunkGenerator`.
- Sources: `PublishedArticleSitemapSource`, `StaticPagesSitemapSource`, `MarketingPagesSitemapSource`.
- Tests: `tests/Feature/Sitemap/SitemapGenerationTest.php`.

Sitemap ingestion:

- `App\Services\PageIntelligence\Discovery\XmlSitemapDiscoveryAdapter`.
- `App\Services\PageIntelligence\Discovery\MonitoredSourceUrlDiscoverer`.
- `App\Jobs\PageIntelligence\DiscoverMonitoredSourceUrlsJob`.
- `App\Jobs\PageIntelligence\FetchMonitoredPageJob`.

There is also SEO-audit-specific sitemap crawl logic in `App\Services\SeoAudit\SeoAuditCrawlerService`; new durable inventory should not extend that crawler directly.

### Campaign References

- `Campaign` belongs to workspace/site and owns `CampaignContent`, `CampaignDistributionPlan`, `SocialPostVariant`, `SocialPublication`, and `EmailCampaignExport`.
- `CampaignContent` references `content_id` and `source_content_id`, both pointing to `contents`.
- Campaign tracking parameters are stored in `Campaign.metadata.tracking_parameters` and applied by `Campaign::trackedUrl()`.
- Distribution plans are tied to campaign content and distribution channels.

### Social References

- `SocialPostVariant` references `campaign_id`, `campaign_content_id`, `campaign_distribution_plan_id`, and `content_id`.
- `SocialPostVariant::sourceUrl()` resolves from generation prompt context, metadata, or the linked `Content` via `SocialArticleUrlResolver`, then applies campaign or variant UTM tracking.
- `SocialPublication` references `social_post_variant_id`, `campaign_id`, and `campaign_distribution_plan_id`, and stores remote post URL and payload/response snapshots.

### Newsletter, Email Campaign, Collection, Digest

- Newsletter activation exists as campaign content assets, not as a standalone newsletter inventory.
- `CampaignContentAssetType::NEWSLETTER_SNIPPET` and `config/content_assets.php` define newsletter snippets.
- `EmailCampaignPayloadBuilder::assertExportable()` only permits newsletter snippets.
- `EmailCampaignExportService` exports campaign content to the configured provider with an idempotency key and provider response capture.
- Email metrics are stored in `email_campaign_metrics`.

### GA4, Search Console, LinkedIn Enrichment

There are no provider-specific GA4, GSC, or LinkedIn analytics tables/models. The adapters write provider-agnostic connector observations and raw records:

- `MarketingObservation`
- `MarketingObservationDimension`
- `MarketingAttribution`
- `ConnectorRawRecord`
- `ConnectorSyncRun`

Important dimensions already configured:

- GA4: `date`, `pagePath`, `sessionSource`, `sessionMedium`, `sessionCampaign`, `deviceCategory`, `country`, `defaultChannelGroup`.
- GSC: `date`, `query`, `page`, `country`, `device`, `searchAppearance`.
- LinkedIn: `date`, `organization`, `post`, `mediaType`, `campaign`, `content`.

This is the right future enrichment path: attach observations to inventory pages by normalized URL, URL key, campaign/content id, or explicit attribution records.

### Tenant, Authorization, Security, Privacy

- Core tenancy is workspace/site/organization based across `ClientSite`, `Content`, `Campaign`, `AnalyticsSite`, connector accounts/datasets, and Page Intelligence models.
- `BelongsToOrganizationViaWorkspace` and `HasSignalIntelligenceTenancy` are used by Page Intelligence models.
- `App\Policies\MonitoredPagePolicy` gates Page Intelligence access through organization membership and manage permissions.
- `App\Http\Controllers\App\AppAnalyticsSiteController` performs explicit organization checks for analytics site actions.
- `DomainVerificationService` verifies analytics domains using first-party internal-domain config or an `argusly-site-verification` meta tag.
- `PageCrawlerSafetyService` blocks localhost/private/reserved IPs, internal host suffixes, disallowed domains, unsafe redirects, invalid content types, and oversized responses; it respects robots.txt by default.
- Connector credentials are encrypted or vaulted through existing connector services and health/audit logging.

### Roadmap, TODOs, Naming

Relevant accepted docs:

- `docs/architecture/page-intelligence-adr.md`
- `docs/architecture/page-intelligence-roadmap.md`
- `docs/architecture/page-intelligence-hardening.md`
- `docs/analytics-content-metrics.md`
- `docs/connectors.md`
- `docs/connector-production-activation-runbook.md`

The important naming boundary is already documented: `MonitoredPage` is the canonical durable observed/external page asset, and owned content/campaign assets remain in the content/campaign domains. This inventory feature should not introduce `WebsiteContent` as a parallel subsystem unless the `Content` model becomes genuinely unsuitable. Based on this audit, it is suitable with additive fields and a bridge to Page Intelligence.

## 2. Reusable Components

Reuse these directly:

- Existing tracker script, site key, config endpoint, event endpoint, origin middleware, domain verification, payload limits, rate limits, and DNT/sampling behavior.
- `AnalyticsUrlKey` for analytics URL key matching and `PageUrlNormalizer` for Page Intelligence canonical URL identity.
- `AnalyticsEvent`, `ContentMetric`, `AnalyticsRollupDaily`, and existing metrics jobs.
- `Content` as the activatable content asset record.
- `MonitoredPage`, `PageSnapshot`, and `PageContentExtraction` as observed page evidence and extraction records.
- `SubmitMonitoredPageAction` as the storage gate for sitemap, manual, analytics-observed, SERP, GEO, and future discovered URLs.
- `PageCrawlerSafetyService`, `PageFetcher`, and `PageContentExtractor`.
- `Campaign`, `CampaignContent`, `SocialPostVariant`, `SocialPublication`, and `EmailCampaignExport` for activation.
- Generic connector observations for GA4, GSC, and LinkedIn enrichment.
- Existing dry-run backfill command style, queue jobs, rate-limit middleware, health/diagnostic patterns, factories, and Pest tests.

## 3. Gaps And Technical Risks

- There is no analytics-observed URL to `MonitoredPage` discovery job yet.
- There is no `Content` to `MonitoredPage` bridge. That makes activation from observed pages possible only through manual URL/published URL fields today.
- `Content` lacks explicit fields for observed source type, management type, discovery method, original URL, normalized URL, deterministic URL hash, fetch status, external modified/change timestamps, campaign eligibility, and observed-page metadata.
- `ContentSource` lacks explicit values such as `observed`, `cms`, or `external_url`. Reusing `import` or `system` would work technically but would hide product semantics.
- `AnalyticsUrlKey` and `PageUrlNormalizer` do not normalize identically. Analytics drops all query strings and lowercases paths; Page Intelligence keeps first-seen query strings and canonical strips tracking params. A deliberate bridge key is needed.
- Page Intelligence extracts structured data into `structured_data_json`, but schema.org types are not projected to a dedicated field.
- Page Intelligence image extraction currently reads `img[src]`; Open Graph image is not projected as a first-class field.
- JavaScript tracking only supplies lightweight metadata. Meta description, H1, language, schema types, OG image, HTTP status, fingerprint, and change detection require server-side fetch/extraction.
- Default sensitive-path exclusions for visited-page discovery are not centralized.
- `MonitoredPage` is intentionally not an activation asset. Campaign, social, and email flows expect `Content` or `CampaignContent`, so observed pages must be promoted or linked before activation.
- Email/newsletter support exists only as campaign content export. A future digest/collection model is not present.
- SEO audit and source extraction still contain older fetch/extraction code. New inventory work should not increase that divergence.

## 4. Recommended Canonical Data Model

Use a two-layer canonical model:

1. `MonitoredPage` remains the canonical observed page/evidence record.
2. `Content` remains the canonical activatable content asset.
3. Add a small bridge between them instead of a separate website content subsystem.

Recommended bridge:

- New table: `content_page_links`
- Model: `App\Models\ContentPageLink`
- Fields:
  - `id`
  - `workspace_id`
  - `client_site_id`
  - `content_id`
  - `monitored_page_id`
  - `link_type`: `observed_source`, `publication_url`, `activation_target`, `canonical_equivalent`
  - `is_primary`
  - `confidence_score`
  - `metadata`
  - timestamps and soft deletes
- Indexes:
  - `workspace_id`, `client_site_id`
  - `content_id`, `link_type`
  - `monitored_page_id`, `link_type`
  - optional unique primary link per content/link type

Recommended additive `contents` fields for activation records:

- `inventory_source_type`: `argusly_managed`, `observed_analytics`, `manual_external_url`, `cms_connected`, `sitemap_discovered`, `connector_import`
- `management_type`: `managed`, `observed`, `external_reference`, `cms_managed`
- `discovery_method`: `argusly_created`, `js_tracking`, `sitemap`, `manual`, `cms_connector`, `api`, `serp`, `geo_citation`
- `original_url`
- `normalized_url`
- `canonical_url`
- `url_hash`
- `content_fingerprint`
- `http_status`
- `first_seen_at`
- `last_seen_at`
- `last_fetched_at`
- `external_modified_at`
- `external_changed_at`
- `review_status`
- `campaign_eligible`
- `inventory_metadata`

Mapping to required representation:

| Requirement | Current or recommended storage |
| --- | --- |
| workspace and site ownership | `contents.workspace_id`, `contents.client_site_id`; `monitored_pages.workspace_id`, `monitored_pages.client_site_id` |
| source type | recommended `contents.inventory_source_type`; existing `contents.source` remains creation-source enum |
| ownership or management type | recommended `contents.management_type` |
| discovery method | recommended `contents.discovery_method`; also `monitored_pages.source_type` and source metadata |
| original URL | recommended `contents.original_url`; `monitored_pages.first_seen_url` |
| normalized URL | recommended `contents.normalized_url`; Page Intelligence normalization result |
| canonical URL | existing `contents.seo_canonical` plus recommended `contents.canonical_url`; `monitored_pages.canonical_url` |
| deterministic URL hash | recommended `contents.url_hash`; `monitored_pages.canonical_url_hash` |
| title | existing `contents.title`; `page_content_extractions.title`; `monitored_pages.title_current` |
| meta description | existing `contents.seo_meta_description`; `page_content_extractions.meta_description` |
| H1 | existing `contents.seo_h1`; `page_content_extractions.h1` |
| language | existing `contents.language`; `page_content_extractions.language`; `monitored_pages.language_current` |
| page type | existing `contents.type`; `monitored_pages.page_type`; recommended inventory metadata taxonomy |
| Open Graph image | existing `contents.seo_og_image`; recommended Page Intelligence projection from OG metadata |
| schema.org types | existing `contents.schema_type`; recommended projection from `page_content_extractions.structured_data_json` |
| summary/main content reference | existing `ContentVersion`/content body for managed assets; `page_content_extractions.summary`, `main_text_path` for observed pages |
| content fingerprint | existing `contents.dedupe_fingerprint` plus recommended `contents.content_fingerprint`; `page_content_extractions.main_text_hash` |
| HTTP status | recommended `contents.http_status`; `page_snapshots.http_status` |
| first/last seen/fetched | recommended `contents.first_seen_at`, `last_seen_at`, `last_fetched_at`; existing Page Intelligence timestamps |
| external modification detection | recommended `contents.external_modified_at`, `external_changed_at`; `page_snapshots.content_changed`, `monitored_pages.last_changed_at` |
| lifecycle status | existing `contents.lifecycle_stage` |
| review status | recommended `contents.review_status`; existing review/approval fields can remain for editorial workflow |
| campaign eligibility | recommended `contents.campaign_eligible` |
| flexible metadata | recommended `contents.inventory_metadata`; existing metadata JSON across domains |
| campaign/social/newsletter relationships | existing `CampaignContent`, `SocialPostVariant`, `EmailCampaignExport` relationships |

Do not auto-create a `Content` row for every tracked pageview by default. Create or update `MonitoredPage` automatically, then promote to `Content` when:

- the user manually registers or activates the page,
- a sitemap-discovered owned page passes eligibility rules,
- a CMS connector maps the page to managed content,
- a campaign/social/newsletter workflow needs it.

## 5. Recommended Service And Job Architecture

Discovery sources:

1. JavaScript tracking for visited-page discovery and attribution.
2. Sitemap ingestion for broad URL coverage.
3. Server-side Page Intelligence fetch/extraction for enrichment and change detection.

Recommended services/jobs:

- `App\Services\WebsiteContentInventory\ObservedAnalyticsPageDiscoveryService`
  - Reads verified analytics sites and `analytics_events`/`content_metrics`.
  - Filters to `page_type = other_page` and allowed domains.
  - Strips or avoids query strings for privacy.
  - Applies sensitive path/category exclusions.
  - Calls `SubmitMonitoredPageAction` with `sourceType = analytics_observed`.

- `App\Jobs\WebsiteContentInventory\DiscoverObservedAnalyticsPagesJob`
  - Runs per analytics site/client site.
  - Uses overlap protection and conservative batching.

- `App\Services\WebsiteContentInventory\WebsiteContentActivationService`
  - Promotes a `MonitoredPage` into a `Content` activation shell.
  - Copies title, meta description, H1, language, canonical URL, OG image, schema type, summary pointer, fingerprint, and HTTP metadata from the latest extraction/snapshot.
  - Creates `content_page_links`.
  - Does not copy raw HTML into `Content`.

- `App\Console\Commands\BackfillWebsiteContentInventoryCommand`
  - `--dry-run` by default or strongly encouraged.
  - Backfills from analytics observed URLs, sitemap sources, and optional existing `Content.published_url`.
  - Reports counts for skipped sensitive URLs, already linked pages, created monitored pages, and promotion candidates.

- `App\Services\WebsiteContentInventory\WebsitePageEligibilityService`
  - Centralizes sensitive path exclusions, campaign eligibility, indexability, status, and public-page checks.

Reuse Page Intelligence jobs:

- `DiscoverMonitoredSourceUrlsJob`
- `FetchMonitoredPageJob`
- `ExtractPageContentJob`
- existing Page Intelligence analysis/score/signal/alert chain

Avoid:

- new crawler/fetcher services,
- new tracking endpoints,
- new event tables,
- new campaign or social activation tables,
- direct campaign/social/newsletter references to arbitrary URL strings when a `Content` activation record is available.

## 6. Recommended UI Integration

Recommended first UI shape:

- Add an inventory view inside the existing Page Intelligence or content area rather than a standalone module.
- Suggested navigation label: "Content Inventory".
- Primary list should combine:
  - promoted `Content` inventory assets,
  - linked `MonitoredPage` evidence,
  - analytics metrics,
  - fetch/extraction status,
  - review status,
  - campaign eligibility.
- Detail page should show:
  - canonical URL and source URL,
  - title/meta/H1/language/schema/OG image,
  - latest HTTP status and change status,
  - analytics summary,
  - latest extraction summary,
  - linked campaigns/social/email exports,
  - actions: review, exclude, activate, add to campaign, generate social variant, create newsletter snippet, refresh fetch.

Reuse patterns:

- Page Intelligence views under `resources/views/app/page-intelligence`.
- Universal interaction/data-table patterns used by `AppMonitoredPageController`.
- Existing campaign planner and social distribution actions for activation.
- Existing policies and workspace/site filters.

Do not create a marketing landing page for this feature. It is an operational tool.

## 7. Migration And Backfill Strategy

Release-safe path:

1. Add schema only:
   - `content_page_links`
   - additive `contents` inventory fields
   - config for exclusions/eligibility

2. Backfill URL keys and links:
   - Reuse `analytics:backfill-page-classification`.
   - Add `website-content:backfill-inventory --dry-run`.
   - First pass: link existing `Content.published_url` to existing or new `MonitoredPage`.
   - Second pass: submit analytics-observed `other_page` URLs to Page Intelligence.
   - Third pass: create promotion candidates, not automatic content rows unless explicitly configured.

3. Sitemap sources:
   - For verified client sites, create `MonitoredSource` records of `source_type = xml_sitemap`.
   - Use `MonitoredSourceUrlDiscoverer`.

4. Fetch/extract:
   - Queue existing `FetchMonitoredPageJob` and extraction pipeline for eligible public pages.
   - Retain raw HTML according to existing Page Intelligence retention settings.

5. Activation:
   - Create `Content` shells only when the user activates or when product approves auto-promotion rules.
   - Link through `content_page_links`.

## 8. Testing Strategy

Extend these test areas:

- Analytics:
  - `tests/Feature/Analytics/AnalyticsIngestTest.php`
  - `tests/Unit/Analytics/AnalyticsUrlKeyTest.php`
  - coverage for observed-page discovery queueing, query stripping, sensitive path exclusion, and tenant/domain checks.

- Page Intelligence:
  - `tests/Feature/PageIntelligence/PageIntelligenceDiscoveryTest.php`
  - `tests/Feature/PageIntelligence/PageIntelligenceHardeningTest.php`
  - `tests/Unit/PageIntelligence/PageCrawlerSafetyServiceTest.php`
  - `tests/Unit/PageIntelligence/PageIntelligenceArchitectureTest.php`
  - coverage for sitemap ingestion, robots/safety, fetch/extract, OG image/schema projection, content fingerprint, and no new fetcher services.

- Content/inventory:
  - new `tests/Feature/WebsiteContentInventory/*`
  - new `tests/Unit/WebsiteContentInventory/*`
  - factories for `ContentPageLink`.
  - promotion from `MonitoredPage` to `Content` shell.
  - no raw HTML copied into content records.

- Campaign/social/email:
  - campaign content creation from activated inventory content.
  - social variant source URL resolution from inventory content.
  - newsletter snippet export from campaign content.

- Connectors/enrichment:
  - GA4/GSC/LinkedIn observations mapped to content inventory by URL/dimensions.
  - no provider-specific enrichment tables.

- Security/privacy:
  - sensitive route exclusion defaults.
  - allowed-domain and domain-verification boundaries.
  - tenant isolation for `ContentPageLink`, `MonitoredPage`, and `Content`.

Targeted audit tests run during this audit:

`php artisan test tests/Unit/PageIntelligence/PageIntelligenceArchitectureTest.php tests/Unit/Analytics/AnalyticsUrlKeyTest.php tests/Feature/Analytics/AnalyticsScriptControllerTest.php tests/Feature/PageIntelligence/PageIntelligenceDiscoveryTest.php tests/Feature/EmailMarketing/EmailCampaignExportServiceTest.php`

Result: 16 tests passed, 61 assertions.

## 9. Phased Implementation Plan

Phase 1: Foundation and links

- Add inventory config for exclusions and eligibility.
- Add `content_page_links`.
- Add additive inventory fields to `contents`.
- Add model relationships and policy checks.
- Add promotion service from `MonitoredPage` to `Content`.
- Add dry-run backfill command.
- Add tests.

Phase 2: Analytics-observed discovery

- Add observed analytics page discovery service/job.
- Scan verified analytics sites for `other_page` URLs.
- Submit eligible URLs via `SubmitMonitoredPageAction`.
- Add queue rate limits and diagnostics.
- Add UI counters for observed pages.

Phase 3: Sitemap coverage

- Add client-site sitemap source setup.
- Reuse Page Intelligence sitemap discovery.
- Add UI controls to refresh discovery and view diagnostics.

Phase 4: Enrichment and activation

- Fetch/extract eligible observed pages.
- Project OG image and schema types from extraction.
- Promote selected pages into `Content` activation records.
- Wire add-to-campaign, social generation, and newsletter snippet flows.

Phase 5: Connector performance enrichment

- Map GA4/GSC/LinkedIn connector observations to inventory pages.
- Surface analytics/search/social performance on inventory list/detail.
- Add attribution confidence and unmapped-observation diagnostics.

Phase 6: Automation and governance

- Optional auto-promotion rules.
- Change detection workflows.
- Refresh-needed lifecycle transitions.
- Notifications/audit trail for page changes and campaign eligibility.

## 10. First Implementation Phase Files

Expected added files:

- `config/website_content_inventory.php`
- `database/migrations/YYYY_MM_DD_HHMMSS_create_content_page_links_table.php`
- `database/migrations/YYYY_MM_DD_HHMMSS_add_inventory_fields_to_contents_table.php`
- `app/Models/ContentPageLink.php`
- `app/Policies/ContentPageLinkPolicy.php` if direct authorization is needed
- `app/Services/WebsiteContentInventory/WebsitePageEligibilityService.php`
- `app/Services/WebsiteContentInventory/WebsiteContentActivationService.php`
- `app/Console/Commands/BackfillWebsiteContentInventoryCommand.php`
- `database/factories/ContentPageLinkFactory.php`
- `tests/Feature/WebsiteContentInventory/WebsiteContentActivationTest.php`
- `tests/Feature/WebsiteContentInventory/WebsiteContentInventoryBackfillTest.php`
- `tests/Unit/WebsiteContentInventory/WebsitePageEligibilityServiceTest.php`

Expected changed files:

- `app/Models/Content.php`
- `app/Models/MonitoredPage.php`
- `app/Providers/AuthServiceProvider.php` or the local policy registration location if needed
- `app/Providers/AppServiceProvider.php` only if new service bindings are necessary
- `app/Enums/ContentSource.php` if product accepts explicit `observed`/`external_url` source values
- `app/Enums/ContentOriginType.php` if product accepts explicit external/observed origin values
- `app/Services/PageIntelligence/PageContentExtractor.php` for OG image/schema type projection if not handled in the new activation service
- Page Intelligence or content inventory controller/view files for first UI, likely under `app/Http/Controllers/App` and `resources/views/app/page-intelligence` or `resources/views/app/content`
- `routes/app.php` for UI routes
- `routes/console.php` if a scheduled inventory backfill/discovery command is enabled

Files to avoid changing in phase 1 unless a test proves it is necessary:

- `routes/track.php`
- `resources/analytics/argusly.js`
- `App\Http\Controllers\Analytics\AnalyticsEventController`
- connector provider adapters
- campaign/social/email table structure

## 11. Product Decisions And Recommended Defaults

- Auto-create `Content` for every discovered URL?
  - Recommended default: no. Create `MonitoredPage` automatically; promote to `Content` on activation or approved high-confidence rules.

- Should observed website pages live in `Content` or a new table?
  - Recommended default: use `Content` for activation records and `MonitoredPage` for observed evidence. Do not add a separate website content table.

- How should query strings be handled for JS-observed discovery?
  - Recommended default: strip all query strings before submitting to Page Intelligence, unless a future allowlist is configured for non-sensitive route variants.

- Raw HTML retention?
  - Recommended default: reuse Page Intelligence retention; do not store raw HTML for excluded/sensitive paths; never send HTML from JavaScript.

- Sensitive path defaults?
  - Recommended default excludes: `/login`, `/logout`, `/register`, `/password`, `/reset-password`, `/forgot-password`, `/verify`, `/checkout`, `/cart`, `/account`, `/profile`, `/dashboard`, `/admin`, `/wp-admin`, `/wp-login.php`, `/my-account`, `/billing`, `/invoice`, `/orders`, `/settings`, `/api`, `/oauth`, `/sso`, `/auth`, `/portal`, `/private`, `/members`.

- Campaign eligibility?
  - Recommended default: eligible only for verified-domain, public, 200 OK, indexable, non-excluded pages with title and either meaningful extracted text or a manual review override.

- CMS-connected content?
  - Recommended default: create or update `Content` directly from the CMS connector and link it to `MonitoredPage` when the public URL is available.

- External URLs?
  - Recommended default: manually registered external URLs create `MonitoredPage` first; create a `Content` shell only if the user wants activation in campaigns/social/email.

- Schema.org and OG image projection?
  - Recommended default: project schema types and OG image into activation metadata and relevant `Content` SEO fields, while preserving full structured data in `PageContentExtraction`.

- Consent handling?
  - Recommended default: keep existing DNT/sampling behavior and add no new JS payload data. If a CMP gate exists in a client integration, the tracking script should remain behind that gate.

## 12. No Duplicate Subsystem Confirmation

This audit explicitly recommends no duplicate tracking, content, analytics, campaign, social, newsletter, crawler, fetcher, sitemap, or connector subsystem.

The implementation should extend:

- existing analytics tracking and event ingestion for discovery signals,
- existing Page Intelligence for URL identity, fetch, extraction, snapshots, and change evidence,
- existing `Content` for activatable content records,
- existing campaign, social, and email campaign content relationships for activation,
- existing connector observations for GA4, Search Console, and LinkedIn enrichment.

A separate `website_contents` table is not recommended for the first implementation. The only new persistence recommended is a narrow `content_page_links` bridge plus additive inventory fields on `contents`.
