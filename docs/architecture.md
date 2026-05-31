# Argusly Architecture Foundation

Argusly is a multi-tenant AI Visibility, Intelligence and Agentic Marketing SaaS built on Laravel 13 and PHP 8.4. This foundation intentionally avoids business features, billing flows, AI generation, OAuth and real integrations. It defines the first product boundaries so those features can be added without reshaping the app later.

## Application Shape

- Public marketing routes live in the default web route group and render Blade views under `resources/views/marketing`.
- Authenticated product surfaces should live behind auth middleware and render through `resources/views/components/app/layout.blade.php`.
- Reusable UI primitives live under `resources/views/components/ui`.
- Marketing-specific shell components live under `resources/views/components/marketing`.
- App-shell navigation components live under `resources/views/components/app`.
- Documentation lives under `docs`.

## Core Domain Concepts

### User

Represents a person who can authenticate and access one or more accounts. A user should not directly own product data. Access comes through memberships, account roles and brand permissions.

### Account

Represents a tenant workspace, company or organization. Accounts contain brands, members, subscriptions, credit wallets and integrations.

### Brand

Represents a monitored entity inside an account. A single account can manage multiple brands. Brand-level permissions allow a user to be an editor on one brand and a viewer on another.

### Membership

Connects users to accounts. Stores account role, status, invitation metadata and account-level preferences.

### Role

Defines account-level responsibility such as owner, admin, editor, analyst or viewer. Roles are assigned per account membership.

### Permission

Defines granular capabilities, especially at brand scope. Examples: view visibility, manage content, approve agent actions, manage integrations, export reports.

### Module

Represents product areas that can be enabled, disabled, limited or billed separately. Initial modules: visibility, intelligence, competitors, mentions, content, campaigns, automations, reports and settings.

### Subscription

Represents the commercial plan for an account. This should remain account-scoped and decoupled from billing provider implementation.

The current subscription architecture is provider-neutral and ready for a future Mollie adapter:

- `plans` define commercial packages and billing intervals.
- `modules` define product capability flags.
- `module_plan` defines which modules ship with each plan.
- `subscriptions` belong to accounts and store billing state.
- `subscription_modules` are account entitlements copied from a plan or enabled as add-ons.

Supported billing intervals:

- monthly
- yearly

Initial modules:

- core
- visibility
- content
- social
- campaigns
- competitive_intelligence
- lead_intelligence
- agentic_content
- agentic_social

`SubscriptionService` activates plans, syncs plan modules onto subscriptions, activates add-on modules and cancels active subscriptions. `ModuleAccessService` checks whether an account has an active module entitlement. Provider fields on `subscriptions` are nullable until Mollie integration is implemented.

### Credit Wallet

Stores metered usage capacity for an account. Credits can later fund AI analysis, monitoring runs, exports or agent actions.

### Integration

Represents external systems connected to an account or brand. Examples include search data providers, social accounts, analytics, CMS platforms and CRMs.

The current integration architecture is provider-neutral and ready for future OAuth adapters:

- `integrations` is the provider catalog.
- `integration_connections` stores user-owned external accounts.
- `integration_permissions` stores explicit sharing grants.

Initial provider catalog:

- LinkedIn
- Google
- WordPress
- Meta
- X
- YouTube

Connection ownership and sharing:

- Every connection is owned by a user through `owner_user_id`.
- Connections can optionally be associated with an account and brand.
- Connections can be shared with a user, account or brand.
- Brand sharing is account-safe; a connection tied to Account A cannot be shared to a brand in Account B.
- A user can use an account-shared connection only with active account membership.
- A user can use a brand-shared connection only with active brand membership.

OAuth architecture:

- Access tokens are encrypted.
- Refresh tokens are encrypted.
- Token payloads are encrypted arrays.
- Scopes are stored as structured arrays.
- Token expiry and revocation timestamps are tracked.
- No provider-specific OAuth logic is implemented yet.

Use `IntegrationConnectionService` to create or revoke connections. Use `IntegrationPermissionService` to share connections and check whether a user can use or manage them.

## Suggested Data Model

The first migrations should introduce these tables:

- `accounts`
- `brands`
- `memberships`
- `roles`
- `permissions`
- `brand_user_permissions`
- `modules`
- `subscriptions`
- `subscription_modules`
- `credit_wallets`
- `integrations`
- `integration_connections`
- `integration_permissions`

Recommended relationships:

- `users` belongs to many `accounts` through `memberships`.
- `accounts` have many `brands`.
- `accounts` have many `memberships`.
- `memberships` belong to `users`, `accounts` and `roles`.
- `brands` belong to `accounts`.
- `users` belong to many `brands` through a brand permissions pivot.
- `plans` belong to many `modules` through `module_plan`.
- `accounts` have many `subscriptions`.
- `subscriptions` have many `subscription_modules`.
- `accounts` have one active `subscription`.
- `accounts` have one `credit_wallet`.
- `integrations` have many user-owned `integration_connections`.
- `integration_connections` have many `integration_permissions`.
- `integration_permissions` can target users, accounts or brands.

## Authorization Model

Use layered authorization:

1. Account membership confirms tenant access.
2. Account role grants broad account capabilities.
3. Brand permissions grant granular brand capabilities.
4. Module availability controls whether a surface can be used.

Examples:

- User A can be owner in Account 1 and viewer in Account 2 through two membership rows.
- User B can be editor for Brand X and viewer for Brand Y through brand permission assignments.

Laravel policies should be written around account and brand scope rather than global user assumptions.

The current implementation provides the first scalable permission system:

- `roles`, `permissions`, `role_permissions` and `user_roles` tables.
- `PermissionService` for role and permission resolution.
- Laravel Gates for configured permission slugs.
- `UserPolicy` as the first policy wired through permissions.
- `permission` and `role` middleware aliases.
- Seeded system roles: owner, admin, manager, editor, viewer, billing and external.

Role checks should not be hardcoded in controllers or views. Use permission gates where possible:

```php
Gate::allows('manage_billing');
$user->can('edit_content');
```

Routes can use:

```php
Route::middleware('permission:manage_users')->group(...);
Route::middleware('role:owner,admin')->group(...);
```

`owner` receives all permissions through the `all_permissions` role flag rather than a role-name exception. Role assignments can be global or scoped with nullable `account_id` and `brand_id` columns until first-class account and brand tables are introduced.

## Tenant Boundary

All product queries should be scoped by account. Brand-scoped product data should include both `account_id` and `brand_id` where practical to keep authorization and indexing explicit.

Avoid relying on a global current tenant helper inside low-level models. Prefer passing account or brand context into services and queries.

The current tenant context implementation includes:

- `accounts`, `brands`, `memberships` and `brand_memberships` tables.
- `CurrentAccountContract` and `CurrentBrandContract`.
- `CurrentAccount` and `CurrentBrand` services.
- `ResolveCurrentAccount` and `ResolveCurrentBrand` middleware.
- Tenant context helpers: `current_account()`, `current_account_id()`, `current_brand()` and `current_brand_id()`.
- Account and brand switch endpoints: `tenant.account.switch` and `tenant.brand.switch`.
- `BelongsToAccount` and `BelongsToBrand` model traits with global scopes for future tenant-owned models.

Tenant context is stored in both session and cache. Session is the primary request context; cache keeps a user's last selected account and brand across browser sessions. Cached IDs are always revalidated against active memberships before use.

Use tenant-safe model traits on product data:

```php
use App\Models\Concerns\BelongsToAccount;
use App\Models\Concerns\BelongsToBrand;

class Mention extends Model
{
    use BelongsToAccount;
    use BelongsToBrand;
}
```

These traits apply global scopes using the current account and brand. In web requests with no resolved context, scoped models return no rows instead of leaking cross-account data. In console contexts, scopes are non-destructive so migrations, queues and maintenance scripts can explicitly choose their tenant context.

Account switching clears the active brand context. Brand switching requires both an active brand membership and active access to the brand's account.

## Service Boundaries For Later

Future features should be grouped into application services by product capability:

- Visibility monitoring
- Brand intelligence
- Competitive intelligence
- Search intelligence
- Content orchestration
- Agentic marketing
- Reporting
- Integrations
- Billing and usage

Each service should accept account and brand context explicitly, emit domain events for important state changes and avoid coupling directly to controllers.

## Current Implementation

This first step includes:

- Public `/` marketing homepage.
- Static `/dashboard` app shell preview.
- Anonymous Blade components for shared UI.
- Tailwind v4 design tokens.
- Static mock data only.

Authentication, database migrations and business workflows are intentionally deferred.
