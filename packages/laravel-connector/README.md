# Argusly Laravel Connector

First-party Laravel connector client for Argusly.

## Installation

Install the package in a Laravel application:

```bash
composer require onygo/argusly-laravel-connector
php artisan vendor:publish --tag=argusly-connector-config
```

## Configuration

Set these environment variables in the consuming Laravel app:

```dotenv
ARGUSLY_CONNECTOR_API_URL=https://api.argusly.com
ARGUSLY_CONNECTOR_API_KEY=
ARGUSLY_CONNECTOR_WORKSPACE_ID=
ARGUSLY_CONNECTOR_DESTINATION_KEY=
ARGUSLY_CONNECTOR_SITE_NAME="${APP_NAME}"
ARGUSLY_CONNECTOR_SITE_URL="${APP_URL}"
ARGUSLY_CONNECTOR_TIMEOUT=15
ARGUSLY_CONNECTOR_WEBHOOKS_ENABLED=true
ARGUSLY_CONNECTOR_WEBHOOK_SECRET=
ARGUSLY_CONNECTOR_SYNC_PATH=argusly/sync
ARGUSLY_CONNECTOR_ALLOWED_OPERATIONS=create,update,draft
ARGUSLY_CONNECTOR_AUTONOMOUS_ALLOWED=false
```

The API key is the site key issued by Argusly and is sent as `Authorization: Bearer <key>`. `ARGUSLY_CONNECTOR_TOKEN`, `ARGUSLY_CONNECTOR_SITE_ID`, and `ARGUSLY_CONNECTOR_DESTINATION_ID` remain supported as legacy aliases.

The package registers `argusly:connector:health` with Laravel's scheduler automatically. The consuming app only needs its normal `php artisan schedule:run` entry.

## Usage

Inject `Onygo\ArguslyConnector\ArguslyClient` where connector actions are needed:

```php
use Onygo\ArguslyConnector\ArguslyClient;

$response = app(ArguslyClient::class)->health();
```

Available client methods:

- `health(array $metadata = [])`
- `contentIndex(array $filters = [])`
- `content(string|int $content)`
- `acknowledgeContentSync(string|int $content, array $payload, ?string $idempotencyKey = null)`

The package also exposes lightweight status endpoints for Argusly connection tests:

- `GET|POST /argusly/connector/activity`
- `GET|POST /argusly/activity`

Argusly can push knowledge article updates directly to the connector sync endpoint:

- `POST /argusly/sync`
- `POST /api/argusly/sync`

Send the site key as `Authorization: Bearer <key>` or `X-Argusly-API-Key`. The endpoint stores incoming `knowledge_article` payloads in `argusly_articles`, enforces the policy blocklist, and rejects duplicate idempotency keys.

## Artisan Commands

```bash
php artisan argusly:connector:health
php artisan argusly:connector:content:pull --limit=25
php artisan argusly:connector:content:ack {content_id} {status} --remote-id=123 --remote-url=https://example.com/post
```

## Platform Contract

The connector calls these Argusly endpoints:

- `POST /api/v1/connectors/heartbeat`
- `GET /api/v1/connectors/content`
- `GET /api/v1/connectors/content/{content}`
- `POST /api/v1/connectors/content/{content}/sync-results`

It sends `X-Argusly-Site`, `X-Argusly-Destination-Id`, and `X-Argusly-Idempotency-Key` where relevant.

## Verification

```bash
composer validate --strict
php -l src/ArguslyClient.php
php -l src/ArguslyConnectorServiceProvider.php
php -l src/Console/Commands/HealthCheckCommand.php
php -l src/Console/Commands/ContentPullCommand.php
php -l src/Console/Commands/ContentSyncCommand.php
```
