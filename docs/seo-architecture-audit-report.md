# Argusly SEO Architecture Audit

## 1. Executive Summary

**Current Maturity Level: Partially Structured (approaching "Close to Target")**

The Argusly codebase demonstrates a surprisingly advanced SEO architecture that already implements many components of the target architecture, often with good engineering patterns. The system is **not foundational** - it has evolved significantly with:

- **Direct SEO field storage** on both `drafts` and `contents` tables (9 SEO fields each)
- **A comprehensive SEO audit system** with crawling, issue detection, AI-powered fix suggestions, and apply flows
- **WordPress SEO plugin detection** via connector heartbeat with capability flags
- **Yoast integration** fully implemented (RankMath/AIOSEO stubbed)
- **LLM visibility tracking** with brand mentions, citations, share of voice, and aggregates
- **Advanced analytics** including scroll depth, read time, engagement rates, ROI scores, and AI visibility scores

**Key Strengths:**
1. SEO metadata is first-class with typed columns (not just JSON blobs)
2. Clear separation between audit → suggestion → apply flows
3. Connector capability detection is already built
4. Publishing pipeline includes full SEO payload with provider-specific mapping

**Key Gaps:**
1. No formal `seo_profiles` table - SEO fields are split across drafts/contents/content_seo
2. RankMath and AIOSEO providers return empty mappings (advisory-only)
3. Laravel connector has minimal SEO support - marks content published but doesn't push SEO
4. `seo_apply_logs` concept doesn't exist as a dedicated table (uses Events + ContentVersion)
5. AI SEO scoring tables exist but calculation pipeline needs verification

**Overall Assessment:** The codebase is approximately **70-75% aligned** with the target architecture. Most gaps are either low-risk extensions or provider implementation work rather than architectural refactors.

---

## 2. Current SEO-Related Data Model

| Entity / Table | Purpose | Existing SEO Fields | Missing Target Fields | Notes / Risks |
|----------------|---------|---------------------|----------------------|---------------|
| **drafts** | Initial generated content | `seo_title`, `seo_meta_description`, `seo_h1`, `seo_canonical`, `seo_og_title`, `seo_og_description`, `seo_og_image`, `seo_twitter_title`, `seo_twitter_description` + `meta` JSON | `robots`, `schema_type`, `focus_keyword`, `internal_link_targets` | Migration `2026_03_06_090000` adds these typed columns. `focus_keyword` could use `primary_keyword` from Brief. |
| **contents** | Published articles | Same 9 SEO fields as drafts + `primary_keyword`, `publish_url_key`, `canonical_url_key` | `robots`, `schema_type`, `internal_link_targets` | Good coverage. Has `primary_keyword` which could serve as `focus_keyword`. |
| **content_seo** | Legacy dedicated SEO table | `meta_title`, `meta_description`, `primary_keyword`, `secondary_keywords` (JSON), `schema_enabled`, `toc_enabled` | `robots`, OG/Twitter fields | Appears to be legacy - main SEO fields now on drafts/contents directly. Risk of dual source of truth. |
| **seo_audits** | Audit run tracking | `pages_crawled`, `issue_counts` (JSON), `status`, `started_at`, `finished_at`, `error_message`, `meta` | None | Well-structured. Maps to `seo_audit_runs` in target. |
| **seo_audit_pages** | Crawled page data | `url`, `title`, `meta_description`, `canonical_url`, `robots_meta`, `h1`, `word_count`, `internal_links_count`, `broken_links_count`, `page_type`, `argusly_content_id` | None | Excellent coverage with article linking. Maps to `seo_audit_issues` scope. |
| **seo_audit_issues** | Detected problems | `severity`, `code`, `title`, `description`, `recommendation`, `context_json` | None | Complete. Maps to target `seo_audit_issues`. |
| **seo_audit_fix_suggestions** | AI-generated fixes | `issue_code`, `status`, `input_snapshot` (JSON), `suggestion` (JSON), `token_usage`, `credits_reserved`, `credits_charged`, `created_by`, `applied_by` | None | Excellent implementation. Maps to target `seo_suggestions`. |
| **content_publish_targets** | Publishing targets | `target_type`, `target_identifier`, `wp_post_id`, `wp_featured_media_id`, `sync_status`, `last_synced_at`, `meta` | `seo_sync_status`, `seo_last_synced_at` | Good structure. Could extend for SEO-specific sync tracking. |
| **client_sites** | Site configuration | `seo_provider`, `supports_meta_title`, `supports_meta_description`, `supports_canonical`, `supports_og_tags`, `connector_platform`, `connector_version`, `connector_meta`, `capabilities` | None | Implements target `connector_seo_capabilities` concept. |
| **analytics_events** | Raw pageview data | `url_key`, `content_id`, `page_type`, `canonical_url`, `visitor_hash`, `session_hash` | None | Good foundation for metrics. |
| **content_metrics** | Performance metrics | `avg_scroll_depth`, `max_scroll_depth`, `avg_read_time`, `median_read_time`, `engaged_rate`, `read_through_rate`, `roi_score`, `conversion_signals`, `attribution_signals`, `ai_traffic_signals` | None | Excellent coverage. Maps to target `content_metrics`. |
| **content_ai_visibility** | AI visibility scores | `llm_citations`, `brand_mentions`, `competitor_mentions`, `ai_visibility_score`, `last_checked_at` | `citation_rank` | Maps partially to `llm_visibility_mentions`. |
| **content_ai_seo_scores** | Composite SEO score | `content_roi_score`, `ai_visibility_score`, `ai_visibility_score_normalized`, `ai_seo_score`, `weights_json`, `calculated_at` | None | New table for scoring. |
| **llm_tracking_queries** | Visibility queries | `query_text`, `brand_terms`, `competitor_terms`, `target_urls`, `locale`, `is_active`, `last_run_at` | None | Maps to target `llm_visibility_runs` (query level). |
| **llm_tracking_query_runs** | Query executions | `answer_text`, `answer_json`, `brand_hits`, `competitor_hits`, `url_hits`, `citation_ranking`, `sources`, `share_of_voice_snapshot`, `suggestions`, `cached_key`, `is_cached` | None | Excellent coverage with rich analysis fields. |
| **llm_tracking_aggregates** | Aggregated metrics | `period`, `period_start`, `model`, `locale`, `metrics` (JSON) | None | Good time-series aggregation support. |
| **llm_source_rules** | Source classification | `type`, `domain_pattern`, `priority` | None | Supports source type detection. |

---

## 3. Current SEO Audit and AI Fix Flow

### How an Audit Starts
1. User clicks "Run SEO Audit" in `AppSiteSeoAuditController::run()`
2. System dispatches `RunSeoAuditJob` on the 'generation' queue
3. Job creates `SeoAudit` record with status 'running'
4. Job checks monthly quota before crawling

### Where Data is Stored
- **SeoAudit**: Run metadata, status, timing, issue counts
- **SeoAuditPage**: One row per crawled URL with all extracted metadata
- **SeoAuditIssue**: One row per detected issue per page
- **SeoAuditFixSuggestion**: One row per AI-generated fix (created during suggestion generation)

### How Suggestions are Created
1. User selects issues in the audit dashboard UI
2. Controller dispatches `GenerateSeoFixSuggestionsJob` with selected issue IDs
3. For each issue linked to a Argusly article:
   - `SeoAuditAiFixService::buildInputSnapshot()` creates context
   - `SeoAuditAiFixService::generateSuggestion()` calls LLM (OpenAI reasoning model)
   - Result stored in `SeoAuditFixSuggestion` with status 'generated'
4. Credits are reserved before generation, captured on success

### How Apply Actions Work
1. User clicks "Apply" on a suggestion
2. Controller calls `SeoAuditAiFixService::applySuggestionToDraft()`
3. Apply function (lines 129-252 in SeoAuditAiFixService):
   - Extracts SEO fields from suggestion via `SeoMetadata::merge()`
   - Validates at least one actionable field exists
   - In DB transaction:
     - Updates/creates `ContentSeo` record
     - Updates latest `Draft` with all SEO fields
     - Creates new `ContentVersion` with type='revision', source='seo_audit_ai_fix'
     - Updates `Content` record with all SEO fields
     - Sets `current_version_id` to new version
     - Marks `SeoAuditFixSuggestion` status → 'applied'
   - Creates `Event` record with type='seo.audit.ai_fix.applied'

### What Is and Is Not Persisted

**Persisted:**
- All audit data (runs, pages, issues, suggestions)
- Applied SEO fields on Draft, Content, ContentSeo
- Version history in ContentVersion with metadata about source
- Event log entries
- Credit transactions

**Not Persisted as Separate Entity:**
- A formal "seo_apply_log" table doesn't exist
- Apply history is reconstructed from Events + ContentVersion.meta

**Gap:** The target `seo_apply_logs` table is conceptually covered by Events and ContentVersion but not as a dedicated queryable entity. This is a minor gap as the audit trail exists.

---

## 4. Connector Audit

### 4.1 WordPress Connector

#### Current Payload Structure
The `DeliverDraftToWordPress::deliver()` method builds a comprehensive payload including:

```php
$payload = [
    'id' => $remoteDraftId,
    'title' => $draft->title,
    'slug' => $slug,
    'content_html' => $payloadHtml,
    'seo_title' => $payloadSeo['seo_title'],
    'seo_meta_description' => $payloadSeo['seo_meta_description'],
    'seo_h1' => $payloadSeo['seo_h1'],
    'seo_canonical' => $payloadSeo['seo_canonical'],
    'seo_og_title' => $payloadSeo['seo_og_title'],
    'seo_og_description' => $payloadSeo['seo_og_description'],
    'seo_og_image' => $payloadSeo['seo_og_image'],
    'seo_twitter_title' => $payloadSeo['seo_twitter_title'],
    'seo_twitter_description' => $payloadSeo['seo_twitter_description'],
    // ... plus meta, images, etc.
];
```

#### SEO Fields Support
**Fully Supported:** All 9 SEO fields are included in every WordPress delivery.

#### WordPress Plugin Detection
**Implemented via `ConnectorHeartbeatController` + `WordPressSeoCapabilityDetector`:**

1. WordPress plugin sends heartbeat with `plugins` or `active_plugins` array
2. `WordPressSeoCapabilityDetector::detect()` normalizes and scans for signatures:
   - **Yoast:** `wordpress-seo`, `wp-seo.php`, `yoast`
   - **RankMath:** `rank-math`, `rankmath`, `seo-by-rank-math`
   - **AIOSEO:** `all-in-one-seo-pack`, `aioseo`, `all in one seo`
3. Detection result stored on `ClientSite`:
   - `seo_provider` (string: yoast, rankmath, aioseo, none)
   - `supports_meta_title`, `supports_meta_description`, `supports_canonical`, `supports_og_tags` (booleans)
4. Capabilities also stored in `ClientSite.capabilities['seo']` JSON

#### Yoast/RankMath/AIOSEO Support

| Provider | Detection | Sync Support | Meta Mapping |
|----------|-----------|--------------|--------------|
| **Yoast** | ✅ Implemented | ✅ Full sync | `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_canonical`, `_yoast_wpseo_opengraph-*` |
| **RankMath** | ✅ Implemented | ❌ Advisory only | Returns empty mapping (stubbed) |
| **AIOSEO** | ✅ Implemented | ❌ Advisory only | Returns empty mapping (stubbed) |
| **None** | ✅ Implemented | ❌ Advisory only | Returns empty mapping |

**Advisory Mode:** When sync not supported, payload includes:
- `seo_recommendations` array with all SEO values
- `seo_sync.mode = 'advisory'`
- SEO fields removed from direct payload to avoid confusion

**Sync Mode (Yoast only):** Payload includes:
- `meta_input` and `wp_post_meta` with mapped Yoast meta keys
- `seo_sync.mode = 'sync'`
- `seo_sync.mapped_fields` array

#### What is Missing
1. **RankMath meta mapping** - Provider exists but returns empty array
2. **AIOSEO meta mapping** - Provider exists but returns empty array
3. **Twitter meta keys for Yoast** - Only OG mapped, not Twitter-specific
4. **Focus keyword sync** - Not currently mapped to Yoast's `_yoast_wpseo_focuskw`
5. **Robots meta sync** - Not currently in the SEO field set

### 4.2 Laravel Connector

#### Current Payload Structure
Laravel publishing in `AppContentController::publishNowToLaravel()` is **minimal**:

```php
$draft->status = 'delivered';
$draft->delivery_status = 'delivered';
$draft->delivered_at = now();
$draft->acked_at = now();
$draft->save();

$content->update([
    'publish_status' => 'published',
    'status' => 'published',
    'delivery_status' => 'delivered',
    'published_url' => $publishedUrl,  // Generated, not pushed
]);
```

#### Support for SEO Metadata
**Not Implemented:** Laravel connector does not:
- Push any SEO fields to the remote Laravel site
- Have an API endpoint equivalent to WordPress `/argusly/v1/posts`
- Use webhooks for delivery

The Laravel flow simply marks content as published locally and generates a guessed `published_url` based on `/blog/{slug}` pattern.

#### Rendering Strategy for Meta Tags
**Not Applicable:** Argusly doesn't control meta tag rendering on Laravel sites. It's assumed the Laravel site would:
1. Pull content via API (the `/v1/drafts` endpoint returns all SEO fields)
2. Handle its own meta tag rendering in Blade views

#### What is Missing
1. **No push-based delivery** - Laravel sites must poll/pull
2. **No SEO capability detection** for Laravel sites
3. **No confirmation of successful publish** from remote Laravel site
4. **No SEO field mapping** to Laravel-specific storage

---

## 5. Metrics and AI Visibility Audit

| Metric | Status | Storage Location | Notes |
|--------|--------|------------------|-------|
| **Views** | ✅ Stored | `analytics_events` (event_type=pageview), `analytics_rollups_daily.page_views` | Real-time + daily rollup |
| **Uniques** | ✅ Stored | `analytics_events.visitor_hash`, `analytics_rollups_daily.unique_visitors` | Deduplicated via hash |
| **Engaged visits** | ✅ Stored | `analytics_events` (event_type=engaged), `analytics_rollups_daily.engaged_views` | Tracked via client-side beacon |
| **Read-through** | ✅ Stored | `analytics_events` (event_type=read_through), `content_metrics.read_through_rate` | Computed as ratio |
| **Scroll depth** | ✅ Stored | `page_scroll_events`, `content_metrics.avg_scroll_depth`, `content_metrics.max_scroll_depth`, `analytics_rollups_daily.scroll_50/scroll_100` | Multiple granularities |
| **Content ROI** | ✅ Stored | `content_metrics.roi_score`, `content_ai_seo_scores.content_roi_score` | Computed metric |
| **AI Visibility** | ✅ Stored | `content_ai_visibility.ai_visibility_score`, `content_ai_seo_scores.ai_visibility_score` | LLM-derived |
| **AI SEO Score** | ✅ Stored | `content_ai_seo_scores.ai_seo_score` | Composite score |
| **LLM Citations** | ✅ Stored | `content_ai_visibility.llm_citations`, `llm_tracking_query_runs.url_hits` | Per URL and per run |
| **Brand Mentions** | ✅ Stored | `content_ai_visibility.brand_mentions`, `llm_tracking_query_runs.brand_hits` | Rich hit data |
| **Competitor Mentions** | ✅ Stored | `content_ai_visibility.competitor_mentions`, `llm_tracking_query_runs.competitor_hits` | Rich hit data |
| **Share of Voice** | ✅ Stored | `llm_tracking_query_runs.share_of_voice_snapshot` | Per-run snapshot |
| **Citation Ranking** | ✅ Stored | `llm_tracking_query_runs.citation_ranking` | Position-weighted scoring |
| **Avg Read Time** | ✅ Stored | `page_read_sessions.read_seconds`, `content_metrics.avg_read_time`, `content_metrics.median_read_time` | Session-level + aggregated |

**Summary:** All target metrics are either stored or can be computed from stored data. The system has excellent coverage of both traditional analytics and emerging AI visibility metrics.

---

## 6. LLM Visibility Tracking Audit

### What Exists Today

**Query Management (`llm_tracking_queries`):**
- Full CRUD for tracking queries
- Brand terms, competitor terms, target URLs arrays
- Locale support
- Active/inactive toggle
- Last run timestamp

**Run Execution (`llm_tracking_query_runs`):**
- Raw response storage
- Parsed answer text and JSON
- Rich analysis fields:
  - `brand_hits` - Array of brand mentions with position, context, count
  - `competitor_hits` - Same structure for competitors
  - `url_hits` - Target URL citations detected
  - `citation_ranking` - Position-weighted ranking data
  - `sources` - Extracted source URLs from response
  - `share_of_voice_snapshot` - Brand vs competitor visibility ratio
  - `suggestions` - AI-generated improvement recommendations
- Caching support (`is_cached`, `cached_key`)
- Provider/model tracking

**Aggregation (`llm_tracking_aggregates`):**
- Period-based aggregation (daily/weekly/monthly)
- Model-specific aggregates
- Locale-specific aggregates
- Metrics JSON blob for flexible storage

**Source Rules (`llm_source_rules`):**
- Domain pattern matching
- Type classification
- Priority ordering

### Analysis Service (`LlmTrackingAnalyzer`)
The analyzer performs deterministic parsing of LLM responses:
- Regex-based term extraction with position tracking
- Sentence-level context snippets
- Normalized position scoring
- URL extraction and matching to targets
- Share of voice computation (brand vs competitor mention ratios)
- Automatic suggestion generation based on gaps

### Assessment for Future Fields

| Future Field | Supportability | Notes |
|--------------|----------------|-------|
| **Citation rank** | ✅ Already exists | `llm_tracking_query_runs.citation_ranking` |
| **Source detection** | ✅ Already exists | `llm_tracking_query_runs.sources` + `llm_source_rules` table |
| **Share of AI voice** | ✅ Already exists | `share_of_voice_snapshot` with brand/competitor ratios |
| **Suggested content opportunities** | ✅ Already exists | `suggestions` array in runs, generated by analyzer |

The LLM visibility tracking system is **fully aligned** with the target architecture. No major gaps identified.

---

## 7. Gap Analysis Against Target Architecture

| Target Component | Status | Current Equivalent | Implementation Complexity | Notes |
|------------------|--------|-------------------|---------------------------|-------|
| **seo_profiles** | Partial | `drafts.seo_*`, `contents.seo_*`, `content_seo` | Medium | Fields exist but split across tables. Could unify with a view or consolidate. |
| **seo_audit_runs** | ✅ Exists | `seo_audits` | N/A | Fully implemented. |
| **seo_audit_issues** | ✅ Exists | `seo_audit_pages` + `seo_audit_issues` | N/A | Two tables together cover the need well. |
| **seo_suggestions** | ✅ Exists | `seo_audit_fix_suggestions` | N/A | Fully implemented with credits, status, user attribution. |
| **seo_apply_logs** | Partial | `events` + `content_versions.meta` | Low | Data exists but not queryable as dedicated entity. |
| **publish_targets** | ✅ Exists | `content_publish_targets` | N/A | Fully implemented with WP-specific fields. |
| **connector_seo_capabilities** | ✅ Exists | `client_sites.seo_provider`, `supports_*` flags | N/A | Fully implemented via heartbeat detection. |
| **content_metrics** | ✅ Exists | `content_metrics` | N/A | All target fields present. |
| **llm_visibility_runs** | ✅ Exists | `llm_tracking_queries` + `llm_tracking_query_runs` | N/A | Exceeds target with rich analysis. |
| **llm_visibility_mentions** | ✅ Exists | `llm_tracking_query_runs` (brand_hits, competitor_hits, url_hits) | N/A | Stored per-run with position data. |

**Summary:** 8 of 10 target components exist or are fully implemented. 2 are partial:
1. `seo_profiles` - data exists but scattered
2. `seo_apply_logs` - data exists in events/versions but not dedicated

---

## 8. Risk and Refactor Assessment

### High Risk

1. **Dual SEO Source of Truth**
   - `drafts.seo_*` vs `contents.seo_*` vs `content_seo` table
   - Risk: Values can diverge during updates
   - Mitigation: `SeoMetadata::merge()` handles precedence but adds complexity

2. **RankMath/AIOSEO Provider Stubs**
   - Currently return empty mappings, falling back to advisory mode
   - Risk: Users with these plugins don't get SEO sync
   - Impact: SEO changes must be manually applied in WordPress

### Medium Risk

3. **Laravel Connector Minimal Implementation**
   - No push-based SEO delivery
   - Risk: Laravel sites miss SEO updates
   - Impact: Must rely on pull-based API access

4. **Migration Complexity for seo_profiles Unification**
   - Consolidating to a single table requires data migration
   - Risk: Breaking existing code that reads from multiple sources
   - Impact: Requires careful testing of all SEO read/write paths

5. **ContentSeo Table Redundancy**
   - Legacy table duplicating data now on `contents`
   - Risk: Confusion about canonical source
   - Impact: Should consider deprecation strategy

### Low Risk

6. **Missing robots Meta Field**
   - Easy to add as a new column
   - No existing data to migrate

7. **Missing schema_type Field**
   - Easy to add, defaults to null
   - Can be populated during content generation

8. **seo_apply_logs as Dedicated Table**
   - Data already captured in events/versions
   - Creating dedicated table is additive, not breaking

9. **Focus Keyword Synchronization**
   - Can reuse `primary_keyword` from Brief/Content
   - Need to add Yoast meta key mapping `_yoast_wpseo_focuskw`

---

## 9. Recommended Phased Roadmap

### Phase 1: Foundation Hardening (Low Risk)

**Scope:**
- Add `robots` field to drafts/contents tables
- Add `schema_type` field to drafts/contents tables
- Add Yoast focus keyword mapping (`_yoast_wpseo_focuskw`)
- Create `seo_apply_logs` table and populate from existing events
- Add index for audit trail queries

**Dependencies:** None

**Risk Level:** Low - purely additive changes

**Expected Impact:**
- Complete SEO field coverage
- Queryable apply history
- Better Yoast integration

### Phase 2: Connector Capability Layer (Medium Risk)

**Scope:**
- Implement RankMath meta key mappings
- Implement AIOSEO meta key mappings
- Add Twitter card meta mappings for all providers
- Extend heartbeat to detect plugin versions
- Add SEO sync status tracking to `content_publish_targets`

**Dependencies:** Phase 1 (for complete field set)

**Risk Level:** Medium - requires WordPress plugin testing

**Expected Impact:**
- SEO sync support for 3 major WordPress SEO plugins
- Twitter card optimization
- Better sync status visibility

### Phase 3: Metrics/Scoring Layer (Low-Medium Risk)

**Scope:**
- Verify AI SEO score calculation pipeline
- Create scheduled job for score recalculation
- Build dashboard widgets for metrics visualization
- Add content ROI trending
- Connect AI visibility to content recommendations

**Dependencies:** Phase 1

**Risk Level:** Low-Medium - mostly additive with some UI work

**Expected Impact:**
- Actionable scoring insights
- Content performance visibility
- AI-driven content recommendations

### Phase 4: LLM Visibility Expansion (Low Risk)

**Scope:**
- Add multi-model comparison runs
- Implement embedding-based topic extraction
- Build competitive intelligence dashboard
- Add automated alerts for visibility changes
- Create content opportunity generator

**Dependencies:** None (system already robust)

**Risk Level:** Low - builds on solid foundation

**Expected Impact:**
- Deeper AI visibility insights
- Competitive monitoring
- Proactive content suggestions

---

## 10. Concrete Next Actions

1. **Audit the `content_seo` table usage** - Determine if it can be deprecated or if it serves a unique purpose. Search all code paths that read from it vs from `contents.seo_*`.

2. **Add `robots` and `schema_type` columns** to drafts and contents tables via migration. Include in SEO payload for WordPress delivery.

3. **Implement RankMath meta key mappings** in `RankMathProvider::mapToWordPressMeta()`. Keys: `rank_math_title`, `rank_math_description`, `rank_math_canonical_url`, `rank_math_facebook_title`, etc.

4. **Implement AIOSEO meta key mappings** in `AioSeoProvider::mapToWordPressMeta()`. Keys: `_aioseo_title`, `_aioseo_description`, `_aioseo_canonical_url`, `_aioseo_og_title`, etc.

5. **Add focus keyword to Yoast mapping** - Map `primary_keyword` to `_yoast_wpseo_focuskw` in `YoastProvider`.

6. **Create `seo_apply_logs` migration** with columns: `id`, `content_id`, `suggestion_id`, `user_id`, `field_changes` (JSON), `applied_at`. Backfill from existing events where `type='seo.audit.ai_fix.applied'`.

7. **Review Laravel connector strategy** - Document whether push-based delivery is needed or if pull-based is acceptable. If push needed, design webhook/API spec.

8. **Add SEO sync tracking fields** to `content_publish_targets`: `seo_synced_at`, `seo_sync_status`, `seo_sync_error`.

9. **Verify AI SEO score calculation** - Review `RecalculateAiSeoScoresCommand` and `AiSeoScoreCalculator` to ensure scores are being computed correctly.

10. **Create comprehensive test coverage** for SEO provider mappings - Add unit tests for each provider with expected meta key outputs.

---

## Suggested First Implementation Slice

**Recommendation: Implement RankMath provider meta key mappings**

**Rationale:**
1. **Architectural Value:** Completes the connector capability layer for the second most popular WordPress SEO plugin
2. **Low Risk:** Provider infrastructure already exists; only needs `mapToWordPressMeta()` implementation
3. **Compatibility:** Uses existing `seo_provider` detection and `applySeoSyncPayload()` flow
4. **Usefulness:** Immediate benefit for users with RankMath - SEO changes will sync instead of being advisory-only

**Implementation Steps:**
1. Research RankMath meta key names (documented in their REST API)
2. Implement `RankMathProvider::mapToWordPressMeta()`
3. Set `supports_*` flags to `true` in provider
4. Add unit tests for mapping
5. Test with live RankMath site

**Estimated Effort:** 2-4 hours

**Files to Modify:**
- `app/Services/Seo/Providers/RankMathProvider.php`
- `tests/Unit/Seo/RankMathProviderTest.php` (new)

---

## Appendix: Evidence

### Database Migrations (SEO-related)
- `database/migrations/2026_02_17_102400_create_content_seo_table.php` - Legacy SEO table
- `database/migrations/2026_02_20_140000_create_seo_audits_tables.php` - Audit infrastructure
- `database/migrations/2026_03_05_160000_add_page_type_to_seo_audit_pages_table.php` - Article linking
- `database/migrations/2026_03_05_170000_create_seo_audit_fix_suggestions_table.php` - AI suggestions
- `database/migrations/2026_03_06_090000_add_seo_metadata_fields_to_drafts_and_contents.php` - Typed SEO columns

### Models
- `app/Models/Draft.php` - Lines with seo_* fillable fields
- `app/Models/Content.php` - Lines with seo_* fillable fields
- `app/Models/ContentSeo.php` - Legacy SEO model
- `app/Models/SeoAudit.php` - Audit run model
- `app/Models/SeoAuditPage.php` - Crawled page model
- `app/Models/SeoAuditIssue.php` - Issue model
- `app/Models/SeoAuditFixSuggestion.php` - AI suggestion model
- `app/Models/ClientSite.php` - Lines 106-112 for seo_provider, supports_* attributes
- `app/Models/LlmTrackingQuery.php` - Visibility tracking
- `app/Models/LlmTrackingQueryRun.php` - Run results with analysis fields
- `app/Models/ContentMetric.php` - Performance metrics
- `app/Models/ContentAiVisibility.php` - AI visibility scores
- `app/Models/ContentAiSeoScore.php` - Composite scoring

### Services
- `app/Services/SeoAudit/SeoAuditCrawlerService.php` - Page crawling and issue detection
- `app/Services/SeoAudit/SeoAuditAiFixService.php` - AI suggestion generation and apply flow (key methods: `generateSuggestion()`, `applySuggestionToDraft()`)
- `app/Services/SeoAudit/SeoAuditScoreCalculator.php` - Health score calculation
- `app/Services/DraftDelivery/DeliverDraftToWordPress.php` - WordPress delivery with SEO payload
- `app/Services/Seo/WordPressSeoCapabilityDetector.php` - Plugin detection logic
- `app/Services/Seo/SeoProviderRegistry.php` - Provider resolution
- `app/Services/Seo/Providers/YoastProvider.php` - Full Yoast mapping implementation
- `app/Services/Seo/Providers/RankMathProvider.php` - Stubbed (returns empty)
- `app/Services/Seo/Providers/AioSeoProvider.php` - Stubbed (returns empty)
- `app/Services/LlmTracking/LlmTrackingAnalyzer.php` - Rich response analysis

### Controllers
- `app/Http/Controllers/App/AppSiteSeoAuditController.php` - Audit UI endpoints
- `app/Http/Controllers/Api/ConnectorHeartbeatController.php` - SEO capability detection on heartbeat
- `app/Http/Controllers/App/AppContentController.php` - Content management with SEO handling
- `app/Http/Controllers/App/AppLlmTrackingController.php` - LLM visibility management

### Jobs
- `app/Jobs/SeoAudit/RunSeoAuditJob.php` - Audit execution
- `app/Jobs/SeoAudit/GenerateSeoFixSuggestionsJob.php` - AI suggestion generation
- `app/Jobs/LlmTracking/RunLlmTrackingQueryJob.php` - Visibility tracking execution
- `app/Jobs/PublishToWordPressJob.php` - Publishing with SEO payload

### Support
- `app/Support/SeoMetadata.php` - SEO field normalization and merging utility
