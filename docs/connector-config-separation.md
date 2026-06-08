# Argusly Config Separation

Argusly now separates server-side runtime config from connector-client config to avoid ambiguous `.env` usage.

## Server-side (`config/argusly.php`)

Used by the Argusly application itself:

- `argusly.webhooks.secret` (`ARGUSLY_WEBHOOK_SECRET`)
- `argusly.webhooks.queue` (`ARGUSLY_WEBHOOK_QUEUE`)
- `argusly.webhooks.connector_public_url` (`ARGUSLY_CONNECTOR_PUBLIC_URL`)
- `argusly.images.enabled` (`ARGUSLY_IMAGES_ENABLED`)
- `argusly.images.disk` (`ARGUSLY_IMAGES_DISK`)

## Connector-client (`config/argusly_connector.php`)

Used for outbound connector HTTP calls from Argusly (for example, connector-backed public blog requests):

- `argusly_connector.api.base_url` (`ARGUSLY_CONNECTOR_BASE_URL`)
- `argusly_connector.api.workspace_id` (`ARGUSLY_CONNECTOR_WORKSPACE_ID`)
- `argusly_connector.api.api_key` (`ARGUSLY_CONNECTOR_API_KEY`)

## Marketing keys

`ARGUSLY_MARKETING_BLOG_SOURCE_MODE` and `ARGUSLY_MARKETING_BLOG_SOURCE_ID` drive marketing blog source scoping.
