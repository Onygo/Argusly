# Argusly Connector for WordPress

Development-only first-party WordPress connector for Argusly. Do not publish or release this plugin yet.

## Purpose

This plugin lets a WordPress site store its Argusly API URL and token, run a connector health check, and expose placeholder endpoints for posts, content sync, health, and webhooks.

Argusly remains the source of truth for connector tokens, connector settings, site registration, content sync decisions, health checks, and webhook delivery.

## Development

Install the plugin by copying this directory into a local WordPress `wp-content/plugins/argusly-connector` directory, then activate **Argusly Connector** in WordPress.

Configure:

- Argusly API URL
- Argusly token

The token is issued by the Argusly platform. The plugin sends it as `Authorization: Bearer <token>` when calling Argusly.

API URL examples:

```text
https://api.argusly.com
https://staging.argusly.com
http://argusly.test
```

## Remote routes

The plugin exposes placeholder routes under `/wp-json/argusly/v1`:

- `GET /wp-json/argusly/v1/health`
- `GET /wp-json/argusly/v1/posts`
- `POST /wp-json/argusly/v1/posts`
- `POST /wp-json/argusly/v1/content/sync`
- `POST /wp-json/argusly/v1/webhooks/{event}`

Remote routes currently require `Authorization: Bearer <token>`.

## Platform contract

The health check calls `POST /api/v1/connectors/heartbeat`. Content sync and webhook route implementations are placeholders until the production payloads are finalized.

## Smoke checks

Until plugin-level automated tests are added, run:

```bash
php -l argusly-connector.php
```

Manual check in WordPress:

1. Activate **Argusly Connector**.
2. Set the Argusly API URL and token in Settings.
3. Run the health check from the settings page.
4. Call `/wp-json/argusly/v1/health` with `Authorization: Bearer <token>`.

## Compatibility

This plugin is Argusly-native. It does not use legacy connector names, headers, routes, or option keys.

## TODO

- TODO(argusly): Review final heartbeat request and response schema.
- TODO(argusly): Implement canonical post and content sync payload handling once production schemas are finalized.
- TODO(argusly): Implement signed webhook verification when the platform webhook contract is finalized.
