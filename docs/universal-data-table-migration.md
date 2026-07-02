# Universal DataTable Migration

Date: 2026-06-30

## Status

The Universal DataTable foundation is now available as a Blade-first, behavior-preserving table framework for authenticated app/admin pages. The first migration batch wraps existing table loops, forms, actions, filters, pagination, and empty states without changing query semantics or controller behavior.

## Component Contract

- `x-data-table`: root table surface with accessible `label`, optional `description`, responsive overflow, density variants, optional `loading` skeleton, optional `max-height`, and named slots for `search`, `filters`, `actions`, `bulkActions`, and `pagination`.
- `x-data-table.header`: table header wrapper with optional sticky behavior.
- `x-data-table.row`: row wrapper with optional interactive row styling.
- `x-data-table.cell`: heading/data cell wrapper with optional `label`, `align`, `nowrap`, `colspan`, and `scope`.
- `x-data-table.empty`: table-row empty state using the shared `x-empty-state`.
- `x-data-table.actions`: row action group with an accessible action-region label.
- `x-data-table.badge`: normalized table badge with neutral, success, warning, danger, and info tones.
- `x-data-table.bulk-actions`: bulk-action bar wrapper for selected-row workflows.
- `x-data-table.pagination`: pagination wrapper that preserves existing paginator output.
- `x-data-table.toolbar`: table toolbar wrapper for search, filters, and actions where a page can safely move those controls.

## Migrated Pages

Admin:

- `resources/views/admin/queues/index.blade.php`
- `resources/views/admin/users/index.blade.php`
- `resources/views/admin/llm/monitor.blade.php`
- `resources/views/admin/early-access/index.blade.php`
- `resources/views/admin/agent-runs/index.blade.php`
- `resources/views/admin/campaigns/index.blade.php`
- `resources/views/admin/feature-flags/index.blade.php`
- `resources/views/admin/invoices/index.blade.php`
- `resources/views/admin/query-intent/index.blade.php`
- `resources/views/admin/drafts/index.blade.php`
- `resources/views/admin/briefs/index.blade.php`
- `resources/views/admin/contact-submissions/index.blade.php`
- `resources/views/admin/sites/index.blade.php`
- `resources/views/admin/company-intelligence/index.blade.php`
- `resources/views/admin/billing/index.blade.php`
- `resources/views/admin/billing/partials/workspace-usage.blade.php`
- `resources/views/admin/organizations/show.blade.php`
- `resources/views/admin/agentic-action-runs/index.blade.php`
- `resources/views/admin/mos-providers/index.blade.php`
- `resources/views/admin/system-health/index.blade.php`
- `resources/views/admin/campaigns/show.blade.php`
- `resources/views/admin/queues/pending-missing.blade.php`

App:

- `resources/views/app/content/index.blade.php`
- `resources/views/app/sites/llm-tracking/index.blade.php`
- `resources/views/app/competitor-intelligence/index.blade.php`
- `resources/views/app/social-distribution/index.blade.php`
- `resources/views/app/signal-intelligence/index.blade.php`
- `resources/views/app/programmatic-brief-blueprints/index.blade.php`
- `resources/views/app/programmatic-draft-requests/index.blade.php`
- `resources/views/app/programmatic-draft-reviews/index.blade.php`
- `resources/views/app/programmatic-publication-plans/index.blade.php`
- `resources/views/app/programmatic-publication-readiness/index.blade.php`
- `resources/views/app/drafts/index.blade.php`
- `resources/views/app/briefs/index.blade.php`
- `resources/views/app/research/index.blade.php`
- `resources/views/app/sites/seo-audits/index.blade.php`
- `resources/views/app/sites/competitors/index.blade.php`
- `resources/views/app/sites.blade.php`
- `resources/views/app/network-linking/index.blade.php`

## Skipped / Deferred Pages

Admin:

- `resources/views/admin/credit-reservations/index.blade.php`: deferred because the table owns checkbox-driven bulk-release JavaScript and a destructive bulk POST flow.
- `resources/views/admin/llm/settings.blade.php`: deferred because the global/workspace rule rows are dense edit-form panels and the audit table has local disclosure semantics.
- `resources/views/admin/editorial-taxonomy/index.blade.php`: deferred because each row opens an inline edit form inside native `<details>` and includes item lifecycle actions.

App:

- `resources/views/app/dashboard.blade.php`: deferred because the desktop table is paired with mobile cards and existing expandable content-tree behavior.
- `resources/views/app/briefs/partials/draft-compare/score-matrix.blade.php`: deferred because the score matrix is a comparison layout, not a standard record table.
- `resources/views/app/programmatic-growth/beta-report.blade.php`: deferred as a beta/report surface with local report cards and program-value semantics.
- `resources/views/app/sites/analytics/show.blade.php`: deferred as an analytics detail surface with compact chart-adjacent tables.
- `resources/views/app/sites/seo-audits/show.blade.php`: deferred because it contains multiple SEO diagnostic/detail tables with local panel semantics.
- `resources/views/app/sites/learnings/index.blade.php`: deferred as a site-learning detail/reporting table that needs page-level visual QA.
- `resources/views/app/sites/llm-tracking/partials/competitors.blade.php`, `resources/views/app/sites/llm-tracking/partials/history.blade.php`, and `resources/views/app/sites/llm-tracking/partials/sources.blade.php`: deferred because they are nested LLM-tracking detail partials; the main LLM-tracking index tables are already migrated.
- `resources/views/app/human-content/dashboard.blade.php`: deferred as a score dashboard with chart-adjacent report tables.
- `resources/views/app/research/show.blade.php`, `resources/views/app/programmatic-clusters/show.blade.php`, and `resources/views/app/programmatic-publication-plans/show.blade.php`: deferred as show-page detail tables where local context should be reviewed separately.
- `resources/views/app/agentic-marketing/index.blade.php` and `resources/views/app/agentic-marketing/workflows/index.blade.php`: deferred as workflow/dashboard tables with adjacent agentic-marketing panels.
- `resources/views/app/content/partials/translation-monitor.blade.php`, `resources/views/app/content/partials/content-improvement-monitor.blade.php`, `resources/views/app/content/automations/show.blade.php`, `resources/views/app/content/batches/show.blade.php`, `resources/views/app/content/lifecycle/index.blade.php`, `resources/views/app/content/series/index.blade.php`, and `resources/views/app/content/series/show.blade.php`: deferred because these are nested content-operation/detail tables with local monitor, lifecycle, or chain semantics.
- `resources/views/app/content-network/index.blade.php`: deferred because it contains paired network tables that should move together in a dedicated content-network pass.
- `resources/views/app/developer/index.blade.php`, `resources/views/app/developer/docs/partials/endpoint-card.blade.php`, and `resources/views/app/developer/docs/partials/schema-viewer.blade.php`: deferred because they are documentation/reference tables rather than app record tables.
- `resources/views/app/settings/image-presets/index.blade.php`: deferred because the row actions include default-switching and destructive delete confirmation forms.

## Deferred Capabilities

- Saved views.
- Column chooser.
- Column resizing.
- Excel/CSV export.
- Advanced JavaScript table state.
- Livewire/Inertia table rewrites.
- Replacing controller query, sort, filter, or pagination logic.

## Known Local Patterns

- Dense table-adjacent filters remain local unless moving them is behavior-neutral.
- Drawer-like workspaces and edit panels remain local.
- Destructive POST forms, confirmation flows, retry/delete actions, and approval workflows remain unchanged inside DataTable action cells.
- Inline operational POST forms such as feature-flag toggles and invoice refunds remain in-row inside `x-data-table.actions`; the migration does not split, modalize, or add JavaScript state to those flows.
- Mobile card alternatives remain local where pages already provide a separate mobile workflow.
- Nested content tree expansion remains local on `resources/views/app/content/index.blade.php`; only its desktop table shell was standardized.
- Programmatic growth filters remain local above their tables because moving them into table toolbars would not add behavior and could alter the surrounding beta banner/status alert flow.
- Contact submission detail rows remain local as paired DataTable rows so message, website, market, competitor, growth-goal, and mail-error context stays attached to each submission without new JavaScript state.
- Authority candidate evidence details remain local inside `resources/views/app/sites/competitors/index.blade.php`; the migration preserves native `<details>` disclosure behavior.
- The admin sites mobile card workflow remains local; only the desktop table shell in `resources/views/admin/sites/index.blade.php` is standardized with `x-data-table`.

## Recommended Next Batches

1. Billing and user/workspace tables only after confirming local drawer, payment, and destructive action semantics remain unchanged.
2. Remaining admin operational/reporting tables with nested detail rows or mobile alternates, preserving local behavior where present.
