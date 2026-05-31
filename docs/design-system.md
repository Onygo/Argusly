# Argusly Frontend Design System

The first Argusly interface is a premium, minimal SaaS system inspired by Instantly.ai, Attio, Linear, Stripe Dashboard and Vercel. It should feel executive-grade, data rich and calm.

## Principles

- White backgrounds with very light gray product surfaces.
- Near black text for primary content.
- Electric blue for action, progress and positive signal.
- Optional purple only for accents and CTA gradients.
- Thin borders instead of heavy shadows.
- Rounded cards, compact metrics and generous whitespace.
- Dense information should still scan quickly.
- Avoid CMS/admin-panel patterns and generic Bootstrap styling.

## Tokens

Defined in `resources/css/app.css`:

- `ink`: `#0b0f17`
- `muted`: `#667085`
- `line`: `#e7eaf0`
- `panel`: `#f8fafc`
- `blue`: `#235cff`
- `purple`: `#7657ff`

Typography uses Instrument Sans through the Laravel Vite font integration.

## Layout

Use `container-page` for marketing page width and `section-pad` for large public sections. Product surfaces should use constrained content widths inside the app shell, with tables and grids allowed to fill available workspace.

Marketing pages use:

- `x-marketing.layout`
- `x-marketing.header`
- `x-marketing.footer`

Product pages use:

- `x-app.layout`
- `x-app.sidebar`
- `x-app.topbar`

## Components

Current reusable primitives:

- `x-brand`
- `x-ui.button`
- `x-ui.badge`
- `x-ui.card`
- `x-ui.metric-card`
- `x-ui.feature-card`
- `x-ui.insight-card`
- `x-ui.agent-card`

Component defaults should stay visually quiet. Prefer subtle border, white or panel background, restrained type and explicit hierarchy.

## Buttons

Use pill buttons for primary marketing CTAs and compact product actions. Variants:

- `primary`: black fill, white text.
- `secondary`: white fill, thin border.
- `ghost`: text-only navigation action.
- `light`: white CTA used on gradient backgrounds.

## Cards

Cards use thin borders and rounded corners. Do not use heavy box shadows. Cards should frame repeated items, product previews, metric groups and dashboard panels.

## Metrics

Metrics should be compact:

- Small uppercase label.
- Large numeric value.
- Small change indicator.

Avoid decorative gauges until real data semantics exist.

## Marketing Homepage Structure

The first homepage includes:

1. Header
2. Hero
3. Product preview
4. Trusted by row
5. Platform cards
6. Intelligence feed
7. Agents
8. Competitive intelligence
9. Customer quotes
10. Final CTA
11. Footer

## App Shell Structure

The app shell includes:

- Left navigation.
- Top account and brand switcher placeholders.
- User menu placeholder.
- Static dashboard cards.

No real product features, integrations, billing, AI generation or OAuth are implemented yet.

## Future Guidance

When Vue or React is introduced later, keep Blade layouts as the route-level shell where possible and mount interactive product islands inside the app content area. Existing Blade components can then become design references for frontend components in the chosen framework.
