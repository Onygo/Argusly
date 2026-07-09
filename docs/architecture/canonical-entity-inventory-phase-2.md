# Canonical Entity Inventory and Mapping Plan

Date: 2026-07-09

Phase: Intelligence Platform consolidation Phase 2

## Guardrails

This phase is inventory and helper-only. It does not add canonical entity database tables, dashboards, breaking migrations, or stored payload shape changes. Existing columns and JSON payloads remain the source of truth. `App\Support\Intelligence\CanonicalEntityReference` is the compatibility primitive for read-time normalization and future shadow mapping.

## Canonical Type Classification

| Canonical type | Existing examples |
| --- | --- |
| `company` | `company_profiles.company_name`, `company_intelligence_profiles.company_name`, workspace/account company identity |
| `brand` | `page_brand_matches.brand_name`, `llm_tracking_queries.brand_terms`, `agentic_marketing_objectives.brand_entities` |
| `competitor` | `site_competitors`, `market_pack_competitors`, competitor JSON arrays, LLM competitor mentions |
| `topic` | `page_topics`, content opportunity topics, research keywords, GSC queries when used as semantic topics |
| `domain` | `monitored_pages.domain`, `site_competitors.domain`, connector dataset site URLs, cited domains |
| `source` | `monitored_sources`, `market_pack_sources`, connector providers/datasets |
| `market` | `market_packs`, `market_category`, `target_market`, `regions`, country/locale market scopes |
| `country` | connector dimensions `country`, SERP/GEO `country`, company intelligence `regions` when country-like |
| `organization` | LinkedIn organization URNs, legal/provider organizations, non-brand organizations |
| `product` | company intelligence `products_services`, content/product positioning payloads |
| `person` | page extraction `author`, personas, buyer roles, team members when used as named people/roles |
| `technology` | authority areas, technologies in target entities, AI/LLM provider references |

## Entity-Bearing Field Inventory

| Area | Entity-bearing fields | Classification and notes |
| --- | --- | --- |
| Page Intelligence foundation | `monitored_sources.name`, `source_type`, `base_url`, `domain`; `monitored_pages.canonical_url`, `first_seen_url`, `final_url`, `domain`, `path`, `page_type`, `publisher_name`, `dedupe_key`, `syndication_group_key`; `page_content_extractions.author`, `publisher`, `structured_data_json`, `images_json`, `media_json`, `outbound_links_json`, `internal_links_json` | Source/domain/page/person/organization references. URL and domain fields should normalize as `domain` or source metadata, not as semantic brands unless matched elsewhere. |
| Page Intelligence analysis | `page_entities.entity_type`, `entity_key`, `entity_name`, `source_ref_type`, `source_ref_id`; `page_mentions.mention_type`, `entity_type`, `entity_key`, `entity_name`, `matched_text`; `page_topics.topic_key`, `topic_name`, `topic_type`, `keywords_json`; `page_sentiments.target_type`, `target_key`, `target_name`, `target_ref_type`, `target_ref_id` | First-class page-local entity/topic references. These should map to canonical references by `entity_type/topic` and key/name while preserving page-local IDs as evidence metadata. |
| Page relationship matches | `page_brand_matches.brand_ref_type`, `brand_ref_id`, `brand_key`, `brand_name`; `page_competitor_matches.site_competitor_id`; `page_campaign_matches.campaign_id`; `page_market_pack_matches.market_pack_key`, `market_pack_name` | Brand/competitor/campaign/market references. FK-backed matches should keep the FK as source metadata and expose canonical references for cross-module joining. |
| SERP and GEO visibility | `page_serp_observations.query`, `country`, `page_url`, `domain`, `title`, `snippet`, `serp_features_json`, `competitor_presence_json`, `keyword_intent`; `page_geo_observations.query`, `provider`, `model`, `locale`, `cited_url`, `cited_domain`, `mentioned_brands_json`, `mentioned_competitors_json`, `answer_summary`, `raw_payload_json` | Query/topic, country/market, domain/source, brand, competitor, model/provider references. Provider/model are technology/source references, not companies unless explicitly mapped. |
| Page scoring and reports | `page_scores.breakdown_json`, `evidence_json`, `metadata_json`; `page_intelligence_reports.market_pack_id`, `market_pack_key`, `payload_json`, `provenance_json`; `page_intelligence_report_snapshot_allocations.market_pack_key`; `scheduled_page_intelligence_briefings.market_pack_key`, `recipients_json`, `delivery_channels_json` | Mostly evidence and report-scope references. Mapping should be read-only and should not rewrite report payloads. |
| Performance Intelligence | `PerformanceSignal.subject_type`, `subject_key`, `subject_name`; `PerformancePageSummary.topics`, `entities`, `channels`; `PerformanceTopicSummary.topic_key`, `topic_name`; `PerformanceMarketPackSummary.market_pack_key`, `market_pack_name`; classifier dimensions `page`, `page_path`, `landing_page`, `url`, `topic`, `query`, `keyword`, `entity`, `brand`, `competitor`, `content`, `post`, `market_pack`, `market` | In-memory references derived from observations, pages, topics, entities, channels, content, and market packs. These are ideal early adopters for opt-in read-time canonical references. |
| Connector observations | `marketing_observations.metric_key`, `external_id`, `source_metadata_json`, `raw_metadata_json`; `marketing_observation_dimensions.dimension_key`, `dimension_value`, `dimension_value_normalized`, `metadata_json`; `marketing_attributions.attribution_type`, `attributed_type`, `attributed_id`, `attribution_key`, `attribution_value`; connector provider/account/dataset keys, names, external IDs, config JSON | Observation dimensions carry provider-specific entity names. GA4: `pagePath`, `sessionSource`, `sessionMedium`, `sessionCampaign`, `deviceCategory`, `country`, `defaultChannelGroup`. GSC: `query`, `page`, `country`, `device`, `searchAppearance`. LinkedIn: `organization`, `post`, `mediaType`, `campaign`, `content`. |
| Agentic Marketing | `agentic_marketing_objectives.name`, `goal`, `audience`, `target_market`, `languages`, `industry`, `brand_entities`, `competitors`, `channels`, `payload`; opportunities/actions/runs/run-items `title`, `type`, `payload`, `result`; execution pipeline `input`, `result`, `rollback_snapshot`; assets `title`, `payload`, `assetable`; orchestration `workflow_key`, `shared_context`, `input`, `normalized_result`; agent memories `memory_key`, `payload`; conflicts `claims`, `resolution` | Objective-level brand, competitor, market, audience, channel, content, and topic references. Payload references are intentionally flexible and must be mapped through read-time extractors only. |
| Marketing OS | `marketing_themes.name`, `market_pack_key`, `metadata_json`; `marketing_objectives.name`, `target_metric_key`, `market_pack_key`, `topics_json`, `entities_json`, `channels_json`; `marketing_initiatives.name`, `topics_json`, `entities_json`, `competitors_json`, `channels_json`, `market_pack_key`; `marketing_priorities.evidence_json`; `marketing_workflows.workflow_key`, `stages_json`, `gates_json`; `marketing_timeline_events.resource_type`, `resource_id`, `resource_key`, `resource_title`; `marketing_reviews.evidence_json`; `marketing_operating_links.resource_type`, `resource_id`, `resource_key`, `resource_title`, `resource_model` | Operating projection references. Resource links should remain the MOS-owned graph projection; canonical references can be attached in read models or future additive metadata. |
| Competitors | `site_competitors.name`, `domain`, `notes`; `competitor_intelligence_runs.input`, `result`; `competitor_content_items.url`, `title`, `detected_topics`, `detected_entities`, `normalized_payload`; `competitor_topic_signals.topic`, `topic_hash`, `entities`, `examples`; `competitor_content_opportunities.topic`, `competitor_evidence`, `argusly_coverage`, `normalized_payload`; `market_pack_competitors.key`, `name`, `domain`, `aliases_json` | Competitor/domain/topic/entity references. `site_competitor_id` is authoritative inside a site; market-pack competitors are reusable templates and need scoped mapping to site competitors. |
| Brands and companies | `company_profiles.company_name`, `industry`, `key_services`, `value_propositions`, `proof_points`, `target_audience`; `brand_contexts.structured_json`; `company_intelligence_profiles.brand_key`, `company_name`, `market_category`, `products_services`, `regions`, `locales`, `icps`, `personas`, `buyer_roles`, `primary_topics`, `authority_areas`, `target_entities`, `strategic_keywords`, `direct_competitors`, `indirect_competitors`, `aspirational_competitors`, `normalized_payload`; `brand_voices.name`, `preferred_terminology`, `disallowed_terminology` | Company, brand, product, market, country, persona/person, topic, and competitor references. Company intelligence should seed canonical references but not be treated as a global canonical table. |
| Topics and opportunities | `content_clusters.topic_keyword`; `content_opportunities.title`, `target_audience`, `primary_search_intent`, `related_entities`, `source_signals`, `query_intent_payload`, `normalized_payload`; `programmatic_clusters.base_topic`; `programmatic_cluster_items.variable_value`, `briefing_requirements`, `ai_visibility_requirements`; `campaign_clusters.topic`, `campaign_cluster_items.topic`; `opportunity_signals.topic`, payload/evidence fields; `link_opportunities.anchor_text`, source/target content refs | Topic, audience, entity, content, and query-intent references. Query strings can be topics for mapping but should retain source context because some are search terms, not durable entities. |
| LLM / AI visibility | `llm_tracking_queries.name`, `query_text`, `query_variants`, `target_brand`, `target_domain`, `brand_terms`, `competitor_terms`, `target_urls`, `tags`; `llm_tracking_query_runs.model`, `prompt_variant_text`, `authority_entities`, `parsed_payload`, detected brand/competitor fields from later migrations; `llm_tracking_aggregates.metrics`; `llm_authority_entity_candidates.brand_name`, `normalized_name`, `entity_category`, `source_urls`, `provider_breakdown`, `query_breakdown`, `evidence`; `llm_authority_learnings.provider`, `title`, `summary`, `evidence`, `recommended_action` | Brand/company/competitor/domain/topic/provider/model references. `brand_name` on authority candidates can describe competitors or benchmarks, so `entity_category` and optional `site_competitor_id` must inform mapping. |
| Reports, briefings, and research | `briefs.title`, `primary_keyword`, `audience`, `intent`, `notes`, `client_refs`; `research_projects.name`, `target_keywords`, `config`, `summary`; `research_findings.finding_type`, `finding_text`, `citations`, `meta`; source brief payloads in generated jobs/services | Topic, audience/persona, source/domain, citation, and briefing-scope references. These should contribute evidence and candidate aliases, not mutate canonical mappings directly. |

## Duplicate Naming and Normalization Risks

- `entity_key`, `topic_key`, `brand_key`, `topic_hash`, `normalized_name`, and `dimension_value_normalized` are generated by different local rules.
- Domains appear as full URLs, paths, hostnames, cited domains, target URLs, and connector dataset IDs.
- Brands and companies overlap: `company_name`, `brand_key`, `brand_name`, `target_brand`, and `brand_terms` may describe the same real-world entity.
- Competitors appear as `site_competitor_id`, competitor domains, market-pack competitor templates, JSON arrays, LLM authority candidates, and page competitor evidence.
- Topics, keywords, queries, authority areas, search intents, and programmatic variables are semantically close but not always the same entity type.
- LLM provider/model names can be technologies, sources, or companies depending on context.
- Connector dimension casing and naming varies by provider (`pagePath`, `page`, `landing_page`, `post`, `content`).
- Legal suffix stripping would be risky now because it can merge distinct companies; keep suffixes in names and use explicit aliases or mapper rules.
- Market pack keys sometimes represent a market, sometimes a scoring configuration, and sometimes a report scope.
- JSON payloads often carry untyped entity arrays; mapping must preserve `source_field`, source model, and evidence IDs.

## Proposed Mapping Strategy

1. Use `CanonicalEntityReference` as the read-time representation: `{type, name, key, aliases, metadata}`.
2. Normalize conservatively:
   - Squish whitespace and decode HTML entities in names.
   - Normalize type strings to lowercase slug values.
   - Use slug keys for semantic names.
   - Use host-only keys for `domain` references.
   - Deduplicate aliases by canonical alias key, but do not strip legal suffixes or infer mergers.
3. Extract references with field maps per source:
   - `brand_terms` -> `brand`
   - `competitor_terms`, `site_competitor_id` display values -> `competitor`
   - `query`, `keyword`, `topic_name`, `topic` -> `topic`
   - `domain`, URL host fields -> `domain`
   - connector provider/dataset/source fields -> `source`
4. Preserve provenance in metadata: `source_field`, source model/table, source ID, workspace/site scope, evidence IDs, and provider.
5. Use `EntityReferenceMapper` as an opt-in substitution layer. It can map local normalized references to a chosen canonical reference without changing storage.
6. Prefer FK-backed scope where it exists. For example, a `site_competitor_id` match is stronger than a free-form competitor name.
7. Keep MOS `MarketingOperatingLink` and evidence bags as graph/evidence projections for now. Canonical entity persistence should come later only after mapper telemetry confirms safe merges.

## Backward-Compatible Adoption Plan

1. Phase 2: ship this inventory, `EntityReferenceNormalizer`, `EntityReferenceResolver`, `EntityReferenceMapper`, and a fake mapper for tests.
2. Add read-only extraction in candidate services one domain at a time, starting with Performance Intelligence summaries and LLM tracking payloads.
3. Log or test canonical references in memory only; do not write them into existing JSON payloads.
4. Compare mapped references against existing FKs and hashes to find collisions, missed aliases, and over-merges.
5. Add feature-specific mappers where explicit ownership exists, such as site competitor mapping and company intelligence brand mapping.
6. Once stable, propose additive tables for canonical entities, aliases, and links in a later phase with backfill and rollback plans.
7. Keep all existing columns and payload readers until every consumer can read both old local references and future canonical references.

## Tests Added

- Normalizer coverage for names, aliases, domain keys, and table-free references.
- Resolver coverage for payload field maps and opt-in fake mapper substitution.
- Architecture guard that no `canonical_entities`, `canonical_entity_aliases`, or `canonical_entity_links` migrations exist in Phase 2.
