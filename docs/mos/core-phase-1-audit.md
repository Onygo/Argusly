# MOS Core Phase 1 Audit

Date: 2026-06-27

## Scope

This phase establishes the first reusable Marketing Operating System core surface without rewriting existing production services. The implementation is intentionally adapter-based and database-neutral.

## Current Findings

- The platform already has canonical Signal Intelligence models and services: `SignalSource`, `SignalEvent`, `SignalDetection`, `SignalSourceRegistry`, detection services, scoring, and promotion into opportunity signals.
- The platform already has a canonical Opportunity Intelligence path: `Opportunity`, `OpportunitySignal`, `OpportunityExecutionPlan`, `OpportunityIntelligenceEngine`, scoring, recommended actions, and signal clustering.
- The platform already has a canonical workflow execution path for agents: `AgentWorkflowInterface`, `AgentWorkflowOrchestrator`, `AgentWorkflowRun`, and concrete workflows for draft post-processing and published content optimization.
- Provider patterns already exist in narrower domains such as SEO providers, billing providers, sitemap sources, and publication destinations.
- Duplicate opportunity-like concepts still exist for domain-specific use cases, including content opportunities, competitor content opportunities, programmatic opportunities, link opportunities, FAQ opportunities, and agentic marketing opportunities.
- Execution and approval surfaces are split across content lifecycle, campaign approval status, agentic action runs, execution pipelines, execution approvals, and opportunity execution plans.

## Canonical Choices

- `Opportunity` remains the canonical MOS Opportunity object for cross-module prioritization and recommendation.
- `OpportunitySignal` remains the canonical bridge from observed signals into opportunities.
- Signal Intelligence remains the canonical Signal Core for source registration, events, detections, scoring, and promotion.
- Agent workflows remain the canonical Workflow Core for orchestrated agent steps and recorded workflow runs.
- A new `MosProviderRegistry` is the canonical discovery mechanism for MOS-capable providers. Existing services are exposed through adapters instead of replaced.

## Implementation

- Added `App\Services\Mos\Contracts\MosProvider`.
- Added `App\Services\Mos\MosDomain` with the canonical MOS bounded contexts.
- Added `App\Services\Mos\MosProviderRegistry` for provider lookup, domain grouping, capability mapping, duplicate protection, and priority sorting.
- Added provider adapters:
  - `OpportunityIntelligenceMosProvider`
  - `SignalIntelligenceMosProvider`
  - `AgentWorkflowMosProvider`
- Registered the adapters through Laravel container tags in `AppServiceProvider`.
- Added unit coverage for registry lookup, capability mapping, domain grouping, and duplicate key rejection.

## Backwards Compatibility

- No migrations were added.
- No model names, table names, URLs, queue names, jobs, controllers, policies, or public UI behavior were changed.
- Existing engines continue to run through their current consumers.
- The MOS registry is additive and resolves existing services through Laravel's container.

## Quality Gates

- Database migration strategy: no schema changes in this phase.
- Architecture review: documented in this audit.
- Unit tests: `tests/Unit/Mos/MosProviderRegistryTest.php`.
- Integration tests: provider registration is resolved through the Laravel application container.
- Regression tests: existing behavior is untouched; targeted test suite should pass.
- UI updates: none required because this phase is internal architecture.
- API updates: none required because no public contract changed.
- Queue verification: no queue names or job dispatch behavior changed.
- Performance review: registry is a singleton using tagged providers; no hot-path query changes.
- Dead code cleanup: no obsolete code removed yet because adapters are the first migration step.
- Documentation updates: this file.

## Next Migration Candidates

- Move domain-specific opportunity creators behind MOS Opportunity providers one at a time, starting with content opportunities and competitor opportunities.
- Add provider adapters for Approval Core and Execution Core using existing agentic approval and opportunity execution services.
- Add a compatibility map showing which legacy opportunity models are sources, projections, or candidates for consolidation into `Opportunity`.
- Add a small internal diagnostics page or command that lists registered MOS providers and capabilities for operators.
