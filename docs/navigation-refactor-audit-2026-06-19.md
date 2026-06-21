# Argusly Navigation Refactor Audit

Date: 2026-06-19

## Navigation Migration Plan

- Remove `Overzicht / Overview` from top-level navigation.
- Make `Platform` the first top-level dropdown and the primary explanation path for Argusly.
- Keep `Oplossingen / Solutions` as problem-led paths.
- Rename public `Markets` navigation to `Sectoren / Industries`.
- Move `Blog` under `Resources`.
- Keep the header CTA short as `Contact` and point it to the contact form.
- Keep `Automotive` in the industries dropdown after the six core B2B sectors.

## Redirect Mapping

- `/markets/{industry}` -> `/en/industries/{industry}`
- `/en/markets/{industry}` -> `/en/industries/{industry}`
- `/markten/{sector}` -> `/nl/sectoren/{sector}`
- `/nl/markten/{sector}` -> `/nl/sectoren/{sector}`
- Existing product legacy redirects remain active:
  - `/product/overview` -> localized product overview route
  - `/product/capabilities` -> localized platform page `#capabilities`
  - `/product/governance` -> localized platform page `#governance`
  - `/product/intelligence` -> localized platform page `#intelligence`

## SEO Impact Assessment

- Positive: industry URLs now match the public IA labels more clearly.
- Positive: `Platform` becomes the canonical education path instead of competing with `Overview`.
- Controlled risk: sector URL changes require 301 redirects, now implemented for old `markets/markten` paths.
- Controlled risk avoided: `Guides`, `Research/Onderzoeken`, and `Whitepapers` are not shown until dedicated destinations exist.
- Recommended follow-up: create dedicated resource landing pages once content volume justifies them, then reactivate those links.

## Dutch Labels Review

- Top-level: `Platform`, `Oplossingen`, `Sectoren`, `Resources`, `Prijzen`.
- Utility: `Inloggen`.
- CTA: `Contact`.
- Solution labels are problem-led: `Kansen Ontdekken`, `AI Zichtbaarheid Vergroten`, `Concurrentie Inzicht`, `Marketing Autonoom Organiseren`.
- Industry labels include the required six sectors and retain `Automotive`.

## English Labels Review

- Top-level: `Platform`, `Solutions`, `Industries`, `Resources`, `Pricing`.
- Utility: `Login`.
- CTA: `Contact`.
- Solution labels are problem-led: `Discover Opportunities`, `Increase AI Visibility`, `Competitive Intelligence`, `Organize Marketing Autonomously`.
- Industry labels include the required six sectors and retain `Automotive`.

## Conversion Impact Assessment

- The primary header CTA stays short and points directly to the contact form.
- First-time visitors get clearer paths by mental model: platform, business problem, industry fit, resources, pricing.
- Blog is still discoverable but no longer competes with product understanding at top level.
- Resources is intentionally limited to `Blog` and `AI Search & GEO` until Guides, Research/Onderzoeken, and Whitepapers exist as real destinations.
- Platform dropdown reinforces Argusly as one integrated platform across AI Visibility, Opportunity Intelligence, and Agentic Marketing.

## Recommended Implementation Order

1. Ship navigation source-of-truth changes.
2. Ship labels and localized route segment changes.
3. Verify route generation and legacy redirects.
4. Backfill dedicated resource pages for Guides, Research/Onderzoeken, and Whitepapers.
5. Review sitemap and Search Console after deploy for old `markets/markten` URL migration.
