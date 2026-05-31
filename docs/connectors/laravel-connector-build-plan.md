# Argusly Laravel Connector Build Plan

Repository: `argusly-laravel-connector`

Package: `argusly/laravel-connector`

This document is the implementation spec for a separate Laravel package repository. Do not add package source code, package migrations, package routes, or package tests to the Laravel Argusly app repository.

## Purpose

The Laravel connector package lets a host Laravel application act as an external publishing target for Argusly. The package authenticates to Argusly with a connector token, registers runtime capabilities, pulls pending publishing actions, delegates content persistence to the host app, and reports success or failure back to Argusly.

## Responsibilities

The package must:

- Register a Laravel app with Argusly.
- Store connector token in config/env.
- Expose a local health endpoint.
- Pull pending content.
- Publish content into the host app.
- Update content in the host app.
- Report publishing status back to Argusly.
- Emit events back to Argusly.
- Provide a default database publisher.
- Allow host apps to implement custom publishing.
- Run publishing work through queues.
- Provide Artisan commands for setup, health checks, pulls, and pending publication.

## Non-Goals

- Do not implement this package inside the Argusly app repository.
- Do not require host apps to use a specific content model.
- Do not make Argusly directly write to the host database.
- Do not assume the host app has SEO, translation, media, or taxonomy tables.
- Do not block HTTP requests while publishing pulled content.

## Target Compatibility

Minimum supported targets:

- Laravel 10, 11, and 12
- PHP 8.2+
- Queue-capable Laravel applications
- Config-cache compatible deployments

Recommended optional integrations:

- Laravel Scheduler
- Laravel Horizon
- Spatie Laravel Data or DTOs later if payload complexity grows

## Repository Shape

Recommended structure:

```text
argusly-laravel-connector/
  composer.json
  README.md
  config/
    argusly.php
  database/
    migrations/
      create_argusly_connector_records_table.php
  routes/
    argusly.php
  src/
    ArguslyConnectorServiceProvider.php
    Contracts/
      PublisherInterface.php
      ReportsHealth.php
    Api/
      ApiClient.php
      ApiException.php
      ResponseData.php
    Console/
      ConnectCommand.php
      HealthCommand.php
      PullCommand.php
      PublishPendingCommand.php
    Data/
      PendingContent.php
      PublishingAction.php
      ContentAsset.php
      PublishResult.php
      PublishFailure.php
    Events/
      ArguslyContentPulled.php
      ArguslyContentPublished.php
      ArguslyContentFailed.php
      ArguslyHealthReported.php
    Http/
      Controllers/
        HealthController.php
      Middleware/
        VerifyArguslyWebhook.php
    Jobs/
      PullPendingContentJob.php
      PublishPendingContentJob.php
      PublishContentItemJob.php
    Publishing/
      DefaultDatabasePublisher.php
      ContentMapper.php
      PublishingLock.php
    Support/
      Config.php
      CapabilityReporter.php
      ConnectorState.php
      Logger.php
  tests/
    Feature/
    Unit/
```

## Installation

Install in the host Laravel app:

```bash
composer require argusly/laravel-connector
```

Publish config:

```bash
php artisan vendor:publish --tag=argusly-config
```

Publish migrations only if local connector state is enabled:

```bash
php artisan vendor:publish --tag=argusly-migrations
php artisan migrate
```

Add scheduler entries:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('argusly:health')->everyFiveMinutes();
Schedule::command('argusly:pull')->everyMinute();
Schedule::command('argusly:publish-pending')->everyMinute();
```

## Configuration

Config file: `config/argusly.php`

Required keys:

```php
<?php

return [
    'api_url' => env('ARGUSLY_API_URL', 'https://app.argusly.com/api/v1'),
    'token' => env('ARGUSLY_CONNECTOR_TOKEN'),
    'channel' => env('ARGUSLY_CHANNEL'),
    'publisher' => env('ARGUSLY_PUBLISHER', \Argusly\LaravelConnector\Publishing\DefaultDatabasePublisher::class),

    'enabled' => env('ARGUSLY_CONNECTOR_ENABLED', true),

    'connector' => [
        'name' => env('ARGUSLY_CONNECTOR_NAME', config('app.name')),
        'version' => env('ARGUSLY_CONNECTOR_VERSION', '1.0.0'),
        'endpoint_url' => env('ARGUSLY_CONNECTOR_ENDPOINT_URL', config('app.url')),
        'external_connector_id' => env('ARGUSLY_CONNECTOR_ID', str(config('app.url'))->slug()->toString()),
    ],

    'http' => [
        'timeout' => env('ARGUSLY_HTTP_TIMEOUT', 10),
        'retry_times' => env('ARGUSLY_HTTP_RETRY_TIMES', 3),
        'retry_sleep_ms' => env('ARGUSLY_HTTP_RETRY_SLEEP_MS', 500),
    ],

    'queue' => [
        'connection' => env('ARGUSLY_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database')),
        'queue' => env('ARGUSLY_QUEUE', 'argusly'),
    ],

    'routes' => [
        'enabled' => env('ARGUSLY_ROUTES_ENABLED', true),
        'prefix' => env('ARGUSLY_ROUTE_PREFIX', 'argusly'),
        'middleware' => ['web'],
    ],

    'capabilities' => [
        'health_check',
        'receive_content',
        'publish_content',
        'update_content',
        'sync_content',
        'preview_url',
    ],

    'database_publisher' => [
        'model' => env('ARGUSLY_CONTENT_MODEL', App\Models\Post::class),
        'title_column' => 'title',
        'slug_column' => 'slug',
        'excerpt_column' => 'excerpt',
        'body_column' => 'body',
        'status_column' => 'status',
        'published_at_column' => 'published_at',
        'language_column' => 'language',
        'locale_column' => 'locale',
        'canonical_url_column' => 'canonical_url',
        'seo_metadata_column' => 'seo_metadata',
        'argusly_uuid_column' => 'argusly_uuid',
        'default_status' => 'draft',
        'published_status' => 'published',
        'failed_status' => 'failed',
    ],
];
```

Required environment variables:

```dotenv
ARGUSLY_API_URL=https://app.argusly.com/api/v1
ARGUSLY_CONNECTOR_TOKEN=argusly_ct_...
ARGUSLY_CHANNEL=production-laravel
ARGUSLY_PUBLISHER=
```

The requested public config names must exist:

- `argusly.api_url`
- `argusly.token`
- `argusly.channel`
- `argusly.publisher`

## ServiceProvider

Class: `ArguslyConnectorServiceProvider`

Responsibilities:

- Merge package config.
- Publish config and migrations.
- Register routes when enabled.
- Bind `ApiClient`.
- Bind `PublisherInterface` to `config('argusly.publisher')`.
- Register console commands.
- Register package events.
- Configure queue connection/queue defaults for package jobs.

Binding behavior:

```php
$this->app->bind(PublisherInterface::class, config('argusly.publisher'));
```

If `argusly.publisher` is null or invalid, fail with a clear exception during command/job execution, not during package discovery.

## Routes

Route file: `routes/argusly.php`

Default prefix:

```text
/argusly
```

Initial route:

```http
GET /argusly/health
```

Response:

```json
{
  "status": "ok",
  "connector": "argusly/laravel-connector",
  "channel": "production-laravel",
  "queue": "ok",
  "token_configured": true,
  "last_pull_at": "2026-05-31T10:00:00Z",
  "last_publish_at": "2026-05-31T10:02:00Z"
}
```

Future webhook route:

```http
POST /argusly/webhook
```

Do not require webhook routes for the first polling-based release.

## Middleware

### VerifyArguslyWebhook

Future webhook middleware should:

- Verify HMAC signature.
- Reject missing timestamp.
- Reject stale timestamp.
- Use constant-time signature comparison.
- Reject replayed event IDs when local state is enabled.

Polling and command workflows do not need inbound webhooks.

## ApiClient

Class: `ApiClient`

Responsibilities:

- Read `argusly.api_url`.
- Read `argusly.token`.
- Send bearer token auth.
- Send JSON requests.
- Parse JSON responses.
- Normalize errors.
- Retry transient failures.
- Respect rate limits.
- Redact token from logs and exceptions.

Required methods:

```php
manifest(): array
register(array $payload): array
capabilities(): array
health(array $payload): array
pendingContent(int $limit = 25): array
reportPublished(string $contentUuid, array $payload): array
reportFailed(string $contentUuid, array $payload): array
event(string $name, array $payload): array
```

HTTP endpoints:

- `GET /connector/manifest`
- `POST /connector/register`
- `GET /connector/capabilities`
- `POST /connector/health`
- `GET /content/pending?limit=25`
- `POST /content/{uuid}/published`
- `POST /content/{uuid}/failed`
- Future: event ingestion endpoint for package-originated events.

## PublisherInterface

Contract:

```php
namespace Argusly\LaravelConnector\Contracts;

use Argusly\LaravelConnector\Data\PendingContent;
use Argusly\LaravelConnector\Data\PublishResult;

interface PublisherInterface
{
    public function publish(PendingContent $content): PublishResult;

    public function update(PendingContent $content): PublishResult;
}
```

The package worker chooses `publish` or `update` based on the Argusly publishing action. Future actions can extend the contract or use optional interfaces.

## PublishResult

Recommended data object:

```php
final class PublishResult
{
    public function __construct(
        public readonly string|int $externalId,
        public readonly ?string $externalUrl = null,
        public readonly ?string $canonicalUrl = null,
        public readonly ?string $language = null,
        public readonly ?string $locale = null,
        public readonly array $response = [],
    ) {}
}
```

Failures should throw a package exception such as `PublishFailedException` with a safe public message and optional context.

## Default Database Publisher

Class: `DefaultDatabasePublisher`

Purpose:

Provide a working default for simple host apps that publish Argusly content into one Eloquent model.

Behavior:

- Resolve model from `argusly.database_publisher.model`.
- Match existing rows by configured Argusly UUID column.
- Create row for publish if missing.
- Update row for update if found.
- Map configured columns.
- Store SEO metadata if configured.
- Store language/locale/canonical URL if configured.
- Set published status and timestamp for publish.

Default field mapping:

| Argusly field | Config column |
| --- | --- |
| `content.uuid` | `argusly_uuid_column` |
| `content.title` | `title_column` |
| `content.slug` | `slug_column` |
| `content.excerpt` | `excerpt_column` |
| `content.body` | `body_column` |
| `content.language` | `language_column` |
| `content.locale` | `locale_column` |
| `content.canonical_url` | `canonical_url_column` |
| `content.seo_metadata` | `seo_metadata_column` |
| publish status | `status_column` |
| publish timestamp | `published_at_column` |

The default publisher should be intentionally conservative. It should not guess relationships, media, custom tables, or translation systems.

## Custom Publisher Contract

Host apps can implement custom publishing by creating a class that implements `PublisherInterface`.

Example:

```php
namespace App\Argusly;

use App\Models\Article;
use Argusly\LaravelConnector\Contracts\PublisherInterface;
use Argusly\LaravelConnector\Data\PendingContent;
use Argusly\LaravelConnector\Data\PublishResult;

class ArticlePublisher implements PublisherInterface
{
    public function publish(PendingContent $content): PublishResult
    {
        $article = Article::query()->updateOrCreate(
            ['argusly_uuid' => $content->content->uuid],
            [
                'title' => $content->content->title,
                'slug' => $content->content->slug,
                'excerpt' => $content->content->excerpt,
                'body' => $content->content->body,
                'status' => 'published',
                'published_at' => now(),
                'language' => $content->content->language,
                'locale' => $content->content->locale,
                'canonical_url' => $content->content->canonicalUrl,
                'seo_metadata' => $content->content->seoMetadata,
            ],
        );

        return new PublishResult(
            externalId: $article->getKey(),
            externalUrl: route('articles.show', $article),
            canonicalUrl: $article->canonical_url,
            language: $article->language,
            locale: $article->locale,
            response: ['model' => Article::class],
        );
    }

    public function update(PendingContent $content): PublishResult
    {
        return $this->publish($content);
    }
}
```

Then configure:

```dotenv
ARGUSLY_PUBLISHER="App\\Argusly\\ArticlePublisher"
```

Or in `config/argusly.php`:

```php
'publisher' => App\Argusly\ArticlePublisher::class,
```

Custom publishers should:

- Validate required content fields.
- Be idempotent by `content.uuid` and/or `publishing_action.uuid`.
- Return stable external IDs.
- Return a public URL when available.
- Preserve Argusly language, locale, canonical URL, and SEO metadata where possible.
- Throw safe package exceptions for expected failures.

## Queue Jobs

### PullPendingContentJob

Responsibilities:

- Call `ApiClient::pendingContent`.
- Store pending items locally when local state is enabled, or dispatch per item immediately.
- Avoid duplicate dispatch by publishing action UUID.

### PublishPendingContentJob

Responsibilities:

- Read locally stored pending items.
- Dispatch `PublishContentItemJob`.
- Enforce max batch size.

### PublishContentItemJob

Responsibilities:

- Acquire lock by publishing action UUID.
- Resolve `PublisherInterface`.
- Call `publish` or `update`.
- Report published result.
- Report failed result when publisher throws.
- Release lock.

All jobs should use:

- `config('argusly.queue.connection')`
- `config('argusly.queue.queue')`

## Artisan Commands

### `php artisan argusly:connect`

Purpose:

- Validate config.
- Test token.
- Fetch manifest.
- Register app.
- Fetch capabilities.
- Send initial health report.

Options:

- `--api-url=`
- `--token=`
- `--channel=`
- `--no-interaction`

Interactive behavior:

- Prompt for API URL if missing.
- Prompt for token if missing.
- Prompt for channel name/identifier if missing.
- Never echo token back after entry.

### `php artisan argusly:health`

Purpose:

- Run local health checks.
- Print local status.
- Report status to Argusly.

Checks:

- Token configured.
- API reachable.
- Manifest readable.
- Queue configured.
- Publisher class resolvable.
- Required capabilities enabled.

### `php artisan argusly:pull`

Purpose:

- Pull pending content from Argusly.
- Dispatch publish jobs or store pending records.

Options:

- `--limit=25`
- `--sync` to process inline for debugging only

### `php artisan argusly:publish-pending`

Purpose:

- Process pending local connector records.
- Dispatch publish jobs in batches.

Options:

- `--limit=25`
- `--action=publish|update`
- `--sync` to process inline for debugging only

## Local State

The package can support local connector records for observability and idempotency.

Recommended table:

```text
argusly_connector_records
```

Columns:

- `id`
- `publishing_action_uuid`
- `content_uuid`
- `action`
- `status`
- `payload`
- `external_id`
- `external_url`
- `attempts`
- `last_error`
- `locked_until`
- `pulled_at`
- `published_at`
- `failed_at`
- timestamps

Local state should be optional but recommended for production.

## Health Endpoint

Local endpoint:

```http
GET /argusly/health
```

This endpoint is for operators and load balancers. It does not authenticate to Argusly.

Do not expose secrets. Never include the token.

Possible response:

```json
{
  "status": "degraded",
  "checks": {
    "token": "configured",
    "api": "ok",
    "queue": "ok",
    "publisher": "missing",
    "capabilities": "missing publish_content"
  }
}
```

## Health Reporting To Argusly

Argusly endpoint:

```http
POST /api/v1/connector/health
```

Payload:

```json
{
  "status": "ok",
  "message": "Laravel connector is healthy.",
  "metrics": {
    "pending_records": 0,
    "failed_records": 0,
    "queue_connection": "redis",
    "queue": "argusly"
  },
  "checked_at": "2026-05-31T10:00:00Z"
}
```

## Registration

Registration endpoint:

```http
POST /api/v1/connector/register
```

Payload:

```json
{
  "endpoint_url": "https://example.com",
  "external_connector_id": "production-laravel-app",
  "connector_version": "1.0.0",
  "capabilities": [
    "health_check",
    "receive_content",
    "publish_content",
    "update_content",
    "sync_content",
    "preview_url"
  ],
  "metadata": {
    "laravel_version": "12.x",
    "php_version": "8.3",
    "app_name": "Customer App",
    "queue_connection": "redis",
    "publisher": "App\\Argusly\\ArticlePublisher"
  }
}
```

Registration must be idempotent and safe to run repeatedly.

## Reporting Status

Success:

```php
$client->reportPublished($contentUuid, [
    'external_id' => (string) $result->externalId,
    'external_url' => $result->externalUrl,
    'external_canonical_url' => $result->canonicalUrl,
    'language' => $result->language,
    'locale' => $result->locale,
    'published_at' => now()->toISOString(),
    'response' => $result->response,
]);
```

Failure:

```php
$client->reportFailed($contentUuid, [
    'message' => $exception->getMessage(),
    'response' => [
        'exception' => class_basename($exception),
        'context' => $exception instanceof PublishFailedException ? $exception->safeContext() : [],
    ],
]);
```

## Events Back To Argusly

The package should emit events where useful:

- `connector.connected`
- `connector.health_reported`
- `content.pulled`
- `content.publish_started`
- `content.published`
- `content.publish_failed`
- `content.updated`

If Argusly has no dedicated event endpoint in the current protocol version, events can be included in health/report payload metadata until the endpoint is added.

## Payload Handling

The package must preserve these Argusly fields for host publishers:

- Content ID and UUID
- Publishing action ID and UUID
- Action
- Title
- Slug
- Excerpt
- Body
- Type
- Language
- Locale
- Market
- Canonical URL
- Hreflang payload
- Translated-from payload
- Translation group ID
- SEO metadata
- Answer Blocks
- Raw metadata

Do not discard unknown payload keys. Keep them available in data objects under `metadata` or `raw`.

## Security

Token handling:

- Token lives in `.env` or secret manager.
- Never log token.
- Redact `Authorization` header.
- Do not expose token through health endpoint.
- Treat missing token as failed health.

Config:

- Support `php artisan config:cache`.
- Read token through config, not direct env calls outside config file.

Routes:

- Health route must not expose secrets.
- Future webhook routes must verify signatures.

Publishing:

- Custom publishers are host-app code and may enforce their own authorization rules.
- Package jobs should validate connector capabilities before publishing.

Errors:

- Report safe error messages to Argusly.
- Log detailed local context without secrets.

## Testing Plan

Unit tests:

- Config resolution.
- ApiClient headers and token redaction.
- ApiClient error normalization.
- ServiceProvider bindings.
- PublisherInterface resolution.
- DefaultDatabasePublisher mappings.
- Health check aggregation.
- Command validation.

Feature tests:

- `argusly:connect` registers app.
- `argusly:health` reports health.
- `argusly:pull` dispatches jobs.
- `argusly:publish-pending` processes local records.
- Publish success reports to Argusly.
- Publish failure reports to Argusly.
- Duplicate pending payload does not double publish.
- Custom publisher is called.
- Local health route hides secrets.

Integration tests:

- Fake Argusly API responses with Laravel HTTP fake.
- Queue fake for job dispatch.
- Database publisher against a test model.
- Config-cache mode.

## Release Plan

1. Create `argusly-laravel-connector` repository.
2. Scaffold Composer package.
3. Implement ServiceProvider and config.
4. Implement ApiClient.
5. Implement health endpoint and command.
6. Implement connect command.
7. Implement pending content pull command/job.
8. Implement PublisherInterface and DefaultDatabasePublisher.
9. Implement publish-pending command/job.
10. Implement status reporting.
11. Add package tests and CI.
12. Tag `v0.1.0` pre-release.
13. Test against a sample Laravel host app.
14. Publish package when stable.

## Open Decisions

- Whether local state is always installed or opt-in.
- Whether to use Laravel actions/data libraries or plain PHP DTOs.
- Whether webhooks ship in v1 or remain future work.
- Which queue defaults are safest for small host apps.
- Whether media upload belongs in this package or a companion package.
