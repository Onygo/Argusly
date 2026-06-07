# Argusly Laravel Connector

Development-only first-party Laravel connector for Argusly.

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
ARGUSLY_CONNECTOR_API_KEY=
ARGUSLY_CONNECTOR_SITE_ID=
ARGUSLY_CONNECTOR_SITE_NAME="${APP_NAME}"
ARGUSLY_CONNECTOR_SITE_URL="${APP_URL}"
```

## Commands

```bash
php artisan argusly:connector:health
php artisan argusly:connector:content:pull
php artisan argusly:connector:content:sync
```

Argusly remains the source of truth for API keys, connector settings, site registration, content sync decisions, health checks, and webhooks.

## TODO

- TODO(argusly): Review final health-check request and response schema.
- TODO(argusly): Implement canonical content sync once the platform payload is finalized.
- TODO(argusly): Add webhook routes only after the signed webhook contract is finalized.
