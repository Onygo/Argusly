# Public Context Boundaries

PublishLayer runs public marketing pages, public blog delivery, the authenticated app, and the admin area in one Laravel application.

The public request path must stay deterministic for guests, crawlers, queue-driven audits, and HTTP clients.

## Public context

- Public routes live in `routes/marketing.php`.
- Public middleware uses request-derived inputs only.
- Public site context is resolved from the incoming host and request scheme.
- Public locale resolution may use:
  - `?lang=`
  - `pl_locale` cookie
  - `Accept-Language`
- Public rendering must not depend on:
  - `auth()`
  - impersonation session keys
  - selected workspace in the UI
  - admin support context

## App context

- App routes keep the authenticated `web` stack.
- App locale may use session and user preference.
- Workspace selection and impersonation remain app concerns only.

## Admin context

- Admin routes keep admin-only authorization and impersonation restoration logic.
- Admin middleware must not bleed into public rendering.

## Tenant and site resolving strategy

- Public requests resolve site context from host/domain first.
- Current resolution order:
  - configured marketing base domain
  - verified `workspace_domains`
  - active `client_sites` with matching `site_url`, `base_url`, or `allowed_domains`
- No public resolver path is allowed to fall back to session, current user, or impersonated workspace state.

## SEO audit crawler expectation

- Crawls must hit fully public URLs over HTTP only.
- Redirects to `/login` or `/verify-code` are treated as crawl failures.
- Failed runs should expose the technical cause in diagnostics and the audit UI.
