# Argusly Laravel Connector Package

Package name: `argusly/laravel-connector`

This document specifies the separate Laravel package that will connect a customer Laravel application to Argusly. Do not implement this package inside the Argusly app repository. The package is an external connector client for the Argusly Connector Protocol.

## Goals

- Register a Laravel application as an Argusly connector installation.
- Authenticate to Argusly using connector tokens.
- Pull pending publishing actions from Argusly.
- Publish, update, unpublish, or schedule content inside the customer Laravel app.
- Report success, failure, health, and operational events back to Argusly.
- Use Laravel queues for remote work without blocking HTTP requests.

## Installation

Install the package in the external Laravel application:

```bash
composer require argusly/laravel-connector
```

Publish the package config:

```bash
php artisan vendor:publish --tag=argusly-connector-config
```

Run package migrations only if the package stores local state such as remote IDs, sync cursors, or event receipts:

```bash
php artisan migrate
```

Register scheduled tasks if polling is enabled:

```php
// routes/console.php or bootstrap/app.php scheduler setup
Schedule::command('argusly:connector:pull')->everyMinute();
Schedule::command('argusly:connector:health')->everyFiveMinutes();
```

## Config File

Expected config file: `config/argusly-connector.php`

```php
<?php

return [
    'enabled' => env('ARGUSLY_CONNECTOR_ENABLED', true),

    'api' => [
        'base_url' => env('ARGUSLY_API_URL', 'https://api.argusly.com/v1'),
        'token' => env('ARGUSLY_CONNECTOR_TOKEN'),
        'timeout' => env('ARGUSLY_CONNECTOR_TIMEOUT', 10),
        'retry_times' => env('ARGUSLY_CONNECTOR_RETRY_TIMES', 3),
        'retry_sleep_ms' => env('ARGUSLY_CONNECTOR_RETRY_SLEEP_MS', 500),
    ],

    'connector' => [
        'name' => env('ARGUSLY_CONNECTOR_NAME', config('app.name')),
        'version' => env('ARGUSLY_CONNECTOR_VERSION'),
        'endpoint_url' => env('ARGUSLY_CONNECTOR_ENDPOINT_URL', config('app.url')),
        'capabilities' => [
            'health_check',
            'receive_content',
            'publish_content',
            'update_content',
            'delete_content',
            'webhooks',
            'preview_url',
            'media_upload',
        ],
    ],

    'publishing' => [
        'model' => App\Models\Post::class,
        'title_column' => 'title',
        'body_column' => 'body',
        'excerpt_column' => 'excerpt',
        'slug_column' => 'slug',
        'status_column' => 'status',
        'published_at_column' => 'published_at',
        'language_column' => 'language',
        'locale_column' => 'locale',
        'market_column' => 'market',
        'canonical_url_column' => 'canonical_url',
        'seo_metadata_column' => 'seo_metadata',
        'translation_group_column' => 'translation_group_id',
        'translated_from_column' => 'translated_from_id',
        'default_status' => 'draft',
        'published_status' => 'published',
        'failed_status' => 'failed',
    ],

    'queue' => [
        'connection' => env('ARGUSLY_CONNECTOR_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        'queue' => env('ARGUSLY_CONNECTOR_QUEUE', 'argusly'),
    ],

    'routes' => [
        'enabled' => env('ARGUSLY_CONNECTOR_ROUTES_ENABLED', true),
        'prefix' => env('ARGUSLY_CONNECTOR_ROUTE_PREFIX', 'argusly'),
        'middleware' => ['web'],
    ],

    'webhooks' => [
        'signing_secret' => env('ARGUSLY_CONNECTOR_WEBHOOK_SECRET'),
    ],
];
```

## Environment Variables

```dotenv
ARGUSLY_CONNECTOR_ENABLED=true
ARGUSLY_API_URL=https://api.argusly.com/v1
ARGUSLY_CONNECTOR_TOKEN=argusly_ct_...
ARGUSLY_CONNECTOR_NAME="Production Laravel App"
ARGUSLY_CONNECTOR_VERSION=1.0.0
ARGUSLY_CONNECTOR_ENDPOINT_URL=https://example.com
ARGUSLY_CONNECTOR_TIMEOUT=10
ARGUSLY_CONNECTOR_RETRY_TIMES=3
ARGUSLY_CONNECTOR_RETRY_SLEEP_MS=500
ARGUSLY_CONNECTOR_QUEUE_CONNECTION=database
ARGUSLY_CONNECTOR_QUEUE=argusly
ARGUSLY_CONNECTOR_ROUTES_ENABLED=true
ARGUSLY_CONNECTOR_ROUTE_PREFIX=argusly
ARGUSLY_CONNECTOR_WEBHOOK_SECRET=
```

## Authentication Token Setup

In Argusly:

1. Open Settings > Connectors.
2. Register or select the Laravel connector installation.
3. Create a connector token for that installation.
4. Grant only the abilities needed by the package:
   - `connector:read`
   - `connector:write`
   - `content:read`
   - `content:publish`
   - `events:write`
   - `health:write`
5. Copy the token immediately. Argusly shows the plaintext token only once.
6. Store the token in the external Laravel app as `ARGUSLY_CONNECTOR_TOKEN`.

All requests to Argusly must send:

```http
Authorization: Bearer argusly_ct_...
Accept: application/json
Content-Type: application/json
```

## Registration Flow

On install or first boot, the package may register runtime metadata with Argusly:

```http
POST /api/v1/connector/register
```

Payload:

```json
{
  "endpoint_url": "https://example.com",
  "external_connector_id": "laravel-app-production",
  "connector_version": "1.0.0",
  "capabilities": [
    "health_check",
    "receive_content",
    "publish_content",
    "update_content",
    "delete_content",
    "webhooks",
    "preview_url",
    "media_upload"
  ],
  "metadata": {
    "laravel_version": "13.x",
    "php_version": "8.4"
  }
}
```

## Health Check Endpoint

The package should expose a local health route for operators:

```http
GET /argusly/health
```

Suggested response:

```json
{
  "status": "ok",
  "connector": "argusly/laravel-connector",
  "version": "1.0.0",
  "queue": "ok",
  "last_pull_at": "2026-05-29T10:00:00Z"
}
```

The package should also report health to Argusly:

```http
POST /api/v1/connector/health
```

Payload:

```json
{
  "status": "ok",
  "message": "Connector is healthy.",
  "metrics": {
    "queue_depth": 0,
    "last_pull_seconds_ago": 45
  }
}
```

Use `status: "degraded"` for warnings and `status: "failed"` for outages.

## Pull Pending Content Flow

The package pulls work from Argusly:

```http
GET /api/v1/content/pending?limit=25
```

Expected response:

```json
{
  "data": [
    {
      "publishing_action": {
        "id": 123,
        "uuid": "7e67e0f5-...",
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
        "uuid": "b132f95f-...",
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
            "content_uuid": "b132f95f-...",
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
        "seo_metadata": {
          "title": "SEO title",
          "description": "SEO description"
        },
        "answer_blocks": [
          {
            "id": 1,
            "uuid": "4b3181d0-...",
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
  ]
}
```

The package should enqueue a local job per item instead of publishing inside the polling command.

## Publish Content Flow

For `action: "publish"`, the package should:

1. Map Argusly content fields to the configured Laravel model.
2. Create or update the local model using a stored remote mapping.
3. Store title, body, excerpt, slug, content language, locale, market, canonical URL, SEO metadata, answer blocks, and translation context as configured.
4. Publish immediately unless the action is scheduled.
5. Generate the final public URL.
6. Report success or failure to Argusly.

`language` is the content language. `locale` is the publishing locale/context. The package should preserve `hreflang`, `translated_from`, and `translation_group_id` for multilingual routing or SEO integrations even when the host app does not use them immediately.

## Future Action Support

The package should design for these actions even if v1 only enables publish:

- `publish`: create or publish a local record.
- `update`: update an existing local record and keep it published.
- `unpublish`: archive, hide, or soft-delete the local record.
- `schedule`: create or update a local record with a future publish date.

## Report Success Flow

```http
POST /api/v1/content/{id}/published
```

Payload:

```json
{
  "external_id": "post_123",
  "external_url": "https://example.com/blog/example-article",
  "language": "en",
  "locale": "en_US",
  "external_locale": "en_US",
  "external_translation_group": "laravel-group-123",
  "external_canonical_url": "https://example.com/blog/example-article",
  "published_at": "2026-05-29T10:00:00Z",
  "response": {
    "model": "App\\Models\\Post",
    "local_id": 123
  }
}
```

Argusly will update the publishing action, update the content asset, record a domain event, and create intelligence signals.

## Report Failure Flow

```http
POST /api/v1/content/{id}/failed
```

Payload:

```json
{
  "message": "Validation failed: slug already exists.",
  "language": "en",
  "locale": "en_US",
  "external_locale": "en_US",
  "external_translation_group": "laravel-group-123",
  "external_canonical_url": "https://example.com/blog/example-article",
  "response": {
    "code": "slug_conflict"
  }
}
```

Argusly will mark the publishing action and content asset as failed, record a domain event, and create an intelligence signal.

## Webhook And Event Flow

The package reports connector-side events to Argusly:

```http
POST /api/v1/connector/events
```

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

Example:

```json
{
  "type": "health.warning",
  "message": "Queue latency is above threshold.",
  "payload": {
    "language": "en",
    "locale": "en_US",
    "external_locale": "en_US",
    "external_translation_group": "laravel-group-123",
    "external_canonical_url": "https://example.com/blog/example-article",
    "queue": "argusly",
    "latency_ms": 2500
  },
  "idempotency_key": "health-warning-2026-05-29T10:00",
  "occurred_at": "2026-05-29T10:00:00Z"
}
```

The package should use stable idempotency keys for retryable events.

## Queue Usage

Recommended package jobs:

- `PullPendingContentFromArgusly`
- `PublishArguslyContent`
- `ReportArguslyPublishSuccess`
- `ReportArguslyPublishFailure`
- `ReportArguslyHealth`
- `SendArguslyConnectorEvent`

Recommended behavior:

- Polling command only fetches pending work and dispatches jobs.
- Publishing jobs are idempotent by publishing action ID.
- Reporting jobs retry with exponential backoff.
- Failed local jobs report `content.failed` or health warnings to Argusly when possible.

## Security Considerations

- Never commit `ARGUSLY_CONNECTOR_TOKEN`.
- Treat connector tokens like production credentials.
- Store only Argusly remote IDs and hashes locally where possible.
- Redact tokens from logs, exceptions, debug pages, and failed job payloads.
- Use HTTPS for all Argusly API calls.
- Rotate tokens from Argusly Settings > Connectors after suspected exposure.
- Use least-privilege token abilities.
- Validate webhook signatures if Argusly later sends inbound webhooks to the package.
- Make local `/argusly/*` routes opt-in or protected.
- Avoid rendering unpublished Argusly content publicly before the publish action is complete.

## Versioning Strategy

Package versioning should follow semantic versioning:

- Patch: bug fixes and internal improvements.
- Minor: new optional capabilities or config keys.
- Major: breaking protocol, config, or model mapping changes.

The package should report its version during registration. Argusly connector manifests and versions should track compatible protocol versions. Future package releases should declare:

- Supported Argusly Connector Protocol version.
- Supported Laravel versions.
- Supported PHP versions.
- Migration requirements.

## Local Development Strategy

Recommended setup:

1. Run Argusly locally at `http://argusly.test` or `http://localhost:8000`.
2. Run a separate Laravel test app with the package installed by path repository.
3. Create a connector installation in Argusly Settings > Connectors.
4. Create a connector token and place it in the external app `.env`.
5. Configure `ARGUSLY_API_URL=http://api.argusly.test/v1`.
6. Use queue workers in both apps where needed.
7. Use `php artisan argusly:connector:health` to verify auth.
8. Create an approved content asset in Argusly and publish to the Laravel channel.
9. Run `php artisan argusly:connector:pull`.
10. Confirm the local app creates content and reports back to Argusly.

For package development, use a path repository in the external Laravel app:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../laravel-connector"
    }
  ]
}
```

Then install:

```bash
composer require argusly/laravel-connector:@dev
```
