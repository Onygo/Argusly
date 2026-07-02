# Drafts Drawer Adoption Post-Audit

Date: 2026-07-01

## Summary

This audit covers only the first production drawer adoption on `app/drafts/index`. No additional page was migrated.

The adoption is safe to keep. Existing draft title links remain authoritative and unchanged, using `route('app.drafts.show', $draft)` for the visible title link. The new `Inspect` control is additive: it renders below the title only when both the draft resource metadata and `app.draft.open` action metadata resolve.

The drawer fallback remains the canonical draft detail page. The trigger receives `href` from `route('app.drafts.show', $draft)`, while drawer metadata is scoped to:

- Drawer target: `draft.inspect`
- Drawer mode: `inspect`
- Resource key: `draft:{id}`
- Action key: `app.draft.open`

The drawer shell is present through the app shell `detailDrawer` slot, but it is closed by default and marked `hidden`. Its state is empty, non-interactive, non-editable, and has no tabs, sections, or footer actions. The page does not render draft body/detail content in the drawer shell, and the index view does not reference `content_html` or the detail-page draft body. Draft detail pages remain the canonical destination for draft body and workflow content.

No POST, heavy, or destructive draft actions are exposed through this adoption. The descriptor payload is limited to the route-backed GET/open action, and tests assert that heavy or mutation terms such as `POST`, `analyze`, `improve`, `translate`, `governance`, and `republish` are absent from the trigger payload.

Empty states and pagination remain unchanged. The empty-state links still point directly to `app.briefs.create` and `app.content.index`, and pagination still comes from `$drafts->links()` with the existing query-string behavior.

Rollback is intentionally small: remove the additive `Inspect` trigger block from the draft title cell. The hidden drawer shell may remain safely unused after that, though it can also be removed as cleanup.

## Risks

- The current adoption depends on resolved metadata maps being scoped to the current paginator collection. This is covered for drafts, but future pages should not assume global metadata maps are safe.
- The hidden shell is safe because it is closed, empty, and actionless. It does not yet prove production-ready open/close JavaScript, focus trapping, or live drawer content.
- The descriptor may include resource summary metadata such as title, status, relationships, preview field names, and GET action metadata. It must continue to avoid draft body/detail content and mutation actions.
- Future edits could accidentally render drawer sections from `DrawerMetadataBuilder` defaults. The production shell deliberately passes empty `tabs`, `sections`, and `footer_actions`.
- The rollback path is user-visible removal of the `Inspect` trigger only. Leaving the hidden shell behind is safe, but code cleanup can remove the `detailDrawer` section once no triggers target it.

## Test Coverage

Covered by `tests/Feature/UI/DraftsInteractionMetadataConsumerTest.php`:

- Existing draft title link text and `href` remain authoritative from `app.drafts.show`.
- `Inspect` renders as an additive drawer trigger with `href` fallback to `app.drafts.show`.
- Trigger metadata includes `draft.inspect`, inspect mode, `draft:{id}`, resource id, draft resource type, and `app.draft.open`.
- Trigger payload keeps `app.draft.open` as GET/link metadata and excludes POST/heavy/destructive draft actions.
- Unauthorized draft resources are not exposed in metadata maps.
- Empty-state links remain literal and metadata maps remain empty when there are no rows.
- Pagination and site filters remain authoritative while metadata resolves only for the current page.
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
- `php artisan test tests/Feature/UI/DraftsInteractionMetadataConsumerTest.php` passed: 6 tests, 71 assertions.
- `php artisan test tests/Feature/UI/DrawerAdoptionBladeHelpersTest.php` passed: 4 tests, 26 assertions.
- `php artisan test tests/Feature/UI/RightDrawerComponentsTest.php` passed: 9 tests, 38 assertions.
- `npm run build` passed. It emitted an npm warning for unknown user config `python`.
- `git diff --check` passed.

## Visual QA Checklist

- On `app/drafts/index`, each existing draft title remains the primary visible navigation link.
- `Inspect` appears as a small secondary row control below the draft title and does not replace or wrap the title link.
- Clicking the title navigates to the existing draft detail page.
- With drawer JavaScript unavailable or inactive, clicking `Inspect` follows the same canonical draft detail href.
- The drawer shell is not visible on initial page load.
- No draft body, draft detail tabs, analysis content, improvement controls, translation controls, governance controls, or republish actions appear in the drawer shell.
- Empty-state copy, empty-state buttons, table headings, row labels, status/language/type badges, created timestamps, and pagination look unchanged.
- Keyboard focus order remains title link first, then the additive `Inspect` trigger.
- Mobile/narrow layouts keep the title link and `Inspect` trigger readable without overlapping adjacent cells.

## Recommendation

`app/briefs/index` can be next, but only if it follows the same additive, metadata-only pattern:

- Keep existing brief title links authoritative.
- Add only an inspect/open trigger with href fallback to `app.briefs.show`.
- Use inspect-mode drawer metadata scoped to `brief:{id}` and the existing brief open action.
- Render no brief body/workspace detail content in the drawer.
- Expose no generate, archive, POST, heavy, or destructive actions.
- Add page-specific tests before or with the adoption for unchanged links, additive trigger behavior, empty state, pagination/filter preservation, unauthorized resources, and no mutation actions.

Do not advance to a denser page until the briefs adoption passes the same production audit shape.
