# Content Asset Architecture

Date: 2026-07-10

## Status

Accepted for Website Content Inventory Phase 1.

## Context

Argusly observes public website pages through Page Intelligence, tracking, sitemap discovery, and connector-enriched marketing data. Those observed pages are evidence: they describe that a URL exists, when it was seen, what was fetched, and what metadata was extracted.

Campaigns, social planning, newsletters, PR workflows, and future distribution channels need a different contract. They need a durable activatable marketing asset with ownership, review state, campaign eligibility, publication metadata, analytics keys, and lifecycle state.

## Decision

Argusly uses a two-layer content asset model:

- `MonitoredPage` is the canonical observed page. It stores URL identity, crawl/fetch status, snapshots, extracted metadata, Page Intelligence signals, and other evidence from the public web.
- `Content` is the canonical activatable marketing asset. It is the record campaigns, social variants, newsletter exports, PR workflows, and future distribution channels reference.
- `ContentPageLink` bridges observed evidence to activatable assets without introducing a parallel website content table.

The activation lifecycle is:

Observed

↓

Reviewed

↓

Campaign Ready

↓

Activated

↓

Measured

`MonitoredPage` owns the Observed evidence. `Content` owns review, campaign readiness, activation, and measured marketing asset state. `ContentPageLink` records how a `Content` asset relates to one or more observed pages, such as `observed_source`, `publication_url`, `activation_target`, or `canonical_equivalent`.

## Channel Contract

Campaigns, Social, Email, PR, and future distribution channels always reference `Content` instead of `MonitoredPage` because:

- `Content` carries workspace/site ownership, lifecycle state, publication URLs, SEO metadata, campaign eligibility, and activation state.
- `MonitoredPage` can represent competitor pages, citations, search results, observed third-party pages, failed fetches, excluded paths, or evidence that should never become a marketing asset.
- Existing campaign, social, newsletter export, analytics, connector, and attribution systems already resolve around `Content` and campaign content models.
- Linking through `ContentPageLink` preserves traceability back to Page Intelligence without coupling distribution workflows to crawler evidence.

## Consequences

Phase 1 adds infrastructure only:

- Add inventory fields to `Content`.
- Add `content_page_links`.
- Add eligibility and activation services.
- Add diagnostics and backfill support.

This architecture intentionally does not add `website_contents`, `website_pages`, `inventory_pages`, duplicate crawlers, duplicate tracking, duplicate analytics, or duplicate distribution systems.
