# Next Drawer Adoption Candidate Audit

Date: 2026-07-01

## Scope

This audit identifies the safest next additive drawer adoption after `app/drafts/index` and `app/briefs/index`.

No additional page was migrated.

The safety bar is the same as the first two adoptions:

- Existing links remain authoritative.
- The drawer trigger is additive and href-backed.
- Only route-backed GET/open action metadata is exposed.
- POST, heavy, AI, workflow, and destructive actions remain outside the drawer descriptor.
- The fallback route is the existing canonical detail route.
- Tests prove page-scoped metadata, unchanged links, empty states, filters or pagination, authorization boundaries, and no mutation action leakage.

## Recommendation

The safest next page is `app/sites/seo-audits/index`.

Why:

- It already resolves row-scoped interaction metadata in `AppSiteSeoAuditController::index`.
- Its current row resource is singular and simple: `seo_audit`.
- Its existing row action is open-only: `app.seo-audit.open` to `app.sites.seo-audits.show`.
- The visible table row has only the existing `Open` link; the heavy run action is outside the table as a separate protected POST form.
- Existing tests already cover authoritative open links, the protected run form, empty state, unauthorized resources, and eager-loaded site context.
- The fallback route is unambiguous: `route('app.sites.seo-audits.show', [$site, $audit])`.

`app/research/index` is the runner-up. It is also a viable next adoption, but it has row-adjacent `Start` and `Rerun` POST forms, so its blast radius is slightly higher than the SEO audit table.

## Candidate Matrix

| Page | Current resource types | Existing GET/open actions | Adjacent POST/heavy/destructive actions | Additive Inspect safe? | Fallback route | Metadata availability | Required tests | Adoption risk |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `app/sites/seo-audits/index` | `seo_audit` | `app.seo-audit.open` via `app.sites.seo-audits.show` | `app.sites.seo-audits.run` is POST + `protect.heavy:audit`; detail page has AI fix generate/apply/sync POST routes, but they are not on the index row | Yes. Safest candidate. Add `Inspect` beside the existing `Open` row action only when `seo_audit:{id}` and `app.seo-audit.open` resolve | `route('app.sites.seo-audits.show', [$site, $audit])` | Strong. Controller passes `interactionResourcesByKey` and `interactionActionsByKey`; provider exposes only `ACTION_SEO_AUDIT_OPEN` for rows | Extend `tests/Feature/UI/SeoAuditsInteractionMetadataConsumerTest.php` for Inspect trigger, descriptor target/mode/resource/action, href fallback, no run/AI-fix terms; keep drawer helper and right-drawer component tests | Low |
| `app/research/index` | `research_project` | `app.research-project.open` via `app.research.show`; page-level create action `app.research-project.create` via `app.research.create` | `app.research.store` POST; row-level `app.research.start` POST + `protect.heavy:report` for draft/failed rows; `app.research.findings.select` POST on detail | Yes, but second safest. Add `Inspect` under the project title or beside `Open`; do not touch Start/Rerun forms | `route('app.research.show', $project)` | Strong. Controller passes row metadata and create action metadata; tests already prove start/rerun is not metadata | Extend `tests/Feature/UI/ResearchInteractionMetadataConsumerTest.php` for Inspect trigger, descriptor target/mode/resource/action, href fallback, no start/rerun/force/POST terms in trigger payload, pagination current-page scope | Low-medium |
| `app/sites/index` | `site` | `app.site.open` via `app.sites.show` | Add-site POST, test WordPress/Laravel connector POST + `protect.heavy:heavy`, update/automation/regenerate-key/toggle POST, delete route | Technically possible, but defer. The page has generated one-time key handling and setup/connectivity forms near the rows | `route('app.sites.show', $site)` | Strong. Controller passes row metadata and filters actions to GET | Would need new drawer adoption assertions in `tests/Feature/UI/SitesInteractionMetadataConsumerTest.php`, plus generated-key block and connector POST non-leakage checks | Medium |
| `app/sites/llm-tracking/index` | Provider supports `llm_tracking_query`, but the index currently does not resolve or pass maps | Existing row links to `app.sites.llm-tracking.show`; other GETs include starter preview and detail/run views | Query create POST + `protect.heavy:ai`, starter create POST, query set create/update/toggle POST, query toggle POST, run-now POST + `protect.heavy:ai`, rescore POST | Not yet. Safe only after index-level metadata resolution is added and tested separately | `route('app.sites.llm-tracking.show', [$site, $query])` | Partial. `AppSiteInteractionProvider` has the resource/action, but `AppLlmTrackingController::index` does not pass `interactionResourcesByKey`/`interactionActionsByKey` | First add metadata consumer tests for scoped query metadata, no run/toggle/query-set leakage, empty state, filters, latest-answer rows; then add drawer trigger tests | Medium-high |
| `app/signal-intelligence/index` | Provider supports `signal_detection`, but the index currently does not resolve or pass maps | Existing links to `app.signal-intelligence.detections.show` in detections, summaries, high priority, candidates | Run detection POST + `protect.heavy:report`; row action menu POSTs review/dismiss/resolve; detail has promote POST | Not yet. Needs metadata resolution first, and row action menu separation must be proven | `route('app.signal-intelligence.detections.show', $detection)` | Partial. Provider exists, but controller does not expose maps to the index | Add metadata consumer tests for detection rows only, no review/dismiss/resolve/promote/run leakage, filters, pagination, unauthorized detections, lazy-loading safety; then drawer tests | High |
| `app/content/index` | Provider supports `content`, but the index currently does not resolve or pass maps | Existing canonical and variant links to `app.content.show`; calendar/create links are GET | Very dense: create content POST, bulk schedule/sync POST, translate POST, restore/delete actions, plus many heavy/detail routes for content AI, publishing, localization, images, lifecycle, and chain suggestions | Defer. This should not be the next adoption because the index mixes canonical rows, variants, bulk actions, translation actions, restore/delete, and expandable row behavior | `route('app.content.show', $content)` for canonical and variant rows | Partial. Provider exists, but index does not pass row metadata maps | Requires a dedicated content-index metadata design first: canonical vs variant resource selection, localization family scoping, bulk action non-leakage, delete/restore non-leakage, mobile and desktop parity, pagination/simple paginator behavior | Very high |

## Safest Next Page

Adopt `app/sites/seo-audits/index` next.

The implementation should be intentionally smaller than research:

- Add a drawer descriptor per audit row only when both `seo_audit:{id}` and `app.seo-audit.open` exist.
- Render an additive `Inspect` trigger beside the existing `Open` link in the row action cell.
- Keep the existing `Open` link unchanged.
- Use href fallback to `app.sites.seo-audits.show`.
- Use target `seo-audit.inspect`, mode `inspect`, resource key `seo_audit:{id}`, and action key `app.seo-audit.open`.
- Add a closed, empty drawer shell through the app shell `detailDrawer` slot, matching the drafts/briefs production pattern.
- Do not expose `app.sites.seo-audits.run`, AI fix generate/apply/sync, POST, heavy, audit-run queuing, issue mutation, or crawl payloads in the descriptor.

## Pages That Must Remain Deferred

`app/content/index` must remain deferred. It is the riskiest candidate because it combines canonical content, variants, localization families, mobile and desktop render paths, bulk POST actions, translation forms, restore/delete controls, and many detail-level heavy/workflow routes. It needs a separate content-index metadata design before drawer adoption.

`app/signal-intelligence/index` must remain deferred. It has an interaction provider for `signal_detection`, but the index does not yet pass scoped metadata maps. It also has row-adjacent review/dismiss/resolve POST actions and a heavy run-detection form.

`app/sites/llm-tracking/index` must remain deferred. It has provider support for `llm_tracking_query`, but the index does not yet resolve metadata. It also mixes query-set editing, query creation, query toggles, run-now, starter-query creation, and dashboard tables.

`app/sites/index` should remain deferred until after one more low-risk adoption. It has strong metadata and route-backed site open actions, but generated key handling, setup instructions, connector test forms, key regeneration, toggle, and delete flows make it less clean than SEO audit runs.

## Exact Implementation Prompt

Use this prompt for the next adoption:

```text
Migrate only `app/sites/seo-audits/index` to the drawer adoption layer.

Do not migrate any other page.

Keep the existing SEO audit `Open` link unchanged and authoritative:
`route('app.sites.seo-audits.show', [$site, $audit])`.

Add an additive `Inspect` drawer trigger beside the existing `Open` row action only when:
- `interactionResourcesByKey["seo_audit:{id}"]` exists
- `interactionActionsByKey["seo_audit:{id}"]["app.seo-audit.open"]` exists

Build the descriptor with `DrawerMetadataBuilder`:
- target: `seo-audit.inspect`
- mode: `inspect`
- resource key: `seo_audit:{id}`
- action key: `app.seo-audit.open`
- href fallback: `route('app.sites.seo-audits.show', [$site, $audit])`

Add the same closed, hidden, empty `detailDrawer` shell pattern used by drafts and briefs:
- no tabs
- no sections
- no footer actions
- no audit issue details
- no AI fix content
- no run-audit content
- no mutation actions

Update `tests/Feature/UI/SeoAuditsInteractionMetadataConsumerTest.php` to assert:
- existing `Open` href remains unchanged
- `Inspect` renders as an href-backed drawer trigger
- trigger metadata includes `seo-audit.inspect`, `inspect`, `seo_audit:{id}`, resource id/type, and `app.seo-audit.open`
- trigger payload keeps the action as GET/link metadata
- trigger payload excludes `POST`, `run`, `audit`, `ai-fix`, `generate`, `apply`, `sync`, `heavy`, and destructive terms
- empty state remains literal and has no row metadata
- unauthorized audit resources are not exposed
- eager-loaded site context still avoids route-model N+1 lookups

Also run:
- `php artisan test tests/Feature/UI/SeoAuditsInteractionMetadataConsumerTest.php`
- `php artisan test tests/Feature/UI/DrawerAdoptionBladeHelpersTest.php`
- `php artisan test tests/Feature/UI/RightDrawerComponentsTest.php`
- `php artisan view:cache`
- `npm run build`
- `git diff --check`
```

## Verification For This Audit

This audit requires only documentation plus build verification. No migration was performed.

Commands requested for this audit:

- `php artisan view:cache`
- `npm run build`
- `git diff --check`
