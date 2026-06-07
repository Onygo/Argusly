# Argusly Connector for WordPress

Development-only first-party WordPress connector for Argusly.

## Purpose

This plugin lets a WordPress site store its Argusly API URL and API key, run a connector health check, and expose placeholder endpoints for content sync and webhooks.

Argusly remains the source of truth for API keys, connector settings, site registration, content sync decisions, health checks, and webhook delivery.

## Development

Install the plugin by copying this directory into a local WordPress `wp-content/plugins/argusly-connector` directory, then activate **Argusly Connector** in WordPress.

Configure:

- Argusly API URL
- Argusly API key

The package is not released yet. Keep changes local to this directory until the connector API contracts are finalized.

## TODO

- TODO(argusly): Review final health-check request and response schema.
- TODO(argusly): Implement canonical content sync once the platform payload is finalized.
- TODO(argusly): Implement signed webhook verification when the platform webhook contract is finalized.
