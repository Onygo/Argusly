# ContentOpportunity Consolidation Completion Audit

Date: 2026-06-27

Phase: 2P audit gate for ContentOpportunity MOS consolidation, covering Phase 2C through Phase 2O.

Recommendation: complete with follow-up.

The ContentOpportunity consolidation slice is ready to be considered behaviorally complete for the purpose of moving the next migration target to `AgenticMarketingOpportunity`. Default production behavior remains unchanged, all targeted test suites pass, and no legacy tables or default app flows have been removed. The remaining items are policy/cleanup follow-ups rather than migration blockers.

## Implemented phases summary

| Phase | Implemented surface | Audit result |
| --- | --- | --- |
| 2C | Canonical link service and `mos:link-content-opportunities` dry-run/apply command. | Complete. Explicit apply only; no default behavior change. |
| 2D | Canonical read model/service for linked `ContentOpportunity` rows. | Complete. Read-only dual-read for campaign planning and shared agentic context. |
| 2E | Lifecycle map, lifecycle diagnostics, and brief handoff planning. | Complete. Diagnostic/read-only by default. |
| 2F | Recommended action canonical-equivalent signatures and dedupe diagnostics. | Complete. No visible action ownership change by default. |
| 2G | `ContentOpportunityRun` canonical reference inspection and optional run summary write. | Complete with note: `--write-summary` is opt-in but not feature-flagged. |
| 2H | Growth/autopilot handoff planner. | Complete. Read-only duplicate execution risk planning. |
| 2I | Canonical brief action planner. | Complete with cleanup candidate: empty subclass alias remains. |
| 2J | Canonical brief payload builder/writer and dry-run-first command. | Complete. Command apply is explicit; visible route is still legacy by default. |
| 2K | Feature-flagged visible brief route integration. | Complete. Default flag is false, so route uses legacy writer in production defaults. |
| 2L | Canonical lifecycle sync service and command. | Complete with policy note: command apply is explicit, while the feature flag currently reserves future app-flow integration. |
| 2M | Recommended action duplicate repair metadata. | Complete. Apply annotates metadata only. |
| 2N | Canonical growth asset and autopilot queue writers. | Complete. Apply requires explicit command intent and service-level default-off flags. |
| 2O | Canonical action ownership resolver and CTA source-link planning. | Complete. Default flag is false, so recommended actions keep legacy CTAs. |

## Service inventory

Core MOS:

| Service/contract | Purpose | Mutates data by default |
| --- | --- | --- |
| `MosProvider`, `MosOpportunityProvider` | Provider contracts for MOS discovery and opportunity-compatible adapters. | No |
| `MosProviderRegistry`, `MosDomain` | Provider lookup, diagnostics, capability map and duplicate-key guard. | No |
| `OpportunityIntelligenceMosProvider`, `SignalIntelligenceMosProvider`, `AgentWorkflowMosProvider` | Read-only MOS provider adapters for existing canonical domains. | No |
| `AbstractLegacyOpportunityProvider` and legacy opportunity providers | Normalize legacy opportunity-like records into `CanonicalOpportunityCandidate`. | No |
| `ContentOpportunityProvider` | Read-only adapter for legacy `ContentOpportunity`. | No |

ContentOpportunity bridge:

| Service | Purpose | Mutates data by default |
| --- | --- | --- |
| `ContentOpportunityCanonicalLinkService` | Creates/links canonical `Opportunity` only when called with apply intent. | No |
| `ContentOpportunityCanonicalReadService` and read model | Canonical-aware read layer with legacy fallback and provenance. | No |
| `ContentOpportunityLifecycleMap` | Maps safe legacy/canonical statuses and reports conflicts. | No |
| `ContentOpportunityCanonicalLifecycleSyncService` | Syncs lifecycle only when `apply=true` and link integrity is safe. | No |
| `ContentOpportunityBriefHandoffPlanner` | Plans future brief handoff readiness. | No |
| `ContentOpportunityCanonicalBriefActionPlanner` | Empty alias of brief handoff planner for command naming. | No |
| `ContentOpportunityBriefPayloadBuilder` | Shared legacy-compatible brief payload builder. | No |
| `ContentOpportunityCanonicalBriefWriter` | Creates briefs only when called with apply intent and safety checks pass. | No |
| `ContentOpportunityRecommendedActionSignature` | Canonical-equivalent recommended action signatures. | No |
| `ContentOpportunityRecommendedActionDedupeService` | Inspects duplicate legacy/canonical recommended actions. | No |
| `ContentOpportunityRecommendedActionRepairService` | Optional metadata-only duplicate annotation. | No |
| `ContentOpportunityRunCanonicalReferenceService` | Inspects run link coverage; optionally writes summary metadata. | No |
| `ContentOpportunityGrowthHandoffPlanner` | Plans growth/autopilot handoff and duplicate execution risks. | No |
| `ContentOpportunityCanonicalGrowthAssetWriter` | Writes canonical `GrowthAsset` only under apply plus default-off flag. | No |
| `ContentOpportunityCanonicalAutopilotQueueWriter` | Writes canonical autopilot queue item/recommended action only under apply plus default-off flag. | No |
| `ContentOpportunityCanonicalActionOwnershipResolver` | Resolves legacy/canonical action ownership and CTA route based on default-off flag. | No |

Touched downstream consumers:

- `AppContentOpportunityController` uses the shared payload builder and optionally uses the canonical brief writer only when `features.mos_canonical_content_opportunity_brief_writer=true`.
- `RecommendedActionMapper` keeps legacy CTA by default and switches only when canonical action ownership flag is enabled.
- `GrowthAutopilotQueueBuilder` continues to build from recommended actions; canonical writer uses it rather than adding a parallel queue path.
- Campaign cluster and agentic shared-context services use the canonical read service for read-only context enrichment.
- `OpportunityIntelligenceEngine` consumes promoted competitor signals through the existing canonical signal clustering path.

## Command inventory

`php artisan list mos` passed and reports 15 commands.

| Command | Default mode | Apply/write option | Notes |
| --- | --- | --- | --- |
| `mos:providers` | Read-only | None | Provider diagnostics. |
| `mos:promote-competitor-opportunity-signals` | Dry-run | `--apply` | Promotes legacy competitor opportunities into `OpportunitySignal`; no `Opportunity` creation. |
| `mos:validate-competitor-opportunity-signals` | Read-only | None | Validation only because `OpportunityIntelligenceEngine` has no dry-run mode. |
| `mos:link-content-opportunities` | Dry-run | `--apply` | Creates/links canonical `Opportunity` only with explicit apply. |
| `mos:compare-content-opportunity-lifecycle` | Read-only | None | Lifecycle comparison diagnostics. |
| `mos:sync-content-opportunity-lifecycle` | Dry-run | `--apply` | Safe linked lifecycle sync only; no app flow invokes it by default. |
| `mos:plan-content-opportunity-brief-handoff` | Dry-run/read-only | None | Handoff planning only. |
| `mos:plan-content-opportunity-brief-actions` | Read-only | None | Canonical action readiness planning. |
| `mos:create-canonical-content-opportunity-brief` | Dry-run | `--apply`, optional `--mark-planned` | Creates briefs only with explicit apply; `--mark-planned` is extra explicit. |
| `mos:dedupe-content-opportunity-actions` | Dry-run | `--apply` | Apply annotates metadata only; no deletes, dismissals, relinks or route changes. |
| `mos:inspect-content-opportunity-run-links` | Read-only | `--write-summary` | Optional write adds `result.canonical_reference_summary` only. |
| `mos:plan-content-opportunity-growth-handoff` | Read-only | None | Growth/autopilot handoff planning. |
| `mos:write-content-opportunity-growth-assets` | Dry-run | `--apply` | Service also requires `features.mos_canonical_content_opportunity_growth_writer=true`. |
| `mos:write-content-opportunity-autopilot-queue` | Dry-run | `--apply` | Service also requires `features.mos_canonical_content_opportunity_autopilot_writer=true`. |
| `mos:inspect-content-opportunity-action-ownership` | Read-only | None | Reports default-off action ownership status. |

All commands are dry-run-first or read-only except explicitly documented opt-in write modes. No command removes legacy tables or legacy rows.

## Feature flag inventory

All MOS ContentOpportunity flags default false in `config/features.php`.

| Flag | Env var | Default | Current role |
| --- | --- | --- | --- |
| `mos_canonical_content_opportunity_brief_writer` | `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_BRIEF_WRITER` | `false` | Enables visible route to attempt safe canonical brief writer before legacy fallback. |
| `mos_canonical_content_opportunity_lifecycle_sync` | `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_LIFECYCLE_SYNC` | `false` | Reserved for future app-flow lifecycle sync; current command remains explicit apply only. |
| `mos_canonical_content_opportunity_growth_writer` | `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_GROWTH_WRITER` | `false` | Required by canonical growth asset writer apply path. |
| `mos_canonical_content_opportunity_autopilot_writer` | `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_AUTOPILOT_WRITER` | `false` | Required by canonical autopilot queue writer apply path. |
| `mos_canonical_content_opportunity_action_ownership` | `ARGUSLY_FEATURE_MOS_CANONICAL_CONTENT_OPPORTUNITY_ACTION_OWNERSHIP` | `false` | Keeps recommended action CTAs on legacy route unless enabled. |

Flag audit notes:

- Default production behavior is unchanged because all visible/app-flow MOS flags are false.
- Growth and autopilot canonical writers enforce their flags inside the services.
- The brief writer and lifecycle sync commands rely on explicit operator apply intent, not service-level flag enforcement. That matches the docs for command-only operation but should be clarified before any scheduled/app-flow use.

## Default behaviour verification

- Content opportunity UI and filters still select/read `content_opportunities`.
- Default content opportunity brief creation still writes the same `Brief` model, marks the legacy `ContentOpportunity` planned, and records the same action run style.
- The canonical brief route path is disabled by default and falls back to legacy when no safe linked canonical row exists.
- Recommended action mapping remains legacy CTA/source-link by default.
- Growth autopilot queue building remains based on existing recommended action flows by default.
- Campaign planning and agentic shared context use canonical reads only as read-only enrichment; legacy ids are preserved.
- No migration removes or rewrites `content_opportunities`, `content_opportunity_runs`, legacy recommended actions, legacy growth assets, or legacy queue rows.

## Production risk review

Low default-production risk:

- All visible behavior-changing flags default false.
- Most new services are read-only planners, inspectors or DTO builders.
- Writer commands require explicit options and are covered by targeted tests.
- Growth/autopilot canonical writers block duplicate execution references and require default-off flags for apply.

Follow-up risks:

- `mos:inspect-content-opportunity-run-links --write-summary` is an opt-in write but is not feature-flagged. It only writes additive JSON summary metadata, so this is not a production behavior blocker.
- `mos:create-canonical-content-opportunity-brief --apply` and `mos:sync-content-opportunity-lifecycle --apply` are explicit operator writes but not service-level feature-flag gated. Keep them command-only until flag policy is clarified.
- `routes/admin.php` has broad reindent/route grouping churn in the dirty diff. MOS admin diagnostics are tested, but the change surface is wider than the MOS provider route alone.
- Full-project Pint currently fails on widespread pre-existing formatter debt. The scoped MOS Pint check narrows current scoped failure to `routes/app.php`, which is not dirty in this worktree.

## Test coverage review

Requested gates:

| Command | Result |
| --- | --- |
| `php artisan test tests/Feature/Mos tests/Unit/Mos` | Passed: 87 tests, 650 assertions. |
| `php artisan test tests/Feature/ContentOpportunityEngine` | Passed: 9 tests, 73 assertions. |
| `php artisan test tests/Feature/RecommendedActions` | Passed: 4 tests, 29 assertions. |
| `php artisan test tests/Feature/GrowthAutopilot` | Passed: 3 tests, 22 assertions. |
| `php artisan test tests/Feature/AgenticMarketing` | Passed: 124 tests, 813 assertions. |
| `php artisan list mos` | Passed: 15 MOS commands listed. |

Additional touched-test gate:

| Command | Result |
| --- | --- |
| `php artisan test tests/Feature/Admin/AdminMosProvidersPageTest.php tests/Feature/Console/MosProvidersCommandTest.php` | Passed: 2 tests, 125 assertions. |

Syntax/format gates:

| Gate | Result |
| --- | --- |
| `php -l` over MOS services, MOS commands, touched MOS integration files, and MOS tests | Passed. |
| `php -l app/Providers/AppServiceProvider.php` | Passed. |
| `php -l config/features.php` | Passed. |
| `php -l routes/admin.php` | Passed. |
| `php -l routes/app.php` | Passed. |
| `vendor/bin/pint --test` | Failed project-wide on extensive existing formatter debt across migrations, app files, tests, routes, packages and `public/temp.php`. |
| Scoped `vendor/bin/pint --test` on MOS/touched integration files | Failed only on `routes/app.php` for `fully_qualified_strict_types` and `ordered_imports`; `routes/app.php` is not dirty in this worktree. |

## Documentation consistency review

Accurate:

- `docs/mos/opportunity-provider-contract.md` correctly describes provider contracts, canonical link service, dual-read rules, lifecycle planning, dedupe/repair metadata, run references, growth/autopilot writers and action ownership.
- `docs/mos/opportunity-compatibility-map.md` correctly classifies `ContentOpportunity` as a high-risk consolidation candidate and preserves `AgenticMarketingOpportunity` as the next high-risk target.
- `docs/mos/content-opportunity-consumer-audit.md` accurately documents Phases 2C through 2O and repeatedly states that default UI/route/lifecycle behavior remains legacy unless a flag or command apply is explicit.
- Existing docs correctly state that legacy tables are not removed and provider adapters are read-only.

Clarifications to add later:

- Spell out that the lifecycle sync flag is not currently enforced by `mos:sync-content-opportunity-lifecycle --apply`; it is a guard for future app-flow integration.
- Spell out that canonical brief command apply is an operator backfill control and not gated by the visible route flag.
- Consider splitting the long rolling `content-opportunity-consumer-audit.md` into smaller phase notes after this gate.

## Remaining blockers

No MOS behavior blocker was found for completing the ContentOpportunity consolidation slice.

Non-blocking follow-ups:

- Full-project Pint is not green because of broad formatter debt outside this slice.
- Scoped Pint still reports `routes/app.php`, which is not dirty in this worktree.
- Clarify the feature-flag policy for command-only apply paths before turning any command into a scheduled job or app-flow writer.

## Cleanup candidates

- Remove or inline `ContentOpportunityCanonicalBriefActionPlanner` if the command can depend directly on `ContentOpportunityBriefHandoffPlanner`; the subclass is currently an empty alias.
- Consolidate repeated `linkedCanonicalOpportunity()` lookups across read, handoff, dedupe and ownership services if a small shared helper can reduce duplication without adding a new architecture layer.
- Consider narrowing future admin route changes to the specific MOS route instead of broad route-file reindent/churn.
- After this gate, keep `ContentOpportunityBriefPayloadBuilder` as the single payload builder and avoid reintroducing controller-local brief payload helpers.
- Keep canonical growth/autopilot writers as controlled services only; do not add another queue/recommended-action writer path.

## Final recommendation

Recommendation: complete with follow-up.

This slice satisfies the readiness criteria for moving the next migration target to `AgenticMarketingOpportunity`: the audit document exists, commands and flags are inventoried, default production behavior is verified, remaining risks are explicit, tests are green, and no obvious dead parallel implementation remains beyond small cleanup candidates.

Before enabling any canonical ContentOpportunity app-flow behavior in production, resolve the follow-up flag-policy clarification and project/scoped Pint debt. Do not remove legacy ContentOpportunity tables or writers until `AgenticMarketingOpportunity` and downstream execution ownership are audited with the same level of detail.
