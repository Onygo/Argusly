# Argusly Laravel Connector

Development-only first-party Laravel connector for Argusly. Do not publish or release this package yet.

## Installation

This package is not published yet. During local monorepo development, require it from a path repository in a consuming Laravel app.

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/laravel-connector"
        }
    ]
}
```

Then install:

```bash
composer require onygo/argusly-laravel-connector
php artisan vendor:publish --tag=argusly-connector-config
```

## Configuration

Set these environment variables in the consuming Laravel app:

```dotenv
ARGUSLY_CONNECTOR_API_URL=https://api.argusly.com
ARGUSLY_CONNECTOR_TOKEN=
ARGUSLY_CONNECTOR_SITE_ID=
ARGUSLY_CONNECTOR_DESTINATION_ID=
ARGUSLY_CONNECTOR_SITE_NAME="${APP_NAME}"
ARGUSLY_CONNECTOR_SITE_URL="${APP_URL}"
```

The required token is issued by the Argusly platform. The connector sends it as `Authorization: Bearer <token>`.

API URL examples:

```dotenv
ARGUSLY_CONNECTOR_API_URL=https://api.argusly.com
ARGUSLY_CONNECTOR_API_URL=https://staging.argusly.com
ARGUSLY_CONNECTOR_API_URL=http://argusly.test
```

## Commands

```bash
php artisan argusly:connector:health
php artisan argusly:connector:content:pull
php artisan argusly:connector:content:sync {content_id}
```

Argusly remains the source of truth for connector tokens, connector settings, site registration, content sync decisions, health checks, and webhooks.

## Platform contract

The package calls the canonical Argusly connector endpoints:

- `POST /api/v1/connectors/heartbeat`
- `GET /api/v1/connectors/content`
- `GET /api/v1/connectors/content/{content}`
- `POST /api/v1/connectors/content/{content}/sync-results`

The connector sends `X-Argusly-Site`, `X-Argusly-Destination-Id`, and `X-Argusly-Idempotency-Key` where relevant.

## Smoke checks

Until package-level automated tests are added, run:

```bash
php -l src/ArguslyClient.php
php -l src/Console/Commands/HealthCheckCommand.php
php -l src/Console/Commands/ContentPullCommand.php
php -l src/Console/Commands/ContentSyncCommand.php
composer validate --strict
```

Manual check in a local consuming app:

```bash
php artisan argusly:connector:health
php artisan argusly:connector:content:pull --limit=5
php artisan argusly:connector:content:sync example-content-id
```

## TODO

- TODO(argusly): Review final heartbeat request and response schema.
- TODO(argusly): Implement concrete content import and acknowledgement payload handling once production schemas are finalized.
- TODO(argusly): Add webhook routes only after the signed webhook contract is finalized.
