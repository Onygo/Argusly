# Universal Right Drawer Engine

## Purpose

The Universal Right Drawer Engine is an infrastructure layer for rendering inspect, preview, readonly, and edit panels in the Application Shell `detailDrawer` region. It gives future pages one reusable drawer contract instead of page-specific side panels.

## Non-goals

- It does not migrate existing detail pages.
- It does not replace routes, controllers, policies, forms, jobs, models, or business logic.
- It does not execute actions.
- It does not render production resources inside drawers yet.
- It does not require JavaScript in this phase.

## Architecture

The engine has two halves:

- PHP metadata contracts in `App\Support\Interaction`.
- Generic Blade components in `resources/views/components/drawer`.

Pages will eventually ask a `DrawerResolver` for resolved drawer metadata, then pass that metadata into `<x-drawer.drawer>`. For now, no production page is wired to the engine.

## Support Classes

- `Drawer`: fluent metadata contract for a stable drawer key, resource identity, mode, modal behavior, width, copy, tabs, sections, footer actions, states, focus behavior, escape behavior, deep-link metadata, history metadata, AI metadata, and resolved registry metadata.
- `DrawerContext`: carries page, route, workspace, organization, site, resource, action, permission, registry, and custom metadata into resolution.
- `DrawerRegistry`: registers drawers by stable key and rejects duplicates.
- `DrawerState`: value object for modes and state flags: open, loading, empty, error, interactive, and editable.
- `DrawerResolver`: resolves drawers from the drawer registry first, then resource drawer metadata where available, and attaches resource/action metadata without executing actions.

## Blade Components

- `drawer.blade.php`: outer accessible region/dialog and state switcher.
- `drawer-header.blade.php`: title, subtitle, description, close slot, escape/focus metadata.
- `drawer-tabs.blade.php`: generic tab metadata renderer.
- `drawer-section.blade.php`: generic section and definition-list renderer.
- `drawer-footer.blade.php`: footer slot or inert resolved action buttons.
- `drawer-loading.blade.php`: accessible loading state.
- `drawer-empty.blade.php`: accessible empty state.
- `drawer-error.blade.php`: accessible error state.

Components accept props and slots only. They do not query models, call controllers, or call business services.

## Drawer Lifecycle

1. A future opener, such as a command palette entry or row action, selects a drawer key and context.
2. `DrawerResolver` resolves registered drawer metadata.
3. The resolver attaches resource metadata from `ResourceRegistry` when a resource key is present.
4. The resolver attaches action metadata from `ActionRegistry` for declared action keys and footer actions.
5. The Application Shell receives the resolved metadata in its `detailDrawer` region.
6. Blade renders loading, empty, error, or interactive content based on `DrawerState`.

## Drawer State Model

`DrawerState` supports:

- `inspect`: canonical read-focused inspection.
- `preview`: lightweight transient preview.
- `readonly`: full read-only detail without mutation affordances.
- `edit`: future form-hosting mode.

State flags are separate from modes, so an edit drawer can still be loading or erroring safely.

## Resource Integration

`DrawerContext` can carry a `ResourceRegistry`, resource type, resource key, resource id, and pre-resolved resource metadata. `DrawerResolver` may resolve a matching resource and attach it under `resolved_resource`. Resource metadata can also define a future drawer target, letting the resolver build safe drawer metadata from the resource registry when no drawer is registered yet.

## Action Integration

`DrawerContext` can carry an `ActionRegistry`, action keys, and pre-resolved action metadata. `DrawerResolver` resolves action metadata under `resolved_actions`. It only reads metadata through the action contract; it does not submit forms, dispatch jobs, call controller methods, or perform mutations.

## Application Shell Integration

The engine is compatible with the existing shell:

```blade
@section('detailDrawer')
    <x-drawer.drawer :drawer="$resolvedDrawer" />
@endsection
```

This documentation is illustrative only. No production page is wired during this phase.

## Focus Behavior

Drawers carry a `focus_return_target` value. Components expose it through `data-focus-return-target` so future JavaScript can restore focus to the opener after close.

## Escape Behavior

Drawers carry `keyboard_escape` metadata:

- `enabled`: whether Escape is allowed.
- `closes_drawer`: whether Escape closes this drawer.
- `strategy`: future client behavior hint.

The Blade layer only renders metadata. It does not add JavaScript behavior yet.

## Deep-link Strategy

Drawers carry `deep_link` metadata for future URL strategies, such as query parameters, hash fragments, or route-backed drawer state. The current phase stores and renders metadata only.

## History Strategy

Drawers carry `history` metadata for future browser history integration. The resolver keeps this metadata near the drawer contract so future shell behavior can decide whether opening or closing a drawer should push, replace, or ignore browser history.

## Future Inspect Mode

Inspect mode should become the default for resource details opened from tables, command palette results, and recommendation panels.

## Future Preview Mode

Preview mode should support hover previews and lightweight command palette previews with reduced width and minimal footer actions.

## Future Readonly Mode

Readonly mode should support full detail views where the user has view permission but no edit permission.

## Future Edit Mode

Edit mode should host form metadata and validation surfaces later. This phase intentionally does not move existing edit forms into drawers.

## Future AI Explanation Drawer

AI metadata can describe model traces, confidence, recommended action provenance, and explanation sections. A future AI drawer can consume the same tabs, sections, and footer action contract.

## First Safe Future Adoption Candidates

- Non-mutating row inspection for low-risk admin tables.
- Readonly recommendation detail panels.
- Hover previews that render synthetic metadata only.
- Command palette previews that do not replace canonical detail pages.

## Deferred Drawer Migrations

Existing content, draft, site, research, SEO audit, opportunity, billing, settings, and admin detail pages remain routed pages until a separate migration explicitly validates parity, permissions, routing, browser history, accessibility behavior, and rollback paths.
