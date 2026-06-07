# Argusly Connectors

Argusly first-party connector packages live in `packages/` and are developed separately from the main Laravel application code.

## Packages

- `packages/wordpress-plugin`: WordPress plugin named **Argusly Connector**. It stores the Argusly API URL and API key, exposes a health-check action, and contains placeholders for content sync and webhook handling.
- `packages/laravel-connector`: Composer package `onygo/argusly-laravel-connector`. It provides config, a service provider, an API client, a health-check command, and placeholder content sync commands for Laravel sites.

## Development Flow

Connector work should stay inside each package directory. The platform application may document or test connector behavior, but package code should not depend on application classes, models, routes, or config files.

Develop against local path installs until the API contracts are stable. Keep package versions in development status and do not publish releases until the release checklist is approved.

## API Ownership

The Argusly platform remains the source of truth for:

- API keys
- connector settings
- site registration
- content sync orchestration
- health checks
- webhook delivery

Connectors should act as thin clients. They store only the configuration needed to authenticate with Argusly and execute local platform instructions.

## Planned Release Process

1. Finalize health-check, site-registration, content-sync, and webhook API contracts.
2. Replace placeholder connector commands and endpoint handlers with contract-backed implementations.
3. Add package-level tests and local installation QA for WordPress and Laravel.
4. Tag internal release candidates.
5. Publish only after product, security, and migration review.

## Migration Note

These packages replace the former PublishLayer WordPress plugin and Laravel connector. During migration, rename all package identifiers, namespaces, config keys, routes, environment variables, plugin labels, and user-facing copy to Argusly before reusing any old behavior.

Add `TODO(argusly)` markers where old PublishLayer-specific behavior requires API, product, or security review before it can be carried forward.
