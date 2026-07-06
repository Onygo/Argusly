# Page Intelligence Hardening

Page Intelligence is the canonical crawler, snapshot, extraction, scoring, signal, and alert pipeline for external page observations. This foundation hardening keeps fetch and extraction behavior centralized before new market packs or product workflows are added.

## Fetch Boundary

All Page Intelligence network fetches must pass through `PageCrawlerSafetyService` before any HTTP request is sent. The service normalizes submitted URLs, enforces global and source-level allow and deny domain policy, blocks internal hostnames and non-public IP ranges, fails closed on empty DNS resolution, validates redirect targets, pins guarded HTTP requests to the public DNS resolution where cURL supports it, enforces response-size limits, and checks content types.

`PageFetcher` must pass the relevant `MonitoredSource` into initial URL validation, redirect validation, and response validation. Source-level allow and deny policy is part of the fetch boundary and cannot be bypassed by changing the submitted URL, following a redirect, or relying on an already stored `MonitoredPage`.

RSS and XML sitemap discovery adapters use the same service before fetching feed or sitemap URLs. `SubmitMonitoredPageAction` is the final storage gate for discovered URLs, so RSS item URLs, sitemap item URLs, manual URLs, known-source URLs, SERP result URLs, and GEO citation URLs are rejected before `MonitoredPage` persistence when they violate public-web safety or source policy.

Robots.txt is enforced by default. Disabling robots requires an explicit source-level crawl policy such as `respect_robots_txt: false`; absence of the policy means robots are respected. Robots fetching uses the crawler safety layer for URL normalization, DNS/private IP blocking, guarded request options, redirect validation, size limits, and content-type validation.

Do not introduce new Page Intelligence fetchers or crawler services. Extend `PageCrawlerSafetyService`, `PageFetcher`, or the existing discovery adapter contracts instead.

## Pipeline Boundary

The queue pipeline is orchestrated by `PageIntelligencePipelineOrchestrator`:

`discover -> fetch -> extract -> analyze -> match -> score -> signal -> alert`

Jobs remain idempotent and are protected by overlap locks and host/source rate limiting where they perform or depend on crawl work. Fetch jobs can continue into the snapshot pipeline after a successful fetch, once a durable snapshot ID exists. LLM tracking source-linking runs on the Page Intelligence signal queue with tries, timeout, backoff, and overlap protection because it persists GEO citation page links.

## Storage And Retention

Raw HTML and extracted main text are stored on disk by default. Database rows retain paths, hashes, sizes, and short previews so search, auditing, and dedupe logic do not depend on large long-text columns.

Retention is controlled by:

- `page_intelligence.retention.raw_html_days`
- `page_intelligence.retention.snapshot_days`
- `page_intelligence.retention.serp_observation_days`
- `page_intelligence.retention.geo_observation_days`
- `page_intelligence.retention.alert_days`

Run `php artisan page-intelligence:prune` to apply retention. The command prunes snapshots, large stored artifacts, observations, and alerts, but it does not delete canonical `MonitoredPage` identity records. When snapshot retention removes associated extraction artifacts, `PageContentExtraction` rows are soft-deleted and their artifact path columns are nulled with retention metadata so stale storage paths are not left behind.

LLM tracking run retention remains separate from Page GEO observation retention. `llm_tracking_query_runs.raw_response`, `answer_text`, `normalized_response`, and `answer_json` continue to store the run-level provider/answer data used for tracking analysis. `PageGeoObservation` rows store bounded summaries by default and omit raw answer/provider payloads unless `llm_tracking.geo.retention.*.store_raw_payload` is enabled for the relevant provider.

## Shared Public-Web Safety

Research ingestion, SEO Audit crawling, Source Extraction, Source Briefing, and onboarding-style site scans are not fully migrated into Page Intelligence yet. Until they read from canonical snapshots and extractions, their direct HTTP calls must use `App\Services\PublicWeb\PublicWebSafetyService`, which reuses the Page Intelligence safety boundary for public URL validation, private DNS blocking, guarded request options, and redirect validation.

Remaining migration steps:

- Move durable external page identity creation to `SubmitMonitoredPageAction` where those products need reusable page assets.
- Prefer `PageFetcher` snapshots over ad hoc direct HTML fetches.
- Prefer `PageContentExtraction` for normalized text, links, metadata, and evidence paths.
- Retire duplicate parser/fetcher logic only after each product has equivalent behavior and tests on the Page Intelligence pipeline.

## Ingestion Consumers

Research, SEO Audit, Source Extraction, and Source Briefing should not add new duplicate fetch/extract services for external pages. Their target integration path is:

- Read canonical page identity from `monitored_pages`.
- Read latest durable state from `page_snapshots`.
- Read normalized extraction metadata from `page_content_extractions`.
- Use stored text paths through model helpers instead of reading raw storage paths directly.
- Submit new external page observations through Page Intelligence ingestion actions when a durable monitored page is needed.

Until those flows are fully migrated, new code should prefer consuming Page Intelligence snapshots/extractions over adding direct HTTP fetchers or HTML extraction services. Any unavoidable direct public-web fetch must use the shared public-web safety service.

## Provider Abstractions

SERP and answer-engine integrations are resolved behind provider registries. Existing providers continue to work, and new external providers should register behind those abstractions instead of being called directly from pipeline jobs.
