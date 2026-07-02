# Universal DataTable Capability Plan

Date: 2026-06-30

## Purpose

This plan defines the next Universal DataTable capability layer after the initial Blade-first migration. It is a design and implementation plan only; it does not implement toolbar, export, saved-view, or controller changes.

Source-of-truth inputs:

- `docs/universal-data-table-migration.md`
- `docs/universal-data-table-post-migration-audit.md`

The current component contract already supports a root `x-data-table`, toolbar/search/filter/action slots, row actions, bulk actions, pagination, badges, loading, sticky headers, and accessible labels/descriptions. The next layer should standardize how those slots are used before adding export and saved-view behavior.

## Goals

- Make table toolbars predictable across app/admin pages without changing query semantics.
- Move safe search controls into `x-slot:search` where a table owns the search result.
- Group related filters consistently while preserving existing GET parameter names and controller behavior.
- Add CSV export as an opt-in server-side capability for high-value record/report tables.
- Add saved views after toolbar and filter conventions are stable.
- Improve row action-region labels on dense tables and pages with multiple action groups.
- Roll out page by page, starting with pages already identified as toolbar/export/saved-view candidates.

## Non-Goals

- Do not migrate custom raw tables as part of this capability layer.
- Do not touch email, PDF, public, vendor, draft comparison matrix, inline-edit, dashboard tree, or custom bulk-selection tables.
- Do not replace controllers with Livewire/Inertia or add advanced JavaScript table state.
- Do not add column resizing, column chooser, or Excel export in this layer.
- Do not change route names, query parameter names, authorization checks, pagination behavior, or destructive form semantics.

## Candidate Pages

Primary candidates from the post-migration audit:

| Priority | Page | Table(s) | Capability Fit |
| --- | --- | --- | --- |
| P1 | `resources/views/admin/billing/index.blade.php` | Billing organizations | Dense operational overview; strong CSV and saved-view candidate. |
| P1 | `resources/views/admin/llm/monitor.blade.php` | LLM request log | Existing log filters; strong export candidate; useful saved views for model/provider/status filters. |
| P1 | `resources/views/admin/users/index.blade.php` | Users | Search/filter/admin actions; good toolbar standardization and action-label candidate. |
| P1 | `resources/views/admin/early-access/index.blade.php` | Pilot applications | Review workflow list; good search/filter/export candidate. |
| P1 | `resources/views/app/briefs/index.blade.php` | Briefs | User-facing search/filter list; good search slot and saved-view candidate. |
| P1 | `resources/views/app/drafts/index.blade.php` | Drafts | User-facing content production list; good search slot and saved-view candidate. |
| P2 | `resources/views/admin/contact-submissions/index.blade.php` | Contact submissions | Support operations; CSV export candidate; paired detail rows need export mapping care. |
| P2 | `resources/views/admin/query-intent/index.blade.php` | Recent persisted classifications | Diagnostic/reporting table; export-first candidate. |
| P2 | `resources/views/admin/queues/index.blade.php` | Translation, pending, failed queue jobs | Operational tables; action-label improvements first, export only where safe. |
| P2 | `resources/views/app/content/index.blade.php` | Content lifecycle table | Complex content tree; toolbar standardization only after visual/behavior QA. |
| P2 | `resources/views/app/signal-intelligence/index.blade.php` | Signal feed; detections | Filter grouping and export candidate; preserve shared `x-status-badge`. |
| P2 | `resources/views/app/sites/seo-audits/index.blade.php` | SEO audit runs | Reporting index; export candidate after route/service pattern exists. |
| P3 | `resources/views/app/sites/llm-tracking/index.blade.php` | Tracking Queries; Query Performance; Latest Answers; Trend Over Time | Multiple report tables; saved views useful but needs table-specific scopes. |
| P3 | `resources/views/app/competitor-intelligence/index.blade.php` | Competitor overview; topic overlap; opportunity output | Multi-table report; export one table at a time. |

Secondary candidates once the pattern is stable:

- `resources/views/app/research/index.blade.php`
- `resources/views/app/programmatic-publication-plans/index.blade.php`
- `resources/views/app/programmatic-publication-readiness/index.blade.php`
- `resources/views/app/programmatic-brief-blueprints/index.blade.php`
- `resources/views/app/social-distribution/index.blade.php`
- `resources/views/admin/campaigns/index.blade.php`
- `resources/views/admin/company-intelligence/index.blade.php`
- `resources/views/admin/sites/index.blade.php`

## Capability Design

### 1. Toolbar Standardization

Current state:

- `x-data-table` already accepts `search`, `filters`, `actions`, `toolbar`, `bulkActions`, and `pagination` slots.
- `x-data-table.toolbar` renders optional title/description plus separate search, filters, and actions regions.
- Many migrated pages still keep filters above the table when moving them would risk behavior or layout changes.

Target behavior:

- Use the default root slots for simple table-owned controls:
  - `x-slot:search` for a single text search control.
  - `x-slot:filters` for select/date/status controls.
  - `x-slot:actions` for create/export/reset/view actions.
- Use an explicit `x-slot:toolbar` only when the page needs custom layout that the default toolbar cannot express.
- Keep page-level filter bars outside DataTable when controls affect multiple panels, metric cards, mobile cards, or several tables at once.
- Preserve existing form method, action route, hidden workspace/site IDs, query names, selected values, and reset links.

Required component changes:

- Extend `x-data-table.toolbar` with optional region labels for search, filters, and actions.
- Add a `summary` or `resultSummary` slot only if pages need result counts inside the toolbar.
- Add CSS support for grouped filters without requiring pages to hand-roll spacing every time.
- Keep the existing `toolbar` override slot for complex pages.

### 2. Search Slot Usage

Target behavior:

- Search belongs in `x-slot:search` when it filters the rows in that DataTable only.
- Search should remain outside DataTable when it drives page-level metrics, multiple tables, tabs, mobile cards, or non-table result areas.
- Search controls should keep existing names such as `q`, `search`, or domain-specific query keys.
- Search forms should remain GET unless the current page already uses another method.

Candidate first pass:

- `app/briefs/index.blade.php`
- `app/drafts/index.blade.php`
- `admin/users/index.blade.php`
- `admin/early-access/index.blade.php`
- `admin/llm/monitor.blade.php`

Required component changes:

- No new behavior is required for basic search slot use.
- Add optional toolbar accessibility labels so the rendered search region can be named, for example "Search users" or "Search drafts".

Controller/service changes needed:

- None for slot migration.
- Confirm each controller keeps current query handling and paginator `withQueryString()` behavior.

### 3. Filter Grouping

Target behavior:

- Group filters by intent rather than dumping every input into a flat flex row.
- Recommended groups:
  - Status/lifecycle filters.
  - Ownership/workspace/site filters.
  - Date/time filters.
  - Provider/model/channel filters.
  - Risk/health/severity filters.
- Use visible labels or accessible labels that match current design density.
- Preserve all current GET parameters and default values.

Required component changes:

- Add a small Blade component such as `x-data-table.filter-group` with props:
  - `label`
  - optional `description`
  - optional `layout` such as `inline`, `stacked`, or `compact`
- Add CSS classes for `pl-data-table-filter-group`, label text, and grouped control layout.
- Keep this as presentational markup only; do not add JS state in this phase.

Controller/service changes needed:

- None for grouping existing controls.
- Later saved views need a normalized list of allowed filter keys per table.

### 4. CSV Export

Target behavior:

- CSV export is opt-in per table and server-side.
- Export should use the same filters/search as the visible table unless a page explicitly documents a smaller export scope.
- Export should not include action columns, button text, confirmation prompts, or nested UI-only detail rows.
- Export should include stable, human-readable column headings and raw values suitable for spreadsheet analysis.
- Export should respect authorization and tenant/workspace/site boundaries.

Required component changes:

- Add a toolbar action convention for export links/buttons, likely via `x-slot:actions`.
- Optional helper component: `x-data-table.export-action` with props:
  - `href`
  - `label` defaulting to "Export CSV"
  - optional `disabled`
  - optional `description` for screen readers
- No client-side CSV generation in this layer.

Controller/service changes needed:

- Add explicit export routes for each page/table, for example:
  - `admin.billing.export`
  - `admin.llm.monitor.export`
  - `admin.users.export`
  - `app.briefs.export`
  - `app.drafts.export`
- Add a reusable CSV response helper or service, for example `App\Support\DataTableCsvExporter`.
- Add page-specific export query builders that reuse existing filter normalization.
- Add per-table column definitions near the query/service layer, not in Blade.
- Stream responses for potentially large datasets.
- Apply export limits or queued export follow-up if a table can exceed safe synchronous response sizes.

Initial export candidates:

1. `admin/query-intent/index.blade.php`
2. `admin/llm/monitor.blade.php`
3. `admin/contact-submissions/index.blade.php`
4. `admin/billing/index.blade.php`
5. `app/sites/seo-audits/index.blade.php`

### 5. Saved Views

Target behavior:

- Saved views persist a table-specific set of search/filter/sort parameters for the current user.
- Saved views are scoped to the table key and, where relevant, organization/workspace/site.
- Applying a saved view should redirect to the existing GET route with the saved query parameters.
- Saved views should not store destructive action state, selected row IDs, pagination page numbers by default, CSRF data, or transient flash state.

Required component changes:

- Add toolbar action/menu support for saved views after export and filter grouping settle.
- Possible components:
  - `x-data-table.saved-views`
  - `x-data-table.saved-view-menu`
  - `x-data-table.save-view-form`
- Add empty/loading states for saved-view menus if loaded server-side.
- Keep the first implementation server-rendered; add JS only for progressive enhancement later.

Controller/service changes needed:

- Add a `saved_table_views` table or equivalent model with:
  - `id`
  - `user_id`
  - nullable `organization_id`
  - nullable `workspace_id`
  - nullable `site_id`
  - `table_key`
  - `name`
  - `filters` JSON
  - optional `is_default`
  - timestamps
- Add a service such as `SavedDataTableViewService` to:
  - validate allowed keys by table key;
  - persist views;
  - apply views to route query parameters;
  - enforce ownership and workspace/site access.
- Add routes/controllers for create, apply, update default, rename, and delete.
- Add per-table allowed filter schemas.

Initial saved-view candidates:

1. `app/briefs/index.blade.php`
2. `app/drafts/index.blade.php`
3. `admin/users/index.blade.php`
4. `admin/llm/monitor.blade.php`
5. `app/sites/llm-tracking/index.blade.php`

### 6. Action-Region Labels

Current state:

- `x-data-table.actions` defaults to `aria-label="Row actions"`.
- The audit says this is acceptable but generic, especially on dense pages or pages with several action groups.

Target behavior:

- Add page/table-specific labels where action cells are present:
  - "User actions"
  - "Queue job actions"
  - "Failed job actions"
  - "Draft actions"
  - "Brief actions"
  - "Contact submission actions"
  - "SEO audit actions"
  - "Campaign actions"
- Use labels that identify the row domain, not the button set.
- Do this opportunistically during toolbar/export/saved-view page work.

Required component changes:

- None. `x-data-table.actions` already accepts `label`.

Controller/service changes needed:

- None.

## Page-Specific Rollout Order

### Phase 0: Contract Hardening

Scope:

- Document toolbar usage rules in `docs/universal-data-table-migration.md` or a companion note.
- Add component tests for toolbar labels, filter groups, and export action rendering before page rollout.

Pages:

- No page migration required.

Why first:

- Gives every later page a stable component contract and keeps behavior changes easy to review.

### Phase 1: Toolbar and Search Standardization

Scope:

- Move table-owned search/filter controls into DataTable slots only where behavior-neutral.
- Add action-region labels on touched pages.

Pages:

1. `resources/views/app/briefs/index.blade.php`
2. `resources/views/app/drafts/index.blade.php`
3. `resources/views/admin/users/index.blade.php`
4. `resources/views/admin/early-access/index.blade.php`
5. `resources/views/admin/llm/monitor.blade.php`

Success criteria:

- Existing GET parameters and paginator behavior remain unchanged.
- No raw `<table>` regressions.
- Toolbar regions render in the expected order.
- Row action labels are no longer generic on touched tables.

### Phase 2: Filter Grouping

Scope:

- Introduce `x-data-table.filter-group`.
- Group existing filters without changing query behavior.

Pages:

1. `resources/views/admin/llm/monitor.blade.php`
2. `resources/views/admin/billing/index.blade.php`
3. `resources/views/app/signal-intelligence/index.blade.php`
4. `resources/views/app/sites/llm-tracking/index.blade.php`
5. `resources/views/app/content/index.blade.php` only if content-tree QA is allocated.

Success criteria:

- Filter controls retain names and selected values.
- Group labels are accessible.
- Multi-table page filters stay outside DataTable when they still affect multiple surfaces.

### Phase 3: CSV Export Foundation

Scope:

- Add reusable CSV export service/helper.
- Add one low-risk export route and test it end to end.
- Add toolbar export action convention.

First page:

- `resources/views/admin/query-intent/index.blade.php`

Follow-up pages:

1. `resources/views/admin/llm/monitor.blade.php`
2. `resources/views/admin/contact-submissions/index.blade.php`
3. `resources/views/app/sites/seo-audits/index.blade.php`
4. `resources/views/admin/billing/index.blade.php`

Success criteria:

- Export applies current filters/search.
- CSV excludes action/UI-only columns.
- Authorization and workspace/site boundaries are tested.
- Large exports stream or have a documented limit.

### Phase 4: Saved Views Foundation

Scope:

- Add saved-view persistence, table keys, allowed filter schemas, and server-rendered apply/save/delete flows.
- Start with user-facing content production lists.

First pages:

1. `resources/views/app/briefs/index.blade.php`
2. `resources/views/app/drafts/index.blade.php`

Follow-up pages:

1. `resources/views/admin/users/index.blade.php`
2. `resources/views/admin/llm/monitor.blade.php`
3. `resources/views/app/sites/llm-tracking/index.blade.php`

Success criteria:

- Saved views only persist allowed keys.
- Applying a view produces a normal GET URL.
- Views are scoped to the current user and relevant workspace/site.
- Default view behavior is explicit and reversible.

### Phase 5: Multi-Table Report Pages

Scope:

- Apply export/saved-view patterns to pages with multiple DataTables only after single-table pages are stable.

Pages:

1. `resources/views/app/sites/llm-tracking/index.blade.php`
2. `resources/views/app/competitor-intelligence/index.blade.php`
3. `resources/views/admin/queues/index.blade.php`

Success criteria:

- Each table has a stable `table_key`.
- Export/saved-view controls clearly apply to one table or to the whole page.
- Operational retry/delete/bulk flows remain unchanged.

## Required Component Changes

- `resources/views/components/data-table/toolbar.blade.php`
  - Add optional accessible labels for search, filters, and actions regions.
  - Support standardized grouped filters.
- New `resources/views/components/data-table/filter-group.blade.php`
  - Presentational wrapper for grouped filter controls.
- Optional `resources/views/components/data-table/export-action.blade.php`
  - Standard export action link/button for toolbar actions.
- Later saved-view components:
  - `resources/views/components/data-table/saved-views.blade.php`
  - `resources/views/components/data-table/saved-view-menu.blade.php`
- `resources/css/app.css`
  - Add toolbar region, filter-group, export-action, and saved-view menu styles.
  - Preserve existing responsive scroller behavior.
- `tests/Feature/UI/DataTableFrameworkTest.php`
  - Extend component contract tests for each new capability.

## Controller and Service Changes Needed

Toolbar/search/filter grouping:

- No controller changes expected for safe slot moves.
- Confirm existing controllers preserve `request()` filters, hidden context IDs, and paginator query strings.

CSV export:

- Add export routes per candidate table.
- Add authorization checks matching the visible index page.
- Extract or reuse filter normalization per controller.
- Add a reusable CSV streaming response helper.
- Add per-table export column definitions.
- Add safeguards for large exports.

Saved views:

- Add database migration/model/service for saved table views.
- Add route/controller actions for save, apply, default, rename, and delete.
- Add per-table `table_key` constants or config entries.
- Add allowed filter schemas per table.
- Add workspace/site/organization scoping helpers.

## Risks

- Moving page-level filters into a table toolbar can accidentally narrow the perceived scope of controls that affect metrics, cards, tabs, or multiple tables.
- CSV export can leak cross-tenant data if it bypasses existing workspace/site query constraints.
- CSV export can drift from visible filters if filter normalization is duplicated carelessly.
- Saved views can persist unsupported or stale parameters unless each table has an allowed-key schema.
- Saved views can confuse users if pagination page numbers or transient parameters are stored.
- Multi-table pages can make export and saved-view scope ambiguous.
- Dense operational pages such as queues can regress destructive/retry flows if toolbar or action refactors are too broad.
- Contact submissions and other detail-row tables need custom export mapping so detail rows do not become malformed CSV rows.
- The current audit docs may lag behind the latest low-risk detail/report migrations; verify candidate status before implementation.

## Tests Needed

Component tests:

- DataTable toolbar renders labelled search, filter, and action regions.
- Filter group renders label/description and preserves slotted controls.
- Export action renders an accessible CSV link and supports disabled/unavailable state if added.
- Saved-view menu/form renders without requiring JavaScript.
- `x-data-table.actions` custom labels render correctly.

Page UI tests:

- Candidate pages use `x-slot:search` only where controls are table-owned.
- Candidate pages use `x-slot:filters` or `x-data-table.filter-group` without losing existing query parameter names.
- Touched pages do not reintroduce raw `<table>` markup.
- Touched action groups include page-specific labels.

Feature tests for CSV:

- Export route requires authentication and authorization.
- Export respects workspace/site/organization scope.
- Export respects active filters/search.
- Export has expected headers and excludes action/UI columns.
- Export handles empty result sets.
- Export streams or limits large datasets.

Feature tests for saved views:

- User can create, apply, rename, delete, and set default saved views.
- Saved view filters are scoped by user and table key.
- Unauthorized users cannot read or apply another user's saved views.
- Unsupported filter keys are discarded or rejected.
- Applying a saved view redirects to the normal index route with expected query parameters.

Regression commands for each implementation phase:

```bash
php artisan view:cache
php artisan test tests/Feature/UI/DataTableFrameworkTest.php
php artisan test tests/Feature/UI/ApplicationShellFrameworkTest.php
npm run build
git diff --check
```

Add focused route/service tests as each CSV or saved-view page is implemented.

## Phased Implementation Prompts

### Prompt 1: Toolbar Contract Hardening

```text
Continue Universal DataTable work with toolbar contract hardening only.

Do not move page filters yet and do not add CSV or saved views.

Add:
- accessible labels for DataTable toolbar search/filter/action regions;
- a presentational x-data-table.filter-group component;
- focused component tests in DataTableFrameworkTest.

Preserve the existing DataTable API and current page behavior.

Run:
php artisan view:cache
php artisan test tests/Feature/UI/DataTableFrameworkTest.php
php artisan test tests/Feature/UI/ApplicationShellFrameworkTest.php
npm run build
git diff --check
```

### Prompt 2: First Toolbar/Search Rollout

```text
Apply the standardized DataTable toolbar/search pattern to the first low-risk pages.

Do not add CSV export or saved views yet.

Migrate only table-owned search/filter controls on:
- resources/views/app/briefs/index.blade.php
- resources/views/app/drafts/index.blade.php
- resources/views/admin/users/index.blade.php
- resources/views/admin/early-access/index.blade.php

For each page:
- preserve GET parameter names, hidden workspace/site context, selected values, reset behavior, pagination, and actions;
- keep controls outside DataTable if they affect more than the table;
- add page-specific x-data-table.actions labels;
- update focused UI tests.

Run:
php artisan view:cache
php artisan test tests/Feature/UI/DataTableFrameworkTest.php
php artisan test tests/Feature/UI/ApplicationShellFrameworkTest.php
npm run build
git diff --check
```

### Prompt 3: Filter Grouping Rollout

```text
Introduce DataTable filter grouping on dense operational/report pages.

Do not add CSV export or saved views yet.

Touch only:
- resources/views/admin/llm/monitor.blade.php
- resources/views/admin/billing/index.blade.php
- resources/views/app/signal-intelligence/index.blade.php

For each page:
- group filters by status, date, ownership/context, and provider/model where applicable;
- preserve all existing controller/query behavior;
- keep page-level filters outside DataTable if they affect multiple surfaces;
- add or update tests for filter-group usage and action labels.

Run:
php artisan view:cache
php artisan test tests/Feature/UI/DataTableFrameworkTest.php
php artisan test tests/Feature/UI/ApplicationShellFrameworkTest.php
npm run build
git diff --check
```

### Prompt 4: CSV Export Foundation

```text
Add the first Universal DataTable CSV export capability.

Implement server-side CSV export only for:
- resources/views/admin/query-intent/index.blade.php

Add:
- reusable CSV export helper/service;
- export route/controller action with matching authorization;
- toolbar export action;
- tests proving filters/search are applied and action/UI columns are excluded.

Do not add saved views and do not add exports to other pages yet.

Run:
php artisan view:cache
php artisan test tests/Feature/UI/DataTableFrameworkTest.php
php artisan test <new CSV export test file>
npm run build
git diff --check
```

### Prompt 5: CSV Export Expansion

```text
Expand DataTable CSV export to the next operational pages.

Touch only:
- resources/views/admin/llm/monitor.blade.php
- resources/views/admin/contact-submissions/index.blade.php
- resources/views/app/sites/seo-audits/index.blade.php

For each page:
- reuse the CSV export service;
- preserve existing filters/search;
- exclude action/UI-only columns and nested detail-row UI;
- add authorization and scope tests.

Do not add saved views yet.

Run:
php artisan view:cache
php artisan test tests/Feature/UI/DataTableFrameworkTest.php
php artisan test <CSV export test files>
npm run build
git diff --check
```

### Prompt 6: Saved Views Foundation

```text
Add the first server-rendered DataTable saved views capability.

Implement saved views only for:
- resources/views/app/briefs/index.blade.php
- resources/views/app/drafts/index.blade.php

Add:
- saved table views persistence;
- service-level allowed filter schemas;
- create/apply/delete/default flows;
- toolbar saved-view UI;
- tests for scoping, allowed keys, applying views, and authorization.

Do not add saved views to admin or multi-table report pages yet.

Run:
php artisan view:cache
php artisan test tests/Feature/UI/DataTableFrameworkTest.php
php artisan test <new saved views test file>
npm run build
git diff --check
```

### Prompt 7: Multi-Table Report Rollout

```text
Extend DataTable export/saved-view patterns to one multi-table report page.

Touch only:
- resources/views/app/sites/llm-tracking/index.blade.php

Before editing, define table keys and decide which controls apply to the page vs individual tables.

Preserve tracking query actions, sticky/max-height report behavior, local badges, and existing filters.

Run:
php artisan view:cache
php artisan test tests/Feature/UI/DataTableFrameworkTest.php
php artisan test <relevant export/saved-view tests>
npm run build
git diff --check
```
