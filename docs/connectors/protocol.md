# Argusly Connector Protocol

This document defines the generic Argusly-side connector protocol for external systems such as WordPress, Laravel, custom APIs, webhooks, headless CMSs, Shopify, Webflow, and Ghost.

External connector code must live outside the Argusly app repository. Argusly manages connector registration, tokens, capabilities, queues, publishing state, events, logs, and tenant-safe API access.

## Base URL

All connector endpoints are under:

```text
/api/v1
```

## Authentication

Connectors authenticate with a connector token:

```http
Authorization: Bearer argusly_ct_...
Accept: application/json
Content-Type: application/json
```

Argusly stores only the token hash. Plaintext tokens are shown once during token creation or rotation.

Connector tokens are scoped to:

- Account
- Brand
- Connector installation
- Channel through the connector installation

Tokens may expire or be revoked.

## Abilities

Supported token abilities:

- `connector:read`
- `connector:write`
- `content:read`
- `content:publish`
- `events:write`
- `health:write`

Endpoints reject requests without the required ability.

## Errors

Errors return JSON:

```json
{
  "error": {
    "code": "connector_token_forbidden",
    "message": "Connector token is missing the [content:read] ability."
  }
}
```

Common status codes:

- `401`: missing or invalid token.
- `403`: revoked, expired, inactive, or insufficient ability.
- `404`: requested content or action is outside connector scope.
- `409`: token or installation cannot resolve account, brand, and channel.
- `422`: validation failed.
- `429`: rate limited.

## Rate Limiting

Connector API requests are rate-limited. Clients should handle `429` responses with backoff and retry later.

## Connector Manifest

```http
GET /api/v1/connector/manifest
```

Required ability: `connector:read`

Response:

```json
{
  "data": {
    "connector": {
      "id": "connector-installation-uuid",
      "status": "active",
      "account_id": 1,
      "brand_id": 2,
      "channel_id": 3
    },
    "manifest": {
      "key": "laravel",
      "type": "laravel",
      "name": "Laravel",
      "description": "Argusly-side registration for future Laravel app connector installations.",
      "version": "0.1.0",
      "api_base_path": "/api/v1"
    }
  }
}
```

## Connector Registration

```http
POST /api/v1/connector/register
```

Required ability: `connector:write`

Payload:

```json
{
  "endpoint_url": "https://example.com",
  "external_connector_id": "production-site",
  "connector_version": "1.0.0",
  "capabilities": ["health_check", "publish_content", "webhooks"],
  "metadata": {
    "runtime": "php",
    "framework": "laravel"
  }
}
```

Argusly stores registration metadata on the connector installation.

## Health Reporting

```http
POST /api/v1/connector/health
```

Required ability: `health:write`

Payload:

```json
{
  "status": "ok",
  "message": "Connector is healthy.",
  "metrics": {
    "queue_depth": 0
  },
  "checked_at": "2026-05-29T10:00:00Z"
}
```

Supported statuses:

- `ok`
- `degraded`
- `failed`

## Capabilities

```http
GET /api/v1/connector/capabilities
```

Required ability: `connector:read`

Response:

```json
{
  "data": {
    "connector": {},
    "capabilities": ["publish_content", "preview_url"],
    "available_capabilities": ["receive_content", "publish_content", "health_check"]
  }
}
```

Generic capability names:

- `receive_content`
- `publish_content`
- `update_content`
- `delete_content`
- `sync_content`
- `sync_taxonomies`
- `sync_authors`
- `health_check`
- `webhooks`
- `media_upload`
- `preview_url`

## Pending Content

```http
GET /api/v1/content/pending?limit=25
```

Required ability: `content:read`

Returns queued or processing publishing actions for the connector installation channel.

Response item format:

```json
{
  "publishing_action": {
    "id": 123,
    "uuid": "action-uuid",
    "action": "publish",
    "status": "queued",
    "language": "en",
    "locale": "en_US",
    "market": null,
    "scheduled_at": null,
    "published_at": null,
    "external_id": null,
    "external_url": null
  },
  "content": {
    "id": 456,
    "uuid": "content-uuid",
    "type": "article",
    "status": "approved",
    "title": "Example article",
    "slug": "example-article",
    "language": "en",
    "locale": "en_US",
    "market": null,
    "canonical_url": "https://example.com/original",
    "hreflang": [
      {
        "content_id": 456,
        "content_uuid": "content-uuid",
        "language": "en",
        "locale": "en_US",
        "url": "https://example.com/original"
      }
    ],
    "translated_from": null,
    "translation_group_id": null,
    "excerpt": "Short summary.",
    "body": "Full content body.",
    "metadata": {},
    "seo_metadata": {},
    "answer_blocks": [
      {
        "id": 1,
        "uuid": "answer-block-uuid",
        "type": "faq",
        "status": "approved",
        "question": "What is this?",
        "answer": "An answer block.",
        "language": "en",
        "position": 1,
        "metadata": {}
      }
    ],
    "published_at": null
  }
}
```

`language` is the content language. `locale` is the publishing locale/context for the content item. Connectors must not treat either field as the Argusly UI locale.

## Publishing Actions

The protocol is designed for:

- `publish`
- `update`
- `unpublish`
- `schedule`

Connectors should treat unknown actions as unsupported and report failure.

## Report Published

```http
POST /api/v1/content/{id}/published
```

Required ability: `content:publish`

`{id}` may be the Argusly content ID or UUID.

Payload:

```json
{
  "external_id": "post_123",
  "external_url": "https://example.com/blog/example-article",
  "language": "en",
  "locale": "en_US",
  "external_locale": "en_US",
  "external_translation_group": "wpml-group-123",
  "external_canonical_url": "https://example.com/blog/example-article",
  "published_at": "2026-05-29T10:00:00Z",
  "response": {
    "remote_status": "published"
  }
}
```

Argusly updates:

- `publishing_actions.status = completed`
- `publishing_actions.external_id`
- `publishing_actions.external_url`
- `content_assets.status = published`
- `content_assets.published_at`
- `content_assets.canonical_url` from `external_canonical_url` when present, otherwise `external_url`
- Domain events
- Intelligence signals

## Report Failed

```http
POST /api/v1/content/{id}/failed
```

Required ability: `content:publish`

Payload:

```json
{
  "message": "Remote validation failed.",
  "language": "en",
  "locale": "en_US",
  "external_locale": "en_US",
  "external_translation_group": "wpml-group-123",
  "external_canonical_url": "https://example.com/blog/example-article",
  "response": {
    "code": "invalid_payload"
  }
}
```

Argusly updates:

- `publishing_actions.status = failed`
- `publishing_actions.error_message`
- `content_assets.status = failed`
- Domain events
- Intelligence signals

## Connector Events

```http
POST /api/v1/connector/events
```

Required ability: `events:write`

Supported event types:

- `content.created`
- `content.updated`
- `content.deleted`
- `content.published`
- `content.failed`
- `health.ok`
- `health.warning`
- `health.failed`
- `taxonomy.synced`
- `author.synced`
- `media.uploaded`

Payload:

```json
{
  "type": "health.warning",
  "status": "warning",
  "message": "Queue latency is above threshold.",
  "payload": {
    "language": "en",
    "locale": "en_US",
    "external_locale": "en_US",
    "external_translation_group": "wpml-group-123",
    "external_canonical_url": "https://example.com/blog/example-article",
    "latency_ms": 2500
  },
  "idempotency_key": "health-warning-2026-05-29T10:00",
  "occurred_at": "2026-05-29T10:00:00Z"
}
```

Argusly stores connector events as `ConnectorEventReceived` domain events. Warning and failure events may also create intelligence signals.

## Event Payload Validation

Minimum payload rules:

- Content events require `payload.content_id`, `payload.content_uuid`, or `payload.external_id`.
- Content event payloads may include `language`, `locale`, `external_locale`, `external_translation_group`, and `external_canonical_url`.
- When present on content events, `language`, `locale`, `external_locale`, and `external_translation_group` must be strings.
- When present on content events, `external_canonical_url` must be a valid URL.
- `content.failed` requires `message`, `payload.error`, or `payload.error_message`.
- `health.warning` and `health.failed` require `message` or `payload.message`.
- `taxonomy.synced` requires `payload.taxonomy`.
- `author.synced` requires `payload.author_id`, `payload.email`, or `payload.name`.
- `media.uploaded` requires `payload.url`.

## Idempotency

Connector event intake supports `idempotency_key`.

For `POST /connector/events`, Argusly deduplicates by:

- Account
- Connector installation
- Event type `ConnectorEventReceived`
- Payload idempotency key

Connectors should use stable keys for retryable events, for example:

```text
content-published:{remote-id}:{timestamp-or-version}
health-warning:{minute-bucket}
taxonomy-synced:{taxonomy}:{cursor}
```

## Logging

Argusly records:

- Connector API request logs.
- Connector event logs.
- Token lifecycle logs.
- Domain events.
- Optional activity logs.
- Intelligence signals for warning and error conditions.

## Security

- Use HTTPS.
- Use least-privilege token abilities.
- Rotate tokens regularly.
- Revoke unused tokens.
- Store only token hashes in Argusly.
- Never log plaintext tokens.
- Validate payloads before applying remote changes.
- Treat connector event payloads as untrusted input.
- Do not expose unpublished content publicly until the connector reports success.

## Protocol Versioning

The protocol should evolve through versioned connector manifests and package versions.

Compatible changes:

- Adding optional fields.
- Adding optional event types.
- Adding optional capabilities.

Breaking changes:

- Removing fields.
- Renaming fields.
- Changing required auth behavior.
- Changing content payload shape.

Future breaking changes should use a new API path, such as `/api/v2`.
