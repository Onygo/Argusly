# Argusly Design Tokens

Argusly uses a strict, quiet SaaS design system inspired by Attio, Linear, Moss, Stripe Dashboard and Vercel. The product should feel calm, expensive and operationally confident.

## Core Tokens

Defined in `resources/css/app.css`:

- `ink`: `#0b0f17` for primary text and rare dark surfaces.
- `muted`: `#667085` for secondary text.
- `line`: `#e7eaf0` for borders.
- `panel`: `#f8fafc` for subtle workspace surfaces.
- `blue`: `#235cff` for actions, active states and focus.
- `purple`: legacy accent only; do not use for new product UI.

## Radius

- Default radius: `rounded-md`.
- Use `rounded-md` for cards, panels, modals, inputs, selects, dropdowns, filters, tables, sidebars, widgets, workspace headers, action bars, tabs and navigation groups.
- Do not use `rounded`, `rounded-lg`, `rounded-xl`, `rounded-2xl` or `rounded-3xl` without documenting a specific exception.
- Use `rounded-full` only for avatars, user profile images, notification indicators, status dots, pills, badges, chips and counters.

## Borders And Elevation

- Default border: `border border-line`.
- Product surfaces should prefer borders over shadows.
- Default shadow: none.
- Use subtle elevation only when an element is visually floating above another surface, such as a popover menu.
- Do not stack random `shadow-sm`, `shadow`, `shadow-md` or `shadow-lg` utilities across screens.

## Spacing

- Compact surfaces: `p-4`.
- Standard cards and repeated items: `p-5`.
- Workspace panels and dashboard sections: `p-6`.
- Page shell spacing: `p-4 sm:p-6 lg:p-8`.
- Avoid arbitrary padding values unless a component has a fixed ergonomic need.

## Typography

- H1: `text-2xl font-semibold tracking-tight text-ink`.
- H2: `text-base font-semibold text-ink`.
- H3: `text-sm font-semibold text-ink`.
- Body: `text-sm leading-6 text-muted` or `text-sm text-ink`.
- Caption: `text-xs text-muted`.
- Labels: `text-xs font-semibold uppercase tracking-[0.1em] text-muted`.
- Avoid oversized headings inside dense workspace panels.

## Buttons

- Primary: filled blue, `x-ui.button` default variant.
- Secondary: outline, `variant="secondary"`.
- Tertiary: ghost, `variant="ghost"`.
- Do not invent module-specific button styles.
- Use icons from the Argusly Lucide-style icon primitive for icon-only controls.

## Color Semantics

- Blue is reserved for primary actions, active states and focus.
- Neutral grays carry most interface structure.
- Success, warning and error colors are allowed only for explicit status, validation and alert states.
- Badges should remain low-saturation and readable.
- Avoid random decorative color per module.

## Components Updated In This Pass

- App shell max width and workspace spacing.
- Sidebar navigation grouping, active state, hover state and desktop behavior.
- Workspace header hierarchy and action structure.
- Topbar notification control and mobile nav trigger.
- Shared button primitive.
- Shared card primitive.
- Shared search input.
- App-facing radius and shadow utilities across Blade views.

## Manual Review Still Needed

- Page-specific forms that still hand-code alerts and badges.
- Dense content detail pages with many inline action rows.
- Marketing and welcome pages, which were not part of the workspace app polish pass.
- Any future modal/dropdown component once richer interactive components are introduced.
