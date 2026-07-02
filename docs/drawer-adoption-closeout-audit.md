# Drawer Adoption Closeout Audit

Date: 2026-07-01

## Summary

This closeout covers the combined additive drawer adoption on `app/drafts/index` and `app/briefs/index`. No additional page was migrated.

Both adoptions are safe to keep. The canonical title links remain the primary row navigation, the `Inspect` triggers are additive only, and each trigger falls back to the same show route as the title link when drawer JavaScript is unavailable.

The drawer payloads are metadata-only GET/open descriptors. They do not expose POST, heavy, destructive, or workflow actions. The hidden drawer shells are closed by default, empty, non-interactive, non-editable, and actionless. No body content, detail-page content, or workflow content is rendered in either index drawer shell.

Rollback remains intentionally small: remove the additive `Inspect` trigger block from the affected title cell. The hidden shell is safe to leave unused, though it can be removed as cleanup if no trigger targets it.

## Shared Pattern

- `app/drafts/index` keeps the visible title anchor on `route('app.drafts.show', $draft)`.
- `app/briefs/index` keeps the visible title anchor on `route('app.briefs.show', $brief)`.
- Each page builds a row-scoped drawer descriptor only after both resource metadata and the matching open action resolve.
- Draft triggers target `draft.inspect`, use inspect mode, carry `draft:{id}`, and use `app.draft.open`.
- Brief triggers target `brief.inspect`, use inspect mode, carry `brief:{id}`, and use `app.brief.open`.
- The trigger `href` is the canonical show URL, so the drawer control is progressive enhancement rather than replacement navigation.
- The drawer shell is supplied through `detailDrawer`, starts closed, and passes empty `tabs`, `sections`, and `footer_actions`.
- The shell state sets `empty: true`, `interactive: false`, `can_edit: false`, and `renders_production_content: false`.

## Audit Findings

- Title links remain canonical: pass for drafts and briefs.
- Inspect triggers are additive only: pass for drafts and briefs.
- Href fallbacks are correct: pass for drafts and briefs.
- Drawer metadata is GET/open only: pass for drafts and briefs.
- Hidden shells are empty/actionless: pass for drafts and briefs.
- No body/detail/workflow content is rendered: pass for drafts and briefs.
- No POST/heavy/destructive actions appear in drawer metadata: pass for drafts and briefs.
- Pagination, filters, and empty states remain unchanged: pass for drafts and briefs.
- Rollback remains removing the Inspect trigger only: pass for drafts and briefs.

## Risks

- The current pattern depends on metadata maps being scoped to the current paginator collection. The drafts and briefs tests cover this, but future pages should repeat that assertion.
- The hidden shells prove safe placeholder rendering, not full production drawer behavior with live JavaScript, focus trapping, and dynamic content loading.
- `DrawerMetadataBuilder` can generate default sections and footer actions when allowed to do so. These adoptions deliberately override the production shells to empty arrays.
- Future action registry growth could add mutation actions to resources. Page-level tests should continue asserting the drawer payload remains GET/open only.
- The descriptor may include safe resource summary metadata such as title, status, relationships, and preview field names. It must not drift into body, detail, or workflow payloads.

## Test Coverage

Covered by `tests/Feature/UI/DraftsInteractionMetadataConsumerTest.php`:

- Draft title text and `href` remain authoritative from `app.drafts.show`.
- `Inspect` renders as an additive drawer trigger with canonical `app.drafts.show` fallback.
- Trigger metadata includes `draft.inspect`, inspect mode, `draft:{id}`, draft resource type, and `app.draft.open`.
- Payload keeps `app.draft.open` as GET/link metadata and excludes POST/heavy/destructive draft terms.
- Unauthorized resources are not exposed.
- Empty-state links remain literal.
- Pagination and filters remain authoritative while metadata resolves for the current page only.
- Metadata resolution runs under `Model::preventLazyLoading()`.

Covered by `tests/Feature/UI/BriefsInteractionMetadataConsumerTest.php`:

- Brief title text and `href` remain authoritative from `app.briefs.show`.
- `Inspect` renders as an additive drawer trigger with canonical `app.briefs.show` fallback.
- Trigger metadata includes `brief.inspect`, inspect mode, `brief:{id}`, brief resource type, and `app.brief.open`.
- Payload keeps `app.brief.open` as GET/link metadata and excludes POST/heavy/destructive/workflow brief terms.
- Unauthorized resources are not exposed.
- Primary actions, filters, reset links, and empty states remain literal.
- Pagination and filters remain authoritative while metadata resolves for the current page only.
- Metadata resolution runs under `Model::preventLazyLoading()`.

Covered by `tests/Feature/UI/DrawerAdoptionBladeHelpersTest.php`:

- Drawer buttons render as href-backed anchors when a fallback exists.
- Drawer triggers carry progressive-enhancement metadata.
- Controls without fallback hrefs render as inert buttons.

Covered by `tests/Feature/UI/RightDrawerComponentsTest.php`:

- Drawer shells render accessible regions/dialogs from metadata.
- Empty, loading, and error states render accessible status/alert content.
- Footer actions render as inert controls.
- Optional drawer metadata can be omitted safely.

Verification completed on 2026-07-01:

- `php artisan view:cache` passed.
- `php artisan test tests/Feature/UI/DraftsInteractionMetadataConsumerTest.php` passed: 6 tests, 71 assertions.
- `php artisan test tests/Feature/UI/BriefsInteractionMetadataConsumerTest.php` passed: 6 tests, 87 assertions.
- `php artisan test tests/Feature/UI/DrawerAdoptionBladeHelpersTest.php` passed: 4 tests, 26 assertions.
- `php artisan test tests/Feature/UI/RightDrawerComponentsTest.php` passed: 9 tests, 38 assertions.
- `npm run build` passed. It emitted an npm warning for unknown user config `python`.
- `git diff --check` passed.

## Visual QA Checklist

- On `app/drafts/index`, the draft title remains the first and primary row link.
- On `app/briefs/index`, the brief title remains the first and primary row link.
- `Inspect` appears as a small secondary control below the title and does not wrap or replace the title link.
- Clicking the title navigates to the existing detail page.
- With drawer JavaScript unavailable or inactive, clicking `Inspect` navigates to the same canonical detail page.
- The drawer shell is not visible on initial page load.
- No draft body, brief body, detail tabs, analysis panels, generated draft controls, improvement controls, translation controls, governance controls, archive controls, comparison controls, apply/reject controls, create-draft controls, or republish controls appear in the shell.
- Draft empty-state links and pagination are unchanged.
- Brief primary action, filters, reset link, empty-state links, and pagination are unchanged.
- Keyboard focus order remains title link first, then the additive `Inspect` trigger.
- Narrow layouts keep the title, `Inspect` trigger, badges, primary keyword, and timestamps readable without overlap.

## Recommendation For Next Page

Do not migrate another page as part of this closeout.

For the next adoption, choose a row-list page whose existing row behavior is already open-only or read-mostly, and repeat the same constraints: canonical title/open links remain authoritative, the drawer trigger is additive, the fallback is the existing show route, the shell is hidden and empty, and page-specific tests prove no mutation/workflow actions enter drawer metadata.

`app/research/index` should wait. It is partly prepared because it already resolves research interaction metadata and has tests for canonical open links, create metadata, authorization scoping, pagination, and lazy-loading safety. However, the visible page still includes real POST `Start`/`Rerun` forms for draft and failed research projects. Those forms are intentional existing behavior, and the current tests correctly ensure `app.research.start` is not exposed as metadata. That makes research a more complex adoption target than drafts and briefs.

Before adopting `app/research/index`, add a research drawer-specific plan that explicitly keeps POST start/rerun as existing page actions, adds only a separate GET/open `Inspect` trigger, and asserts the drawer payload excludes start/rerun/force/workflow actions. Until that is done, prefer the next page to be a simpler open-only index rather than research.
