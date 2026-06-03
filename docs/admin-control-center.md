# Argusly Admin Control Center

The Admin Control Center is the global administration area for platform operators. It is available under `/admin` and is protected by the unscoped `manage_platform` permission through the `platform.admin` middleware.

## Structure

- Overview: platform metrics, connector health, failed publishing/outbox/projection counts, low-credit accounts and recent admin activity.
- Accounts: searchable account list, account creation, account detail, tenant activity, modules, credits, integrations, publishing channels, domain events, recommendations and signals.
- Brands: searchable and account-filterable brand list, brand creation, status and profile/knowledge-center indicators.
- Users: searchable user list plus account and brand membership assignment.
- Modules and Subscriptions: module catalog, account subscription inspection and manual module enable/disable.
- Credits: account wallets, transaction audit trail and manual credit adjustments.
- Integrations and Connectors: integration catalog, connections, connector manifests, installations, tokens, health checks and logs.
- Publishing Channels and Publishing Actions: publishing channel inspection and failed publishing troubleshooting.
- Pilot Signups: live table review if `pilot_signups` exists; otherwise a ready placeholder.
- Developer Tools: domain events, outbox messages, activity logs, connector logs, source syncs, graph nodes, graph edges and system health.

## Available Actions

- Create accounts and brands.
- Update account and brand status.
- Assign users to accounts with account-scoped roles.
- Assign users to brands with brand-scoped roles.
- Remove account memberships.
- Enable, disable or pause account modules.
- Manually grant or deduct credits with a required reason.
- Revoke connector tokens.
- Inspect failed publishing actions, outbox messages, projection runs and connector logs.

Payment-provider plan changes, invitation delivery, publishing retries, graph rebuild and graph verify command execution are intentionally placeholders in this validation phase.

## Permissions

Global admin access requires the `platform_admin` role or another global role assignment with `manage_platform`.

The role assignment must be unscoped:

- `account_id` is `null`
- `brand_id` is `null`

Account owners and account admins do not receive global admin access from tenant-scoped roles. Developer Tools are inside the same platform-admin middleware group, so non-platform admins cannot access or see them.

## Audit Rules

Admin write actions create activity logs. Manual credit changes also create a `CreditBalanceAdjusted` domain event with:

- the account
- the admin user
- amount
- balance after adjustment
- reason

Existing model-level domain events remain in place for models that already record them.

## Troubleshooting Guide

- Failed publishing: open `/admin/publishing-actions` to inspect content asset, channel, connector installation, payload, response and error message.
- Failed outbox: open `/admin/developer-tools/outbox-messages` to inspect status, attempts, availability and error.
- Failed projections: open `/admin/developer-tools/domain-events` and `/admin/developer-tools/system-health`.
- Connector issues: open `/admin/connectors` for installation health and `/admin/developer-tools/connector-logs` for logs.
- Graph projection: open `/admin/developer-tools/graph-nodes` or `/admin/developer-tools/graph-edges` and filter by account, brand, node type or relationship type.
- Low credits: open `/admin/credits`, inspect wallets and recent transactions, then record a manual adjustment if needed.

## Known Placeholders

- Pilot signup conversion and invitation flow.
- Payment-provider plan updates.
- Connector token rotation UI.
- Publishing retry execution.
- Graph rebuild and verify command execution.
- External connector/plugin code.
- Creator Intelligence.
