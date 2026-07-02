# SEO Audits Drawer Adoption Post-Audit

Date: 2026-07-01

## Summary

This audit covers the additive production drawer adoption on `app/sites/{site}/insights/audits`.

The adoption is safe to keep. Existing `Open` links remain authoritative and unchanged, using `route('app.sites.seo-audits.show', [$site, $audit])` for the visible row link. The new `Inspect` control is additive: it renders alongside `Open` only when both the SEO audit resource metadata and `app.seo-audit.open` action metadata resolve.

The drawer fallback remains the canonical SEO audit detail page. The trigger receives `href` from `route('app.sites.seo-audits.show', [$site, $audit])`, while drawer metadata is scoped to:

- Drawer target: `seo-audit.inspect`
- Drawer mode: `inspect`
- Resource key: `seo_audit:{id}`
- Action key: `app.seo-audit.open`

The drawer shell is present through the app shell `detailDrawer` slot, but it is closed by default and marked `hidden`. Its state is empty, non-interactive, non-editable, and has no tabs, sections, or footer actions. The shell does not render issue details, AI fixes, run-audit content, or mutation actions. SEO audit detail pages remain the canonical destination for all production audit detail and fix workflows.

No POST, heavy, destructive, AI-fix, generate, apply, sync, or run-audit actions are exposed through this adoption. The descriptor payload is limited to the route-backed GET/open action.

The Run Audit form remains a literal protected POST form pointing to `app.sites.seo-audits.run`. Header links and empty states remain unchanged: `All sites`, `Site setup`, and `No audit runs yet` continue to render as their existing literal links or copy.

Rollback is intentionally small: remove the additive `Inspect` trigger block from the SEO audit action cell. The hidden drawer shell may remain safely unused after that, though it can also be removed as cleanup.

## Risks

- The current adoption depends on resolved metadata maps being scoped to the current audit collection. This is covered for SEO audits, but future pages should not assume global metadata maps are safe.
- The hidden shell is safe because it is closed, empty, and actionless. It does not yet prove production-ready open/close JavaScript, focus trapping, or live drawer content.
- The descriptor may include route-backed SEO audit resource and GET action metadata. It must continue to avoid issue details, AI-fix data, run-audit forms, and mutation actions.
- Future edits could accidentally render drawer sections from `DrawerMetadataBuilder` defaults. The production shell deliberately passes empty `tabs`, `sections`, and `footer_actions`.
- The rollback path is user-visible removal of the `Inspect` trigger only. Leaving the hidden shell behind is safe, but code cleanup can remove the `detailDrawer` section once no triggers target it.

## Test Coverage

Covered by `tests/Feature/UI/SeoAuditsInteractionMetadataConsumerTest.php`:

- Existing SEO audit `Open` links remain authoritative from `app.sites.seo-audits.show`.
- `Inspect` renders as an additive drawer trigger with `href` fallback to `app.sites.seo-audits.show`.
- Trigger metadata includes `seo-audit.inspect`, inspect mode, `seo_audit:{id}`, resource id, SEO audit resource type, and `app.seo-audit.open`.
- Trigger payload keeps `app.seo-audit.open` as GET/link metadata and excludes POST/heavy/destructive/run/AI-fix mutation action terms.
- Unauthorized SEO audit resources are not exposed in metadata maps.
- Header links, the Run Audit POST form, and empty-state copy remain literal or empty when there are no rows.
- Metadata resolution is covered for eager-loaded site context without excessive route-model relation loading.

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
- `php artisan test tests/Feature/UI/SeoAuditsInteractionMetadataConsumerTest.php` passed: 6 tests, 105 assertions.
- `php artisan test tests/Feature/UI/DrawerAdoptionBladeHelpersTest.php` passed: 4 tests, 26 assertions.
- `php artisan test tests/Feature/UI/RightDrawerComponentsTest.php` passed: 9 tests, 38 assertions.
- `npm run build` passed. It emitted an npm warning for unknown user config `python`.
- `git diff --check` passed.

## Visual QA Checklist

- On `app/sites/{site}/insights/audits`, each existing `Open` link remains the primary canonical navigation to the audit detail page.
- `Inspect` appears as a small secondary row control beside `Open` and does not replace or wrap the `Open` link.
- Clicking `Open` navigates to the existing SEO audit detail page.
- With drawer JavaScript unavailable or inactive, clicking `Inspect` follows the same canonical SEO audit detail href.
- The drawer shell is not visible on initial page load.
- No issue detail sections, AI fix suggestion panels, run-audit form content, generate/apply/sync controls, or mutation actions appear in the drawer shell.
- Header links, table headings, row labels, status badges, issue counts, monthly cap, last-run summary, Run Audit POST form, and empty-state copy look unchanged.
- Keyboard focus order keeps the existing `Open` link first, then the additive `Inspect` trigger.
- Mobile/narrow layouts keep the `Open` link, `Inspect` trigger, status, pages, and issue counts readable without overlapping adjacent cells.
