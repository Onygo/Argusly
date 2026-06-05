# Markdown Delivery API

Argusly exposes canonical markdown artifacts to authenticated connectors through site-scoped API endpoints.

## Endpoints

- `GET /api/sites/{site}/content/{content}/markdown`
- `GET /api/sites/{site}/content/{content}/html`
- `GET /api/sites/{site}/markdown-index`

## Authentication

These endpoints use the existing integration auth stack:

- site tokens with `drafts:read` or `content:read`
- workspace API keys with `content:read` or `drafts:read`

Site tokens are restricted to their resolved `client_site_id`.
Workspace API keys are restricted to sites inside their workspace.

## Visibility rules

The delivery API never exposes:

- draft content
- archived content
- private or remote-private content
- ineligible markdown artifacts
- non-ready artifacts

Visibility is enforced through the existing `MarkdownEligibilityService` plus a ready-artifact requirement.

## Caching

Single-item endpoints return:

- `ETag` based on `markdown_checksum`
- `Last-Modified` based on `markdown_generated_at`
- `Vary: Authorization, X-Argusly-Site`

Clients can revalidate with `If-None-Match` and receive `304 Not Modified`.

The markdown index also returns `ETag` and `Last-Modified` based on the current page payload.

## Locale behavior

- By default, the API returns the artifact for the content's resolved markdown locale.
- Pass `?locale=nl` or another supported locale to request a specific stored artifact.
- If the requested locale does not have a ready artifact, the endpoint returns `404`.

## Index format

`GET /api/sites/{site}/markdown-index` returns:

- `items`: connector-friendly entries with `slug`, `locale`, `markdown_url`, `html_url`, and `updated_at`
- `meta`: pagination fields and the applied locale filter
