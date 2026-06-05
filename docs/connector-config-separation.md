# PublishLayer Config Separation

PublishLayer now separates server-side runtime config from connector-client config to avoid ambiguous `.env` usage.

## Server-side (`config/publishlayer.php`)

Used by the PublishLayer application itself:

- `publishlayer.webhooks.secret` (`PL_WEBHOOK_SECRET`, legacy `PUBLISHLAYER_WEBHOOK_SECRET`)
- `publishlayer.webhooks.queue` (`PL_WEBHOOK_QUEUE`, legacy `PUBLISHLAYER_WEBHOOK_QUEUE`)
- `publishlayer.webhooks.connector_public_url` (`PL_CONNECTOR_PUBLIC_URL`, legacy `PUBLISHLAYER_CONNECTOR_PUBLIC_URL`)
- `publishlayer.images.enabled` (`PL_IMAGES_ENABLED`, legacy `PUBLISHLAYER_IMAGES_ENABLED`)
- `publishlayer.images.disk` (`PL_IMAGES_DISK`, legacy `PUBLISHLAYER_IMAGES_DISK`)

## Connector-client (`config/publishlayer_connector.php`)

Used for outbound connector HTTP calls from PublishLayer (for example, connector-backed public blog requests):

- `publishlayer_connector.api.base_url` (`PL_CONNECTOR_BASE_URL`, legacy `PUBLISHLAYER_BASE_URL`)
- `publishlayer_connector.api.workspace_id` (`PL_CONNECTOR_WORKSPACE_ID`, legacy `PUBLISHLAYER_WORKSPACE_ID`)
- `publishlayer_connector.api.api_key` (`PL_CONNECTOR_API_KEY`, legacy `PUBLISHLAYER_API_KEY`)

## Marketing keys

`PL_MARKETING_BLOG_SOURCE_MODE` and `PL_MARKETING_BLOG_SOURCE_ID` are unchanged and still drive marketing blog source scoping.

