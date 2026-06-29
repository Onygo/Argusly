# Agentic Detector Canonical Mapping

Phase 3B defines a read-only mapping and dedupe contract for Agentic Marketing detector outputs. It does not migrate records, create `Opportunity` rows, create `OpportunitySignal` rows, dispatch queues, run detectors from diagnostics or change Agentic execution behaviour.

## Mapping Service

`App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityCanonicalMappingService` maps:

- `DetectedOpportunity`
- `AgenticMarketingObjective`
- detector key/source type
- optional existing `AgenticMarketingOpportunity`
- optional explicit context

The service returns `AgenticCanonicalMappingResult` with:

- detector classification;
- signal capability;
- canonical opportunity candidate capability;
- execution-only status;
- missing context and blocked reasons;
- canonical signal preview;
- canonical opportunity preview;
- source-scoped dedupe key;
- risk level.

DTOs are serializable through `toArray()` and are diagnostics-only.

## Detector Classification

| Detector/output | Classification | Signal preview | Opportunity preview | Notes |
| --- | --- | --- | --- | --- |
| `refresh_lifecycle` / `RefreshLifecycleOpportunityDetector` | `signal_only` | Yes | No | Lifecycle/decay observation should enter canonical MOS as `OpportunitySignal`. |
| `internal_links` / `InternalLinkOpportunityDetector` | `signal_only` | Yes | No | Tactical link suggestions are evidence; execution stays specialized. |
| `localization_gaps` / `LocalizationGapOpportunityDetector` | `signal_only` | Yes | No | Locale gap observation, not execution state. |
| `structured_answer_gaps` / `StructuredAnswerGapOpportunityDetector` | `signal_only` | Yes | No | Answer coverage evidence for later canonical opportunity clustering. |
| `seo_indexability` / `SeoIndexabilityOpportunityDetector` | `signal_only` | Yes | No | SEO/indexability observations become canonical evidence first. |
| `ai_visibility_gaps` / `AiVisibilityGapOpportunityDetector` | `signal_only` | Yes | No | AI visibility metrics are observed signals. |
| `llm_tracking_ai_visibility` / `LlmTrackingAiVisibilityOpportunityDetector` | `signal_only` | Yes | No | LLM tracking brand/citation/competitor observations are signals. |
| `content_network_gaps` / `ContentNetworkGapOpportunityDetector` | `signal_and_opportunity` | Yes | Yes | Can represent a reviewable strategic content-network work item when cluster context exists. |
| `campaign_cluster_action_materializer` | `signal_and_opportunity` | Yes | Yes | Blocked unless stable campaign-cluster context and payload dedupe key exist. |
| unknown additional detector | `blocked` | No | No | Must be explicitly classified before any canonical writer phase. |

## Signal Mapping

Signal previews map:

- `workspace_id`
- `organization_id`
- `objective_id`
- `client_site_id` when available
- `content_id` when available
- detector key
- Agentic opportunity type
- topic/title
- canonical signal source
- canonical category
- signal strength
- confidence
- priority
- metrics
- evidence
- metadata
- legacy source model/id when an existing Agentic row exists
- source-scoped dedupe key

Source mapping:

| Detector | `OpportunitySignal.source` |
| --- | --- |
| `refresh_lifecycle` | `content_decay` |
| `internal_links` | `internal_analytics` |
| `content_network_gaps` | `content_cluster` |
| `campaign_cluster_action_materializer` | `content_cluster` |
| `ai_visibility_gaps` | `ai_citation_tracking` |
| `llm_tracking_ai_visibility` | `ai_citation_tracking` |
| Other classified signal outputs | `signal_intelligence` |

Category mapping:

| Agentic type | Canonical category |
| --- | --- |
| `refresh` | `refresh_opportunity` |
| `ai_visibility`, `answer_coverage` | `ai_visibility_opportunity` |
| `internal_links`, `locale_expansion`, `seo_indexability`, `new_article`, `content_network`, `metadata`, `schema` | `content_gap` |

## Opportunity Candidate Mapping

Opportunity previews are produced only for `signal_and_opportunity` and future `opportunity_only` outputs.

They map:

- title;
- summary;
- category/type;
- workspace, site, content and objective context;
- priority;
- confidence;
- impact;
- effort;
- business value;
- recommended actions;
- evidence;
- source signal summary;
- dedupe key;
- legacy Agentic traceability metadata.

No preview is persisted in Phase 3B.

## Dedupe Contract

The source-scoped dedupe key is `sha256` over stable JSON with:

- contract version `agentic-detector-output:v1`;
- workspace id;
- objective id;
- detector key;
- Agentic opportunity type;
- content id when available;
- client site id when available;
- locale when available;
- normalized topic/title;
- stable payload fingerprint.

The stable payload fingerprint includes:

- detector;
- signal type;
- explicit payload `dedupe_key`;
- payload content id;
- references;
- stable signal identity fields such as ids, locale, gap type, query text, topic keyword, campaign cluster ids and materialized action type.

It intentionally strips volatile timestamp-style fields such as `created_at`, `updated_at`, `captured_at`, `generated_at`, `materialized_at` and keys ending in `_at`. Score refreshes and timestamp movement alone should not create a new canonical dedupe key.

The key distinguishes:

- different workspaces;
- different Agentic objectives;
- different sites;
- different content;
- different detector keys;
- different Agentic types;
- different stable campaign cluster or content cluster contexts.

## Required Context And Blocking

All outputs require:

- workspace id;
- objective id;
- detector key;
- Agentic opportunity type;
- topic or title;
- dedupe key.

`content_network_gaps` additionally requires `signals.cluster_id` to emit a canonical opportunity preview safely.

`campaign_cluster_action_materializer` additionally requires `signals.campaign_cluster_id` and a stable payload `dedupe_key`.

Missing context is reported as `missing_context` and converted into `blocked_reasons`. Missing context is not inferred from unrelated records.

## Diagnostics

Use:

```bash
php artisan mos:map-agentic-detector-outputs
```

Options:

- `--workspace=`
- `--objective=`
- `--detector=`
- `--limit=`
- `--sample`

The command is read-only. It inspects existing `AgenticMarketingOpportunity` rows and maps them through the Phase 3B contract. It does not run live detectors because detectors may perform broad workspace scans and this command is intended for bounded diagnostics, not detection.

`--sample` adds deterministic in-memory sample mappings for known detectors. Samples do not persist rows and do not call detector implementations.

The command reports:

- detector classifications;
- signal-capable count;
- opportunity-capable count;
- execution-only count;
- blocked count;
- missing context reasons;
- dedupe key samples.

## Phase 3C Bridge Eligibility

Phase 3C builds on this mapping contract with `AgenticOpportunityBridgeEligibilityService` and:

```bash
php artisan mos:inspect-agentic-opportunity-bridges
```

The bridge inspector maps each existing Agentic row with this Phase 3B service, then checks whether existing canonical `Opportunity` rows already point to the legacy row, whether the Phase 3B dedupe key matches an unbridged canonical opportunity, and whether Agentic actions, execution pipelines, growth assets or programmatic opportunities still depend on the legacy id.

Eligibility statuses are `signal_ready`, `canonical_link_ready`, `signal_and_canonical_ready`, `execution_blocked`, `missing_context`, `duplicate_risk` and `blocked`. Duplicate bridge or dedupe risks block canonical linking. Execution-state dependencies do not block future signal promotion, but they block canonical writer phases until continuity is planned.

The bridge inspector is also read-only. It does not create `OpportunitySignal`, create `Opportunity`, backfill `opportunities.agentic_marketing_opportunity_id`, run detectors, dispatch queues or change Agentic execution state.

## Verification

Phase 3B verification:

```bash
vendor/bin/pint app/Services/Mos/Opportunity/AgenticMarketing app/Console/Commands/MosMapAgenticDetectorOutputsCommand.php tests/Unit/Mos/AgenticDetectorCanonicalMappingTest.php tests/Feature/Mos/AgenticDetectorMappingCommandTest.php
php -l app/Services/Mos/Opportunity/AgenticMarketing/AgenticDetectorClassification.php
php -l app/Services/Mos/Opportunity/AgenticMarketing/AgenticCanonicalSignalPreview.php
php -l app/Services/Mos/Opportunity/AgenticMarketing/AgenticCanonicalOpportunityPreview.php
php -l app/Services/Mos/Opportunity/AgenticMarketing/AgenticCanonicalMappingResult.php
php -l app/Services/Mos/Opportunity/AgenticMarketing/AgenticOpportunityCanonicalMappingService.php
php -l app/Console/Commands/MosMapAgenticDetectorOutputsCommand.php
php artisan test tests/Unit/Mos/AgenticDetectorCanonicalMappingTest.php tests/Feature/Mos/AgenticDetectorMappingCommandTest.php
php artisan test tests/Feature/AgenticMarketing
php artisan test tests/Feature/Mos tests/Unit/Mos
php artisan list mos
```

Results:

- Scoped Pint passed after mechanical formatting fixes.
- PHP lint passed for all new Phase 3B PHP files.
- Phase 3B mapping tests passed: 8 tests, 95 assertions.
- Existing Agentic Marketing feature tests passed: 124 tests, 813 assertions.
- Existing MOS feature/unit tests passed: 95 tests, 745 assertions.
- `php artisan list mos` passed and listed `mos:map-agentic-detector-outputs`.

## Next Phase

Phase 3D should plan a guarded bridge writer only after Phase 3C diagnostics show which rows are signal-ready, canonical-ready, duplicate-risk or execution-state-dependent.

## Phase 3D Writer Use Of This Mapping

Phase 3D reuses the `AgenticCanonicalOpportunityPreview` only for rows that Phase 3C reports as `canonical_link_ready` or `signal_and_canonical_ready`. The writer does not call live detectors and does not change detector persistence.

Canonical opportunity writes use the preview fields for title, summary, category/type, workspace/site/content/objective context, score fields, evidence, recommended actions, source-signal summary, metadata and the source-scoped dedupe key. The writer adds Phase 3D bridge metadata around the preview, including the legacy Agentic opportunity id, detector key, Agentic type/status, payload snapshot and execution continuity note.

Signal previews remain read-only in Phase 3D. `OpportunitySignal` promotion is intentionally deferred to a later phase.

## Phase 3E Signal Promotion Use Of This Mapping

Phase 3E promotes existing `AgenticMarketingOpportunity` rows into canonical `OpportunitySignal` rows through `AgenticOpportunitySignalPromotionService` and:

```bash
php artisan mos:promote-agentic-opportunity-signals
```

The promotion service reuses the Phase 3B `AgenticCanonicalSignalPreview` and requires `canEmitSignal`. It writes only `OpportunitySignal` rows, scoped by workspace plus the Phase 3B dedupe key. It does not create canonical `Opportunity` rows, does not link `opportunities.agentic_marketing_opportunity_id`, and does not update Agentic opportunities, actions or execution pipelines.

Eligible mappings are every `signal_only` detector and the signal side of `signal_and_opportunity` detectors when their required context is complete. Unknown or blocked detector mappings and missing context remain blocked. Phase 3D bridge status is not required: `signal_ready`, `signal_and_canonical_ready` and execution-state-dependent rows may all be promoted when the Phase 3B signal mapping is safe.

The promoted signal payload preserves:

- source, category, topic, signal strength and confidence from the signal preview;
- workspace, site, content and objective context;
- preview metrics, evidence and metadata;
- legacy Agentic opportunity id, source model/source id, detector key, Agentic type/status and source-scoped dedupe key;
- promotion metadata with version `agentic-opportunity-signal-promotion:v1`, promoted-at and optional promoted-by.

The feature flag `features.mos_agentic_marketing_opportunity_signal_promotion`, backed by `ARGUSLY_FEATURE_MOS_AGENTIC_MARKETING_OPPORTUNITY_SIGNAL_PROMOTION`, defaults off. Dry-run works with the flag disabled; apply requires `--apply` and the flag enabled.

## Phase 3F Signal Consumer Validation

Phase 3F adds read-only validation for the promoted signal shape through:

```bash
php artisan mos:validate-agentic-opportunity-signals
```

The validator checks that Phase 3E signals still contain the Phase 3B detector key, Agentic type, objective id, legacy Agentic source id, source-scoped dedupe key, evidence and valid canonical source/category values. It also reports stale legacy source rows, duplicate signal risk, existing canonical opportunity links and unlinked-but-eligible signals.

`OpportunityIntelligenceEngine` does not need an Agentic-specific query path because it already consumes all non-deleted workspace `OpportunitySignal` rows. The only Phase 3F compatibility addition is preserving Agentic legacy opportunity ids, objective ids and detector keys in canonical opportunity metadata when the normal engine links promoted Agentic signals.
