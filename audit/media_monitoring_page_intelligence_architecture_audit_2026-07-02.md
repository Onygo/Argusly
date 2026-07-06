# Media Monitoring and Page Intelligence Architecture Audit

Date: 2026-07-02

## Scope

This audit extends Argusly's Media Monitoring architecture with page-level intelligence, SERP scoring, and GEO/AI visibility scoring.

No code has been implemented. This document audits the current Laravel application and proposes an implementation roadmap that reuses existing architecture instead of building a parallel monitoring product.

## Executive Summary

Argusly already has strong reusable infrastructure for a broader Marketing Intelligence Platform:

- Multi-tenant workspace/site scoping.
- Queue and scheduler foundations.
- Signal Intelligence domain objects for sources, feed items, mentions, events, detections, scores, and processing runs.
- LLM/GEO visibility tracking with provider-aware query runs, citation analysis, source classification, scoring, and aggregates.
- Competitor intelligence with competitor content items, topic signals, and opportunities.
- Source briefing and research ingestion flows that can fetch and extract page content.
- SEO audit crawling and sitemap discovery.
- Universal Resource Registry, Action Registry, Drawer metadata, and application shell patterns.
- Notification and Recommended Action infrastructure.
- Campaigns, Opportunity Intelligence, Content assets, Brand/Company profiles, Taxonomy, Analytics, and Reports-adjacent data.

The major gap is a canonical page intelligence layer. Today, external pages are represented in several product-specific tables:

- `signal_feed_items`
- `research_sources`
- `source_extractions`
- `content_sources`
- `competitor_content_items`
- `seo_audit_pages`
- `llm_tracking_query_runs.sources`

Those objects are useful, but none is durable enough to be the single reusable intelligence asset requested here. The recommended architecture is to add a new `MonitoredPage` aggregate with versioned `PageSnapshot` records and analysis child tables, then make Signal Intelligence, Research, Competitor Intelligence, SEO Audit, LLM Tracking, Campaigns, Reports, and Market Packs consume that canonical page layer.

## Current Application Audit

### Tenancy and Scope

Argusly is already workspace/site scoped. Most relevant models carry `workspace_id`, `client_site_id`, and sometimes `organization_id`. This is the right tenancy shape for Media Monitoring.

Reusable:

- `Workspace`, `Organization`, `ClientSite`
- `BelongsToOrganizationViaWorkspace`
- `HasSignalIntelligenceTenancy`
- existing policies and feature gates

Gap:

- Page monitoring needs explicit monitored scope beyond owned sites: source domains, competitor domains, press rooms, forums, review sites, trade publications, and campaign landing pages. Those should still belong to a workspace and may optionally attach to a `client_site_id`, market pack, campaign, competitor, or brand.

### Signal Intelligence

Existing tables:

- `signal_sources`
- `signal_feed_items`
- `signal_entities`
- `signal_mentions`
- `signal_events`
- `signal_detections`
- `signal_detection_links`
- `signal_scores`
- `signal_processing_runs`

Existing services and commands:

- `SignalSourceRegistry`
- `FeedItemNormalizer`
- `MentionExtractionService`
- `SignalEntityResolver`
- `SignalEventIngestor`
- `SignalScoringEngine`
- `BrandMonitoringDetectionService`
- `CompetitorMonitoringDetectionService`
- `TrendDetectionService`
- `RiskDetectionService`
- `LlmTrackingSignalAdapter`
- `signal-intelligence:process-feed-item`
- `signal-intelligence:detect`

What works:

- The downstream evidence-to-detection loop is already a strong fit for media monitoring.
- Sources have type, config, status, failure count, timestamps, and creator.
- Mentions already model entity name/key/type, context, sentiment, confidence, position score, URL, observed timestamp, source reference, and dedupe hash.
- Events and detections already support severity, status, scoring, evidence, metrics, recommendations, and promotion to Opportunity Intelligence.

What is missing:

- Actual RSS polling / website polling / source crawling implementation is not complete.
- `signal_feed_items` stores normalized item text, but not full HTML, response metadata, canonical conflict metadata, content extraction versions, media assets, outbound links, structured data, or page-level scores.
- Mention extraction is deterministic text matching; it is a reasonable first pass but not enough for page-level entity, quote, product, and competitor analysis.
- `SignalSourceType` lacks explicit page intelligence source types such as `xml_sitemap`, `serp`, `competitor_crawl`, `press_room`, `review_site`, `social_url`, `campaign_backlink`, and `answer_engine_citation`.

Decision:

- Keep Signal Intelligence as the review, alerting, scoring, and opportunity-promotion layer.
- Do not turn `signal_feed_items` into the canonical page table.
- Make page analysis emit `SignalMention`, `SignalEvent`, `SignalDetection`, `SignalScore`, `Notification`, and `RecommendedAction` rows.

### LLM/GEO Visibility

Existing tables:

- `llm_tracking_query_sets`
- `llm_tracking_queries`
- `llm_tracking_query_runs`
- `llm_tracking_aggregates`
- `llm_authority_entity_candidates`
- `llm_authority_learnings`
- `llm_source_rules`

Existing services and jobs:

- `LlmVisibilityTrackingService`
- `LlmTrackingAnalyzer`
- `LlmVisibilityScoreCalculator`
- `LlmTrackingAggregateBuilder`
- `LlmAuthorityEntityExtractor`
- `LlmAuthorityCandidateService`
- `RunLlmTrackingQueryJob`
- `BuildLlmTrackingAggregatesJob`
- `llm-tracking:dispatch-daily`

What works:

- Provider-aware runs already exist.
- Query variants, prompt variant intent, provider/model keys, detected domains, sources, brand hits, competitor hits, URL hits, citation ranking, answer text, sentiment/context label, presence score, position score, citation score, owned/earned visibility, competitor pressure, model confidence, real-world gap, and AI visibility score are already modeled.
- Scheduler dispatches due runs hourly.
- The aggregate builder rolls results up by day/week/month, provider, model, locale.
- `LlmTrackingSignalAdapter` turns LLM tracking results into Signal Intelligence evidence.

What is missing:

- Answer engine adapters are currently LLM-provider oriented, not answer-engine oriented. Google AI Overviews, Perplexity, Copilot, Claude web, Gemini app/web, and future engines need a stable adapter contract.
- Citations and sources are stored as JSON arrays on query runs, not linked to canonical monitored pages.
- There is no `PageGeoObservation` table for per-page AI visibility over time.
- Answer text retention/compliance rules are not separated by provider/engine.
- Query intent, market pack, and campaign relationships are not first-class in LLM tracking.

Decision:

- Reuse the LLM tracking run and scoring machinery as the first GEO implementation.
- Add a page-level observation table that links runs/citations to `MonitoredPage`.
- Introduce an `AnswerEngineAdapter` interface around current providers and future answer engines.

### SEO, Crawling, Search Console, and SERP

Existing:

- `SeoAuditCrawlerService` discovers pages from `sitemap.xml` or BFS and extracts title, meta description, canonical, robots, h1, word count, internal links, broken links, status, fetched URL, fetch diagnostics, and page type.
- `seo_audits`, `seo_audit_pages`, and `seo_audit_issues` store audit results.
- `SearchConsoleIndexationSyncService` and `ContentIndexationHealthService` store indexation health for owned `Content`.
- Scheduled SEO jobs validate sitemap entries, canonical integrity, redirect chains, duplicate canonical issues, and Search Console indexation.

What works:

- Sitemap discovery and basic page crawling are reusable.
- Fetch diagnostics, redirect handling, local fallback, and HTML extraction routines already exist.
- Owned content indexation health has useful primitives for canonical/indexability signals.

What is missing:

- There is no SERP tracking domain for query, keyword, engine, locale, device, market, position, result type, snippet, SERP features, competitor overlap, or ranking history.
- Search Console support is indexation-health oriented and does not ingest ranking/click/impression data.
- SEO audit pages are per-audit findings, not durable monitored pages.

Decision:

- Reuse crawler/extraction techniques from SEO Audit, but store discovered/fetched external pages in the new page layer.
- Build SERP tracking as a new observation subsystem, not inside `seo_audit_pages`.

### Source Fetching and Extraction

Existing:

- `UrlSourceFetcher` fetches public HTML with timeout, retries, safety checks, content-type checks, and response-size guardrails.
- `ArticleContentExtractor` extracts title, meta description, canonical URL, h1/h2/h3 outline, readable text, language, publish date, author, summary, extraction method, quality score, and estimated tokens.
- `SourceIngestionService` normalizes/fetches research URLs and stores text in `research_sources`.
- `content_sources` stores source URL, final URL, source title, language, extracted text, outline, analysis, generation payload, and generation state.
- `source_extractions` stores URL hash, final URL, title, author, publish date, language, summary, extracted text, word count, chars, estimated tokens, method, status, metadata, fetched timestamp, and expiry.
- Onboarding/Workspace Intelligence crawlers fetch a homepage and priority pages.

What works:

- The app already has most pieces for page fetching and main-content extraction.
- Multiple fetchers include host safety checks and timeout handling.

What is missing:

- There are too many fetch/extraction implementations with different return shapes.
- Raw HTML snapshots are generally not persisted.
- Images/media, outbound links, structured data, canonical conflicts, redirect chains, response headers, content hash versions, publisher/source authority, and extraction provenance are not modeled consistently.
- `source_extractions` uses `tenant_id`, not the app's current `workspace_id`/`organization_id` pattern.

Decision:

- Consolidate page fetching/extraction into a `PageIntelligence` pipeline.
- Keep existing fetchers as source material, but introduce one canonical `PageFetchResult` and `PageExtractionResult` DTO shape.

### Competitor Intelligence

Existing:

- `site_competitors`
- `competitor_intelligence_runs`
- `competitor_content_items`
- `competitor_topic_signals`
- `competitor_content_opportunities`

What works:

- Competitor content already stores URL, hash, title, meta description, excerpt, normalized text, content type/format, query intent, funnel stage, topics, entities, SEO/AEO patterns, and normalized payload.
- Topic signals and competitor opportunities are directly useful for page-level market intelligence.

What is missing:

- Competitor crawls are not backed by a durable source/page snapshot model.
- Competitor content is competitor-specific and cannot represent news articles, forums, reviews, press rooms, or neutral industry sources.
- Page versioning/change detection is missing.

Decision:

- Keep `competitor_content_items` for competitor-specific analysis where useful, but link it to `MonitoredPage`.
- New competitor crawling should discover canonical pages first, then emit competitor content analysis rows and signals from those pages.

### Content, Campaigns, and Opportunity Intelligence

Existing:

- `Content`, `ContentPublication`, `ContentMetric`, `ContentAiVisibility`, `ContentIndexationHealth`, `ContentRecommendation`
- `Campaign`, `CampaignContent`, `CampaignCluster`, `CampaignDistributionPlan`
- `Opportunity`, `OpportunitySignal`, `OpportunityExecutionPlan`, `RecommendedAction`

What works:

- The opportunity and recommendation stack can receive page-level findings.
- Campaign content and distribution objects can be linked to page evidence.
- Content assets already include lifecycle, performance, SEO, AI visibility, and publication metadata.

What is missing:

- No `PageCampaignMatch`, `PageCompetitorMatch`, or page-to-content relationship exists.
- Campaign backlink discovery and landing-page monitoring are not modeled.
- PR value per page does not exist.

Decision:

- Treat external monitored pages as evidence and intelligence assets, not as `Content`.
- Link pages to content/campaign/opportunity objects through explicit match tables.

### Alerts and Notifications

Existing:

- `notifications` table and `NotificationService`
- `RecommendedActionEngine`
- Signal detections have statuses and promotion flow.

What works:

- Notification delivery and bell UI can be reused.
- Recommended Actions can surface "what to do next" from page-level findings.
- Signal Detection status transitions support review, dismiss, resolve, promote.

What is missing:

- No `AlertRule` or `PageAlert` model for saved page-level thresholds.
- Notification types are intentionally generic (`action_required`, `system`, `announcement`).
- No user-configurable media alert templates.

Decision:

- Add page-level alert rules and alert events, then send notifications through the existing `NotificationService`.
- Also create `RecommendedAction` rows for high-value page insights.

### Universal Interaction Framework

Existing:

- Universal Resource Registry
- Universal Action Registry
- Drawer metadata
- Interaction metadata providers for content, research, site, and signal resources

What works:

- `signal_detection`, `llm_tracking_query`, `seo_audit`, `research_project`, `site`, `content`, and other resources already have a metadata vocabulary.
- This is the right way to expose monitored pages in search, drawers, command palette, notifications, and AI explanations.

What is missing:

- No `monitored_page`, `page_snapshot`, `monitored_source`, `page_alert`, or `serp_observation` resource types.
- No page drawer, page action keys, or page DataTable adoption yet.

Decision:

- Add `monitored_page` as a Universal Resource once the model and route exist.
- Keep lower-level snapshots/extractions as non-resource internals unless a UI needs them.

### Market Packs

Existing:

- `config/argusly_markets.php` provides public industry page definitions for IT/SaaS, consulting, recruitment, telecom, logistics, manufacturing, energy, healthcare, finance, government, retail, and automotive-style market narratives.
- `CompanyIntelligenceProfile` stores market category, products/services, regions, personas, topics, authority areas, strategic keywords, query intents, and competitors.
- `TaxonomySet` / `TaxonomyItem` provide tenant-scoped taxonomy for intents and audiences.

What works:

- There is already a market configuration mindset.
- Company profiles and taxonomy can seed monitoring topics, competitor lists, and query sets.

What is missing:

- No first-class `MarketPack` model with sources, competitors, themes, metrics, dashboards, reports, alert templates, AI prompts, and scoring models.
- Current market config is public marketing content, not operational intelligence configuration.

Decision:

- Introduce operational Market Packs separately from public market pages.
- Allow public config to inform defaults, but store runtime market-pack installation and customization in database tables.

## Recommended Architecture

### Architecture Principle

Make every external page a durable reusable intelligence asset.

Page monitoring should not be only mention monitoring. A monitored page should become reusable evidence for:

- Media monitoring
- Brand monitoring
- Competitor intelligence
- SERP visibility
- GEO/AI visibility
- Campaign measurement
- PR value
- Research projects
- Opportunity detection
- Recommended actions
- Reports and dashboards

## Proposed Domain Model

### Core Source and Page Tables

#### `MonitoredSource`

Purpose:

- Represents a source that can produce pages or observations.

Examples:

- RSS feed
- XML sitemap
- news domain
- blog index
- forum board
- press room
- competitor website
- review site
- SERP provider
- answer engine
- social/shared URL collector
- campaign backlink source
- manual submission
- API ingestion source

Recommended fields:

- `id`
- `organization_id`
- `workspace_id`
- `client_site_id` nullable
- `market_pack_id` nullable
- `source_type`
- `name`
- `base_url`
- `domain`
- `status`
- `trust_level`
- `authority_score`
- `polling_frequency`
- `crawl_policy_json`
- `fetch_config_json`
- `discovery_config_json`
- `last_discovered_at`
- `last_fetched_at`
- `last_processed_at`
- `failure_count`
- `last_error`
- `created_by`
- timestamps / soft deletes

Reuse:

- Can evolve from or coexist with `signal_sources`.

Recommendation:

- Keep `signal_sources` for Signal Intelligence evidence origin.
- Add `monitored_sources` for page discovery/fetch policy.
- Link `signal_sources.source_ref_type/source_ref_id` to `MonitoredSource` where needed.

#### `MonitoredPage`

Purpose:

- Canonical durable page record.

Recommended fields:

- `id`
- `organization_id`
- `workspace_id`
- `client_site_id` nullable
- `monitored_source_id` nullable
- `canonical_url`
- `canonical_url_hash`
- `first_seen_url`
- `first_seen_url_hash`
- `final_url`
- `domain`
- `path`
- `source_type`
- `page_type`
- `content_type`
- `publisher_name`
- `title_current`
- `language_current`
- `published_at_current`
- `first_seen_at`
- `last_seen_at`
- `last_fetched_at`
- `last_changed_at`
- `crawl_status`
- `indexability_status`
- `dedupe_key`
- `syndication_group_key`
- `metadata_json`
- timestamps / soft deletes

Constraints:

- Unique by `workspace_id + canonical_url_hash`, with fallback on `first_seen_url_hash` when canonical is absent.

#### `PageSnapshot`

Purpose:

- Immutable-ish versioned fetch snapshot.

Recommended fields:

- `id`
- `monitored_page_id`
- `workspace_id`
- `snapshot_number`
- `requested_url`
- `final_url`
- `canonical_url`
- `http_status`
- `content_type`
- `response_headers_json`
- `redirect_chain_json`
- `raw_html_path` or `raw_html`
- `raw_html_hash`
- `text_hash`
- `content_changed`
- `canonical_conflict`
- `fetch_duration_ms`
- `fetched_at`
- `fetcher_version`
- `error_code`
- `error_message`
- timestamps

Storage:

- Prefer file/object storage for raw HTML when large, with hashes in DB.
- Keep text and normalized metadata in DB for queries.

#### `PageContentExtraction`

Purpose:

- Stores the extraction result for one snapshot.

Recommended fields:

- `id`
- `page_snapshot_id`
- `monitored_page_id`
- `workspace_id`
- `extraction_method`
- `extractor_version`
- `title`
- `meta_description`
- `h1`
- `headings_json`
- `author`
- `published_at`
- `publisher`
- `language`
- `summary`
- `main_text`
- `main_html` nullable
- `word_count`
- `char_count`
- `estimated_tokens`
- `content_depth_score`
- `quality_score`
- `structured_data_json`
- `images_json`
- `media_json`
- `outbound_links_json`
- `internal_links_json`
- `metadata_json`
- timestamps

### Analysis Tables

#### `PageEntity`

Purpose:

- Canonical entity references detected on a page.

Recommended fields:

- `monitored_page_id`
- `page_snapshot_id`
- `signal_entity_id` nullable
- `entity_type`
- `entity_name`
- `entity_key`
- `canonical_entity_id`
- `aliases_json`
- `first_position`
- `mention_count`
- `prominence_score`
- `confidence_score`
- `extraction_method`
- `metadata_json`

Use:

- Link to `signal_entities` where possible.

#### `PageMention`

Purpose:

- Page-specific mention instance.

Recommended fields:

- `monitored_page_id`
- `page_snapshot_id`
- `page_entity_id`
- `signal_mention_id` nullable
- `mention_type`
- `entity_type`
- `entity_name`
- `context`
- `quote_text`
- `position_score`
- `prominence_score`
- `sentiment_label`
- `sentiment_score`
- `confidence_score`
- `observed_at`
- `metadata_json`
- `dedupe_hash`

Use:

- Emit or link to `signal_mentions`.

#### `PageTopic`

Purpose:

- Topics/themes classified for a page.

Fields:

- `topic_key`
- `topic_name`
- `theme_key`
- `market_pack_id`
- `relevance_score`
- `confidence_score`
- `model_used`
- `explanation`
- `metadata_json`

#### `PageSentiment`

Purpose:

- Multi-target sentiment, not a flat value only.

Fields:

- `target_type`: page, entity, brand, competitor, topic, quote
- `target_id` nullable
- `target_name` nullable
- `compound_score`
- `label`
- `confidence`
- `model_used`
- `explanation`
- `evidence_json`

#### `PagePrValue`

Purpose:

- PR value per page and per model.

Fields:

- `model_key`: `traditional_ave`, `weighted_earned_media_value`, `argusly_pr_value`
- `score`
- `estimated_value_amount`
- `currency`
- `source_authority`
- `estimated_reach`
- `page_visibility`
- `serp_visibility`
- `geo_visibility`
- `sentiment_factor`
- `brand_prominence`
- `topic_relevance`
- `campaign_relevance`
- `backlink_value`
- `social_amplification`
- `content_depth`
- `geographic_relevance`
- `industry_relevance`
- `media_type_factor`
- `competitor_context`
- `breakdown_json`
- `computed_at`

#### `PageScore`

Purpose:

- General score ledger for pages.

Score types:

- `page_intelligence`
- `brand_visibility`
- `competitor_pressure`
- `topic_relevance`
- `content_depth`
- `source_authority`
- `pr_value`
- `serp_visibility`
- `geo_visibility`
- `risk`
- `opportunity`

Fields:

- `scope_type`
- `scope_id`
- `score_type`
- `score`
- `previous_score`
- `delta`
- `breakdown_json`
- `computed_at`

Can reuse concept from `signal_scores`.

### Observation Tables

#### `PageSerpObservation`

Purpose:

- Records page/domain/brand visibility in search results.

Fields:

- `monitored_page_id` nullable
- `workspace_id`
- `client_site_id` nullable
- `query`
- `query_hash`
- `keyword_id` nullable
- `locale`
- `country`
- `device`
- `search_engine`
- `observed_at`
- `result_type`
- `position`
- `absolute_position`
- `page_url`
- `page_url_hash`
- `domain`
- `title`
- `snippet`
- `serp_features_json`
- `featured_snippet_owned`
- `people_also_ask_owned`
- `news_result`
- `image_result`
- `video_result`
- `local_pack_result`
- `competitor_presence_json`
- `search_volume`
- `keyword_intent`
- `click_potential`
- `visibility_score`
- `raw_payload_json`

#### `PageGeoObservation`

Purpose:

- Records page/brand/source visibility in AI answers.

Fields:

- `monitored_page_id` nullable
- `llm_tracking_query_id` nullable
- `llm_tracking_query_run_id` nullable
- `workspace_id`
- `client_site_id` nullable
- `query`
- `query_hash`
- `answer_engine`
- `provider`
- `model`
- `locale`
- `observed_at`
- `answer_summary`
- `answer_text_path` nullable
- `cited_url`
- `cited_url_hash`
- `cited_domain`
- `citation_position`
- `citation_count`
- `mentioned_brands_json`
- `mentioned_competitors_json`
- `client_cited`
- `competitors_cited`
- `brand_mentioned`
- `sentiment_label`
- `sentiment_score`
- `source_attribution`
- `topic_ownership_score`
- `consistency_score`
- `geo_visibility_score`
- `raw_payload_json`
- `retention_policy`

### Relationship Tables

#### `PageCampaignMatch`

Links pages to:

- `campaign_id`
- `campaign_content_id`
- campaign landing page
- press release
- backlink target
- UTM/campaign markers

Fields:

- `match_type`
- `match_score`
- `evidence_json`
- `observed_at`

#### `PageCompetitorMatch`

Links pages to:

- `site_competitor_id`
- competitor domain
- competitor product/entity
- competitor claim

Fields:

- `match_type`
- `prominence_score`
- `sentiment_score`
- `evidence_json`

#### `PageBrandMatch`

Links pages to:

- workspace brand
- brand profile
- product/service
- `CompanyIntelligenceProfile`

Fields:

- `match_type`
- `prominence_score`
- `sentiment_score`
- `evidence_json`

#### `PageMarketPackMatch`

Links pages to an operational market pack.

Fields:

- `market_pack_id`
- `topic_key`
- `theme_key`
- `relevance_score`
- `evidence_json`

### Alerting Tables

#### `AlertRule`

General reusable rule model, not page-only.

Fields:

- `workspace_id`
- `client_site_id`
- `name`
- `scope_type`
- `scope_id`
- `trigger_type`
- `conditions_json`
- `frequency`
- `cooldown_minutes`
- `severity`
- `notification_channels_json`
- `is_active`

#### `PageAlert`

Stores fired page alerts.

Fields:

- `alert_rule_id`
- `monitored_page_id` nullable
- `page_snapshot_id` nullable
- `signal_event_id` nullable
- `signal_detection_id` nullable
- `type`
- `severity`
- `title`
- `summary`
- `evidence_json`
- `recommendation_json`
- `status`
- `triggered_at`
- `resolved_at`

Use:

- Create `Notification` and/or `RecommendedAction` from high-priority alerts.

## Discovery Architecture

### Discovery Sources

Supported discovery methods should be implemented as adapters:

- RSS feeds
- XML sitemaps
- known source crawling
- search engine result monitoring
- manual URL submission
- competitor website crawling
- press room monitoring
- blog index monitoring
- social/shared URL discovery
- campaign backlink discovery
- API ingestion where available
- AI answer engine citation discovery

### Discovery Adapter Contract

Each adapter should return a common `DiscoveredUrl` DTO:

- `url`
- `source_type`
- `source_ref`
- `discovered_at`
- `published_at` nullable
- `title` nullable
- `summary` nullable
- `author` nullable
- `language` nullable
- `priority`
- `confidence`
- `metadata`

### Discovery Pipeline

1. Select due `MonitoredSource` records by cadence and status.
2. Run source adapter.
3. Normalize URL.
4. Compute URL hash.
5. Upsert `MonitoredPage`.
6. Write discovery evidence to page metadata or a future `PageDiscovery` table.
7. Dispatch fetch job for new/changed/high-priority pages.
8. Record `SignalProcessingRun`.

### Discovery Reuse Decisions

- Reuse `SeoAuditCrawlerService` sitemap/BFS logic as source material.
- Reuse `FeedItemNormalizer` URL normalization concepts.
- Reuse `SignalSourceRegistry` capability model, but add page source capabilities.
- Add first-class source adapter classes instead of putting discovery logic into controllers.

## Fetching and Normalization Pipeline

### Pipeline Stages

1. `DiscoverPageUrlsJob`
2. `FetchMonitoredPageJob`
3. `ExtractPageContentJob`
4. `AnalyzePageEntitiesJob`
5. `ClassifyPageTopicsJob`
6. `AnalyzePageSentimentJob`
7. `CalculatePageScoresJob`
8. `EmitPageSignalsJob`
9. `EvaluatePageAlertsJob`
10. `BuildPageRecommendationsJob`

Stages can be split or merged for MVP, but the data model should preserve this lifecycle.

### Fetch Requirements

The fetcher should capture:

- requested URL
- final URL
- redirect chain
- status code
- content type
- response headers
- raw HTML
- fetch timestamp
- duration
- fetcher version
- robots/indexability signals where available
- error codes/messages

Reuse:

- `UrlSourceFetcher`
- `SeoAuditCrawlerService::fetchPage` patterns
- host safety checks from source briefing/research/onboarding

### Extraction Requirements

The extractor should capture:

- canonical URL
- title
- meta description
- h1/h2/h3 headings
- publication date
- author
- publisher/source
- main content
- images/media
- outbound links
- structured data
- word count/content depth
- language
- dedupe/content hash
- canonical conflicts
- extraction quality

Reuse:

- `ArticleContentExtractor`
- `ContentExtractionService`
- `LanguageDetector`
- `HeadingQualityEvaluator`

### Deduplication Strategy

Use layered dedupe:

1. URL hash: normalized/canonical URL.
2. Final URL hash: after redirects.
3. Content hash: extracted main text.
4. Syndication group key: title + publisher/date + content hash similarity.
5. Canonical conflict detection: canonical points elsewhere or changes over time.

Do not dedupe away syndicated articles. Keep each page record, but group them for reporting.

## Entity and Mention Intelligence

### Entity Sources

Use existing context as seeds:

- Workspace display name
- Company profiles
- Company intelligence profiles
- Brand voices/preferred terminology
- Site competitors
- Market pack competitors/themes/keywords
- Campaign names/landing pages/press releases
- Products and services
- People/journalists/media contacts once modeled

### Detection Methods

Phase 1:

- Deterministic matching using `MentionExtractionService` style candidate matching.
- Entity aliases from CompanyIntelligenceProfile and Market Packs.

Phase 2:

- LLM-assisted extraction for people, brands, companies, products, quotes, claims, and competitors.
- Confidence scoring and model provenance.

Phase 3:

- Entity linking and canonical entity graph.
- Quote-level and claim-level extraction.

### Signal Emission

Page analysis should emit:

- `PageMention`
- linked or created `SignalMention`
- `SignalEvent`
- possible `SignalDetection`
- `RecommendedAction`
- optional `Notification`

## Sentiment Architecture

Sentiment must support:

- whole-page sentiment
- entity-level sentiment
- brand-level sentiment
- competitor-level sentiment
- topic-level sentiment
- quote-level sentiment

Store:

- compound score
- label
- confidence
- model used
- explanation
- evidence snippets
- target entity/topic/quote

Implementation approach:

- Start with deterministic/LLM hybrid classification.
- Keep sentiment in `PageSentiment`.
- Mirror essential sentiment into `SignalMention` and `SignalEvent.metrics` for existing dashboards.

## PR Value Architecture

### Supported Models

1. Traditional AVE
2. Weighted Earned Media Value
3. Argusly PR Value

### Argusly PR Value Factors

- source authority
- estimated reach
- page visibility
- SERP visibility
- GEO visibility
- sentiment
- brand prominence
- topic relevance
- campaign relevance
- backlink value
- social amplification
- content depth
- geographic relevance
- industry relevance
- media type
- competitor context

### Architecture

Use a pluggable `PrValueModel` interface:

- `TraditionalAveModel`
- `WeightedEarnedMediaValueModel`
- `ArguslyPrValueModel`

Each model returns:

- score
- estimated monetary value where supported
- confidence
- breakdown
- input provenance

Persist results in `PagePrValue`.

## SERP Scoring Architecture

### Query Model

Add a reusable SERP query set:

- `SerpQuerySet`
- `SerpQuery`
- `SerpObservation`
- `SerpAggregate`

Or implement as `PageSerpObservation` first and extract query sets later.

Recommended fields for query:

- workspace/site
- query text
- locale/country/device
- intent
- target brand
- target domain
- target pages
- competitors
- topics
- campaign
- market pack
- priority
- frequency

### SERP Observation Support

Track:

- keyword rankings
- query visibility
- page position
- domain position
- featured snippets
- People Also Ask
- video/image/news results
- local pack where relevant
- competitor presence
- page title/snippet shown in SERP
- changes over time
- page gains/losses

### SERP Visibility Score

Score should work at multiple levels:

- page
- domain
- brand
- campaign
- topic
- competitor
- market pack

Recommended formula components:

- ranking position
- keyword intent
- search volume
- topic relevance
- click potential
- SERP feature ownership
- competitor overlap
- ranking stability
- trend momentum

Example weights for v1:

- position score: 30%
- intent/search volume weighted value: 20%
- topic/campaign relevance: 15%
- click potential: 10%
- SERP feature ownership: 10%
- competitor displacement: 10%
- stability/momentum: 5%

Persist the score breakdown so weights can change without losing explainability.

### Integration

- SERP observations should link to `MonitoredPage` by normalized URL.
- Unmatched SERP URLs should create `MonitoredPage` records with `source_type = serp`.
- SERP result snippets should be page evidence, not the full page content.
- SERP gains/losses should create Signal Events.

## GEO and AI Visibility Architecture

### Adapter-Based Answer Engine Model

Introduce `AnswerEngineAdapter`:

- `engineKey()`
- `supportsCitations()`
- `supportsRawAnswerStorage()`
- `run(QueryContext $query): AnswerEngineResult`
- `retentionPolicy()`
- `rateLimitKey()`

Initial adapters:

- Existing LLM tracking provider adapter
- ChatGPT/OpenAI
- Gemini
- Claude
- Perplexity where API/access allows
- Google AI Overviews through approved SERP/API provider if available
- Copilot and future engines as later adapters

### GEO Observation Support

Track:

- query
- answer engine
- provider/model
- answer text or summary where allowed
- cited domains
- cited URLs
- mentioned brands
- mentioned competitors
- sentiment
- source attribution
- ranking/order of citations
- whether the client is cited
- whether competitors are cited
- answer consistency over time
- topic ownership

### GEO Visibility Score

Score components:

- citation presence
- citation position
- brand mention presence
- brand mention prominence
- source authority
- answer sentiment
- competitor displacement
- topical relevance
- query intent
- repeatability across engines
- change over time

Recommended v1 weights:

- brand/page presence: 20%
- citation presence/position: 25%
- answer prominence/context: 15%
- source authority: 10%
- competitor displacement: 10%
- topical/query relevance: 10%
- repeatability across engines: 5%
- trend momentum: 5%

Reuse:

- `LlmVisibilityScoreCalculator`
- `LlmTrackingAnalyzer`
- `LlmTrackingAggregateBuilder`

Gap to close:

- Link `sources` from LLM runs to `MonitoredPage`.
- Store page-level `PageGeoObservation` rows.
- Aggregate by page/domain/brand/topic/campaign/market pack.

## Market Pack Architecture

### Operational Market Packs

Add a database-backed operational market pack layer.

Suggested tables:

- `market_packs`
- `market_pack_sources`
- `market_pack_competitors`
- `market_pack_themes`
- `market_pack_keywords`
- `market_pack_metrics`
- `market_pack_dashboard_templates`
- `market_pack_report_templates`
- `market_pack_alert_templates`
- `market_pack_prompt_templates`
- `market_pack_scoring_models`
- `market_pack_installations`

### Market Pack Provides

Each pack should provide:

- sources
- competitors
- themes
- keywords
- industry metrics
- default dashboards
- default reports
- alert templates
- AI prompts
- scoring models

### Initial Packs

The requested examples should be modeled as operational packs:

- Automotive
- Telecom
- Energy
- Manufacturing
- Logistics
- Healthcare
- Finance
- Government
- Retail

Current `config/argusly_markets.php` can seed public-facing defaults and language, but operational packs should be installable/customizable per workspace.

## UI and Product Fit

### Universal Resources

New resource types:

- `monitored_source`
- `monitored_page`
- `page_alert`
- `serp_query`
- `serp_observation`
- `market_pack`

Priority:

- Add `monitored_page` first.
- Add `monitored_source` when source management UI exists.
- Keep snapshots/extractions internal unless there is a specific UI need.

### Universal DataTable

Recommended tables:

- Monitored Pages
- Sources
- Page Alerts
- SERP Observations
- GEO Observations
- Page Mentions

Reusable filters:

- source type
- domain
- publisher
- page type
- language
- topic
- entity
- sentiment
- PR value range
- SERP score range
- GEO score range
- campaign
- competitor
- market pack
- status
- date range

### Drawers

Recommended drawers:

- Page detail drawer
- Snapshot comparison drawer
- Mention evidence drawer
- SERP observation drawer
- GEO observation drawer
- Alert review drawer

The page detail drawer should show:

- current page metadata
- latest snapshot
- extracted content summary
- entities/mentions
- sentiment
- PR value
- SERP visibility
- GEO visibility
- linked campaigns/competitors/market packs
- signal events/detections
- recommendations

## Alerts and Recommendations

### Alert Examples

- New page mentioning brand.
- Negative brand mention on high-authority source.
- Competitor launches new comparison page.
- Press-room update detected.
- Brand disappears from AI answer for high-priority query.
- Competitor gains citation in AI answer.
- Client page enters/leaves SERP top 3/top 10.
- News page with high PR value discovered.
- Campaign backlink discovered.
- Canonical conflict or syndicated duplicate detected.

### Recommendation Examples

- Respond to high-impact negative mention.
- Create competitor comparison content.
- Update campaign landing page messaging.
- Add FAQ/answer block for topic where competitors dominate.
- Pitch journalist/source because article mentions competitor but not brand.
- Refresh content to regain SERP/GEO visibility.
- Add internal links from owned content to campaign page.

Implementation:

- Page analysis produces `SignalEvent` rows.
- Detection services group them into `SignalDetection`.
- `RecommendedActionEngine` maps high-value detections/page alerts into recommended actions.
- `NotificationService` sends workspace/user notifications.

## Reuse vs New Build Decisions

| Area | Reuse | New |
| --- | --- | --- |
| Source registry | `SignalSourceRegistry` concepts | `MonitoredSource` and source adapters |
| Fetching | `UrlSourceFetcher`, SEO crawler, research fetcher patterns | canonical `PageFetchResult`, raw HTML snapshots |
| Extraction | `ArticleContentExtractor`, onboarding extraction, language detector | `PageContentExtraction`, media/link/schema extraction |
| Mentions | `MentionExtractionService`, `SignalEntityResolver`, `SignalMention` | `PageEntity`, `PageMention`, richer extraction |
| Events/detections | `SignalEvent`, `SignalDetection`, scoring, promotion | page-specific event emitters |
| GEO | LLM tracking runs, analyzer, scorer, aggregates | answer-engine adapters, page-level observations |
| SERP | SEO/Search Console concepts | SERP query/observation/score subsystem |
| Competitors | `SiteCompetitor`, competitor content tables | link to monitored pages and snapshots |
| Campaigns | campaign/content/opportunity models | page-campaign matching |
| Alerts | notifications, recommended actions, signal detections | alert rules and page alerts |
| UI | Universal Resource/Action/Drawer/DataTable foundations | monitored page/source resources and drawers |
| Market packs | public market config, taxonomy, company intelligence | operational market pack models |

## Implementation Roadmap

### Phase 0: Architecture Foundation

Deliverables:

- Finalize naming: `MonitoredPage` vs `IntelligencePage`.
- Add ADR for canonical page asset boundary.
- Define DTO contracts:
  - `DiscoveredUrl`
  - `PageFetchResult`
  - `PageExtractionResult`
  - `PageAnalysisResult`
  - `SerpObservationResult`
  - `AnswerEngineResult`
- Define queue names and rate-limit strategy.
- Decide raw HTML storage retention policy.

No user-facing changes required.

### Phase 1: Canonical Page Asset MVP

Deliverables:

- Migrations/models for:
  - `monitored_sources`
  - `monitored_pages`
  - `page_snapshots`
  - `page_content_extractions`
- `PageUrlNormalizer`
- `PageFetcher`
- `PageContentExtractor` wrapper around existing extraction service
- `FetchMonitoredPageJob`
- manual URL submission command or internal service
- basic page detail admin/app route

Acceptance:

- Submit a URL.
- Fetch HTML.
- Store raw snapshot.
- Extract title/meta/headings/body/date/author/language.
- Create/update one `MonitoredPage`.
- Create versioned `PageSnapshot`.

### Phase 2: Discovery Adapters

Deliverables:

- RSS adapter
- XML sitemap adapter
- known source crawl adapter
- manual URL adapter
- competitor website adapter
- press room/blog index adapter
- source scheduling command/job

Acceptance:

- Sources can discover URLs on cadence.
- New URLs become monitored pages.
- Changed pages produce new snapshots.
- Failed source runs record diagnostics.

### Phase 3: Page Analysis and Signal Emission

Deliverables:

- `PageEntity`
- `PageMention`
- `PageTopic`
- `PageSentiment`
- `PageScore`
- page analysis jobs
- deterministic entity matching from existing brand/competitor/company context
- LLM-assisted extraction as optional feature-gated step
- signal emission service

Acceptance:

- Page mentions create linked Signal Mentions and Signal Events.
- Existing Signal Intelligence dashboard shows page-generated evidence.
- Detections can group page signals.

### Phase 4: Competitor and Campaign Matching

Deliverables:

- `PageCompetitorMatch`
- `PageCampaignMatch`
- `PageBrandMatch`
- competitor page crawl integration
- campaign backlink/landing-page discovery
- Opportunity Intelligence mapping

Acceptance:

- Competitor pages are canonical monitored pages.
- Competitor content items can link to monitored pages.
- Campaign reports can show discovered coverage/backlinks/mentions.

### Phase 5: PR Value

Deliverables:

- `PagePrValue`
- model interface and three model implementations:
  - Traditional AVE
  - Weighted Earned Media Value
  - Argusly PR Value
- source authority inputs
- page visibility inputs
- scoring breakdown UI

Acceptance:

- A page can have multiple PR value models.
- PR value updates when SERP/GEO/sentiment/source authority changes.

### Phase 6: SERP Tracking

Deliverables:

- SERP provider adapter abstraction.
- SERP query/query set models or page observation MVP.
- `PageSerpObservation`
- SERP visibility scorer.
- SERP aggregate builder.
- signal events for gains/losses.

Acceptance:

- Track query rankings over time.
- Link results to monitored pages/domains/competitors.
- Score SERP visibility by page/domain/brand/campaign/topic/competitor/market pack.

### Phase 7: GEO Page Observations

Deliverables:

- `AnswerEngineAdapter` abstraction.
- link existing LLM tracking citations to monitored pages.
- `PageGeoObservation`
- GEO visibility scorer.
- cross-engine aggregate builder.
- source/citation page enrichment.

Acceptance:

- AI answer cited URLs become monitored pages.
- Page-level GEO visibility is visible over time.
- Existing LLM tracking dashboard can retain its current behavior while page intelligence adds depth.

### Phase 8: Alerts, Recommendations, and UX

Deliverables:

- `AlertRule`
- `PageAlert`
- alert evaluation job
- notification integration
- recommended action mapping
- Universal Resource types and actions for monitored pages/sources
- DataTables and drawers

Acceptance:

- Users can review page alerts.
- High-value alerts produce notifications and recommended actions.
- Monitored pages are searchable/openable as Universal Resources.

### Phase 9: Operational Market Packs

Deliverables:

- market pack tables
- seeders for initial packs
- installation/customization flow
- source/competitor/theme/keyword defaults
- alert templates
- scoring model templates
- dashboard/report templates

Acceptance:

- Media Monitoring is not automotive-only.
- Workspaces can install/customize market packs.
- Discovery/scoring/dashboards inherit pack defaults.

## Key Risks

### Storage and Retention

Raw HTML snapshots can grow quickly. Use configurable retention and object storage paths.

### Legal and Compliance

AI answer engines and search providers have different retention and quoting rules. Store summaries where full answer text is not allowed.

### Fetch Safety

Continue blocking localhost/private/internal IPs. Add robots/crawl policy support and source-level rate limits.

### Data Duplication

Do not let `signal_feed_items`, `research_sources`, `content_sources`, and `competitor_content_items` each become their own page universe. New page monitoring should centralize the fetch/extraction layer.

### Scoring Explainability

PR, SERP, and GEO scores must persist breakdowns and model versions. Avoid opaque single numbers.

### UI Scope Creep

Start with monitored page list/detail and reuse Signal Intelligence for review. Full dashboards should come after data is stable.

## Recommended First Implementation Slice

The safest first slice is:

1. Add canonical page tables.
2. Add manual URL submission service/command.
3. Reuse `UrlSourceFetcher` and `ArticleContentExtractor` through a new wrapper.
4. Store raw snapshot and extraction.
5. Create deterministic brand/competitor/topic mentions.
6. Emit Signal Events.
7. Show page-generated evidence in existing Signal Intelligence.

This proves the core idea that every page is a reusable intelligence asset without waiting for SERP/GEO providers, market packs, or full UI.

## Final Recommendation

Media Monitoring should become a first-class intelligence domain built around `MonitoredPage`, not a set of one-off mention feeds.

The strongest architecture is:

- `MonitoredSource` discovers URLs.
- `MonitoredPage` owns durable page identity.
- `PageSnapshot` owns versioned fetch evidence.
- `PageContentExtraction` owns normalized content.
- Page analysis tables own entities, mentions, sentiment, topics, PR value, SERP observations, GEO observations, and relationships.
- Signal Intelligence remains the event/detection/review layer.
- Opportunity Intelligence, Recommended Actions, Notifications, Campaigns, Reports, and Universal Resources consume page-level signals.

This keeps Argusly aligned with its AI-first Marketing Intelligence Platform direction while preserving the infrastructure already built.
