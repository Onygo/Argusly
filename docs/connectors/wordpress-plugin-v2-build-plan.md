# Argusly WordPress Plugin v2 Build Plan

Repository: `argusly-wordpress-plugin`

Plugin name: **Argusly Connector**

This document is the implementation spec for a separate WordPress plugin repository. Do not add plugin source code, WordPress assets, build tooling, or plugin packaging files to the Laravel Argusly app repository.

## Purpose

The WordPress plugin is an external connector client for the Argusly Connector Protocol. It connects one WordPress site to one Argusly connector installation and publishing channel, then receives content distribution work from Argusly and reports outcomes back.

The plugin must support a production editorial workflow:

- Register the site with Argusly.
- Store the connector token securely.
- Send health checks.
- Pull pending content.
- Publish posts and pages.
- Update posts and pages.
- Report published and failed actions.
- Sync taxonomies.
- Sync authors.
- Support SEO metadata.
- Support language and locale.
- Support Answer Blocks.
- Support canonical URL.
- Support future hreflang.
- Prepare for media support later.

## Non-Goals

- Do not implement the plugin inside the Laravel app repository.
- Do not make WordPress push arbitrary content into Argusly in v2.
- Do not require direct database access to Argusly.
- Do not assume one SEO plugin; use compatibility adapters where possible.
- Do not implement media ingestion in the first build, but design payload and service boundaries so it can be added later.

## Repository Shape

Repository name:

```text
argusly-wordpress-plugin
```

Recommended structure:

```text
argusly-wordpress-plugin/
  argusly-connector.php
  readme.txt
  composer.json
  package.json
  src/
    Admin/
      SettingsPage.php
      NoticeRenderer.php
    Api/
      ArguslyClient.php
      ApiException.php
    Cron/
      CronWorker.php
      LockManager.php
    Publishing/
      PublisherService.php
      ContentMapper.php
      SeoMetaMapper.php
      AnswerBlockRenderer.php
      TaxonomySyncService.php
      AuthorSyncService.php
    Health/
      HealthService.php
      CapabilityReporter.php
    Logging/
      Logger.php
    Support/
      Options.php
      Sanitizer.php
      PluginInfo.php
  assets/
    admin.css
    admin.js
  tests/
    Unit/
    Integration/
```

Use a simple namespaced PHP architecture. Avoid framework-heavy abstractions; the plugin should remain understandable to WordPress developers and easy to review for security.

## WordPress Compatibility

Minimum supported targets:

- WordPress 6.4+
- PHP 8.1+
- Single-site WordPress first
- Multisite aware, but multisite-specific management can be a later enhancement

The plugin must use WordPress APIs for:

- Options and secrets
- HTTP requests
- Cron events
- Nonces
- Capabilities
- Post insertion and updates
- Taxonomy terms
- Users/authors
- Post meta
- Admin notices

## Connector Protocol

The plugin communicates with Argusly over `/api/v1`.

Authentication header:

```http
Authorization: Bearer argusly_ct_...
Accept: application/json
Content-Type: application/json
```

Expected token abilities:

- `connector:read`
- `connector:write`
- `content:read`
- `content:publish`
- `events:write`
- `health:write`

The plugin must gracefully handle:

- `401` invalid token
- `403` revoked, expired, inactive, or insufficient abilities
- `404` content outside connector scope
- `409` connector/channel mismatch
- `422` validation errors
- `429` rate limiting
- `5xx` transient Argusly failures

## Architecture

### Admin Settings Page

Location:

```text
Settings > Argusly Connector
```

Responsibilities:

- Capture Argusly API base URL.
- Capture connector token.
- Show masked token state after save.
- Test connection.
- Register site.
- Show connector status.
- Show enabled capabilities.
- Show last health check.
- Show last pending content pull.
- Show last publishing result.
- Show plugin version and WordPress environment details.
- Allow manual pull now.
- Allow token rotation by replacing the stored token.

Forms must use WordPress nonces and `manage_options` capability checks.

### API Client

Class: `ArguslyClient`

Responsibilities:

- Centralize Argusly HTTP requests.
- Add authentication headers.
- Encode JSON payloads.
- Decode JSON responses.
- Normalize API errors.
- Apply timeouts.
- Apply retry/backoff for transient failures and `429`.
- Redact tokens from logs.

Primary calls:

- `GET /connector/manifest`
- `POST /connector/register`
- `GET /connector/capabilities`
- `POST /connector/health`
- `GET /content/pending?limit=25`
- `POST /content/{uuid}/published`
- `POST /content/{uuid}/failed`
- Future: taxonomy and author sync endpoints as Argusly exposes them.

### Cron Worker

Class: `CronWorker`

Responsibilities:

- Register WP-Cron schedules.
- Pull pending content on a regular interval.
- Prevent concurrent runs with a lock.
- Process a bounded number of items per run.
- Report failures per publishing action.
- Send health after each run.

Recommended intervals:

- Pull pending content every 1 to 5 minutes.
- Send health every 5 to 15 minutes.
- Retry failed transient pulls with exponential backoff.

Locking:

- Use transients or an options-backed lock.
- Lock per connector and per publishing action.
- Expire locks automatically to avoid dead queues.

### Publisher Service

Class: `PublisherService`

Responsibilities:

- Convert Argusly content payloads into WordPress posts/pages.
- Create new posts/pages for `publish`.
- Find and update existing posts/pages for `update`.
- Store Argusly IDs and UUIDs in post meta.
- Keep idempotency for repeated pending content pulls.
- Apply SEO metadata.
- Render Answer Blocks.
- Map language/locale metadata.
- Store canonical URL.
- Prepare hreflang metadata for future output.
- Report published or failed status back to Argusly.

Idempotency keys:

- Argusly `publishing_action.uuid`
- Argusly `content.uuid`
- Local `_argusly_content_uuid`
- Local `_argusly_last_publishing_action_uuid`

### Health Service

Class: `HealthService`

Responsibilities:

- Check token presence.
- Check Argusly API reachability.
- Check manifest/capability access.
- Check WP-Cron availability.
- Check write permissions for posts/pages.
- Check whether the last pull failed.
- Check whether recent publishing failures exist.
- Report status to Argusly.

Health statuses:

- `ok`: ready to publish.
- `degraded`: connected but some workflows may fail.
- `failed`: cannot publish.

### Logger

Class: `Logger`

Responsibilities:

- Write connector events to a local option, custom table, or WordPress debug log depending on configuration.
- Redact tokens and sensitive headers.
- Store a bounded event history for the admin screen.
- Include correlation IDs where Argusly provides them.
- Include publishing action UUIDs for traceability.

Log levels:

- `debug`
- `info`
- `warning`
- `error`

### Capability Reporter

Class: `CapabilityReporter`

Responsibilities:

- Report plugin-supported capabilities to Argusly during registration.
- Fetch enabled capabilities from Argusly.
- Make the admin UI reflect disabled capabilities.
- Prevent worker actions when the required capability is not enabled.

Initial supported capabilities:

- `health_check`
- `receive_content`
- `publish_content`
- `update_content`
- `sync_content`
- `sync_taxonomies`
- `sync_authors`
- `preview_url`

Later capabilities:

- `media_upload`
- `webhooks`
- `delete_content`

## Security Requirements

### Token Storage

The connector token must be stored securely using WordPress options.

Requirements:

- Store only in an autoload-disabled option.
- Never display the token after save.
- Mask token state in the UI.
- Redact token from logs and admin notices.
- Delete token on disconnect.
- Allow replacement during token rotation.

Recommended option names:

- `argusly_connector_api_base_url`
- `argusly_connector_token`
- `argusly_connector_capabilities`
- `argusly_connector_last_health`
- `argusly_connector_last_pull`

Use `update_option($name, $value, false)` for sensitive or bulky options so they are not autoloaded.

### Capability Checks

Admin actions must require:

- `manage_options` for settings, connection tests, registration, and manual pulls.
- Nonce verification for every POST action.

Publishing worker actions run through WP-Cron and should not depend on a logged-in user. They must still validate that the plugin is configured, connected, and capability-enabled before publishing.

### Nonce Handling

Every admin form must include a nonce.

Recommended nonce action names:

- `argusly_connector_save_settings`
- `argusly_connector_test_connection`
- `argusly_connector_register_site`
- `argusly_connector_manual_pull`
- `argusly_connector_disconnect`

### Sanitization

Sanitize all admin inputs:

- API base URL: `esc_url_raw`, require `https` except local development.
- Token: trim string, reject empty token when connecting.
- Numeric settings: cast and bounds-check.
- Checkbox settings: cast to boolean.

Sanitize all remote content before saving:

- Title: `sanitize_text_field`
- Slug: `sanitize_title`
- Excerpt: `wp_kses_post`
- Body/content: allow safe HTML with `wp_kses_post`, then pass through block rendering.
- Meta keys: allow only known Argusly-owned keys.
- Meta values: sanitize per type.
- Taxonomy names: `sanitize_text_field`
- Term slugs: `sanitize_title`

### Escaping

Escape all output:

- Text nodes: `esc_html`
- Attributes: `esc_attr`
- URLs: `esc_url`
- Textarea values: `esc_textarea`
- HTML content in admin logs: avoid rendering raw remote HTML.

### Error Handling

The plugin must not fatal on remote payload issues.

Requirements:

- Catch API and publishing exceptions.
- Log local error details with redaction.
- Report failed publishing actions to Argusly.
- Include a user-readable message in admin UI.
- Preserve enough context for debugging: publishing action UUID, content UUID, endpoint, HTTP status.
- Avoid leaking tokens, Authorization headers, raw cookies, or private site config.

## Payload Mappings

### Argusly Content Asset To WordPress Post/Page

| Argusly field | WordPress target | Notes |
| --- | --- | --- |
| `content.id` | `_argusly_content_id` post meta | Internal numeric ID for traceability. |
| `content.uuid` | `_argusly_content_uuid` post meta | Primary idempotency key. |
| `content.type` | `post_type` | `article` -> `post`; `page` and `landing_page` -> `page`; configurable fallback. |
| `content.status` | post meta | Do not blindly copy to `post_status`. Publishing action controls WP state. |
| `content.title` | `post_title` | Sanitized text. |
| `content.slug` | `post_name` | Sanitized slug. |
| `content.excerpt` | `post_excerpt` | Safe HTML or plain text. |
| `content.body` | `post_content` | Safe HTML/Gutenberg-compatible content. |
| `content.language` | post meta / multilingual plugin | Store even when no multilingual plugin is active. |
| `content.locale` | post meta / multilingual plugin | Store as `_argusly_locale`. |
| `content.market` | post meta | Store as `_argusly_market`. |
| `content.canonical_url` | SEO/canonical meta | See canonical mapping. |
| `content.translation_group_id` | post meta | Future hreflang and translation grouping. |
| `publishing_action.uuid` | `_argusly_last_publishing_action_uuid` | Prevent duplicate work. |

Post status mapping:

- `publish` action: use `publish` by default.
- `schedule` action: use `future` when `scheduled_at` is present.
- `update` action: preserve existing post status unless Argusly explicitly instructs otherwise.
- `unpublish` action: future support can set `draft` or `private`.

### Argusly SEO Metadata To WordPress Meta

Argusly `seo_metadata` should map to Argusly-owned meta and, where supported, SEO plugin fields.

Argusly-owned meta:

| Argusly SEO field | WP meta key |
| --- | --- |
| `title` | `_argusly_seo_title` |
| `description` | `_argusly_seo_description` |
| `robots` | `_argusly_seo_robots` |
| `og_title` | `_argusly_og_title` |
| `og_description` | `_argusly_og_description` |
| `og_image` | `_argusly_og_image` |
| `twitter_title` | `_argusly_twitter_title` |
| `twitter_description` | `_argusly_twitter_description` |

SEO plugin adapters should be optional:

- Yoast SEO
- Rank Math
- All in One SEO

If no supported SEO plugin is active, store Argusly meta only and optionally render canonical/SEO tags through a future front-end integration setting.

### Argusly Answer Blocks To HTML/Gutenberg Placeholder

Argusly `answer_blocks` should be appended or injected according to plugin settings.

Initial implementation:

- Render Answer Blocks as safe HTML sections.
- Add clear wrappers and data attributes.
- Store raw Answer Block payload in post meta for future block transforms.

Recommended HTML placeholder:

```html
<section class="argusly-answer-blocks" data-argusly-answer-blocks="true">
  <article class="argusly-answer-block" data-argusly-answer-block-uuid="...">
    <h2>Question</h2>
    <div class="argusly-answer">Answer HTML</div>
  </article>
</section>
```

Future Gutenberg support:

- Register `argusly/answer-block` block.
- Convert Answer Blocks into block comments.
- Preserve UUID and language as block attributes.

### Argusly Language To WordPress Locale/Meta

Always store:

- `_argusly_language`
- `_argusly_locale`
- `_argusly_market`
- `_argusly_translation_group_id`
- `_argusly_translated_from_uuid`

Optional multilingual plugin adapters:

- WPML
- Polylang
- TranslatePress

If a multilingual plugin is active:

- Map Argusly language code to the plugin language.
- Attach translation group relationships where possible.
- Keep Argusly meta as the source of truth for connector traceability.

If no multilingual plugin is active:

- Publish as normal WordPress content.
- Store language/locale metadata only.

### Argusly Canonical To WordPress Meta

Always store:

- `_argusly_canonical_url`

When a supported SEO plugin is active:

- Map canonical URL to the plugin-specific canonical field.

If no SEO plugin adapter exists:

- Store only `_argusly_canonical_url` in v2.
- A later rendering option may output canonical tags.

### Future Hreflang Support

Argusly pending content may include `hreflang`.

Initial v2 behavior:

- Store payload in `_argusly_hreflang`.
- Store translation group ID.
- Do not output front-end hreflang tags unless explicitly enabled.

Future behavior:

- Render hreflang tags.
- Integrate with multilingual plugin translation groups.
- Report canonical/hreflang URLs back to Argusly after publish.

### Media Later

Media is not part of the first implementation, but the architecture must reserve a `MediaService`.

Future media behavior:

- Download Argusly media assets.
- Insert into WordPress media library.
- Set featured image.
- Rewrite content image references.
- Report uploaded media IDs/URLs back to Argusly.

## Site Registration Flow

1. Admin enters API base URL and token.
2. Plugin calls `GET /connector/manifest`.
3. Plugin calls `GET /connector/capabilities`.
4. Plugin calls `POST /connector/register`.
5. Plugin stores last registration result.
6. Plugin sends initial health check.

Registration payload:

```json
{
  "endpoint_url": "https://example.com",
  "external_connector_id": "wordpress:https://example.com",
  "connector_version": "2.0.0",
  "capabilities": [
    "health_check",
    "receive_content",
    "publish_content",
    "update_content",
    "sync_content",
    "sync_taxonomies",
    "sync_authors",
    "preview_url"
  ],
  "metadata": {
    "wordpress_version": "6.x",
    "php_version": "8.x",
    "site_url": "https://example.com",
    "home_url": "https://example.com",
    "multisite": false,
    "active_theme": "theme-name"
  }
}
```

Registration must be safe to retry.

## Pending Content Worker

Worker flow:

1. Confirm connector is configured.
2. Confirm `receive_content` capability is enabled.
3. Pull `GET /content/pending?limit=25`.
4. For each item, acquire a lock by publishing action UUID.
5. Dispatch to `PublisherService`.
6. Release lock.
7. Update last pull metadata.
8. Send health.

Publishing flow:

1. Validate required payload fields.
2. Decide post type.
3. Find existing post by `_argusly_content_uuid`.
4. Apply create/update action.
5. Apply SEO metadata.
6. Apply language/locale metadata.
7. Apply canonical metadata.
8. Apply Answer Blocks.
9. Sync terms/authors when payload includes them.
10. Report success or failure.

## Reporting Back To Argusly

Success payload should include:

```json
{
  "external_id": "123",
  "external_url": "https://example.com/example-article/",
  "published_at": "2026-05-31T10:00:00Z",
  "response": {
    "post_id": 123,
    "post_type": "post",
    "post_status": "publish"
  },
  "external_canonical_url": "https://example.com/example-article/",
  "language": "en",
  "locale": "en_US"
}
```

Failure payload should include:

```json
{
  "message": "WordPress validation failed: post title is missing.",
  "response": {
    "code": "wp_insert_post_failed"
  },
  "language": "en",
  "locale": "en_US"
}
```

## Taxonomy Sync

Initial taxonomy support:

- Categories
- Tags
- Optional custom taxonomies configured in settings

Behavior:

- Create missing terms only when enabled.
- Match by slug first, then name.
- Store Argusly term IDs in term meta where provided.
- Never delete WordPress terms in v2.

## Author Sync

Initial author support:

- Map Argusly author email to WordPress user.
- Fall back to configured default author.
- Optionally create users only when explicitly enabled.

Security:

- Do not create administrator users from remote payloads.
- New users should use the lowest practical role, usually `author` or `contributor`.
- Sanitize display names and emails.

## Admin UX States

The settings page should show clear states:

- Not configured
- Token saved, not tested
- Connected
- Connected with missing capabilities
- Degraded
- Failed
- Token expired/revoked

Each state should include a concrete next action.

## Testing Plan

Unit tests:

- API client header construction.
- Token redaction.
- Payload mapping.
- SEO meta mapping.
- Answer Block rendering.
- Capability gating.
- Error normalization.

Integration tests:

- Register site flow.
- Pull pending content.
- Publish new post.
- Update existing post.
- Report success.
- Report failure.
- Cron lock prevents duplicate processing.
- Token missing/invalid stops worker.

WordPress test environment:

- Use the WordPress core test suite or a maintained plugin test harness.
- Mock Argusly API responses.
- Include tests for Yoast/Rank Math adapters only when those plugins are available in the test matrix.

Manual QA:

- Fresh install.
- Token rotation.
- Disconnected token.
- Pending content publish.
- Pending content update.
- Failed payload report.
- Health check status changes.
- Admin escaping with hostile payload strings.

## Release Plan

1. Create separate repository `argusly-wordpress-plugin`.
2. Scaffold plugin headers and autoloading.
3. Implement settings and secure token storage.
4. Implement API client.
5. Implement registration and capabilities.
6. Implement health checks.
7. Implement cron worker and locks.
8. Implement publish/update mapping.
9. Implement reporting.
10. Implement taxonomy and author sync.
11. Add SEO, language, canonical, and Answer Block mapping.
12. Add tests and CI.
13. Package a signed release artifact.
14. Document installation in Argusly connector docs.

## Open Decisions

- Whether media is v2.1 or v3.
- Whether to render canonical tags without an SEO plugin.
- Which multilingual plugin adapter ships first.
- Whether the plugin should use Action Scheduler instead of WP-Cron for higher-volume sites.
- Whether to store logs in a custom table or bounded option history.
