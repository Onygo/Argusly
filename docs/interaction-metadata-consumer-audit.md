# Interaction Metadata Consumer Audit

## Scope

This audit identifies the first safe UI consumers for the metadata-only Resource Registry and Action Registry adoption batch. It does not propose UI output changes, Blade rendering changes, controller behavior changes, route changes, policy changes, model changes, form changes, job changes, or business logic changes.

The current provider batch covers:

- `content`
- `draft`
- `brief`
- `research_project`
- `site`
- `llm_tracking_query`
- `seo_audit`
- `signal_detection`

Current first-batch actions are route-backed metadata links only:

- `app.content.open`
- `app.content.create`
- `app.content.open-calendar`
- `app.draft.open`
- `app.brief.open`
- `app.research-project.open`
- `app.research-project.create`
- `app.site.open`
- `app.llm-tracking-query.open`
- `app.seo-audit.open`
- `app.signal-detection.open`

## Page-by-page Candidate Table

| Page | Current row resources | Existing row links/actions | Resource Registry mapping | Action Registry mapping | Metadata can resolve without changing output? | Required tests | Risks |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `app/drafts/index` | `$drafts` paginator rows, each `App\Models\Draft`; row also displays related `brief` and `clientSite`. | Title link to `app.drafts.show`; empty-state links to `app.briefs.create` and `app.content.index`. No row mutation actions. | Row: `draft:{id}` / `ResourceType::DRAFT`. Related brief/site are relationships in metadata when loaded. | Row open: `app.draft.open`. Empty-state links are not row actions and can remain literal. | Implemented as the first consumer pass. The controller resolves Resource + Action metadata for the current paginator collection only and passes auxiliary `interactionResourcesByKey` and `interactionActionsByKey` maps to the view. Rendering still uses the existing Blade fields and `route()` calls. | Feature coverage asserts title link URLs/text unchanged, `draft:{id}` and `app.draft.open` metadata are present, unauthorized resources are not exposed, empty state/pagination/filter output stays literal, and row metadata resolves without lazy-loading relations. | Very low. Main risk remains accidentally using registry title/status instead of existing view fields, changing labels or fallback behavior. |
| `app/briefs/index` | `$briefs` paginator rows, each `App\Models\Brief`; row displays related `clientSite`. | Title link to `app.briefs.show`; primary action `app.briefs.create`; filter/reset links; empty-state links to create brief, content batches, or clear filters. No row mutation actions. | Row: `brief:{id}` / `ResourceType::BRIEF`. Related site and content relationships resolve from eager-loaded row relationships. | Row open: `app.brief.open`. Primary create action is close to `app.content.create` naming but not currently covered as `app.brief.create`. | Implemented as the second consumer pass. The controller resolves Resource + Action metadata for the current paginator collection only and passes auxiliary `interactionResourcesByKey` and `interactionActionsByKey` maps to the view. Rendering still uses existing Blade fields, labels, filters, empty states, pagination, and `route()` calls. Primary "New Brief" remains literal and is not mapped. | Feature coverage asserts title link URLs/text unchanged, `brief:{id}` and `app.brief.open` metadata are present, unauthorized resources are not exposed, primary action/reset/filter/empty-state/pagination output stays literal, and row metadata resolves without lazy-loading relations. | Very low. Main risk remains accidentally substituting `app.content.create` for "New Brief"; keep create outside this consumer pass. |
| `app/research/index` | `$projects` paginator rows, each `App\Models\ResearchProject`; row displays related `brief`, `clientSite`, source count, finding count. | Project title link to `app.research.show`; row "Open" link to `app.research.show`; conditional POST form to `app.research.start` for Start/Rerun; primary action to `app.research.create` when authorized. | Row: `research_project:{id}` / `ResourceType::RESEARCH_PROJECT`. | Open/title: `app.research-project.open`; primary create: `app.research-project.create`; Start/Rerun has no first-batch action key. | Implemented as the third consumer pass for GET metadata only. The controller resolves row Resource + Open Action metadata for the current paginator collection and page-level Create Action metadata, then passes auxiliary maps to the view. Rendering still uses existing Blade fields, links, labels, empty state, pagination, and forms. Start/Rerun remains a literal POST form and is explicitly deferred. | Feature coverage asserts title link, row Open link, primary Create link, and Start/Rerun POST form output remain unchanged; `research_project:{id}`, `app.research-project.open`, and creator-visible `app.research-project.create` metadata are present; unauthorized resources are not exposed; row metadata resolves without lazy-loading relations. | Low to medium. The page still mixes GET navigation and POST run controls, so Start/Rerun must remain outside metadata adoption until mutation actions are explicitly modeled. |
| `app/sites/seo-audits/index` | `$audits` collection rows, each `App\Models\SeoAudit`; page scoped by `$site`. | Row "Open" link to `app.sites.seo-audits.show`; POST form to `app.sites.seo-audits.run`; header links to all sites and site setup. | Row: `seo_audit:{id}` / `ResourceType::SEO_AUDIT`; relationship to `site:{id}`. | Row open: `app.seo-audit.open`. Run audit has no first-batch action key and is protected/heavy POST. | Implemented as the fourth consumer pass for GET metadata only. The controller resolves row Resource + Open Action metadata for the current audit collection only and passes auxiliary maps to the view. Rendering still uses existing Blade fields, links, labels, empty state, and the Run Audit POST form. Run Audit remains literal and explicitly deferred. | Feature coverage asserts row Open URLs, Run Audit POST form action/method, header links, and empty state remain unchanged; `seo_audit:{id}` and `app.seo-audit.open` metadata are present; unauthorized resources are not exposed; row metadata resolves from eager-loaded site context without relation N+1 behavior. | Low to medium. The page has one row resource type but includes a prominent mutation form; future adoption must keep `app.sites.seo-audits.run` out of GET/open metadata until heavy mutation actions are explicitly modeled. |
| `app/sites/llm-tracking/index` | Multiple row concepts: `$querySets` forms, `$queries` rows as `App\Models\LlmTrackingQuery`, `$queryPerformanceRows` rows, `$latestResponseRows` rows, trend rows, missing visibility cards. | Query row Open link to `app.sites.llm-tracking.show`; POST toggle and run-now forms; first-run POST form; query set update/toggle/store forms; latest answer Open link to query show. | Query management rows: `llm_tracking_query:{id}` / `ResourceType::LLM_TRACKING_QUERY`. Latest answer rows point back to query resource but are run-derived rows, not first-batch resources. Query sets have no current resource type. | Query Open/latest answer Open: `app.llm-tracking-query.open`. Toggle, Run now, query set update/toggle/create, starter query flows have no first-batch metadata keys. | Partially. Query Open can resolve safely, but the page is too dense for first adoption because row resources and forms are interleaved across several tables. | Feature tests preserving query row Open, toggle, run-now, query set forms, latest answer Open; metadata test for query Open; explicit test that performance/trend rows are not registered as query resources unless they contain a real query id. | High. Multiple tables and mixed forms make it easy to attach query metadata to aggregate rows or to misrepresent mutation forms as registry actions. |
| `app/signal-intelligence/index` | Two main row families: `$recentEvents` signal event rows with no first-batch resource type; `$detections` rows as `App\Models\SignalDetection`. Summary/high-priority/candidate cards also link to detections. | Detection title/Review links to `app.signal-intelligence.detections.show`; conditional POST review/dismiss/resolve forms; primary POST run detection; summary/high-priority/candidate cards link to detection show. | Detection rows/cards: `signal_detection:{id}` / `ResourceType::SIGNAL_DETECTION`. Signal events are not covered by first-batch resource types. | Detection open/review navigation: `app.signal-detection.open`. Review/dismiss/resolve/run detection have no first-batch action keys. | Partially. Detection open links can resolve safely, but the page mixes evidence rows, detection rows, status transitions, and opportunity review language. | Feature tests preserving detection links and POST transition forms; metadata test for `signal_detection:{id}` and `app.signal-detection.open`; guard test that signal events are not resolved through signal detection metadata. | Medium to high. The word "Review" is currently a GET navigation in one place and POST state transition nearby; metadata adoption could blur that distinction. |
| `app/content/index` | Complex content lifecycle rows: canonical `App\Models\Content` rows, child variant `Content` rows, mobile card rows, group/header rows, translation target pseudo-rows, bulk selected contents. | Canonical and variant Open links to `app.content.show`; bulk POST schedule/sync; per-row translate POST; restore POST; delete dialog triggers backed by POST URL; create/calendar/batch/network links; filter links. | Canonical/variant content rows: `content:{id}` / `ResourceType::CONTENT`. Group rows, translation target cards, filter chips, and bulk selections are not standalone first-batch resources. | Open links: `app.content.open`; toolbar create/calendar partly map to `app.content.create` and `app.content.open-calendar`; all translate/restore/delete/bulk actions lack first-batch metadata keys. | Technically yes for Open links, but not safe as a first consumer. Existing output has desktop/mobile duplicate render paths, child rows, bulk forms, and destructive workflows. | Broad feature coverage required: desktop and mobile open links unchanged, child variant links unchanged, bulk form actions unchanged, translate/restore/delete behavior unchanged, empty states unchanged. Also snapshot-like HTML assertions for representative content family. | Very high. Highest chance of behavior drift, duplicate metadata resolution work, N+1 regressions, and accidental replacement of business actions. |
| `app/sites/index` | `$sites` paginator rows, each `App\Models\ClientSite`. | Row partial includes View setup details link to `app.sites.show`; conditional POST Test connection form to either WordPress or Laravel route; add-site POST form; generated key copy button. | Row: `site:{id}` / `ResourceType::SITE`. | View setup details: `app.site.open`. Test connection/add-site have no first-batch action keys. | Implemented as the fifth consumer pass for GET metadata only. The controller resolves row Resource + Open Action metadata for the current paginator collection and passes auxiliary maps to the view. Rendering still uses existing Blade fields, links, labels, pagination, Add site POST form, generated key block, and connector Test connection POST forms. Connector test forms remain literal and explicitly deferred. | Feature coverage asserts row setup URLs, WordPress/Laravel Test connection POST form actions/methods, Add site POST form, generated key block, `site:{id}`, `app.site.open`, unauthorized resource exclusion, current-page paginator scoping, and eager-loaded workspace metadata resolution. | Low to medium. Conditional connector test forms sit next to the open link; future adoption must keep WordPress/Laravel test POSTs out of GET/open metadata until mutation actions are explicitly modeled. |

## Recommended First Consumer Batch

First implementation pages completed: `app/drafts/index`, `app/briefs/index`, `app/research/index`, `app/sites/seo-audits/index`, and `app/sites/index`.

Recommended first batch:

1. `app/drafts/index` - implemented as a metadata-only consumer; existing Blade output remains authoritative.
2. `app/briefs/index` - implemented as a metadata-only consumer; existing Blade output remains authoritative.
3. `app/research/index` - implemented as a metadata-only GET consumer; Start/Rerun POST remains literal and deferred.
4. `app/sites/seo-audits/index` - implemented as a metadata-only GET consumer; Run Audit POST remains literal and deferred.
5. `app/sites/index` - implemented as a metadata-only GET consumer; WordPress/Laravel connector Test connection POST forms and Add site POST remain literal and deferred.

Why these pages first:

- Each row maps one-to-one to a first-batch Resource Registry type.
- Each row has a single GET navigation action already represented in the Action Registry.
- Any adjacent mutation forms, destructive controls, bulk controls, or state transitions remain literal and deferred.
- Existing controllers already load the relationships used by provider metadata.
- No Blade output needs to change: metadata can be resolved and optionally passed beside the current data while existing `route()` calls and displayed fields remain authoritative.

Suggested first-pass implementation shape, for a later change:

- Resolve metadata in the controller or a view composer from the current paginator collection.
- Pass metadata as an auxiliary map such as `interactionResourcesByKey` or `interactionActionsByKey`.
- Do not render from metadata yet.
- Add tests that assert the metadata exists and that the rendered HTML for current links is unchanged.

## Deferred Consumers

| Deferred page | Reason |
| --- | --- |
| `app/research/index` | Implemented for open/create metadata only; conditional POST Start/Rerun behavior remains deferred until mutation actions are explicitly modeled. |
| `app/sites/seo-audits/index` | Implemented for row Open metadata only; protected/heavy Run SEO audit POST remains deferred until mutation actions are explicitly modeled. |
| `app/sites/index` | Implemented for row Open metadata only; WordPress/Laravel connector Test connection POST forms and Add site POST remain deferred until mutation actions are explicitly modeled. |
| `app/signal-intelligence/index` | Detection open metadata exists, but the page also has signal event rows without a first-batch resource type and adjacent Review navigation plus review/dismiss/resolve POST transitions. |
| `app/sites/llm-tracking/index` | Query Open metadata exists, but the page has query sets, tracking queries, latest-answer aggregate rows, run-now/toggle forms, and starter-query flows. Needs narrower table-level adoption rules first. |
| `app/content/index` | Content Open metadata exists, but this page has the broadest surface: canonical rows, variants, mobile/desktop duplicate paths, translation targets, bulk forms, restore/delete flows, and toolbar actions. Defer until metadata consumption has a proven no-output-change test harness. |

## Test Plan

Baseline framework tests to keep running:

- `php artisan test tests/Feature/UI/InteractionProviderAdoptionTest.php`
- `php artisan test tests/Feature/UI/ResourceRegistryFrameworkTest.php`
- `php artisan test tests/Feature/UI/ActionRegistryFrameworkTest.php`

For the first consumer implementation, add focused feature coverage:

- Drafts index renders the same row title link URL and text before and after metadata is resolved.
- Drafts index resolves `draft:{id}` and exposes `app.draft.open` metadata without rendering from it.
- Briefs index renders the same row title link URL and text before and after metadata is resolved.
- Briefs index resolves `brief:{id}` and exposes `app.brief.open` metadata without rendering from it.
- Research index renders the same title, Open, Create, and Start/Rerun POST output before and after metadata is resolved.
- Research index resolves `research_project:{id}`, exposes `app.research-project.open`, and exposes creator-visible `app.research-project.create` metadata without rendering from it.
- SEO audits index renders the same Open link, Run Audit POST form, header links, and empty state before and after metadata is resolved.
- SEO audits index resolves `seo_audit:{id}` and exposes `app.seo-audit.open` metadata without rendering from it.
- Sites index renders the same View setup details link, WordPress/Laravel Test connection POST forms, Add site POST form, generated key block, empty state, and pagination before and after metadata is resolved.
- Sites index resolves `site:{id}` and exposes `app.site.open` metadata without rendering from it; connector Test connection forms remain literal and deferred.
- Empty states, pagination, filters, and primary actions remain literal and unchanged unless a later batch explicitly maps them.
- Query count stays stable or improves; metadata resolution must not introduce relation N+1 behavior.

For deferred pages, require page-specific guard tests before adoption:

- Research: Start/Rerun remains POST to `app.research.start`.
- SEO audits: Run SEO audit remains POST to `app.sites.seo-audits.run`.
- Sites: Test connection remains POST to the WordPress/Laravel route selected by site type.
- Signal Intelligence: Review link remains GET, while review/dismiss/resolve remain POST forms.
- LLM tracking: Open remains GET, while toggle/run-now/query-set forms remain POST.
- Content: Open links remain GET, while translate/restore/delete/bulk schedule/bulk sync remain current forms/dialog actions.

## No-UI-Change Verification Checklist

Use this checklist for the first implementation PR:

- No Blade markup changes are required for the audit-only step.
- No existing `href`, `action`, `method`, button label, visible text, badge label, column order, empty state, pagination, or filter output changes.
- No controllers, routes, policies, models, forms, jobs, or business logic change in this audit-only step.
- Any later metadata resolution is auxiliary data only; existing rendering remains authoritative.
- Metadata resolution must be scoped to the page's actual row collection, not unrelated filter options, summaries, aggregates, or empty-state links.
- GET/open actions may be mapped; POST mutations and protected/heavy operations remain literal until action metadata explicitly models them.
- Authorization behavior remains the existing page/controller authorization behavior; metadata authorization is observed only, not used to hide or show UI in the first consumer pass.
- `php artisan view:cache` succeeds.
- The three interaction framework/adoption test files pass.
- `npm run build` succeeds.
- `git diff --check` reports no whitespace errors.
