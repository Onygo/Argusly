# Universal Drawer Adoption Layer

## Purpose

The Universal Drawer Adoption Layer is the reusable bridge between Argusly pages and the Universal Right Drawer Engine. It lets future pages opt into drawer behavior with a descriptor and fallback-safe Blade helper, without replacing links, removing detail pages, changing controllers, or executing actions.

The infrastructure is now used by the first two additive production adoptions: `app/drafts/index` and `app/briefs/index`. Those pages keep their existing title links and detail pages canonical, and add separate href-backed `Inspect` triggers for current-page rows.

## Architecture

The layer lives in `App\Support\Interaction` beside the interaction registries:

- `DrawerTarget` describes the intended drawer target, mode, resource, action, and route or href fallback.
- `DrawerDescriptor` is the complete adoption payload consumed by Blade helpers and future JavaScript.
- `DrawerOpenAction` converts a descriptor into an inert action-shaped contract for future command palette and context menu surfaces.
- `DrawerResourceAdapter` maps resolved Resource Registry metadata into a descriptor.
- `DrawerActionAdapter` maps resolved Action Registry metadata into a descriptor.
- `DrawerHistoryAdapter` builds fallback URLs and future drawer URLs.
- `DrawerMetadataBuilder` centralizes title, subtitle, icon, badge, tab, section, footer, permission, preview, AI, relationship, loading, empty, and error metadata defaults.

The layer integrates with the Drawer Engine by preserving drawer target, mode, width, state metadata, and engine-compatible arrays. It integrates with the Application Shell through the existing detail drawer region and generic trigger markup. It integrates with Metadata Providers and Consumers through the resolved Resource Registry and Action Registry arrays they already produce.

## Fallback Strategy

Every trigger remains useful without JavaScript:

- `x-drawer-link` renders a normal anchor.
- `x-drawer-button` renders an anchor when an href fallback is present, or an inert button when there is no route fallback.
- `x-drawer-preview` renders an anchor preview card with hover-ready metadata.

The helpers add `data-*` attributes for future enhancement, but the `href` remains the source of truth for navigation.

## Route Fallback

`DrawerTarget` accepts either a direct href or a named route fallback. If a named route exists, the target resolves it to a URL. If neither exists, the fallback is `#`, which keeps the helper inert until a page supplies a real destination.

## Progressive Enhancement

The Blade helpers expose stable attributes:

- `data-drawer-target`
- `data-drawer-mode`
- `data-drawer-resource-type`
- `data-drawer-resource-key`
- `data-drawer-resource-id`
- `data-drawer-action-key`
- `data-drawer-url`
- `data-drawer-payload`
- `data-command-palette-ready`
- `data-context-menu-ready`
- `data-hover-preview-ready`

Future JavaScript can intercept these triggers and open a drawer. Without JavaScript, users continue to reach the existing detail page.

The first production adoption follows this strategy on `app/drafts/index`: the `Inspect` trigger is an anchor with an `app.drafts.show` href fallback, `data-drawer-target="draft.inspect"`, `data-drawer-mode="inspect"`, resource key `draft:{id}`, and action key `app.draft.open`.

The second production adoption follows the same strategy on `app/briefs/index`: the `Inspect` trigger is an anchor with an `app.briefs.show` href fallback, `data-drawer-target="brief.inspect"`, `data-drawer-mode="inspect"`, resource key `brief:{id}`, and action key `app.brief.open`.

## Resource Mapping

`DrawerResourceAdapter` accepts resolved resource metadata or resolves a supplied resource key from an explicit `ResourceRegistry`. It does not load production resources globally. Resource drawer metadata, primary URLs, permissions, relationships, preview metadata, AI metadata, history metadata, status, badges, titles, subtitles, and icons are copied into the descriptor.

## Action Mapping

`DrawerActionAdapter` accepts resolved action metadata or resolves a supplied action key from an explicit `ActionRegistry`. It preserves drawer target metadata, fallback URL, disabled state, authorization state, history metadata, AI metadata, icon, label, and resource references. It never executes the action.

For the drafts and briefs additive adoptions, the pages consume only the existing `interactionResourcesByKey` and `interactionActionsByKey` maps. They do not resolve new production resources in Blade. Drafts does not expose analyze, improve, translate, governance, or republish actions; briefs does not expose generate, archive, enhance, compare, apply/reject suggestion, or create-draft actions.

## History Strategy

`DrawerHistoryAdapter` produces two URLs:

- `fallback_url`, the existing route or href users can navigate to today.
- `drawer_url`, the fallback URL with query parameters describing the drawer state.

History mutation is disabled by default (`push: false`, `replace: false`). A future JavaScript controller can opt into push or replace behavior per descriptor.

## URL Strategy

Drawer URLs use query parameters so they can be layered onto existing detail URLs without adding routes:

- `drawer`
- `drawer_mode`
- `drawer_resource`
- `drawer_resource_id`
- `drawer_action`

The current implementation does not require new routes and does not remove existing routes.

## Future JS Enhancement

A future controller can:

- Intercept enhanced drawer links.
- Fetch or hydrate drawer content.
- Open the Universal Right Drawer Engine.
- Push or replace browser history.
- Return focus to the trigger.
- Reuse descriptors in the command palette, context menus, and hover previews.

This document intentionally avoids defining that JavaScript implementation.

## Future Accessibility

The adoption layer keeps semantic fallbacks first. Future drawer JavaScript should add:

- Focus trapping for modal drawers.
- Escape handling from Drawer Engine metadata.
- `aria-expanded` updates on triggers.
- Live-region updates for loading and error states.
- Focus return to the originating trigger.

The current helpers provide `aria-haspopup="dialog"` and preserve native anchor behavior.

## Non-Goals

This layer does not replace links, remove detail pages, change controllers, execute actions, render production detail resources, or require JavaScript. Production adoptions must remain additive unless a later migration explicitly changes that boundary.

## Production Adoptions

`app/drafts/index` is the first additive production adoption. It adds a separate `Inspect` drawer trigger beside each unchanged draft title link. The full draft detail page at `app.drafts.show` remains the canonical destination and href fallback. The page renders only an inert empty drawer shell in the Application Shell detail drawer region; it does not render draft body or detail-page actions inside the drawer.

`app/briefs/index` is the second additive production adoption. It adds a separate `Inspect` drawer trigger beside each unchanged brief title link. The full brief detail page at `app.briefs.show` remains the canonical destination and href fallback. The page renders only an inert empty drawer shell in the Application Shell detail drawer region; it does not render brief body, workspace/detail content, or detail-page actions inside the drawer.
