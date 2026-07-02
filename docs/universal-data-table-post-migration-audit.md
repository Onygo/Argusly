# Universal DataTable Post-Migration Audit

Date: 2026-06-30

## Scope

This audit covers the Universal DataTable migration after the first migration batches. It does not migrate new tables. The scan covered:

- all `x-data-table` usage in `resources/views/admin`, `resources/views/app`, and the DataTable component files;
- remaining raw `<table>` usage in app/admin views;
- out-of-scope raw tables in emails, PDFs, public pages, vendor overrides, tests, and generic table components;
- accessibility labels, empty states, badge consistency, row actions, pagination, mobile overflow, and deferred-table rationale.

## Audit Summary

- Migrated inventory: 48 app/admin DataTable instances, plus component/test coverage.
- Raw app/admin inventory: 35 raw table instances remain across 24 files.
- Out-of-scope/custom raw inventory: emails, invoice PDFs, public/legal/marketing pages, vendor package overrides, markdown renderer tests, and generic table components.
- Accessibility: every migrated `x-data-table` root has a `label`; most migrated roots also include a useful `description`. Data cells generally provide `label` for mobile `data-label` rendering; the exceptions are intentional `colspan` detail rows.
- Empty states: migrated tables consistently use `x-data-table.empty`; remaining raw tables use local empty rows or surrounding empty states.
- Badges: migrated tables mostly use `x-data-table.badge`; some intentionally retain `x-status-badge` where status semantics already come from the shared app status component. Remaining raw tables still contain several local badge/chip styles.
- Row actions: migrated action cells use `x-data-table.actions`, preserving GET links, POST forms, destructive confirms, retry/requeue controls, and impersonation flows. Most groups rely on the default `aria-label="Row actions"`; page-specific action-region labels can be improved later.
- Pagination: migrated paginated tables mostly use the `pagination` slot. Exceptions remain where pagination serves both a desktop table and mobile cards or a complex content tree.
- Mobile overflow: DataTable scrollers normalize horizontal overflow. A few migrated tables intentionally keep `min-w`, `sticky`, `max-height`, or existing mobile-card alternatives.
- Deferred reasons are still valid overall. The highest-risk remaining raw tables are edit-form grids, destructive bulk-selection flows, dense diagnostic detail pages, and bespoke expandable/tree layouts.

## Migrated Table Inventory

| Area | File | DataTable label(s) | Notes |
| --- | --- | --- | --- |
| Admin | `resources/views/admin/briefs/index.blade.php` | Admin briefs | Paginated, delete action preserved. |
| Admin | `resources/views/admin/billing/index.blade.php` | Billing organizations | Dense billing metrics; good export/saved-view candidate. |
| Admin | `resources/views/admin/billing/partials/workspace-usage.blade.php` | Workspace quota usage | Compact nested billing table. |
| Admin | `resources/views/admin/campaigns/index.blade.php` | Campaigns | Paginated campaign overview. |
| Admin | `resources/views/admin/campaigns/show.blade.php` | Campaign content assets; Campaign distribution plan; Campaign social timeline | Detail-page tables migrated without changing show-page context. |
| Admin | `resources/views/admin/company-intelligence/index.blade.php` | Company intelligence profiles | Paginated, badge-normalized. |
| Admin | `resources/views/admin/contact-submissions/index.blade.php` | Contact submissions | Detail rows preserved as paired DataTable rows. |
| Admin | `resources/views/admin/drafts/index.blade.php` | Admin drafts | Paginated, destructive action preserved. |
| Admin | `resources/views/admin/early-access/index.blade.php` | Pilot applications | Review actions preserved. |
| Admin | `resources/views/admin/feature-flags/index.blade.php` | Effective flags | Inline POST toggle/delete forms preserved. |
| Admin | `resources/views/admin/invoices/index.blade.php` | Invoices | Refund/open actions preserved. |
| Admin | `resources/views/admin/llm/monitor.blade.php` | LLM request log | Dense audit log; export/saved-view candidate. |
| Admin | `resources/views/admin/mos-providers/index.blade.php` | MOS providers; Opportunity providers | Read-only diagnostics. |
| Admin | `resources/views/admin/organizations/show.blade.php` | Organization users; Organization workspaces | Show-page nested tables migrated. |
| Admin | `resources/views/admin/query-intent/index.blade.php` | Recent persisted classifications | Diagnostic table; export candidate. |
| Admin | `resources/views/admin/queues/index.blade.php` | Queue overview; Translation queue state; Pending queue jobs; Failed queue jobs | Complex operational page migrated while preserving retry/delete/bulk flows. |
| Admin | `resources/views/admin/queues/pending-missing.blade.php` | Nearby pending jobs | Compact diagnostic table. |
| Admin | `resources/views/admin/sites/index.blade.php` | Admin sites | Desktop table migrated; mobile cards and shared pagination remain local. |
| Admin | `resources/views/admin/system-health/index.blade.php` | Queue summary | Compact status summary. |
| Admin | `resources/views/admin/users/index.blade.php` | Users | Sticky, paginated, action-heavy admin list. |
| App | `resources/views/app/briefs/index.blade.php` | Briefs | Search/filter table with separate empty states for no data vs no filter matches. |
| App | `resources/views/app/competitor-intelligence/index.blade.php` | Competitor overview; Topic overlap; Opportunity output | Multi-table report migrated. |
| App | `resources/views/app/content/index.blade.php` | Content lifecycle table | Complex content-tree behavior preserved; custom pagination placement retained. |
| App | `resources/views/app/drafts/index.blade.php` | Drafts | Paginated draft overview with rich empty state. |
| App | `resources/views/app/network-linking/index.blade.php` | Network linking permissions | Compact permissions/actions table. |
| App | `resources/views/app/programmatic-brief-blueprints/index.blade.php` | Programmatic brief blueprints | Paginated programmatic table. |
| App | `resources/views/app/programmatic-draft-requests/index.blade.php` | Programmatic draft requests | Paginated programmatic table. |
| App | `resources/views/app/programmatic-draft-reviews/index.blade.php` | Programmatic draft reviews | Paginated programmatic table. |
| App | `resources/views/app/programmatic-publication-plans/index.blade.php` | Programmatic publication plans | Paginated programmatic table. |
| App | `resources/views/app/programmatic-publication-readiness/index.blade.php` | Programmatic publication readiness | Paginated programmatic table. |
| App | `resources/views/app/research/index.blade.php` | Research projects | Actions and linked context preserved. |
| App | `resources/views/app/signal-intelligence/index.blade.php` | Signal feed; Detections | Detections paginated; status badges intentionally use shared `x-status-badge`. |
| App | `resources/views/app/sites.blade.php` | Connected sites | Paginated workspace site list. |
| App | `resources/views/app/sites/competitors/index.blade.php` | High-performing entities to consider; Competitor list | Native evidence disclosure preserved. |
| App | `resources/views/app/sites/llm-tracking/index.blade.php` | Tracking Queries; Query Performance; Latest Answers; Trend Over Time | Sticky/max-height report tables migrated. |
| App | `resources/views/app/sites/seo-audits/index.blade.php` | SEO audit runs | Audit-run index migrated; detail tables remain raw. |
| App | `resources/views/app/social-distribution/index.blade.php` | Distribution Overview | Localized label/description preserved. |
| Components | `resources/views/components/data-table.blade.php` and `resources/views/components/data-table/*` | Component internals | The root component owns the native `<table>` element and wrappers. |
| Tests | `tests/Feature/UI/DataTableFrameworkTest.php` | Contract fixtures | Covers root, toolbar, bulk actions, sticky header, interactive row, badge, empty/loading state, and representative migrated pages. |

## Remaining Raw Table Inventory

Risk ratings describe migration risk, not production severity.

| Risk | File | Table(s) | Reason still valid / next action |
| --- | --- | --- | --- |
| High | `resources/views/admin/credit-reservations/index.blade.php` | Credit reservations with checkbox bulk release | Still valid. Owns checkbox-driven JavaScript, selected count, required reason input, and destructive bulk POST flow. Migrate only with a dedicated bulk-selection DataTable pattern. |
| High | `resources/views/admin/llm/settings.blade.php` | Global feature routing; workspace overrides; settings audit log | Still valid. Two tables are dense per-row edit forms; the audit-log table has disclosure JSON. Consider only the audit log for an early migration. |
| High | `resources/views/admin/editorial-taxonomy/index.blade.php` | Taxonomy item editor | Still valid. Row-level `<details>` contains update/delete forms and hierarchy controls. Should remain custom until inline-edit patterns exist. |
| High | `resources/views/app/dashboard.blade.php` | Recent content desktop tree | Still valid. Paired with mobile cards and custom expandable content-tree behavior. Should remain custom or move only after DataTable supports tree rows. |
| Low | `resources/views/app/briefs/partials/draft-compare/score-matrix.blade.php` | Draft comparison score matrix | Still valid. This is a comparison matrix, not a record table. Should remain custom. |
| Medium | `resources/views/app/human-content/dashboard.blade.php` | Trend Over Time; Blocked By Human Content Gate | Reason still valid as chart/report-adjacent dashboard content. Blocked table could migrate in a reporting pass; trend table may remain custom. |
| Low | `resources/views/app/programmatic-publication-plans/show.blade.php` | Plan Items | Simple show-page detail table. Migration is low risk and a good next-phase candidate. |
| Medium | `resources/views/app/programmatic-clusters/show.blade.php` | Cluster items | Wide detail table with filters and many columns. Migration is feasible but needs overflow QA. |
| Low | `resources/views/app/research/show.blade.php` | Sources | Simple show-page detail table. Good next-phase candidate. |
| Medium | `resources/views/app/content-network/index.blade.php` | Network opportunities; related network table | Still valid. Paired network tables should move together in a dedicated pass. |
| Medium | `resources/views/app/developer/index.blade.php` | Developer reference table | Documentation/reference table. Can remain custom unless app reference tables are explicitly standardized. |
| Low | `resources/views/app/developer/docs/partials/endpoint-card.blade.php` | Endpoint parameter/body table | Documentation/reference table. Should remain custom unless docs tables are in scope. |
| Low | `resources/views/app/developer/docs/partials/schema-viewer.blade.php` | Schema property table | Documentation/reference table. Should remain custom unless docs tables are in scope. |
| Medium | `resources/views/app/settings/image-presets/index.blade.php` | Image presets | Still valid. Contains default-switching and destructive delete confirmation forms. Migrate after row-action semantics are reviewed. |
| Medium | `resources/views/app/sites/analytics/show.blade.php` | Analytics detail table | Chart-adjacent detail surface. Feasible in a reporting/detail pass. |
| Medium | `resources/views/app/sites/learnings/index.blade.php` | Learnings detail table | Reporting/detail table with local page context. Feasible after visual QA. |
| High | `resources/views/app/sites/seo-audits/show.blade.php` | Multiple SEO diagnostic/detail tables | Still valid. Contains several diagnostic sections with different densities and overflow needs. Needs a dedicated SEO audit detail pass. |
| High | `resources/views/app/content/partials/translation-monitor.blade.php` | Translation monitor tables | Still valid. Nested operational monitor with local dense state and detail rows. Needs a content-operations pass. |
| Medium | `resources/views/app/content/partials/content-improvement-monitor.blade.php` | Improvement monitor table | Still valid. Operational monitor; migrate with translation monitor if standardized. |
| High | `resources/views/app/content/automations/show.blade.php` | Automation run/detail table | Still valid. Nested operational detail with local semantics. |
| Medium | `resources/views/app/content/batches/show.blade.php` | Batch items | Detail table; feasible after show-page table pattern is confirmed. |
| High | `resources/views/app/content/series/index.blade.php` | Content series list | Paginated app record table, but deferred due surrounding series workflow and local actions. Good later candidate after safer show-page pass. |
| Medium | `resources/views/app/content/series/show.blade.php` | Series structure/item tables | Multiple nested detail tables. Migrate together in a content-series pass. |
| High | `resources/views/app/content/lifecycle/index.blade.php` | Lifecycle table | Still valid. Lifecycle/workflow table with local behavior should remain deferred until lifecycle-specific QA. |
| High | `resources/views/app/agentic-marketing/index.blade.php` | Agentic action table | Still valid. Workflow/dashboard table with adjacent panels and paginated actions. Good candidate for a later agentic-marketing pass, not a generic migration. |
| High | `resources/views/app/agentic-marketing/workflows/index.blade.php` | Workflow table | Still valid. Workflow controls and adjacent panels need dedicated QA. |
| Low | `resources/views/app/sites/llm-tracking/partials/sources.blade.php` | Source citations | Nested detail partial; low migration risk but should be grouped with other LLM tracking partials. |
| Medium | `resources/views/app/sites/llm-tracking/partials/competitors.blade.php` | Mentioned competitors; competitor citation details | Nested detail partials; migrate together with LLM tracking detail QA. |
| Medium | `resources/views/app/sites/llm-tracking/partials/history.blade.php` | Run history; history detail table | Nested detail partials with local context. Migrate in LLM tracking detail pass. |

## Out-of-Scope Or Custom Raw Tables

These raw tables should not be treated as Universal DataTable migration targets unless the scope changes:

- `resources/views/components/data-table.blade.php`: owns the native table element for the DataTable component.
- `resources/views/components/responsive-table.blade.php`: generic wrapper component; keep as component infrastructure unless replaced deliberately.
- `resources/views/emails/**`: email layout and notification tables use presentation-table markup for client compatibility.
- `resources/views/pdf/partials/**` and `resources/views/components/invoices/logo.blade.php`: invoice/PDF rendering tables should remain print/PDF-specific.
- `resources/views/public/legal/cookies.blade.php`, `resources/views/public/marketing-topic.blade.php`, `resources/views/public/agentic-marketing-operating-system.blade.php`: public marketing/legal tables are outside authenticated app/admin table standardization.
- `resources/views/vendor/publishlayer/inbox/index.blade.php` and `resources/views/vendor/argusly/inbox/index.blade.php`: vendor override package surfaces; migrate only if that package UI is brought into the design-system scope.
- Markdown renderer tests containing literal `<table>` markup are renderer fixtures, not UI tables.

## Accessibility Labels

- Passed: all migrated `x-data-table` roots include `label` or localized `:label`.
- Passed: root descriptions are present for migrated app/admin table instances and are rendered as screen-reader captions.
- Passed: headings render as scoped `th` cells through `x-data-table.cell heading`.
- Watch: row action containers use `aria-label="Row actions"` by default. This is acceptable but generic on pages with multiple action groups. Next phase should add labels such as "Queue row actions", "User row actions", or "Detection actions" where a page has several action regions.
- Watch: raw tables generally rely on nearby section headings rather than table-level labels/captions. When migrating them, add explicit DataTable labels/descriptions instead of copying visual headings only.

## Empty States

- Passed: migrated record tables use `x-data-table.empty`, with stronger descriptions on user-facing tables such as briefs, drafts, competitor intelligence, early access, LLM monitor, agent runs, and content.
- Watch: many raw tables use bare `<td colspan>` empty rows. This is acceptable for deferred raw tables, but next migrations should replace them with `x-data-table.empty`.
- Watch: report/detail tables with no explicit empty state should be checked during migration, especially dashboard trend tables and documentation/reference tables.

## Badge Consistency

- Passed: migrated tables use `x-data-table.badge` for most status/tone chips.
- Intentional exception: `resources/views/app/signal-intelligence/index.blade.php` uses shared `x-status-badge` for detection status/severity because those tones already come from domain-specific status helpers.
- Remaining inconsistency: raw tables still use local `span` badge classes, `pl-badge`, and `x-status-badge`. Normalizing those belongs with each raw-table migration, not as a separate sweeping edit.

## Row Action Semantics

- Passed: migrated action groups preserve link vs form semantics, method spoofing, CSRF tokens, confirmation prompts, retry/delete behavior, impersonation controls, and inline operational POST forms.
- Watch: default action-region label is generic. Improve this in the next implementation pass for high-density pages.
- Watch: tables with checkbox bulk flows, inline edit forms, or destructive row forms should not be migrated until the DataTable pattern has explicit support for selection and inline form ergonomics.

## Pagination Consistency

- Passed: migrated paginated tables generally use `<x-slot:pagination>...->links()</x-slot:pagination>`.
- Passed: queue sub-tables preserve `onEachSide(1)` behavior.
- Intentional exceptions: `resources/views/app/content/index.blade.php` uses `x-data-table.pagination` outside the root flow for its content-tree layout, and `resources/views/admin/sites/index.blade.php` keeps pagination outside the desktop table because it also serves mobile cards.
- Remaining raw paginated lists not using DataTable include admin content policies, credit reservations, product updates, notifications, organizations, app content opportunities, growth programs, billing tab partials, agentic pages, content automations, programmatic clusters/opportunities, recommended actions, and content series. Many of these are card/list pages rather than raw tables; do not migrate solely for pagination consistency.

## Mobile Overflow Behavior

- Passed: DataTable root provides a consistent scroller, and wide migrated tables set explicit `table-class`, `sticky`, or `max-height` where needed.
- Passed: existing mobile-card alternatives remain local on content/admin-sites/dashboard surfaces.
- Watch: raw show/detail/report tables usually have `overflow-x-auto`, but do not get `data-label` mobile cells. Migration candidates with many columns need mobile screenshots or viewport QA.
- Watch: DataTable mobile behavior is overflow-first, not card-transform. Tables that already have a mobile card workflow may be better left custom.

## Toolbar, Export, And Saved-View Candidates

Best candidates for future toolbar/search/filter/export/saved-view work:

- `resources/views/admin/billing/index.blade.php` - billing organizations.
- `resources/views/admin/llm/monitor.blade.php` - LLM request log.
- `resources/views/admin/queues/index.blade.php` - translation, pending, and failed queue job tables.
- `resources/views/admin/users/index.blade.php` - user administration.
- `resources/views/admin/early-access/index.blade.php` - pilot applications.
- `resources/views/admin/query-intent/index.blade.php` - persisted classifications.
- `resources/views/admin/contact-submissions/index.blade.php` - support/contact operations.
- `resources/views/app/content/index.blade.php` - content lifecycle table.
- `resources/views/app/sites/llm-tracking/index.blade.php` - tracking queries and performance tables.
- `resources/views/app/signal-intelligence/index.blade.php` - detections.
- `resources/views/app/competitor-intelligence/index.blade.php` - competitor overview/opportunity output.
- `resources/views/app/sites/seo-audits/index.blade.php` - SEO audit runs.
- `resources/views/app/briefs/index.blade.php` and `resources/views/app/drafts/index.blade.php` - user-facing content production lists.

## Candidates That Should Remain Custom

- Email and PDF tables.
- Public/legal/marketing tables.
- Vendor override package inbox tables.
- Draft comparison score matrix.
- Dashboard content-tree table unless DataTable gains first-class tree-row support.
- Admin LLM settings routing/override edit-form tables.
- Admin editorial taxonomy inline-edit table.
- Admin credit reservations bulk-release table until DataTable has checkbox selection/bulk action conventions.
- Dense SEO audit show-page diagnostic tables until a dedicated diagnostic-table variant exists.
- Content monitors and lifecycle tables until content-operation-specific behavior is standardized.

## Recommended Next Phase

Run the next phase as a targeted "low-risk detail/report table migration" instead of another broad sweep:

1. Migrate low-risk show-page detail tables first: research sources, publication plan items, batch items, and selected LLM tracking detail partials.
2. Add action-region label improvements to existing migrated tables while touching nearby code.
3. Normalize badges only inside tables being migrated; avoid a global badge refactor.
4. Keep high-risk custom tables deferred until the component supports checkbox selection, bulk action state, inline edit panels, and tree/expandable rows.
5. After low-risk detail tables pass, schedule dedicated passes for content-network, content-series, LLM-tracking detail partials, and SEO-audit detail tables.

## Recommended Next Implementation Prompt

```text
Continue the Universal DataTable work with a low-risk detail/report-table pass.

Do not touch custom bulk-selection, inline-edit, dashboard tree, email, PDF, public, vendor, or draft comparison matrix tables.

Migrate only:
- resources/views/app/research/show.blade.php sources table
- resources/views/app/programmatic-publication-plans/show.blade.php plan items table
- resources/views/app/content/batches/show.blade.php batch items table
- resources/views/app/sites/llm-tracking/partials/sources.blade.php source citations table

For each migration:
- preserve query/controller behavior, links, forms, empty states, and surrounding layout;
- add meaningful DataTable labels/descriptions;
- use x-data-table.empty for empty rows;
- use x-data-table.badge only where it matches existing semantics;
- keep existing overflow behavior or add explicit table-class/min-width if needed;
- add or update focused UI tests that assert no raw <table> remains in the migrated files.

Run:
php artisan view:cache
php artisan test tests/Feature/UI/DataTableFrameworkTest.php
php artisan test tests/Feature/UI/ApplicationShellFrameworkTest.php
npm run build
git diff --check
```

## Safe Fixes Made

No Blade fixes were made during this audit. The only change in this pass is this documentation file.
