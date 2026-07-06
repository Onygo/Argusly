# Page Intelligence Foundation Roadmap

Date: 2026-07-03

Source of truth: `audit/media_monitoring_page_intelligence_architecture_audit_2026-07-02.md`

## Purpose

This roadmap sequences the future Page Intelligence foundation for Argusly. It documents architecture and delivery order only. No database, UI, or business logic changes are included in this documentation change.

The roadmap follows the accepted boundary from `docs/architecture/page-intelligence-adr.md`:

- `MonitoredPage` is the canonical durable external page asset.
- `PageSnapshot` stores versioned fetch evidence.
- `PageContentExtraction` stores normalized extracted content.
- Signal Intelligence remains the event, detection, review, scoring, alerting, and opportunity-promotion layer.
- Research, SEO audit, competitor intelligence, LLM tracking, campaigns, reports, and market packs reuse the canonical page layer.
- Raw HTML snapshots use configurable retention.
- Scores are explainable and versioned.

## Current Architecture Baseline

Reusable foundations already present in Argusly:

- Workspace, organization, and site scoping.
- Queue and scheduler foundations.
- Signal Intelligence sources, feed items, mentions, events, detections, scores, and processing runs.
- LLM/GEO tracking runs, citation analysis, provider-aware scoring, aggregates, and Signal Intelligence adapter.
- Competitor intelligence runs, content items, topic signals, and opportunities.
- Research and source briefing fetch/extraction flows.
- SEO audit crawling, sitemap discovery, redirects, canonical checks, and page-level audit findings.
- Universal Resource Registry, Action Registry, Drawer metadata, and application shell patterns.
- Notifications, Recommended Actions, campaigns, Opportunity Intelligence, company profiles, taxonomy, analytics, and reporting-adjacent data.

Architecture gap:

- External pages are represented separately in `signal_feed_items`, `research_sources`, `source_extractions`, `content_sources`, `competitor_content_items`, `seo_audit_pages`, and LLM tracking source JSON. None of these should become the canonical page universe.

## Delivery Principles

- Centralize page identity before building broad product features.
- Reuse existing fetch, extraction, scoring, Signal Intelligence, notification, recommendation, and Universal Resource patterns.
- Keep existing product domains intact while linking them to canonical pages.
- Prefer append-oriented evidence and versioned analysis over mutable opaque state.
- Preserve provider, model, extractor, fetcher, and scoring provenance.
- Add UI only after the data model and evidence lifecycle are stable.
- Keep raw HTML retention configurable from the first runtime implementation.

## Phase 0: Architecture Foundation

Status: Documentation only.

Deliverables:

- Finalize naming around `MonitoredPage`, `PageSnapshot`, and `PageContentExtraction`.
- Accept the ADR for canonical page asset boundaries.
- Define DTO contracts:
  - `DiscoveredUrl`
  - `PageFetchResult`
  - `PageExtractionResult`
  - `PageAnalysisResult`
  - `SerpObservationResult`
  - `AnswerEngineResult`
- Define queue names, retry posture, rate-limit strategy, and source-level crawl policies.
- Decide configurable raw HTML retention defaults.
- Define score versioning conventions and required breakdown fields.

Acceptance:

- Architecture can be implemented without changing current runtime behavior.
- Existing domains have a clear reuse path.
- No product-specific page table is designated canonical.

## Phase 1: Canonical Page Asset MVP

Goal: Create the durable page foundation.

Future deliverables:

- Migrations and models for:
  - `monitored_sources`
  - `monitored_pages`
  - `page_snapshots`
  - `page_content_extractions`
- `PageUrlNormalizer`.
- Canonical `PageFetcher` wrapper around existing fetch patterns.
- Canonical `PageContentExtractor` wrapper around existing extraction patterns.
- `FetchMonitoredPageJob`.
- Manual URL submission command or internal service.
- Minimal internal route only if needed for verification.

Acceptance:

- A submitted URL creates or updates one `MonitoredPage`.
- Fetching creates a versioned `PageSnapshot`.
- Extraction creates a `PageContentExtraction`.
- Raw HTML is stored according to retention configuration.
- Fetcher and extractor versions are persisted.
- No Signal Intelligence behavior is required yet.

## Phase 2: Discovery Adapters

Goal: Feed monitored pages from multiple source types.

Future deliverables:

- Discovery adapter contract returning `DiscoveredUrl`.
- RSS adapter.
- XML sitemap adapter.
- Known source crawl adapter.
- Manual URL adapter.
- Competitor website adapter.
- Press room and blog index adapter.
- Source scheduling command or job.
- Source run diagnostics through existing processing-run concepts where appropriate.

Acceptance:

- Due sources discover URLs on cadence.
- New URLs become monitored pages.
- Changed or high-priority pages enqueue fetches.
- Source failures record diagnostics and do not block unrelated sources.

## Phase 3: Page Analysis and Signal Emission

Goal: Convert page evidence into reviewable intelligence.

Future deliverables:

- Page analysis models for:
  - `PageEntity`
  - `PageMention`
  - `PageTopic`
  - `PageSentiment`
  - `PageScore`
- Deterministic entity matching seeded from workspace, company profile, competitors, campaigns, products, services, and market configuration.
- Optional feature-gated LLM-assisted extraction for richer entities, quotes, claims, topics, and sentiment.
- Signal emission service linking page evidence to Signal Intelligence.

Acceptance:

- Page mentions can create or link to `SignalMention`.
- Page findings can emit `SignalEvent`.
- Existing Signal Intelligence detections can group page-generated events.
- Essential sentiment and metrics are mirrored into Signal Intelligence where needed.
- Page scores include model key, version, input provenance, and breakdown.

## Phase 4: Competitor, Campaign, Brand, and Market Matching

Goal: Connect pages to commercial context.

Future deliverables:

- `PageCompetitorMatch`.
- `PageCampaignMatch`.
- `PageBrandMatch`.
- `PageMarketPackMatch`.
- Competitor crawl integration that discovers canonical monitored pages first.
- Campaign backlink, landing-page, and press-release discovery hooks.
- Opportunity Intelligence mapping from page evidence.

Acceptance:

- Competitor pages are canonical monitored pages before competitor-specific analysis is attached.
- Campaign reporting can reference discovered coverage, backlinks, and mentions.
- Brand and competitor matches include evidence and confidence.
- Opportunity Intelligence can consume page-generated Signal Intelligence evidence.

## Phase 5: PR Value

Goal: Score page-level earned media value with explainability.

Future deliverables:

- `PagePrValue`.
- Pluggable `PrValueModel` interface.
- Model implementations:
  - Traditional AVE.
  - Weighted Earned Media Value.
  - Argusly PR Value.
- Source authority inputs.
- Page visibility, SERP visibility, GEO visibility, sentiment, brand prominence, topic relevance, campaign relevance, backlink value, social amplification, content depth, geographic relevance, industry relevance, media type, and competitor context inputs.

Acceptance:

- A page can have multiple PR value model outputs.
- Each output stores model key, model version, value, confidence, breakdown, and computed timestamp.
- PR value can update when SERP, GEO, sentiment, source authority, or campaign relevance changes.

## Phase 6: SERP Tracking

Goal: Model search result visibility as page-linked observations.

Future deliverables:

- SERP provider adapter abstraction.
- SERP query and query-set models, or a `PageSerpObservation` MVP before extracting query sets.
- SERP visibility scorer.
- SERP aggregate builder.
- Signal events for ranking gains, losses, and competitor displacement.

Acceptance:

- Query rankings can be tracked over time.
- Search results link to monitored pages by normalized URL.
- Unmatched SERP URLs create monitored pages with a SERP source type.
- SERP snippets are stored as observation evidence, not treated as full page content.
- SERP visibility scores are explainable and versioned across page, domain, brand, campaign, topic, competitor, and market pack scopes.

## Phase 7: GEO and AI Visibility Page Observations

Goal: Link AI answer visibility to canonical pages.

Future deliverables:

- `AnswerEngineAdapter` abstraction.
- Adapters around current LLM tracking providers and future answer engines where available.
- Link existing LLM tracking source/citation URLs to monitored pages.
- `PageGeoObservation`.
- GEO visibility scorer.
- Cross-engine aggregate builder.
- Provider-specific answer retention policy handling.

Acceptance:

- AI answer cited URLs become or link to monitored pages.
- Page-level GEO visibility can be tracked over time.
- Existing LLM tracking can keep current behavior while Page Intelligence adds page-level depth.
- Full answer text, answer summaries, citations, and raw payloads follow provider-specific retention rules.
- GEO scores include model key, version, input provenance, and breakdown.

## Phase 8: Alerts, Recommendations, and UX

Goal: Let users review and act on high-value page intelligence.

Future deliverables:

- `AlertRule`.
- `PageAlert`.
- Alert evaluation job.
- Notification integration.
- Recommended Action mapping.
- Universal Resource registration for `monitored_page`.
- Later Universal Resource registration for `monitored_source`, `page_alert`, `serp_query`, `serp_observation`, and `market_pack` where needed.
- DataTables and drawers for monitored pages, sources, alerts, SERP observations, GEO observations, and mentions.

Acceptance:

- Users can review page alerts.
- High-priority alerts can create notifications and recommended actions.
- Existing Signal Intelligence review remains the primary detection workflow.
- Snapshots and extractions remain internal unless a concrete UI need requires exposing them.

## Phase 9: Operational Market Packs

Goal: Make Page Intelligence configurable by industry and market.

Future deliverables:

- Operational market pack tables for sources, competitors, themes, keywords, metrics, dashboard templates, report templates, alert templates, prompt templates, scoring models, and installations.
- Seed packs for automotive, telecom, energy, manufacturing, logistics, healthcare, finance, government, and retail.
- Installation and workspace customization flow.
- Defaults informed by existing public market configuration where useful.

Acceptance:

- Page Intelligence is not tied to one market.
- Workspaces can install and customize source, competitor, theme, keyword, alert, scoring, dashboard, and report defaults.
- Discovery, scoring, and reporting can inherit pack defaults while preserving tenant-specific overrides.

## Cross-Cutting Requirements

### Retention

- Raw HTML snapshot retention must be configurable.
- Store hashes and normalized metadata even when raw HTML expires.
- Support provider/source/workspace retention policies.
- Preserve enough evidence to explain scores, detections, alerts, and recommendations.

### Fetch Safety

- Continue blocking localhost, private IPs, and internal network targets.
- Use source-level rate limits and crawl policies.
- Capture response headers, redirect chains, status codes, errors, and fetcher version.

### Deduplication

- Use layered dedupe: normalized URL hash, final URL hash, content hash, syndication group key, and canonical conflict detection.
- Do not delete syndicated articles by dedupe. Keep page records and group them for reporting.

### Explainability

- Persist score model key, version, input provenance, component breakdown, weights, computed timestamp, previous score, and delta.
- Avoid opaque single-number scoring for PR Value, SERP, GEO, risk, opportunity, and visibility metrics.

### Domain Reuse

- Research, SEO audit, competitor intelligence, and LLM tracking must link to canonical pages when runtime implementation begins.
- Existing product-specific tables may keep their domain state, but should not own external page identity.
- Signal Intelligence remains the event, detection, review, scoring, and opportunity-promotion layer.

## First Runtime Slice Recommendation

The audit recommends the safest first implementation slice:

1. Add canonical page tables.
2. Add manual URL submission service or command.
3. Reuse `UrlSourceFetcher` and `ArticleContentExtractor` through a new wrapper.
4. Store raw snapshot and extraction.
5. Create deterministic brand, competitor, and topic mentions.
6. Emit Signal Events.
7. Show page-generated evidence in existing Signal Intelligence.

This slice is intentionally deferred. The current deliverable is architecture documentation only.

## Documentation Verification

For this documentation change, verification should confirm:

- Only docs are added.
- No migrations are added.
- No UI routes or views are changed.
- No runtime business logic is changed.
- `php artisan test` passes.
