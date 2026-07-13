# Agentic Marketing OS execution guardrails

Date: 2026-07-12

## Purpose

This document turns the Argusly roadmap execution prompt into durable architecture guardrails for future implementation work.

Argusly is not an AI content generator. Argusly is an Agentic Marketing Operating System for B2B organizations. New work must extend the existing architecture in a controlled way and must contribute to the operating loop:

`Observations -> Knowledge -> Insights -> Recommendations -> Actions -> Execution -> Measurement -> Learning`

Features that do not fit this loop do not belong in Argusly.

## Sources of truth

- `audit/argusly_functional_status_audit_2026-07-12.md`
- `audit/argusly_roadmap_execution_plan_2026-07-12.md`
- `audit/argusly_agentic_marketing_os_positioning_audit_2026-06-21.md`
- `docs/architecture/page-intelligence-roadmap.md`
- `docs/architecture/website-content-inventory-activation-audit.md`
- `docs/mos/core-phase-1-audit.md`
- `docs/programmatic-growth-beta-test-checklist.md`
- `docs/connector-production-activation-runbook.md`

## Non-negotiable architecture rules

### Respect existing domains

Do not create parallel systems when a canonical model already exists.

Canonical examples:

| Concept | Use this | Do not introduce |
| --- | --- | --- |
| Observed external page | `MonitoredPage` | `WebsitePage`, `InventoryPage`, `ObservedContent` |
| Owned marketing asset | `Content` | `WebsiteContent`, `ContentNode` |
| Page-to-content bridge | `ContentPageLink` | A second inventory/content join model |
| Business opportunity | `Opportunity` | Another opportunity root for the same business concept |
| Raw observation | `SignalEvent` | A duplicate signal event table |
| Executable task | `RecommendedAction` | A parallel task/action queue |

If the existing concept is not sufficient, extend it, refactor it, or add an adapter around it. Do not copy the domain.

### Avoid duplicate business logic

When functionality already exists:

1. Reuse it.
2. Extend it.
3. Refactor it.
4. Abstract it behind a provider or adapter.

Copying domain logic into a new service, controller, job, or model is a regression unless there is a documented migration path and compatibility reason.

### Do not build feature-first

New code must start from the operating loop:

- What observation starts this?
- What knowledge is stored or reused?
- What insight is produced?
- What recommendation follows?
- What action can be owned, approved, scheduled, or executed?
- What execution path exists?
- What measurement proves impact?
- What learning is persisted?

Avoid starting from "make a dashboard" or "add a module". Dashboards and modules are presentation surfaces for the loop, not the architecture.

### Use existing infrastructure

Prefer existing platform primitives:

- Universal Resource Registry
- Action Registry
- Feature flags
- Policies
- Jobs
- Commands
- Notifications
- Scheduler
- Queue system
- Existing events and audit trails

Do not invent a new registry, permission system, workflow runner, queue abstraction, notification layer, or scheduling mechanism without an architecture review.

## Required development order

Work must follow this order. Later phases should not be implemented before earlier foundations are stable enough for the dependency.

### Phase 0: Launch hardening

No new product features.

Goals:

- Fix unit tests and test harness failures.
- Prove production readiness.
- Define queue topology.
- Add monitoring and observability.
- Create the feature flag matrix.
- Update stale documentation.

### Phase 1: Content Inventory UI

Use the existing backend only.

Build:

- Inventory hub.
- Filters.
- Drawer.
- Diagnostics.
- Promote.
- Ignore.
- Refresh.
- Link existing content.

Do not build a new website inventory domain.

### Phase 2: Page Intelligence action layer

Page Intelligence must not only report. Each finding needs:

- Evidence.
- Impact.
- Confidence.
- Recommendation.
- Owner.
- Status.
- Action.

All relevant findings should be able to generate `RecommendedAction` records.

### Phase 3: Marketing Memory

Introduce a read model, not a rewrite.

Marketing Memory connects:

- Website.
- Pages.
- Content.
- Campaigns.
- Signals.
- Entities.
- Competitors.
- Brand.
- Personas.
- Social.
- Publishing.
- Analytics.
- AI citations.

Only add relationships and read models. Do not duplicate source domains.

### Phase 4: Marketing Graph

Create graph context, not a graph database.

Use existing tables and relationships to explain context such as:

`Page -> Campaign -> Persona -> CTA -> Competitor -> Signals -> AI citations`

The graph should reveal why records belong together and what action follows.

### Phase 5: Brand Intelligence

Centralize:

- Company profile.
- Brand voice.
- ICP.
- Tone.
- Writing style.
- Markets.
- Personas.
- Positioning.
- Proof points.

Every AI-assisted feature should consume the same approved brand context.

### Phase 6: Entity Intelligence

Create a central registry for:

- Companies.
- People.
- Products.
- Technologies.
- Competitors.
- Topics.
- Markets.

Use these entities across Page Intelligence, Signal Intelligence, Research, Content, Campaigns, SEO, GEO, and agentic workflows.

### Phase 7: Signal Intelligence 2.0

Expand observations with:

- News.
- RSS.
- Competitors.
- GEO.
- AI Search.
- LLM citations.
- LinkedIn.
- Analytics.
- CRM.

Every observation should eventually be able to lead to:

`Opportunity -> RecommendedAction -> Execution`

### Phase 8: Agentic Marketing

The operating flow is:

`Observe -> Reason -> Plan -> Approve -> Generate -> Publish -> Monitor -> Learn -> Improve`

Never publish automatically without explicit policy.

Every agent action must have:

- Audit trail.
- Rollback or recovery path.
- Idempotency.
- Owner.
- Policy and approval state.

### Phase 9: Campaign OS

Campaigns become the central workspace, not content.

A campaign contains:

- Objectives.
- Audiences.
- Channels.
- Assets.
- Actions.
- Timeline.
- KPIs.
- Learnings.

Use the same graph and action primitives. Do not create a parallel campaign ecosystem.

### Phase 10: Executive Intelligence

Management should see decisions, not only dashboards.

Every executive surface should follow:

`Evidence -> Insight -> Recommendation -> Action`

It may include risks, opportunities, ROI, content health, AI visibility, authority, pipeline, and recommended actions.

## Pre-build architecture checklist

Before changing code, answer:

1. Does this functionality already exist?
2. If yes, which model, service, job, action, registry, policy, or command should be extended?
3. Which part of the operating loop does this change support?
4. Which existing platform primitives should be reused?
5. What tenant boundary applies?
6. What feature flag or rollout gate applies?
7. What observable state proves this works in production?
8. Which tests protect the behavior?
9. Which docs must be updated?

If the answer requires a new parallel domain, stop and do an architecture review first.

## Required implementation output format

Implementation proposals and substantial code changes should be framed with:

### Analyse

- Current architecture.
- Existing components.
- Impact.

### Ontwerp

- Why this solution.
- Why it avoids duplication.

### Implementatieplan

- Step-by-step implementation.

### Bestanden

- Files to change.
- Files intentionally not changed.

### Risico's

- Possible regressions.
- Rollout and rollback concerns.

### Tests

- New tests.
- Existing tests to run.

### Documentatie

- Docs to update.

### Architectuurcontrole

Explicitly confirm:

- No duplicate domains.
- No parallel services.
- No new business logic where existing logic can be reused.
- No violation of the Marketing OS architecture.

## Core principle

Do not build Argusly as a collection of AI tools. Build it as one coherent Agentic Marketing Operating System where observations become knowledge, knowledge becomes insights, insights become actions, and actions become measurable marketing outcomes.
