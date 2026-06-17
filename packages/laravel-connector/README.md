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
ARGUSLY_CONNECTOR_TOKEN=
ARGUSLY_CONNECTOR_SITE_ID=
ARGUSLY_CONNECTOR_DESTINATION_ID=
ARGUSLY_CONNECTOR_SITE_NAME="${APP_NAME}"
ARGUSLY_CONNECTOR_SITE_URL="${APP_URL}"
ARGUSLY_CONNECTOR_TIMEOUT=15
```

The token is issued by Argusly and is sent as `Authorization: Bearer <token>`.

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
