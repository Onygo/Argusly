# Argusly Connectors

Argusly first-party connector packages live in `packages/` and are developed separately from the main Laravel application code.

## Packages

- `packages/wordpress-plugin`: WordPress plugin named **Argusly Connector**.
- `packages/laravel-connector`: Composer package `onygo/argusly-laravel-connector`.

## Canonical Contract

Authorization uses `Authorization: Bearer <token>` as the connector credential transport.

Canonical connector headers:

- `X-Argusly-Site`
- `X-Argusly-Destination-Id`
- `X-Argusly-Idempotency-Key`
- `X-Argusly-Timestamp`
- `X-Argusly-Nonce`
- `X-Argusly-Signature`
- `X-Argusly-Event`
- `X-Argusly-Event-Version`
- `X-Argusly-Event-ID`

## Platform Endpoints

- `POST /api/v1/connectors/heartbeat`
- `GET /api/v1/connectors/content`
- `GET /api/v1/connectors/content/{content}`
- `POST /api/v1/connectors/content/{content}/sync-results`

## API Ownership

The Argusly platform remains the source of truth for API keys, connector settings, site registration, content sync orchestration, health checks, and webhook delivery.

Connectors should act as thin clients. They store only the configuration needed to authenticate with Argusly and execute local platform instructions.

## Development Flow

Connector work should stay inside each package directory. The platform application may document or test connector behavior, but package code should not depend on package internals.

Develop against local path installs until the API contracts are stable. Keep package versions in development status and do not publish releases until the release checklist is approved.

## Planned Release Process

1. Stabilize the Argusly connector contract.
2. Replace placeholder connector commands and endpoint handlers with contract-backed implementations.
3. Add package-level tests and local installation QA for WordPress and Laravel.
4. Tag internal release candidates.
5. Publish only after product, security, and migration review.

## Migration Note

These packages replace the former connector packages and use Argusly naming throughout.
