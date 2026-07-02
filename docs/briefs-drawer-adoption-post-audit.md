# Briefs Drawer Adoption Post-Audit

Date: 2026-07-01

## Summary

This audit covers the second additive production drawer adoption on `app/briefs/index`.

The adoption is safe to keep. Existing brief title links remain authoritative and unchanged, using `route('app.briefs.show', $brief)` for the visible title link. The new `Inspect` control is additive: it renders below the title only when both the brief resource metadata and `app.brief.open` action metadata resolve.

The drawer fallback remains the canonical brief detail page. The trigger receives `href` from `route('app.briefs.show', $brief)`, while drawer metadata is scoped to:

- Drawer target: `brief.inspect`
- Drawer mode: `inspect`
- Resource key: `brief:{id}`
- Action key: `app.brief.open`

The drawer shell is present through the app shell `detailDrawer` slot, but it is closed by default and marked `hidden`. Its state is empty, non-interactive, non-editable, and has no tabs, sections, or footer actions. The page does not render brief body, workspace/detail content, or detail-page workflows in the drawer shell. Brief detail pages remain the canonical destination for workspace and workflow content.

No POST, heavy, or destructive brief actions are exposed through this adoption. The descriptor payload is limited to the route-backed GET/open action, and tests assert that mutation or workflow terms such as `POST`, `generate`, `archive`, `enhance`, `compare`, `apply`, `reject`, and `create-draft` are absent from the trigger payload.

Primary actions, filters, reset links, empty states, and pagination remain unchanged. `New Brief`, `Create your first brief`, `Generate multiple articles`, and `Clear filters` still point directly to their existing routes, and pagination still comes from `$briefs->links()` with the existing query-string behavior.

Rollback is intentionally small: remove the additive `Inspect` trigger block from the brief title cell. The hidden drawer shell may remain safely unused after that, though it can also be removed as cleanup.

## Risks

- The current adoption depends on resolved metadata maps being scoped to the current paginator collection. This is covered for briefs, but future pages should not assume global metadata maps are safe.
- The hidden shell is safe because it is closed, empty, and actionless. It does not yet prove production-ready open/close JavaScript, focus trapping, or live drawer content.
- The descriptor may include resource summary metadata such as title, status, relationships, preview field names, and GET action metadata. It must continue to avoid brief body, workspace/detail content, and mutation actions.
- Future edits could accidentally render drawer sections from `DrawerMetadataBuilder` defaults. The production shell deliberately passes empty `tabs`, `sections`, and `footer_actions`.
- The rollback path is user-visible removal of the `Inspect` trigger only. Leaving the hidden shell behind is safe, but code cleanup can remove the `detailDrawer` section once no triggers target it.

## Test Coverage

Covered by `tests/Feature/UI/BriefsInteractionMetadataConsumerTest.php`:

- Existing brief title link text and `href` remain authoritative from `app.briefs.show`.
- `Inspect` renders as an additive drawer trigger with `href` fallback to `app.briefs.show`.
- Trigger metadata includes `brief.inspect`, inspect mode, `brief:{id}`, resource id, brief resource type, and `app.brief.open`.
- Trigger payload keeps `app.brief.open` as GET/link metadata and excludes POST/heavy/destructive/workflow brief actions.
- Unauthorized brief resources are not exposed in metadata maps.
- Primary actions, filter/reset links, empty-state links, and metadata maps remain literal or empty when there are no rows.
- Pagination and filters remain authoritative while metadata resolves only for the current page.
- Metadata resolution is covered under `Model::preventLazyLoading()`.

Covered by `tests/Feature/UI/DrawerAdoptionBladeHelpersTest.php`:

- Drawer buttons render as href-backed anchors when fallback hrefs exist.
- Drawer triggers carry progressive-enhancement metadata and remain navigable without drawer JavaScript.
- Buttons without fallback hrefs render as inert buttons.

Covered by `tests/Feature/UI/RightDrawerComponentsTest.php`:

- Drawer shell renders accessible metadata when open.
- Optional drawer metadata can be omitted safely.
- Empty, loading, and error states render accessible status/alert content.
- Footer actions render as inert buttons.

Verification completed on 2026-07-01:

- `php artisan view:cache` passed.
- `php artisan test tests/Feature/UI/BriefsInteractionMetadataConsumerTest.php` passed: 6 tests, 87 assertions.
- `php artisan test tests/Feature/UI/DrawerAdoptionBladeHelpersTest.php` passed: 4 tests, 26 assertions.
- `php artisan test tests/Feature/UI/RightDrawerComponentsTest.php` passed: 9 tests, 38 assertions.
- `npm run build` passed. It emitted an npm warning for unknown user config `python`.
- `git diff --check` passed.

## Visual QA Checklist

- On `app/briefs/index`, each existing brief title remains the primary visible navigation link.
- `Inspect` appears as a small secondary row control below the brief title and does not replace or wrap the title link.
- Clicking the title navigates to the existing brief detail page.
- With drawer JavaScript unavailable or inactive, clicking `Inspect` follows the same canonical brief detail href.
- The drawer shell is not visible on initial page load.
- No brief body, workspace content, generated draft controls, archive controls, enhancement controls, comparison controls, apply/reject suggestion controls, or create-draft actions appear in the drawer shell.
- Primary `New Brief`, filter/reset controls, empty-state copy/buttons, table headings, row labels, status badges, updated timestamps, and pagination look unchanged.
- Keyboard focus order remains title link first, then the additive `Inspect` trigger.
- Mobile/narrow layouts keep the title link, `Inspect` trigger, and primary keyword readable without overlapping adjacent cells.
