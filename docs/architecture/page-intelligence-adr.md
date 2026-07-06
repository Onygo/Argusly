# ADR: Page Intelligence Foundation

Date: 2026-07-03

Status: Accepted for planning

Source of truth: `audit/media_monitoring_page_intelligence_architecture_audit_2026-07-02.md`

## Context

Argusly already has reusable foundations for a broader Marketing Intelligence Platform: workspace and site scoping, queue and scheduler infrastructure, Signal Intelligence, LLM/GEO visibility tracking, competitor intelligence, research ingestion, SEO audit crawling, Universal Resources, notifications, recommended actions, campaigns, opportunities, content assets, company profiles, taxonomy, analytics, and reporting-adjacent data.

The architecture audit identifies one major gap: there is no canonical durable page layer. External pages currently appear in product-specific structures such as `signal_feed_items`, `research_sources`, `source_extractions`, `content_sources`, `competitor_content_items`, `seo_audit_pages`, and JSON source payloads on `llm_tracking_query_runs`. Those records are useful within their current domains, but they are not durable enough to become the shared asset for Media Monitoring, Page Intelligence, SERP, GEO, PR Value, research, SEO audit, competitor intelligence, and campaign reporting.

This ADR defines the architectural boundary only. It does not authorize database, UI, or runtime behavior changes by itself.

## Decision

Argusly will introduce Page Intelligence as a canonical foundation around durable external page assets.

The core boundary is:

- `MonitoredPage` is the canonical durable external page asset.
- `PageSnapshot` stores immutable-ish, versioned fetch evidence for a monitored page.
- `PageContentExtraction` stores normalized extracted content for a snapshot.
- Page analysis tables own page-level entities, mentions, topics, sentiment, PR value, SERP observations, GEO observations, scores, and relationships.
- Signal Intelligence remains the event, detection, review, scoring, alerting, and opportunity-promotion layer.
- Research, SEO audit, competitor intelligence, LLM tracking, campaigns, reports, and market packs must reuse the canonical page layer instead of creating separate page universes.

## Non-Goals

This ADR does not implement:

- Database migrations.
- Eloquent models.
- Fetching jobs.
- Extraction jobs.
- SERP provider integrations.
- Answer engine adapters.
- PR value scoring.
- UI routes, tables, drawers, or dashboards.
- Business logic changes.

## Canonical Ownership

### `MonitoredPage`

`MonitoredPage` owns durable page identity for external pages. A page may originate from RSS, XML sitemap, known source crawling, competitor crawling, press rooms, review sites, search results, answer engine citations, campaign backlink discovery, manual submission, API ingestion, or research workflows.

Expected ownership:

- Canonical URL and URL hashes.
- First seen URL and final URL.
- Domain and path.
- Source type and page type.
- Current title, language, publisher, publication date, and crawl/indexability status.
- First seen, last seen, last fetched, and last changed timestamps.
- Dedupe and syndication grouping keys.
- Workspace, organization, optional site, optional source, and future market/campaign relationships.

`MonitoredPage` must not be treated as owned content. Owned articles, campaign assets, and publications remain in the content/campaign domains. A monitored page is an evidence and intelligence asset that can be linked to those domains.

### `PageSnapshot`

`PageSnapshot` owns versioned fetch evidence for a monitored page. It captures what was fetched, when, how, and with which response metadata.

Expected ownership:

- Requested URL, final URL, and canonical URL observed during fetch.
- HTTP status, content type, response headers, and redirect chain.
- Raw HTML storage pointer or body, raw HTML hash, text hash, and change flags.
- Fetch duration, fetcher version, error code, and error message.
- Snapshot number and fetched timestamp.

Snapshots should be append-oriented. They provide evidence for change detection, canonical conflict analysis, extraction provenance, and later scoring explainability.

### `PageContentExtraction`

`PageContentExtraction` owns normalized extraction output for one snapshot.

Expected ownership:

- Extraction method and extractor version.
- Title, meta description, headings, author, publisher, publish date, and language.
- Summary, main text, optional main HTML, word count, character count, estimated tokens, quality score, and content depth score.
- Structured data, images, media, outbound links, internal links, and extraction metadata.

Existing fetchers and extractors should be reused as source material, but future page intelligence code should converge on canonical DTOs such as `DiscoveredUrl`, `PageFetchResult`, `PageExtractionResult`, `PageAnalysisResult`, `SerpObservationResult`, and `AnswerEngineResult`.

## Signal Intelligence Boundary

Signal Intelligence remains downstream of page intelligence. It should not become the canonical page table, and `signal_feed_items` should not be expanded into a full page asset model.

Page analysis may emit or link to:

- `SignalMention`.
- `SignalEvent`.
- `SignalDetection`.
- `SignalScore`.
- `Notification`.
- `RecommendedAction`.
- `OpportunitySignal`.

Signal Intelligence owns review, grouping, severity, status transitions, scoring, detection promotion, and opportunity handoff. Page Intelligence owns fetch evidence, extracted content, page relationships, page-level observations, and page-specific scores.

## Product Domain Boundaries

### Media Monitoring

Media Monitoring should be built on `MonitoredPage`, not one-off mention feeds. Source discovery creates monitored pages; page analysis creates page mentions, entities, sentiment, topics, alerts, recommendations, and Signal Intelligence evidence.

### Research

Research URLs and extracted source material should reuse or link to `MonitoredPage`, `PageSnapshot`, and `PageContentExtraction`. Research-specific notes, project state, and generated outputs remain in the research domain.

### SEO Audit

SEO audit crawling techniques may be reused for discovery and fetching, but `seo_audit_pages` remain per-audit findings. Durable page identity, fetch history, and extracted content belong in the canonical page layer.

### Competitor Intelligence

Competitor content items may continue to store competitor-specific analysis, but new competitor discovery and crawling should create or reuse `MonitoredPage` records first. Competitor-specific analysis should link to the monitored page and relevant snapshot or extraction.

### LLM Tracking and GEO

Existing LLM tracking remains the first GEO foundation. Citations and source URLs from LLM runs should link to `MonitoredPage`. Future page-level GEO observations should record answer engine, provider/model, cited URL/domain, citation position, brand and competitor mentions, sentiment, source attribution, topic ownership, consistency, visibility score, raw payload policy, and retention policy.

Answer engine integrations should use an adapter boundary rather than hard-code provider behavior into page models.

### SERP

SERP tracking should be an observation subsystem that links search results to `MonitoredPage` by normalized URL. Unmatched SERP URLs should create monitored pages with a SERP source type. SERP result snippets are observation evidence; they are not replacements for full page fetches or extracted content.

### PR Value

PR Value should be calculated from page intelligence inputs, not stored as an opaque number. Supported models may include traditional AVE, weighted earned media value, and Argusly PR Value. Each score must persist model key, model version, inputs, breakdown, confidence, and computed timestamp.

## Retention Decision

Raw HTML snapshots must use configurable retention.

The default storage posture should be:

- Store hashes and normalized metadata in the database.
- Store large raw HTML bodies in file or object storage when retained.
- Apply workspace/source/provider-aware retention policies.
- Allow shorter retention or summary-only storage where provider terms, privacy, or compliance require it.
- Preserve enough evidence to explain scores and detections after raw bodies expire.

Answer text and search/AI provider payloads must follow provider-specific retention and quoting constraints. Where full answer storage is not allowed, store summaries, extracted citations, hashes, and provenance metadata.

## Scoring Decision

Page Intelligence scores must be explainable and versioned.

This applies to:

- Page intelligence score.
- Brand visibility score.
- Competitor pressure score.
- Topic relevance score.
- Content depth score.
- Source authority score.
- PR value.
- SERP visibility score.
- GEO visibility score.
- Risk score.
- Opportunity score.

Every persisted score should include:

- Score type.
- Score value.
- Previous score and delta where relevant.
- Model key and model version.
- Input provenance.
- Component breakdown and weights.
- Computed timestamp.
- Scope, such as page, domain, brand, campaign, topic, competitor, market pack, or site.

Scoring formulas may change over time, but historical scores must remain interpretable.

## Consequences

Positive consequences:

- External pages become reusable intelligence assets instead of fragmented records.
- Media Monitoring, SERP, GEO, PR Value, research, SEO audit, competitor intelligence, campaigns, reports, and market packs can share fetch and extraction evidence.
- Signal Intelligence remains focused on reviewable events, detections, scoring, and opportunity promotion.
- Existing infrastructure can be reused without turning any current product-specific table into a universal page table.
- Future UI and reporting can explain why a score, alert, detection, or recommendation exists.

Tradeoffs:

- The canonical page layer introduces additional models and storage volume.
- Raw HTML retention needs deliberate operational policy.
- Existing domains will need migration paths to link to canonical pages over time.
- The architecture requires careful dedupe and syndication handling so pages are centralized without losing source-specific context.

## Implementation Guardrails

- Do not create new product-specific page universes.
- Do not store opaque scores without model version and breakdown.
- Do not treat `signal_feed_items`, `seo_audit_pages`, `research_sources`, `content_sources`, or `competitor_content_items` as canonical page identity.
- Do not make raw HTML retention implicit or permanent by default.
- Do not expose snapshots and extractions as Universal Resources unless a concrete UI need exists.
- Keep `MonitoredPage` as the first Universal Resource candidate once runtime implementation begins.

## Verification for This ADR

This ADR is documentation only. The current change set must not include database, UI, or business logic changes.
