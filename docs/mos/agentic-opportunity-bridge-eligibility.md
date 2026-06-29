# Agentic Opportunity Bridge Eligibility

Phase 3C adds read-only diagnostics for existing `AgenticMarketingOpportunity` rows. It determines whether a legacy Agentic row is ready for future canonical signal promotion, canonical `Opportunity` linking through `opportunities.agentic_marketing_opportunity_id`, both, or neither.

No writer exists in this phase. The diagnostics do not create canonical opportunities, create opportunity signals, backfill bridge links, run detectors, dispatch queues, plan actions or change execution behaviour.

## Service

`App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityBridgeEligibilityService` accepts an existing `AgenticMarketingOpportunity` and reuses the Phase 3B `AgenticOpportunityCanonicalMappingService`.

The service returns `AgenticOpportunityBridgeEligibilityResult` with:

- legacy Agentic opportunity id, objective id, workspace id, site id and content id;
- detector key, Agentic type and Agentic status;
- Phase 3B detector classification and full mapping result;
- canonical `Opportunity` ids already linked through `agentic_marketing_opportunity_id`;
- unbridged canonical `Opportunity` candidates matching the Phase 3B source-scoped dedupe key;
- duplicate bridge and duplicate strategic opportunity risk flags;
- open Agentic action count;
- execution pipeline count;
- growth asset and programmatic opportunity reference counts;
- campaign-cluster materialization reference count;
- signal eligibility, canonical opportunity eligibility and execution blocker status;
- blocked reasons;
- final eligibility status;
- recommended future action.

## Eligibility Statuses

| Status | Meaning |
| --- | --- |
| `signal_ready` | The row can safely be considered for future `OpportunitySignal` promotion. It is not a canonical opportunity link candidate. |
| `canonical_link_ready` | A future guarded writer may link or create a canonical `Opportunity` for the row without signal promotion. This is reserved for future `opportunity_only` classifications. |
| `signal_and_canonical_ready` | The row has required context and Phase 3B says it can produce both a signal preview and canonical opportunity preview. No duplicate or execution-state blocker was found. |
| `execution_blocked` | Signal promotion may still be safe, but canonical linking must wait because open Agentic actions, execution pipelines, growth assets or programmatic references depend on the legacy id. |
| `missing_context` | Required workspace, objective, type, title, detector, topic/title or dedupe context is incomplete. All migration readiness is blocked. |
| `duplicate_risk` | Existing canonical links, source-scoped dedupe matches or campaign-cluster materialization references could create duplicate strategic opportunities. Canonical linking is blocked. |
| `blocked` | The detector is not classified, Phase 3B mapping is blocked, or no safe signal/canonical path exists. |

## Duplicate Risk Rules

The diagnostics report duplicate bridge risk when more than one canonical `Opportunity` already points at the same legacy row through `opportunities.agentic_marketing_opportunity_id`.

They report duplicate strategic opportunity risk when:

- a canonical `Opportunity` in the same workspace has the Phase 3B dedupe key but no bridge to this Agentic row;
- campaign-cluster materialization payload references an existing campaign cluster or campaign cluster item that may already represent the strategic work item.

The service does not repair duplicates, merge records, update dedupe hashes or attach bridges.

## Execution-State Blockers

Open Agentic actions and existing execution pipelines are not signal blockers. They are canonical writer blockers because current execution consumers still resolve `agentic_marketing_opportunities.id`.

Growth assets and programmatic opportunities that already reference the Agentic row are also reported as execution-state dependencies. They show where a later canonical bridge writer would need continuity rules before moving ownership.

## Command

Use:

```bash
php artisan mos:inspect-agentic-opportunity-bridges
```

Options:

- `--workspace=`
- `--objective=`
- `--site=`
- `--source-id=`
- `--status=`
- `--detector=`
- `--limit=`

The command reports total inspected rows, readiness counts, execution-blocked count, duplicate-risk count, missing-context count, blocked count, existing canonical link count, open action count, execution pipeline count, sample blocked reasons and dedupe key samples.

## Why No Writer Exists Yet

The passive bridge column already exists, but no writer should populate it until Phase 3D defines guarded write rules. Agentic action planning, autonomous execution, pipeline assets, approvals, growth assets and programmatic detection still depend on legacy Agentic ids. Writing canonical opportunities or bridge links now could create duplicate strategic opportunities or split execution state.

## Next Phase Recommendation

Phase 3D should plan a guarded bridge writer only for rows that Phase 3C marks `signal_and_canonical_ready` or future `canonical_link_ready`. It should remain dry-run-first, preserve legacy execution continuity, prevent dedupe collisions and explicitly decide whether signal promotion happens before or with canonical linking.

## Phase 3D Bridge Writer

Phase 3D adds `App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunityBridgeWriter` and:

```bash
php artisan mos:link-agentic-opportunities
```

The writer consumes this eligibility result directly. It only considers `canonical_link_ready` and `signal_and_canonical_ready` rows for canonical bridge writes. It reports `missing_context`, `duplicate_risk`, `execution_blocked` and `blocked` rows without writing.

The command is dry-run-first and supports:

- `--apply`
- `--workspace=`
- `--objective=`
- `--site=`
- `--source-id=`
- `--status=`
- `--detector=`
- `--limit=`

Dry-run works even when `features.mos_agentic_marketing_opportunity_bridge_writer` is disabled. Apply requires both `--apply` and `ARGUSLY_FEATURE_MOS_AGENTIC_MARKETING_OPPORTUNITY_BRIDGE_WRITER=true`.

Phase 3D still does not create `OpportunitySignal` rows, mutate Agentic opportunities, mutate Agentic actions, update execution pipelines or repoint execution foreign keys.

## Phase 3F Signal Validation Boundary

Phase 3F validates promoted Agentic `OpportunitySignal` rows separately from bridge eligibility:

```bash
php artisan mos:validate-agentic-opportunity-signals
```

Bridge eligibility answers whether a legacy Agentic row can be linked to or represented by a canonical `Opportunity`. Signal validation answers whether a Phase 3E promoted signal is complete enough for the existing `OpportunityIntelligenceEngine` to cluster, score and link it.

The validator reports linked and unlinked signals, but it does not create bridges, repair duplicate bridge risks, update `opportunities.agentic_marketing_opportunity_id` or mutate Agentic execution state. Duplicate promoted signal risk blocks signal eligibility until the source-scoped dedupe/source-id collision is understood.
