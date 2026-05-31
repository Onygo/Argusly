# Argusly WordPress Plugin v2

Plugin name: **Argusly Connector**

This document specifies the separate WordPress plugin that will connect a WordPress site to Argusly. Do not implement this plugin inside the Argusly app repository. The plugin is an external connector client for the Argusly Connector Protocol.

## Goals

- Connect a WordPress site to an Argusly connector installation.
- Authenticate to Argusly with connector tokens.
- Pull pending content from Argusly.
- Publish, update, unpublish, or schedule WordPress posts and pages.
- Report success, failure, health, and operational events back to Argusly.
- Prepare taxonomy, author, media, SEO metadata, and Answer Block handling.

## WordPress To Argusly Mapping

| Argusly | WordPress |
| --- | --- |
| Argusly Content Asset | WordPress Post/Page |
| Argusly Answer Blocks | Gutenberg block, HTML block, or post meta |
| Argusly SEO metadata | SEO plugin compatible meta where possible |
| Argusly Publishing Channel | WordPress site connection |

## Authentication Setup

In Argusly:

1. Open Settings > Connectors.
2. Register a WordPress connector installation for the target publishing channel.
3. Create a connector token.
4. Grant only the required abilities:
   - `connector:read`
   - `connector:write`
   - `content:read`
   - `content:publish`
   - `events:write`
   - `health:write`
5. Copy the plaintext token immediately. Argusly shows it only once.

In WordPress:

1. Install and activate **Argusly Connector**.
2. Open Settings > Argusly Connector.
3. Enter the Argusly API base URL, usually `https://app.argusly.com/api/v1`.
4. Paste the connector token.
5. Save settings.
6. Run the connection test.

The plugin must send:

```http
Authorization: Bearer argusly_ct_...
Accept: application/json
Content-Type: application/json
```

The plugin must never log or display the token after it is saved.

## Connection Screen

The WordPress admin screen should include:

- Connection status.
- Argusly API base URL.
- Token input with masked saved state.
- Test connection button.
- Site registration button.
- Last successful health report.
- Last pending content pull.
- Connector version.
- Capability list.
- Recent connector events and failures.

Recommended admin location:

```text
Settings > Argusly Connector
```

The screen should be usable without custom code edits and should clearly show whether the site is connected, degraded, or disconnected.

## Site Registration

After saving credentials, the plugin should register site metadata with Argusly:

```http
POST /api/v1/connector/register
```

Payload:

```json
{
  "endpoint_url": "https://example.com",
  "external_connector_id": "wordpress:example.com",
  "connector_version": "2.0.0",
  "capabilities": [
    "health_check",
    "receive_content",
    "publish_content",
    "update_content",
    "delete_content",
    "sync_taxonomies",
    "sync_authors",
    "webhooks",
    "media_upload",
    "preview_url"
  ],
  "metadata": {
    "wordpress_version": "6.x",
    "php_version": "8.x",
    "site_url": "https://example.com",
    "multisite": false,
    "active_theme": "theme-name"
  }
}
```

Registration should be repeatable and safe to retry.

## Capabilities

The plugin should fetch Argusly capabilities:

```http
GET /api/v1/connector/capabilities
```

The plugin should enable UI and background jobs only for capabilities granted by Argusly.

Expected v2 capabilities:

- `health_check`
- `receive_content`
- `publish_content`
- `update_content`
- `delete_content`
- `sync_content`
- `sync_taxonomies`
- `sync_authors`
- `webhooks`
- `media_upload`
- `preview_url`

## Health Checks

The plugin should report health:

```http
POST /api/v1/connector/health
```

Payload:

```json
{
  "status": "ok",
  "message": "WordPress connector is healthy.",
  "metrics": {
    "wp_cron_enabled": true,
    "pending_jobs": 0,
    "last_pull_seconds_ago": 60
  }
}
```

Use:

- `ok` when WordPress can reach Argusly and process jobs.
- `degraded` when queue, cron, permissions, or plugin compatibility issues exist.
- `failed` when publishing cannot run.

The plugin may also send event intake health events:

- `health.ok`
- `health.warning`
- `health.failed`

## Pull Pending Content

The plugin should pull pending publishing actions:

```http
GET /api/v1/content/pending?limit=25
```

Each item includes:

- Publishing action ID and action.
- Content title, body, excerpt, slug, content language, locale, and market.
- Canonical URL, hreflang relationships, source translation pointer, and translation group ID.
- Metadata and SEO metadata.
- Answer Blocks, including each block language.

The plugin should not publish inline during the HTTP polling request. It should enqueue each item into a local WordPress background task or WP-Cron job.

Recommended behavior:

1. Poll Argusly on a schedule.
2. Store a transient or custom table lock per publishing action ID.
3. Queue processing.
4. Publish/update WordPress content.
5. Report success or failure.

## Publish Post/Page

For `action: "publish"`:

1. Determine WordPress post type from Argusly content type:
   - `article` → `post`
   - `page` or `landing_page` → `page`
   - Other types default to `post` unless configured.
2. Create a WordPress post or page.
3. Set `post_title`, `post_name`, `post_excerpt`, `post_content`, and `post_status`.
4. Map content language, locale, market, canonical URL, hreflang, and translation group context where a multilingual plugin is available.
5. Store Argusly content ID and UUID in post meta.
6. Apply SEO metadata.
7. Apply Answer Blocks.
8. Report success to Argusly.

`language` is the Argusly content language. `locale` is the publishing locale/context. The plugin should preserve both separately and should not use either value as an Argusly UI locale.

Success report:

```http
POST /api/v1/content/{id}/published
```

Payload:

```json
{
  "external_id": "123",
  "external_url": "https://example.com/example-post/",
  "language": "en",
  "locale": "en_US",
  "external_locale": "en_US",
  "external_translation_group": "wpml-group-123",
  "external_canonical_url": "https://example.com/example-post/",
  "published_at": "2026-05-29T10:00:00Z",
  "response": {
    "post_id": 123,
    "post_type": "post",
    "post_status": "publish"
  }
}
```

## Update Post/Page

For `action: "update"`:

1. Find the WordPress post by stored Argusly content ID, UUID, or external ID.
2. Update title, slug, excerpt, content, content language, locale, market, translation group context, SEO metadata, and Answer Blocks.
3. Preserve WordPress-only fields unless Argusly owns them.
4. Keep the post published unless the action requires another status.
5. Report success to Argusly through `/content/{id}/published`.

If the post cannot be found, the plugin may create it only if configured to do so. Otherwise it should report failure.

## Sync Taxonomies

The plugin should prepare taxonomy sync for:

- Categories.
- Tags.
- Custom taxonomies.

When taxonomy sync completes, report:

```http
POST /api/v1/connector/events
```

Payload:

```json
{
  "type": "taxonomy.synced",
  "payload": {
    "taxonomy": "category",
    "created": 2,
    "updated": 5,
    "skipped": 0
  },
  "idempotency_key": "taxonomy-synced:category:cursor"
}
```

Taxonomy sync should be idempotent and should not delete WordPress terms unless explicitly configured.

## Sync Authors

The plugin should prepare author sync for:

- WordPress users.
- Display names.
- Email addresses where safe.
- Author slugs.
- Optional role mapping.

Report author sync:

```json
{
  "type": "author.synced",
  "payload": {
    "author_id": 12,
    "name": "Jane Editor",
    "email": "jane@example.com"
  },
  "idempotency_key": "author-synced:12"
}
```

The plugin must avoid exposing private user data unless the site owner opted in.

## Media Upload Preparation

The v2 plugin should prepare media handling but does not need full media synchronization in the first release.

Expected future flow:

1. Argusly content references media in metadata or body.
2. Plugin downloads media from allowed Argusly URLs.
3. Plugin uploads media to WordPress Media Library.
4. Plugin replaces body references with local media URLs.
5. Plugin reports media upload events.

Media event example:

```json
{
  "type": "media.uploaded",
  "payload": {
    "url": "https://example.com/wp-content/uploads/image.jpg",
    "attachment_id": 987,
    "source_url": "https://cdn.argusly.com/image.jpg"
  },
  "idempotency_key": "media-uploaded:987"
}
```

Security rules:

- Only download from allowed hosts.
- Enforce file size limits.
- Validate MIME type.
- Use WordPress media APIs.
- Do not sideload executable files.

## SEO Metadata Handling

Argusly SEO metadata should map to WordPress and SEO plugin meta where possible.

Generic fields:

- SEO title.
- SEO description.
- Canonical URL.
- Open Graph title.
- Open Graph description.
- Open Graph image.
- Robots directives.

Compatibility targets:

- WordPress core title/excerpt where no SEO plugin exists.
- Yoast SEO meta keys where Yoast is active.
- Rank Math meta keys where Rank Math is active.
- SEOPress meta keys where SEOPress is active.

The plugin should detect active SEO plugins and write compatible meta only when safe. It should store original Argusly SEO metadata in a dedicated `_argusly_seo_metadata` post meta key for traceability.

## Answer Blocks Handling

Argusly Answer Blocks may be rendered in one of three modes:

1. Gutenberg blocks.
2. HTML block appended to post content.
3. Structured post meta for theme rendering.

Recommended default:

- Store all blocks in `_argusly_answer_blocks` post meta as JSON.
- Optionally append a Gutenberg/HTML FAQ section to `post_content`.

Answer Block fields:

- UUID.
- Type.
- Status.
- Question.
- Answer.
- Language.
- Position.
- Metadata.

The plugin should preserve order by `position`.

## Failure Reporting

When publishing, updating, syncing, or health checks fail, report failure to Argusly.

Publishing failure:

```http
POST /api/v1/content/{id}/failed
```

Payload:

```json
{
  "message": "WordPress rejected the post because the slug already exists.",
  "language": "en",
  "locale": "en_US",
  "external_locale": "en_US",
  "external_translation_group": "wpml-group-123",
  "external_canonical_url": "https://example.com/example-post/",
  "response": {
    "code": "slug_conflict",
    "wp_error": "..."
  }
}
```

Connector event failure:

```json
{
  "type": "content.failed",
  "message": "WordPress publishing failed.",
  "payload": {
    "content_id": 456,
    "language": "en",
    "locale": "en_US",
    "external_locale": "en_US",
    "external_translation_group": "wpml-group-123",
    "external_canonical_url": "https://example.com/example-post/",
    "error": "slug_conflict"
  },
  "idempotency_key": "content-failed:456:slug-conflict"
}
```

Failures should be retryable where possible and should include stable idempotency keys.

## Webhook/Event Flow

The plugin should use:

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

Argusly stores these as domain events linked to the connector installation. Warning and failure events may create intelligence signals.

Content event payloads may include `language`, `locale`, `external_locale`, `external_translation_group`, and `external_canonical_url`. Use these fields when reporting multilingual plugin state, external translation groups, or canonical URL decisions back to Argusly.

## Queue Usage

WordPress does not have a native queue equivalent to Laravel queues, so the plugin should support:

- WP-Cron for simple sites.
- Action Scheduler for more reliable background processing.
- Optional custom queue table if needed later.

Recommended jobs:

- Pull pending content.
- Process publishing action.
- Report publishing success.
- Report publishing failure.
- Report health.
- Send connector event.

Action Scheduler is recommended for production because it supports retries, visibility, and operational tooling.

## Version Update Strategy

Plugin versioning should follow semantic versioning:

- Patch: bug fixes and compatibility adjustments.
- Minor: optional capabilities, integrations, or admin UI improvements.
- Major: breaking protocol or storage changes.

The plugin should:

- Report its version during site registration.
- Store the last registered version locally.
- Re-register after plugin updates.
- Run database migrations through WordPress upgrade hooks if custom tables are introduced.
- Keep backward compatibility with the current Argusly Connector Protocol whenever possible.

Argusly connector manifests should track compatible plugin/protocol versions.

## Security Considerations

- Store the connector token encrypted or protected using WordPress options best practices.
- Never log plaintext connector tokens.
- Redact tokens from admin screens after save.
- Require `manage_options` capability for settings screens.
- Use nonces for admin form submissions.
- Use HTTPS for Argusly API calls.
- Validate all Argusly payloads before writing posts, meta, users, media, or taxonomies.
- Sanitize post content according to WordPress capability and site policy.
- Restrict media sideloading to safe MIME types and allowed hosts.
- Avoid deleting posts, terms, users, or media unless explicitly configured.
- Use idempotency keys for all retryable event reports.
- Keep a local mapping of Argusly IDs to WordPress IDs to avoid duplicate posts.
- Support token rotation without reinstalling the plugin.

## Local Development Strategy

Recommended development setup:

1. Run Argusly locally.
2. Run WordPress locally in a separate project.
3. Install the plugin from a separate plugin repository or symlink.
4. Register a WordPress connector in Argusly Settings > Connectors.
5. Create a connector token with content, event, health, and connector abilities.
6. Configure the plugin with local Argusly API URL and token.
7. Test site registration.
8. Create an approved Argusly content asset and publish to the WordPress channel.
9. Run the plugin pull job.
10. Confirm the post/page is created and Argusly receives success or failure.

No WordPress plugin code should be committed to the Argusly app repository.
