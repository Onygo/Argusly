# Agentic Opportunity Signal Validation

Phase 3F validates Phase 3E promoted Agentic `OpportunitySignal` rows before any dual-read or execution ownership change.

## Command

Use:

```bash
php artisan mos:validate-agentic-opportunity-signals
```

Options:

- `--workspace=`
- `--objective=`
- `--site=`
- `--source-id=`
- `--detector=`
- `--limit=`

The command is read-only. It does not create `Opportunity` records, does not run detectors, does not plan actions and does not mutate Agentic opportunities, actions, runs or execution pipelines.

## Validation Service

`App\Services\Mos\Opportunity\AgenticMarketing\AgenticOpportunitySignalValidationService` inspects promoted Agentic signals and returns:

- signal, workspace, site, content, objective and legacy Agentic opportunity ids;
- detector key, Agentic type, source, category and dedupe hash;
- signal strength and confidence;
- evidence completeness and metadata completeness;
- whether the legacy source row exists;
- linked canonical opportunity ids;
- duplicate signal risk and stale source risk;
- eligibility for the normal `OpportunityIntelligenceEngine`;
- blocked reasons.

## Quality Criteria

A promoted Agentic signal is eligible when:

- workspace exists;
- objective id is present and points to an existing `AgenticMarketingObjective`;
- metadata points to `AgenticMarketingOpportunity` with a source id;
- the legacy Agentic source row exists;
- detector key and Agentic type are present;
- source and category are valid canonical enum values;
- topic/title and dedupe hash are present;
- evidence contains detector and legacy-source traceability;
- metadata contains source model/id, objective id, detector key, Agentic type and source-scoped dedupe key;
- no duplicate signal risk is detected.

Missing evidence, missing metadata, stale source rows and duplicate signal risk block eligibility. Linked signals can still be eligible; linked/unlinked status only reports whether the normal canonical engine has already clustered them.

## Canonical Engine Compatibility

The existing `OpportunityIntelligenceEngine` already consumes promoted Agentic signals because it reads all non-deleted `OpportunitySignal` rows in a workspace and clusters them by the existing category/source/topic context. Phase 3F does not add an Agentic-specific engine and does not create opportunities directly from Agentic migration services.

The only compatibility addition is traceability when the normal engine links promoted Agentic signals: canonical opportunity metadata and `source_signal_summary` now preserve Agentic legacy opportunity ids, objective ids and detector keys.

## Remaining Blockers Before Phase 3G Dual-Read

- Decide how canonical opportunities and legacy Agentic opportunities should be selected when both exist for the same strategic work.
- Define how Agentic action planning resolves canonical-linked source rows without duplicating open actions.
- Keep execution pipeline parent and payload continuity rules explicit before repointing any execution consumers.
- Keep duplicate signal validation green before broad rollout of Phase 3E promotion.

## Phase 3G Read-Model Interaction

Phase 3G introduces `AgenticOpportunityCanonicalReadService` and `php artisan mos:inspect-agentic-canonical-read-model` as read-only dual-read diagnostics. The service can display canonical strategic context for a legacy Agentic row when a safe bridge exists, but it does not depend on promoted signal presence and does not validate or mutate `OpportunitySignal` rows.

Signal-validation output remains the authority for promoted-signal quality. The Phase 3G read model only reports linked canonical context, field provenance, fallback usage, execution-state dependency samples and blocked bridge/read reasons.

## Phase 3H Action Dedupe Interaction

Phase 3H does not change signal validation or promoted signal eligibility. It adds `AgenticOpportunityActionSignatureService`, `AgenticOpportunityActionDedupeInspectionService` and `php artisan mos:inspect-agentic-action-dedupe` to inspect whether linked legacy/canonical Agentic opportunities would duplicate action planning.

The action signature contract reuses Phase 3B source-scoped dedupe keys when available, but promoted signals remain source evidence only. A promoted signal being valid does not make canonical action creation safe. Future planner migration still requires canonical-equivalent action signatures, legacy execution parent continuity and explicit lifecycle mapping.
