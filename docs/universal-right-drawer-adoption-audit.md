# Universal Right Drawer Adoption Audit

Date: 2026-07-01

## Purpose

This audit identifies the first safe app pages and resources that can adopt the Universal Right Drawer Engine later.

Reference documents:

- `docs/universal-right-drawer.md`
- `docs/interaction-metadata-consumer-post-audit.md`
- `docs/universal-resource-registry.md`
- `docs/universal-action-registry.md`

This began as an adoption audit only. The first approved production adoption is now `app/drafts/index`, implemented as an additive drawer trigger that keeps the existing title link and `app.drafts.show` detail route canonical. The second additive adoption is `app/briefs/index`, using the same metadata-only inspect pattern while keeping `app.briefs.show` canonical.

## Current Baseline

The five audited pages already resolve interaction metadata in controllers and pass it to Blade as auxiliary maps:

- `interactionResourcesByKey`
- `interactionActionsByKey`

The maps are current-row scoped and visible-resource scoped. Existing Blade output remains authoritative for titles, URLs, methods, forms, copy, filters, empty states, pagination, and generated-key output.

The drawer engine supports `inspect`, `preview`, `readonly`, and future `edit` modes. The first two production uses are metadata-only: `app/drafts/index` and `app/briefs/index` render `inspect` triggers with href fallbacks and hidden empty drawer shells, but do not render draft body, brief body, workspace content, or detail-page actions inside the drawer.

## Recommendation

First drawer adoption page: `app/drafts/index`.

Second drawer adoption page: `app/briefs/index`.

Reason:

- One current row resource family: `draft:{id}`.
- One resolved row open action: `app.draft.open`.
- Existing route-backed detail URL is stable: `app.drafts.show`.
- The table has no row-level POST, heavy, destructive, copy-key, setup, or inline workflow controls.
- The first drawer can be `inspect` mode with a route-backed "Open full draft" footer action.
- Rollback can be as small as removing a drawer opener while keeping the existing title link untouched.

Approved first drawer scope:

- Add drawer metadata only for current page draft rows.
- Keep each row title as a normal `href` to `app.drafts.show`.
- Use drawer opening as an additive row action and progressive enhancement only.
- Keep the full draft route as the canonical detail page and route fallback.
- Do not render draft body, intelligence, improvement, translation, governance, or publishing content in the drawer.

Approved second drawer scope:

- Add drawer metadata only for current page brief rows.
- Keep each row title as a normal `href` to `app.briefs.show`.
- Use drawer opening as an additive row action and progressive enhancement only.
- Keep the full brief route as the canonical detail page and route fallback.
- Do not render brief body, workspace/detail content, generated draft workflows, archive/enhance/compare controls, or suggestion apply/reject actions in the drawer.

## Page Audit

### `app/drafts/index`

| Area | Finding |
| --- | --- |
| Current row resource type | `draft:{id}` / `ResourceType::DRAFT` from `Draft` rows. |
| Existing detail route | `app.drafts.show` with `{draft}`. |
| Existing open action | `app.draft.open`, GET/link, row-visible. |
| Available metadata maps | `interactionResourcesByKey['draft:{id}']`; `interactionActionsByKey['draft:{id}']['app.draft.open']`. |
| Safe drawer mode candidate | `inspect`. |
| Required drawer tabs/sections | Overview; relationships; signals. Overview should show title, status, language, draft type, created time, site subtitle. Relationships should show brief, content, and site from resource relationships when available. Signals should show safe preview fields such as status, language, delivery status, history timeline key, and AI-safe summary metadata. |
| Actions that must remain routed/literal | Empty-state `app.briefs.create` and `app.content.index`; all full draft show-page actions such as analyze, improve, translate, governance transitions, link suggestions, image restore, and republish. |
| History/deep-link risk | Low. Do not push drawer state in first adoption; use no-history or replace-only drawer metadata. Keep `app.drafts.show` as the shareable/canonical URL. |
| Focus/keyboard risk | Low. First implementation must set `focus_return_target` to the row opener and verify Escape only closes the drawer when enabled. Existing title link keyboard behavior must remain intact. |
| Test requirements | Assert existing title links still render literal `app.drafts.show` URLs; assert current-page-only metadata keys; assert no POST/heavy actions are exposed in drawer footer; component test for resolved draft inspect metadata; browser/a11y test for opener focus return after close once JavaScript exists. |
| Adoption risk | Low. Best first candidate. |

First adoption status: implemented additively. The row title link still points to `app.drafts.show`; the new `Inspect` trigger is a separate href-backed control using `draft.inspect`, `inspect` mode, resource key `draft:{id}`, and action key `app.draft.open`.

### `app/briefs/index`

| Area | Finding |
| --- | --- |
| Current row resource type | `brief:{id}` / `ResourceType::BRIEF` from `Brief` rows. |
| Existing detail route | `app.briefs.show` with `{brief}`. |
| Existing open action | `app.brief.open`, GET/link, row-visible. |
| Available metadata maps | `interactionResourcesByKey['brief:{id}']`; `interactionActionsByKey['brief:{id}']['app.brief.open']`. |
| Safe drawer mode candidate | `inspect`. |
| Required drawer tabs/sections | Overview; brief inputs; relationships; history. Overview should show title, status, content type, source, updated time, and site. Brief inputs should show primary keyword and safe preview fields such as intent/source metadata. Relationships should show related content and site. |
| Actions that must remain routed/literal | `app.briefs.create`, filter/reset GET form, empty-state create/batch links, and all detail-page actions such as edit, enhance, archive, create draft, generate draft, compare, apply/reject suggestions. |
| History/deep-link risk | Low to medium. Filters already own query string state, so first drawer adoption should not add query parameters until a shared drawer query convention exists. |
| Focus/keyboard risk | Low to medium. The page has a filter form and primary action before the table; focus return must target the exact row opener rather than the first matching title link. |
| Test requirements | Preserve literal filter form and reset output; assert `app.brief.open` remains the only row action metadata used for the drawer candidate; assert empty-state links stay literal; add keyboard tests for closing a drawer after opening from filtered rows. |
| Adoption risk | Low. Good second candidate after drafts. |

Second adoption status: implemented additively. The row title link still points to `app.briefs.show`; the new `Inspect` trigger is a separate href-backed control using `brief.inspect`, `inspect` mode, resource key `brief:{id}`, and action key `app.brief.open`. Primary `New Brief`, filter/reset links, empty-state create/batch links, and all generate/archive/enhance/compare/apply/reject/create-draft workflows remain routed/literal and are not mapped into drawer metadata.

### `app/research/index`

| Area | Finding |
| --- | --- |
| Current row resource type | `research_project:{id}` / `ResourceType::RESEARCH_PROJECT` from `ResearchProject` rows. |
| Existing detail route | `app.research.show` with `{project}`. |
| Existing open action | `app.research-project.open`, GET/link, row-visible. Page-level create action `app.research-project.create` may also be present under `interactionActionsByKey['app.research.index']`. |
| Available metadata maps | `interactionResourcesByKey['research_project:{id}']`; `interactionActionsByKey['research_project:{id}']['app.research-project.open']`; optional `interactionActionsByKey['app.research.index']['app.research-project.create']`. |
| Safe drawer mode candidate | `readonly`. |
| Required drawer tabs/sections | Overview; linked context; counts; run state. Overview should show name, status, created time. Linked context should show related brief or site. Counts should show sources and findings counts. Run state may show whether a routed Start/Rerun action exists, but must not execute it. |
| Actions that must remain routed/literal | New research project link; Start/Rerun POST form to `app.research.start`; hidden `force` field for failed reruns; detail-page finding selection POST. |
| History/deep-link risk | Medium. Research index is workspace-scoped by query string, and drawer state must not obscure or drop `workspace_id`. First adoption should keep drawer state out of the URL. |
| Focus/keyboard risk | Medium. Row action cells contain both GET links and POST forms. Keyboard order must keep Start/Rerun reachable and must not make Enter on the POST button open a drawer. |
| Test requirements | Assert Start/Rerun remains a literal POST with CSRF and `force` behavior; assert drawer footer excludes start/rerun until queued actions are modeled; assert create metadata is not confused with row drawer actions; add focus-order coverage for mixed link/form row actions. |
| Adoption risk | Medium. Defer until a link-only page proves drawer adoption. |

### `app/sites/seo-audits/index`

| Area | Finding |
| --- | --- |
| Current row resource type | `seo_audit:{id}` / `ResourceType::SEO_AUDIT` from limited recent `SeoAudit` rows. |
| Existing detail route | `app.sites.seo-audits.show` with `{site}` and `{audit}`. |
| Existing open action | `app.seo-audit.open`, GET/link, row-visible. |
| Available metadata maps | `interactionResourcesByKey['seo_audit:{id}']`; `interactionActionsByKey['seo_audit:{id}']['app.seo-audit.open']`. |
| Safe drawer mode candidate | `readonly`. |
| Required drawer tabs/sections | Overview; issue counts; site context; AI safety. Overview should show audit ID/date, status, pages crawled, and error message. Issue counts should show error/warning/info counts from the same visible overview counts used by the table. Site context should show the scoped site. AI safety should only summarize available `ai` metadata and must not expose fix suggestions from the detail page. |
| Actions that must remain routed/literal | All sites link, site setup link, Run SEO audit POST form to `app.sites.seo-audits.run`, max-pages input, and detail-page AI fix generate/apply/edit/sync routes. |
| History/deep-link risk | Medium. The page already has canonical nested site/audit URLs and legacy redirects for older audit URLs. Drawer state must not replace canonical audit detail URLs. |
| Focus/keyboard risk | Medium. The page has a run-audit form before the table and row Open links inside action cells. Drawer opener focus must not interfere with number input or submit behavior. |
| Test requirements | Assert run-audit form remains literal POST and protected by existing middleware; assert metadata keys exactly match the limited recent audit collection; assert issue counts in drawer metadata match visible overview counts; add route-fallback test for `app.sites.seo-audits.show`. |
| Adoption risk | Medium. Defer until read-only drawer patterns are proven and SEO issue-count parity tests exist. |

### `app/sites/index`

| Area | Finding |
| --- | --- |
| Current row resource type | `site:{id}` / `ResourceType::SITE` from `ClientSite` rows. |
| Existing detail route | `app.sites.show` with `{site}`. |
| Existing open action | `app.site.open`, GET/link, row-visible. |
| Available metadata maps | `interactionResourcesByKey['site:{id}']`; `interactionActionsByKey['site:{id}']['app.site.open']`. |
| Safe drawer mode candidate | `preview` for a minimal setup summary, or `readonly` later for richer site state. |
| Required drawer tabs/sections | Overview; setup; usage; integrations. Overview should show site name, URL, type, status, last seen, workspace. Setup should show safe setup state only, not generated secrets. Usage should mirror non-sensitive site usage counts when available. Integrations should show WordPress/Laravel connection status without running tests. |
| Actions that must remain routed/literal | Add site POST form, billing/manage link, WordPress plugin download link, generated key copy block, setup instructions, row Test connection POST forms for WordPress/Laravel, site update, automation, key regeneration, plugin license key generation, toggle, and delete routes. |
| History/deep-link risk | High. The index can render one-time generated-key state from session, setup instructions, simple pagination, and workspace scope. Drawer state must not hide or duplicate generated-key presentation. |
| Focus/keyboard risk | High. The page has setup forms, a copy-key button using inline JavaScript, type-select JavaScript, and row POST forms. Drawer focus trapping must not capture form workflows or clipboard interactions. |
| Test requirements | Preserve generated key one-time output; assert copy-key and site-type JavaScript still target existing IDs; assert test-connection forms remain POST and type-specific; assert drawer candidate never includes generated secrets; add regression coverage for simple pagination plus drawer row opener focus. |
| Adoption risk | High. Defer. |

## Deferred Pages

`app/briefs/index` has now completed the second additive inspect adoption. Continue to keep detail-page workflows and mutation actions outside drawer metadata.

`app/research/index` is deferred because GET Open links sit beside Start/Rerun POST forms and a page-level Create action. It needs stricter action separation before drawer adoption.

`app/sites/seo-audits/index` is deferred because the page mixes readonly audit rows with a heavy Run SEO audit POST workflow and nested site/audit route state.

`app/sites/index` is deferred because it includes site creation, setup instructions, generated one-time secrets, clipboard behavior, and connector test POST forms. It should not be an early drawer migration target.

## No-Production-Migration Checklist

- Add `@section('detailDrawer')` only for approved additive adoptions, and keep those shells closed, empty, and actionless.
- Do not replace any existing row `href`, form `action`, HTTP method, CSRF token, button copy, filter, empty state, pagination, generated key, or setup instruction.
- Do not register production drawer openers in Blade outside approved additive adoptions.
- Do not add JavaScript drawer state, focus trapping, Escape behavior, URL parameters, hash fragments, or browser history handling.
- Do not move routed detail-page content into drawer components.
- Do not expose POST, heavy, destructive, external publishing, setup, connection test, generated-key, or AI-fix actions as drawer footer actions.
- Do not read sensitive generated secrets into drawer metadata.
- Keep `interactionResourcesByKey` and `interactionActionsByKey` auxiliary until a separate migration explicitly consumes them.

## Rollback Strategy

For the first future adoption, keep drawer behavior additive and reversible:

- Existing row title links remain canonical route links.
- Drawer openers, if added later, should be separate controls or progressive enhancements that can be removed without changing table navigation.
- Full detail routes remain the fallback for every drawer footer "Open full page" action.
- Use a feature flag or page-local guard for the first production drawer opener.
- Keep browser history disabled for the first adoption so rollback does not need URL migration logic.
- If drawer rendering fails, render no drawer and preserve the current table output.
- If focus restoration fails in testing, ship with routed links only and keep drawer metadata unused.

## Test Plan

Before any production drawer adoption:

- Run the existing drawer engine/component/architecture tests.
- Add page-level tests proving current Blade output remains route/form literal.
- Add metadata tests proving only current visible rows produce drawer-eligible resources.
- Add tests proving POST/heavy/destructive actions are absent from drawer footer metadata.
- Add authorization tests proving unauthorized resources do not resolve into drawer metadata.
- Add N+1 or lazy-loading coverage for any provider relationship used by drawer sections.
- Add browser accessibility coverage for opening, closing, Escape behavior, focus return, and tab order once JavaScript exists.
- Add route-fallback tests proving every drawer resource has a working canonical detail URL.

Requested verification commands for this audit:

- `php artisan view:cache`
- `php artisan test tests/Feature/UI/RightDrawerComponentsTest.php`
- `php artisan test tests/Unit/DrawerEngineTest.php`
- `php artisan test tests/Unit/Architecture/UniversalRightDrawerArchitectureTest.php`
- `npm run build`
- `git diff --check`
