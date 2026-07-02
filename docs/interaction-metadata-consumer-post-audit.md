# Interaction Metadata Consumer Post-Audit

## Scope

This post-consumer audit covers the implemented Interaction Metadata consumer passes for:

- `app/drafts/index`
- `app/briefs/index`
- `app/research/index`
- `app/sites/seo-audits/index`
- `app/sites/index`

It does not propose or implement new consumers. The audited state is metadata-only: controllers resolve Resource Registry and Action Registry data as auxiliary maps, while Blade output still comes from existing view data and literal routes/forms.

## Implementation Summary

The implemented consumers follow the same high-level pattern:

- Resolve metadata in the controller after the page query has produced the current paginator or collection rows.
- Pass `interactionResourcesByKey` and `interactionActionsByKey` to the view.
- Key row resources by the canonical registry key, such as `draft:{id}`, `brief:{id}`, `research_project:{id}`, `seo_audit:{id}`, and `site:{id}`.
- Resolve only GET/open metadata for row actions, plus the creator-visible research page create action.
- Keep existing Blade-rendered `href`, `action`, `method`, button text, labels, empty states, filters, pagination, and generated-key output authoritative.

Implemented page details:

| Page | Row resource | Resolved actions | Deferred actions |
| --- | --- | --- | --- |
| `app/drafts/index` | `draft:{id}` | `app.draft.open` | Empty-state links remain literal. |
| `app/briefs/index` | `brief:{id}` | `app.brief.open` | New Brief and empty-state links remain literal. |
| `app/research/index` | `research_project:{id}` | `app.research-project.open`, page-level `app.research-project.create` | Start/Rerun POST remains literal. |
| `app/sites/seo-audits/index` | `seo_audit:{id}` | `app.seo-audit.open` | Run SEO audit POST remains literal. |
| `app/sites/index` | `site:{id}` | `app.site.open` | Add site and connector Test connection POST forms remain literal. |

## Consistency Findings

- Auxiliary metadata maps are consistently named `interactionResourcesByKey` and `interactionActionsByKey` across all five consumers.
- Metadata resolution is scoped to current rows:
  - Drafts, briefs, research, and sites use the paginator collection via `getCollection()`.
  - SEO audits uses the already-limited recent audit collection.
- Blade templates do not render links, labels, buttons, forms, or row text from metadata yet.
- Existing `route()` output remains authoritative for navigation links.
- Existing literal forms remain authoritative for POST, heavy, or state-changing actions.
- POST/heavy/destructive actions are not registered as first-batch metadata actions and are explicitly kept out of the resolved action maps in tests.
- Relation-loading discipline is mostly consistent: controllers eager-load the relations used by provider metadata before resolving row metadata.
- The existing pre-audit document already matches the implemented behavior and correctly marks dense pages as deferred.

## Risks

- Providers read relationship data from row models (`clientSite`, `brief`, `content`, `site`). Future consumers can introduce N+1 behavior if they resolve metadata without matching eager loads.
- `app/research/index`, `app/sites/seo-audits/index`, and `app/sites/index` place literal POST forms near GET open links. Future UI work could accidentally treat adjacent mutation controls as metadata-backed actions before mutation semantics are modeled.
- SEO audits uses a transformed limited collection rather than a paginator. This is acceptable for the current page, but future collection consumers should document whether "current rows" means paginated rows, limited rows, or table rows after transformation.
- Research stores a page-level create action under the page key `app.research.index` in `interactionActionsByKey`. That is useful, but it is a different key shape from row resources and should remain documented until there is a shared page-action convention.
- The maps are passed to Blade but unused. That is intentional, but it means regressions in rendered output can only be caught by the existing literal-output assertions, not by visual changes.

## Authorization And N+1 Findings

- Unauthorized resource coverage exists for all five consumer tests:
  - Draft resources from another organization are not exposed.
  - Brief resources from another organization are not exposed.
  - Research project resources from another organization are not exposed.
  - SEO audit resources from another organization are not exposed.
  - Site resources from another organization are not exposed.
- Current-page scoping is covered for drafts, briefs, research, and sites; SEO audits covers empty/current limited collection behavior and unauthorized exclusion.
- Lazy-loading or N+1 guard coverage exists:
  - Drafts, briefs, research, and sites use `Model::preventLazyLoading()`.
  - SEO audits asserts eager-loaded site/workspace context and watches route-model lookup query counts.
- No new relation N+1 behavior was identified in the audited passes.

## Output Authority Findings

- No audited Blade template currently renders metadata-derived titles, URLs, methods, status labels, or button text.
- Existing href/action/method/text output remains authoritative:
  - Draft and brief title links still use `route()` directly.
  - Research title/Open links and Start/Rerun POST forms remain literal.
  - SEO audit Open links, header links, and Run SEO audit POST form remain literal.
  - Sites View setup details links, WordPress/Laravel Test connection POST forms, Add site form, and generated-key block remain literal.
- POST/heavy/destructive actions remain deferred. The current metadata action registry only covers route-backed GET/link behavior for this consumer batch.

## Recommended Next Consumer Batch

Recommended next batch: `app/signal-intelligence/index`, but only for detection open metadata.

Suggested scope:

- Resolve metadata only for the current `$detections` paginator rows and any already-loaded detection cards that correspond to real `SignalDetection` rows.
- Expose `signal_detection:{id}` resources and `app.signal-detection.open` actions only.
- Keep Signal Event feed rows out of metadata because they are not first-batch resources.
- Keep Run detection, Mark reviewing, Dismiss, and Resolve POST forms literal and deferred.
- Add tests that distinguish the GET "Review" link from POST review/dismiss/resolve state transitions.

Do not include `app/sites/llm-tracking/index` in the next batch unless it is narrowed to one table. The page mixes query sets, tracking query rows, aggregate performance rows, latest response rows, starter flows, toggle forms, run-now forms, and query creation. That is riskier than signal detection open metadata.

## Signal Intelligence Versus LLM Tracking

Signal Intelligence is safer next.

Reasoning:

- Signal Intelligence has one first-batch resource type for the main detection rows: `signal_detection:{id}`.
- The unsafe pieces are clear and separable: Signal Event rows and POST state transitions.
- LLM tracking has more row families and more adjacent forms. Query sets do not have a first-batch resource type, latest response rows are aggregate/run-derived, and query rows sit beside toggle/run-now POST forms.

If LLM tracking is selected anyway, the safer sub-scope is the query management table's `LlmTrackingQuery` Open links only. Do not resolve metadata for query sets, performance rows, trend rows, latest-answer aggregate rows, first-run controls, starter query flows, toggles, or run-now actions in that pass.

## Content Index Deferral

`app/content/index` should remain deferred.

Reasons:

- It has canonical rows, variant rows, mobile cards, grouped tree/header behavior, translation target controls, bulk selections, and filter/preset links.
- It includes multiple POST or destructive workflows: quick create, bulk schedule, bulk sync, translate, restore, and delete.
- Open links appear in multiple desktop and mobile render paths, which increases the chance of duplicate metadata resolution or inconsistent output assertions.
- The page already has content-tree-specific exceptions in nearby UI migration docs, so it should wait until the no-output-change harness is proven on simpler pages.

Recommended preconditions before adopting `app/content/index`:

- Add snapshot-like assertions for representative canonical and variant rows in both desktop and mobile paths.
- Add explicit guards for bulk schedule/sync, translate, restore, and delete modal actions.
- Decide whether metadata should resolve for only canonical table roots, visible variants, or both.
- Ensure relation eager loads cover every provider relationship used by content metadata.

## Test Gaps

- SEO audits does not use `Model::preventLazyLoading()` like the other four consumers. The query-listener guard is useful, but a stricter lazy-loading test may be worth adding if the model graph allows it.
- Research create metadata is covered for creators, but there is no paired test proving it is absent for a user who can view the page but cannot create research projects.
- Current-page-only tests focus on paginator pages. SEO audits should add a stronger assertion that metadata keys exactly match the limited `$audits` collection returned to the view.
- There is no centralized helper for the consumer metadata resolution pattern, so future consumers may drift in naming, scoping, or GET-only filtering.
- There is no test that all five consumer views ignore `interactionResourcesByKey` and `interactionActionsByKey` in Blade. Current coverage proves rendered output remains literal, which is the behavior that matters, but it does not directly guard against future metadata rendering.

## Documentation Findings

- `docs/interaction-metadata-consumer-audit.md` matches implemented behavior for the five audited consumers.
- It correctly documents deferred mutation actions and identifies `app/signal-intelligence/index`, `app/sites/llm-tracking/index`, and `app/content/index` as more complex follow-up candidates.
- This post-audit should be used as the consumer-pass closeout and as the guardrail for selecting the next batch.
