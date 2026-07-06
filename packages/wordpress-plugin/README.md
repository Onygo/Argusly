# Argusly Connector for WordPress

First-party WordPress connector for publishing Argusly content into WordPress.

## Installation

Copy this directory to `wp-content/plugins/argusly-connector`, then activate **Argusly Connector** in WordPress.

Configure these settings under **Settings > Argusly Connector**:

- Argusly API URL
- Argusly site token

The token is issued by Argusly. The plugin uses it for outbound heartbeat calls and validates inbound REST calls with `Authorization: Bearer <token>` or `X-Argusly-API-Key: <token>`.

## REST Routes

All routes are under `/wp-json/argusly/v1` and require bearer-token authentication.

- `GET /ping`
- `GET /health`
- `GET /heartbeat`
- `POST /posts`
- `POST /webhook/draft`
- `GET /posts/{id}`
- `POST /posts/{id}`
- `GET /webhook/draft/{id}`
- `POST /webhook/draft/{id}`
- `GET /posts/lookup`
- `POST /posts/{id}/featured-image`

The post routes create, update, read, and look up WordPress posts. The `/webhook/draft` routes are backwards-compatible aliases for older Argusly webhook URLs. The plugin stores Argusly identity fields, SEO meta, answer-block metadata, AI-transparency metadata, and sync policy metadata in post meta for stable future updates.

The post endpoints accept both the classic WordPress post payload and the newer `article`-wrapped payload shape used by Argusly connector syncs.

## Heartbeat

The settings-page health check calls:

- `POST /api/v1/connectors/heartbeat`

The heartbeat reports the WordPress version, PHP version, site URL, active plugins, and connector capabilities.

## Verification

```bash
php -l argusly-connector.php
```

Manual check in WordPress:

1. Activate **Argusly Connector**.
2. Set the Argusly API URL and site token.
3. Run the health check from the settings page.
4. Call `/wp-json/argusly/v1/health` with `Authorization: Bearer <token>`.
