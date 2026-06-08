# Sites Setup Audit (Phase 0)

## Existing models and tables
- `app/Models/ClientSite.php` with table from `database/migrations/2026_01_22_200842_create_client_sites_table.php`
  - Current fields: `id`, `workspace_id`, `type`, `name`, `site_url`, `allowed_domains`, `is_active`, timestamps.
  - Tenant relation already present through `workspace`.
- `app/Models/SiteToken.php` with table from `database/migrations/2026_01_22_200908_create_site_tokens_table.php`
  - Current fields: `id`, `client_site_id`, `token_hash`, `scopes`, `revoked`, `last_used_at`, timestamps.
- Workspace and organization
  - `app/Models/Workspace.php` belongs to `Organization` and has many `ClientSite`.
  - `app/Models/Organization.php` has many `workspaces` and `clientSites` (through workspace).
- Users
  - `app/Models/User.php` belongs to `Organization` and uses roles (`owner`, `admin`, member roles).

## Existing WordPress connector routes and flow
- API routes in `routes/api.php` under `/api/v1`:
  - Site token admin endpoints: `/auth/site-tokens*`.
  - Token protected endpoints (`site.token` + `client.domain`):
    - `/briefs` (`App\Http\Controllers\Api\V1\BriefController`)
    - `/drafts*` (`App\Http\Controllers\Api\V1\DraftController`)
    - taxonomy/options/events endpoints.
- Incoming brief creation and draft state updates:
  - `app/Http/Controllers/Api/V1/BriefController.php`
  - `app/Http/Controllers/Api/V1/DraftController.php`
- Outbound push to WordPress:
  - `app/Services/DraftDelivery/DeliverDraftToWordPress.php`
  - `app/Jobs/DeliverDraftJob.php`

## Current permissions
- Route middleware for app area: `auth`, `user.approved`, `user.org`.
- Gates in `app/Providers/AppServiceProvider.php`:
  - `manage-organization` for owner/admin (and platform admin).
  - `view-organization`, `manage-cross-link-permissions`.
- Admin routes protected by `platform.admin` middleware.

## Current package and entitlement system
- Billing models and plans in DB (`plans`, `subscriptions`, `credit_*`).
- Workspace/plan features and entitlements now exist:
  - `plan_features`, `workspace_entitlements`.
  - `app/Services/Entitlements/FeatureGate.php`
  - `app/Services/Entitlements/EntitlementRefreshService.php`
- Existing plan feature key already present: `wp_sites_limit` (seeded in `database/seeders/PlansSeeder.php`).

## Reuse vs add
### Reuse
- Reuse `ClientSite` and `SiteToken` foundations.
- Reuse existing `/api/v1/briefs`, `/api/v1/drafts`, and outbound delivery service.
- Reuse `FeatureGate` and existing billing plan/entitlement layer.
- Reuse app shell/nav and existing Tailwind components.

### Add
- Extend `client_sites` to include setup/health status metadata and normalized URL.
- Extend `site_tokens` for workspace scoping, key metadata, revocation metadata, and IP usage.
- Add workspace monthly usage counters for briefs/drafts quota enforcement.
- Add workspace entitlement enforcement service for `max_sites`, brief/draft quotas, and push permission.
- Add connector heartbeat endpoint (`/api/v1/connectors/heartbeat`) and app-side test connection action.
- Expand `/app/sites` into full setup flow: add site, generate key once, status, details, key rotation, enable/disable, removal.
- Add WP token replay protection support (`X-Argusly-Timestamp`, `X-Argusly-Nonce`) with canonical Argusly config.
- Add focused admin visibility columns for site status/version/last seen.
