# Application Shell Migration

Argusly app and admin screens now share one application-shell contract. The migration is intentionally incremental: existing routes, policies, controllers, forms, tables, and business logic remain in place while every page rendered by `layouts.app` or `layouts.admin` is wrapped by the reusable shell framework.

## Shell Structure

The shared shell exposes these ordered regions:

1. Breadcrumb
2. Page Header
3. Description
4. Primary Actions
5. Filter Bar
6. KPI Section
7. Main Content
8. Detail Drawer
9. Footer Actions

Pages can fill regions with Blade sections such as `breadcrumb`, `pageHeader`, `primaryActions`, `filterBar`, `metricSection`, `detailDrawer`, and `footerActions`. Existing page content continues to render in `@section('content')`.

## Components

Reusable components added for the migration:

- `x-app-shell`
- `x-page-header`
- `x-page-description`
- `x-breadcrumb`
- `x-action-bar`
- `x-filter-bar`
- `x-metric-section`
- `x-metric-card`
- `x-section-header`
- `x-section-container`
- `x-empty-state`
- `x-loading-skeleton`
- `x-error-state`
- `x-drawer-container`

`x-filter-bar` was enhanced rather than duplicated, so existing filter pages keep working.

## Design Tokens

The token layer in `resources/css/app.css` now defines shared values for spacing, radius, typography rhythm, animation timing, transitions, shadows, and icon sizing. The shell components consume these tokens so app/admin screens use the same visual hierarchy without creating parallel layouts.

## Migration Summary

- App and admin layouts now render through `x-app-shell`.
- Existing pages inherit the universal region order without route changes.
- Existing content remains in place to preserve behavior.
- New components support incremental page-level refactors.
- Feature coverage verifies shell rendering and layout adoption.
