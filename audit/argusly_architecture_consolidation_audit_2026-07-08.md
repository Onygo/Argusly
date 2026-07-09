# Argusly Architecture Consolidation Audit

Date: 2026-07-08

Scope: architecture consolidation only. This audit reviews existing Laravel models, migrations, services, support registries, and tests across Page Intelligence, PR/earned-media intelligence, SERP/GEO/AI Visibility, connector data, canonical marketing observations, Performance Intelligence, Agentic Marketing Intelligence, and the Marketing Operating System.

## Architecture Score

Current platform score after this consolidation pass: 8.6 / 10.

The core architecture is strong. Argusly already has generic connector sync, canonical observations, universal interaction primitives, Page Intelligence inputs, performance snapshots, explainable Score v2, deterministic agentic reasoning, recommended actions, and MOS operating links. The main remaining risk is concept drift: entities, evidence, time windows, signals, and graph edges are present in several modules but are not all governed by first-class platform contracts yet.

## Executive Classification

| Area | Classification | Priority |
| --- | --- | --- |
| Canonical Entity Graph | Needs consolidation, should become platform service | High |
| Signal Engine | Needs consolidation, should become platform service | High |
| Knowledge / Intelligence Graph | Needs consolidation | Medium |
| Campaign Intelligence Layer | Already solved, needs consolidation | Medium |
| Unified Time Engine | Should become platform service | High |
| Evidence Engine | Should become platform service | High |
| Reasoning Pipeline | Needs consolidation, should become platform service | High |
| Cross-module duplication | Needs consolidation | Medium |

## Platform Changes Implemented In This Pass

These changes are additive and backward compatible. No migrations, routes, UI, or provider-specific flows were added.

1. Added `App\Support\Intelligence\CanonicalEntityReference` and `CanonicalEntityType`.
   - Centralizes entity key normalization before a future canonical entity table is introduced.
   - Adopted by `SignalEntityResolver` without changing `signal_entities`.

2. Added `App\Support\Intelligence\Evidence`.
   - Provides a reusable evidence bag for reference IDs, nested page-intelligence inputs, and source metrics.
   - Adopted by `ScoreEvidence` and `MarketingEvidence` while preserving their public constructors and `toArray()` payloads.

3. Added `App\Support\Intelligence\TimeWindow`.
   - Centralizes daily, weekly, monthly, previous-period, rolling-window, and same-period-previous-year math.
   - Adopted by Performance Intelligence and Agentic Marketing evidence collection.

4. Added `App\Support\Intelligence\IntelligenceStage` and `HasIntelligenceStage`.
   - Formalizes `raw_observation -> signal -> insight -> recommendation -> action -> outcome`.
   - Adopted by `PerformanceSignal`, `MarketingInsight`, and `MarketingRecommendation`.

5. Added architecture unit coverage in `tests/Unit/Architecture/IntelligencePlatformSupportTest.php`.

## 1. Canonical Entity Graph

Classification: Needs consolidation, should become platform service.

Reason:
Entity-like concepts are spread across `SignalEntity`, `PageEntity`, `ArticleEntity`, `LlmAuthorityEntityCandidate`, `SiteCompetitor`, `CompanyProfile`, brand/team/persona records, `MarketPack`, `PageTopic`, and JSON arrays such as `marketing_objectives.entities_json`, `marketing_initiatives.entities_json`, and campaign metadata. `SignalEntity` is useful, but it is scoped to Signal Intelligence and cannot safely be treated as the canonical entity graph for brands, products, countries, markets, organizations, technologies, and people.

Recommendation:
Introduce a canonical entity platform in phases. Start with shared value objects and normalization rules, then later add additive tables such as `canonical_entities`, `canonical_entity_aliases`, and `canonical_entity_links` only after consumers are mapped.

Benefits:
Consistent references across pages, reports, campaigns, recommendations, competitors, market packs, and connector-derived observations. Better dedupe, better longitudinal intelligence, better reasoning context.

Risks:
Premature persistence could duplicate or conflict with `SignalEntity`, `SiteCompetitor`, and brand/company models. Poor alias handling could merge distinct entities.

Migration complexity:
High if persistent tables are introduced. Low for the value-object layer implemented now.

Backward compatibility impact:
Should be additive. Existing entity columns and JSON arrays should remain readable until all consumers migrate.

Priority:
High.

## 2. Signal Engine

Classification: Needs consolidation, should become platform service.

Reason:
Argusly already contains multiple signal paths:

- Connector records write canonical `MarketingObservation` rows.
- Performance Intelligence turns observations into in-memory `PerformanceSignal` objects.
- Signal Intelligence persists `SignalEvent`, `SignalDetection`, and promotes detections to `OpportunitySignal`.
- Agentic Marketing turns signals and page context into insights and recommendations.

This is architecturally coherent, but the stage vocabulary was implicit before this pass.

Recommendation:
Keep `MarketingObservation` as the canonical raw observation model. Treat `SignalEvent` and `PerformanceSignal` as signal-stage outputs. Build a reusable signal contract and mapper layer before adding more signal-producing modules.

Benefits:
Clear separation between raw provider data, normalized observations, signals, insights, recommendations, actions, and outcomes. Easier auditability and less provider leakage.

Risks:
Over-formalizing persistence too early could force simple in-memory signals into tables unnecessarily.

Migration complexity:
Medium. Existing services can adopt contracts gradually.

Backward compatibility impact:
Additive if contracts wrap existing DTOs and models.

Priority:
High.

## 3. Knowledge / Intelligence Graph

Classification: Needs consolidation.

Reason:
The graph already exists in practice:

- `MarketingOperatingLink` links objectives and initiatives to campaigns, content, pages, observations, reports, briefings, performance snapshots, and recommendations.
- `SignalDetectionLink` links detections to signal events.
- Opportunity signal links connect `OpportunitySignal` to opportunities.
- Page Intelligence has page-to-topic, page-to-entity, page-to-campaign, page-to-market-pack, and page-to-competitor matches.

The strongest graph abstraction today is MOS, but it is scoped to objectives and initiatives rather than being a universal intelligence graph.

Recommendation:
Do not add a graph database or broad graph table now. First extract a reusable resource reference and graph edge contract from `MarketingResourceLinker` and apply it to reports, campaigns, recommendations, and observations.

Benefits:
Future reasoning can traverse evidence without hardcoding feature tables. MOS can remain the operating projection while other modules share the same edge vocabulary.

Risks:
A universal edge table could become a dumping ground if relationship types are not governed.

Migration complexity:
Medium to high, depending on whether existing link tables are unified or only adapted.

Backward compatibility impact:
Should be additive if existing link tables continue to own their domains.

Priority:
Medium.

## 4. Campaign Intelligence Layer

Classification: Already solved, needs consolidation.

Reason:
`Campaign` is already a canonical platform concept. It has content assets, distribution plans, tone profiles, CTA presets, matching against monitored pages, links from Opportunity Signals, and MOS resource linking. The current implementation is not provider-specific.

Recommendation:
Keep Campaign as canonical. Add a campaign intelligence read model later that aggregates pages, press releases, LinkedIn posts, ads, reports, performance observations, recommendations, and outcomes through existing resource links and marketing attributions.

Benefits:
Campaign-level intelligence can remain provider-agnostic and reuse connector observations.

Risks:
If aggregation is implemented directly inside providers, campaign logic will fragment.

Migration complexity:
Medium.

Backward compatibility impact:
Additive.

Priority:
Medium.

## 5. Unified Time Engine

Classification: Should become platform service.

Reason:
Time windows appear in connector sync runs, marketing observations, performance snapshots, trend comparisons, reports, campaigns, MOS workflows, reviews, briefings, and scheduled jobs. The duplicated logic was most visible in Performance Intelligence and Agentic Marketing.

Recommendation:
Use `TimeWindow` for rolling windows, previous periods, same period previous year, and granularity-aware bucket keys. Extend it later with business calendars and named periods.

Benefits:
Consistent comparisons, fewer off-by-one period bugs, and one place to add campaign/release/business-period semantics.

Risks:
Week starts and business calendars are locale-sensitive. Future changes should avoid silently changing historical report periods.

Migration complexity:
Low for deterministic value-object adoption. Medium if persisted report semantics are migrated.

Backward compatibility impact:
Additive. Existing date columns remain unchanged.

Priority:
High.

## 6. Evidence Engine

Classification: Should become platform service.

Reason:
Explainability exists in Score v2, Performance Intelligence, Signal Intelligence, Agentic Marketing, MOS reviews/priorities, reports, and recommendations. The common shape is resource references plus source metrics plus explanation text. Before this pass, `ScoreEvidence` and `MarketingEvidence` duplicated merge behavior.

Recommendation:
Continue using the shared `Evidence` bag as the compatibility layer. Later, introduce typed evidence item contracts and only consider persisted evidence items when enough modules need queryable evidence independent of their owning record.

Benefits:
One evidence merge strategy, stable payloads, traceability from recommendations and scores back to observations, snapshots, reports, and input rows.

Risks:
Over-generalizing evidence can hide domain-specific meaning. Evidence should preserve source-specific details in metadata rather than flattening everything.

Migration complexity:
Low for DTO adoption. Medium if persisted evidence items are introduced.

Backward compatibility impact:
No breaking impact in this pass. Existing evidence arrays are preserved.

Priority:
High.

## 7. Reasoning Pipeline

Classification: Needs consolidation, should become platform service.

Reason:
Agentic Marketing already behaves as a reasoning pipeline:

`MarketingObservation -> PerformanceSignal -> MarketingInsight -> MarketingRecommendation -> AgenticMarketingOpportunity / RecommendedAction`

Signal Intelligence has a parallel path:

`SignalMention / SignalEvent -> SignalDetection -> OpportunitySignal`

The concepts align, but the contracts were implicit.

Recommendation:
Adopt `IntelligenceStage` across new intelligence DTOs and gradually expose stage metadata on persisted outputs where useful. Do not force all modules into one runtime pipeline yet.

Benefits:
Clearer audits, easier debugging, and a shared mental model for future reasoning modules.

Risks:
A single orchestrator too early would reduce feature autonomy and increase coupling.

Migration complexity:
Medium.

Backward compatibility impact:
Additive. Existing payloads receive optional stage metadata only where DTO arrays are emitted.

Priority:
High.

## 8. Cross-module Duplication

Classification: Needs consolidation.

Duplicated concepts found:

- Entity keys and aliases across Signal, Page, LLM authority, competitors, brands, and MOS JSON fields.
- Evidence arrays across score, recommendation, signal, report, review, and priority models.
- Period math across Performance, Agentic Marketing, reports, sync windows, campaigns, and MOS.
- Resource references across MOS links, recommended actions, opportunity signals, report payloads, and notifications.
- Priority and confidence scoring across SignalDetection, OpportunitySignal, MarketingPriority, RecommendedAction, and Agentic recommendations.
- Recommendation mapping across `RecommendedActionEngine`, Agentic Marketing lifecycle, MOS priorities, and SignalDetection promotion.

Recommendation:
Prioritize consolidation in this order:

1. Evidence and time windows. Implemented in this pass.
2. Entity reference normalization. Started in this pass.
3. Resource reference / graph edge contract extracted from MOS.
4. Signal pipeline contracts across Signal Intelligence and Performance Intelligence.
5. Priority/confidence scoring utilities after more consumers are mapped.

Benefits:
Less duplicate logic and fewer divergent payload shapes.

Risks:
Consolidation can become churn if done without consumer mapping and compatibility tests.

Migration complexity:
Medium overall.

Backward compatibility impact:
Should remain additive if each step wraps existing behavior first.

Priority:
Medium.

## Recommended Migration Plan

Phase 1, complete in this pass:
Add support-layer contracts for canonical entity references, evidence, time windows, and intelligence stages. Adopt them in the narrowest existing services with tests.

Phase 2:
Create an inventory of all entity-bearing fields and map each to `CanonicalEntityType`. Add non-persistent canonical references to page/entity/report/recommendation payloads.

Phase 3:
Extract a `ResourceReference` and `IntelligenceGraphEdge` contract from `MarketingResourceLinker`. Keep `MarketingOperatingLink` as the MOS projection.

Phase 4:
Bridge Signal Intelligence and Performance Intelligence with shared signal-stage interfaces. Avoid persisting every signal until query requirements are proven.

Phase 5:
Add campaign intelligence aggregation as a read model using campaigns, marketing attributions, MOS links, reports, recommendations, observations, and outcomes.

Phase 6:
Only after entity and graph consumers are mapped, consider additive canonical entity tables and backfill jobs.

## Not Recommended Now

- Do not add a dashboard.
- Do not add a graph database.
- Do not replace `SignalEntity` with a universal entity table yet.
- Do not merge all graph-like link tables into one table in a single migration.
- Do not persist every in-memory `PerformanceSignal`.
- Do not build provider-specific campaign intelligence.
- Do not break existing evidence JSON payloads.

## Verification

Focused verification after implementation:

`php artisan test tests/Unit/Architecture/IntelligencePlatformSupportTest.php tests/Feature/Performance/PerformanceIntelligenceEngineTest.php tests/Feature/PageIntelligence/IntelligenceScoreV2Test.php tests/Feature/AgenticMarketing/AgenticMarketingIntelligenceTest.php`

Result: 24 passed, 170 assertions.

Full suite verification should run with:

`php artisan test`
