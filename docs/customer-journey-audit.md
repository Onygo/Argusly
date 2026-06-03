# Argusly Customer Journey Audit

Date: 2026-06-02

Scope: first customer path from public marketing site through first published article, including signup/pilot signup, account and brand setup, team invitation, workspace onboarding, Knowledge Center, properties, publishing channels, content, audit, answer blocks, translations, recommendations, publishing, connectors, public blog output, and reporting.

## Executive Summary

A completely new customer cannot currently use Argusly end to end from signup to first published article without internal admin/manual provisioning.

The product has a strong authenticated product foundation for a pre-provisioned tenant: dashboard, content assets, knowledge center, audits, translations, answer blocks, fake-provider publishing, connector registration, and public blog rendering all exist. However, the first-customer journey is blocked before product use because there is no working self-serve signup, no pilot intake submit path, no invitation/password setup flow, no customer-facing account or brand creation, and no customer-facing property or publishing-channel creation.

If an admin manually creates or seeds the user, account, brand, modules, credits, properties, and channels, a customer with publisher permissions can create, approve, and queue a first article for fake publishing. Real connector publishing still requires careful manual setup of an active connector installation tied to a channel with `publish_content`.

## Validation Performed

- Static route/controller/view audit across marketing, auth, settings, content, connector, publishing, dashboard, reporting, and admin surfaces.
- Focused feature test run:
  - Command: `php artisan test tests/Feature/AuthenticationTest.php tests/Feature/MarketingSiteTest.php tests/Feature/PublishingActionTest.php tests/Feature/ConnectorPublishingQueueTest.php tests/Feature/BrandKnowledgeCenterTest.php`
  - Result: 21 tests, 20 passed, 1 failed.
  - Failure: `AuthenticationTest::test_login_form_can_be_rendered` expects `Sign in to your workspace`, while the current login view renders `Welcome back` and `Sign in to continue to your workspace.`
- No product code changes were made.

## Journey Verdict

| Step | Status | Priority | Notes |
| --- | --- | --- | --- |
| 1. Marketing site | Partial | High | Public pages render, but primary CTA routes to login instead of signup/trial. |
| 2. Signup / pilot signup | Blocked | Critical | No public registration, pilot form, or intake submit route found. |
| 3. Account creation | Blocked for customers | Critical | Account creation exists only in platform admin. |
| 4. Brand creation | Blocked for customers | Critical | Brand creation exists only in platform admin; customer settings brand page is read-only. |
| 5. User invitation | Blocked | Critical | Team screen explicitly says invitations will be added later. |
| 6. First login | Partial | High | Login works for pre-created users, but forgot password, Google auth, signup, and password setup are dead links/buttons. |
| 7. Workspace setup | Partial | High | Dashboard exists but is not an onboarding checklist and depends on existing tenant context. |
| 8. Brand Knowledge setup | Works after brand exists | Medium | Knowledge Center supports profile, product, service, and narrative input. |
| 9. Property creation | Blocked | Critical | Properties screen is read-only and says creation is a placeholder. |
| 10. Publishing channel creation | Blocked | Critical | Channels screen is read-only except connector assignment; seeded channels only exist for seeded brands. |
| 11. Content creation | Works after provisioning | Medium | Content create/edit exists for users with module and permissions. |
| 12. Content audit | Partial | Medium | Audit can be queued, but UI labels it deterministic placeholder scoring. |
| 13. Answer blocks | Works after provisioning | Medium | CRUD exists and can attach to content assets. |
| 14. Translation | Partial | Medium | Translation draft creation exists, but depends on configured enabled languages and credits. |
| 15. Recommendation generation | Partial | High | Recommendations display/action infrastructure exists, but first-user generation is not guided and empty by default. |
| 16. Publishing workflow | Partial | High | Fake/manual publishing path can queue; real channel path requires connector/channel setup that customers cannot fully complete. |
| 17. Connector workflow | Partial | High | Connector registration and token creation exist, but setup depends on missing property/channel creation and manual capability/status choices. |
| 18. Public blog output | Partial | Medium | Public blog renders published/approved article assets, but this is not connected to a customer's own domain/channel by default. |
| 19. Reporting visibility | Partial | Medium | Reporting/analytics views exist, but first customer sees empty states until integrations/syncs are configured. |

## Critical Findings

### CR-1: No self-serve signup or pilot signup path

Evidence:
- Marketing CTAs `Start monitoring` link to `route('login')`, not a registration or pilot intake route (`resources/views/marketing/home.blade.php:41`, `resources/views/marketing/home.blade.php:228`).
- Guest auth routes only define `/login` GET/POST (`routes/web.php:94`, `routes/web.php:95`).
- Login view has `Sign up`, `Forgot password`, and `Continue with Google` UI, but they are `#` links or non-submitting buttons (`resources/views/auth/login.blade.php:72`, `resources/views/auth/login.blade.php:91`, `resources/views/auth/login.blade.php:94`).
- Admin has a `pilot_signups` listing/count, but no public route or table migration was found for creating pilot signups (`app/Http/Controllers/Admin/AdminControlCenterController.php:447`, `app/Http/Controllers/Admin/AdminControlCenterController.php:525`).

Impact: A new customer cannot enter the product from the marketing site.

### CR-2: Account and brand creation are platform-admin-only

Evidence:
- Account creation route is under `/admin/accounts` and protected by `auth` plus `platform.admin` (`routes/web.php:116`).
- Brand creation route is under `/admin/brands` and protected by `auth` plus `platform.admin` (`routes/web.php:121`).
- Customer brand settings page lists brands but provides no create/edit form; it only shows an empty state when no brands exist (`resources/views/app/settings/brands.blade.php`).

Impact: Even if a new user exists, they cannot create the account/brand context required by the app middleware and dashboard.

### CR-3: No invitation or first-password setup flow

Evidence:
- Admin can assign memberships only to existing users (`routes/web.php:126`, `routes/web.php:127`; `app/Http/Controllers/Admin/AdminControlCenterController.php:222`, `app/Http/Controllers/Admin/AdminControlCenterController.php:247`).
- Team settings explicitly says: "Invitations will be added later. No email or role mutation is performed on this screen." (`resources/views/app/settings/team.blade.php:47`).
- No customer-facing invite acceptance, password setup, password reset, or email verification route was found.

Impact: A customer admin cannot invite a teammate, and a newly provisioned user has no guided way to set credentials.

### CR-4: Customers cannot create properties or publishing channels

Evidence:
- Property settings only has `GET /settings/properties`; there is no store/update route for properties in customer settings (`routes/web.php:719`).
- Properties screen says property creation and verification are a placeholder (`resources/views/app/settings/properties.blade.php:32`).
- Channel settings only has `GET /settings/channels` and `PATCH /settings/channels/{channel}` to assign a connector; there is no channel creation route (`routes/web.php:723`, `routes/web.php:726`).
- Channels screen says channels will appear after connector setup is implemented when none exist (`resources/views/app/settings/channels.blade.php:22`).
- `PublishingFoundationSeeder` creates demo properties/channels only for brands that already exist during seeding (`database/seeders/PublishingFoundationSeeder.php:13`, `database/seeders/PublishingFoundationSeeder.php:37`, `database/seeders/PublishingFoundationSeeder.php:53`).

Impact: A real first customer cannot configure the website target needed for a meaningful first published article.

## High Findings

### HI-1: Marketing promises trial/self-serve value but sends users to login

The home page claims "No credit card", "14-day trial", and "Start monitoring", but the only action is login. This creates immediate trust friction because the next screen assumes an existing account.

Priority: High

### HI-2: First-login screen contains dead affordances

`Forgot password`, `Sign up`, and `Continue with Google` appear available but are not wired. This is worse than hiding them because first-time users will try exactly those paths.

Priority: High

### HI-3: Workspace onboarding is informational, not procedural

The dashboard shows modules, credits, Knowledge Center completeness, graph health, visibility, integrations, topics, mentions, recommendations, and reports. It does not provide a sequenced "finish setup" path for the first article: create brand profile, create property, create channel, connect/pick connector, create content, audit, approve, publish, verify public output, view reporting.

Priority: High

### HI-4: Real connector publishing is easy to misconfigure

Connector registration exists (`routes/web.php:703`) and token creation exists (`routes/web.php:709`), but real publishing through a connector requires:

- A channel with provider matching the connector type.
- An active connector installation tied to that channel.
- `publish_content` enabled.
- A channel update to select that connector.
- A connector-side callback or poller to complete queued publishing.

The service enforces active connector/capability checks (`app/Services/PublishingService.php:266`, `app/Services/PublishingService.php:272`, `app/Services/PublishingService.php:350`). Tests confirm publishing is blocked without an active connector (`tests/Feature/PublishingActionTest.php:148`).

Priority: High

### HI-5: Recommendations are visible but not reliable for first value

The dashboard has recommendation stats and cards, but empty state says recommendations appear only as intelligence signals identify actions (`resources/views/app/dashboard.blade.php:337`). A new customer has no guided trigger to create initial recommendations from brand profile/content setup.

Priority: High

### HI-6: Permissions and modules can block core first-article tasks

Content, connectors, visibility, campaigns, and reporting sections are gated by modules and permissions. This is correct architecturally, but first-customer provisioning has no default customer-safe bundle visible outside seed/admin flows. A user without `publish_content`, credits, active content module, or brand membership will hit forbidden states or hidden navigation.

Priority: High

## Medium Findings

### ME-1: Content creation works, but the form lacks first-article guidance

The content form supports title, slug, excerpt, body, type, language, locale, source, source URL, and canonical URL. It does not explain what is required for public publishing, how canonical URL should be used, or whether property/channel assignment is expected later.

Priority: Medium

### ME-2: Publish button uses fake/manual provider by default

The direct publish button calls the publish route without a channel (`routes/web.php:463`), so `PublishingService` can queue fake/manual publishing and generate a fake external URL. The UI even says "Queue a fake publishing action" in empty state (`resources/views/app/content/show.blade.php:325`), and the service response says fake provider completed the action (`app/Services/PublishingService.php:127`).

Priority: Medium

### ME-3: Public blog output is not tenant/domain aware

The public blog renders any article content asset with status `published` or `approved` (`app/Http/Controllers/MarketingController.php:25`). There is no first-customer routing that maps a customer's brand/property/channel to their own blog output. This is enough for a global demo blog, not a customer publishing destination.

Priority: Medium

### ME-4: Audit and generation messaging exposes placeholder state

The content detail page describes audit as deterministic placeholder scoring and generation as static foundation runs with no real AI provider connected (`resources/views/app/content/show.blade.php:439`, `resources/views/app/content/show.blade.php:515`). Useful for foundation testing, but confusing for a customer expecting production-grade audit/recommendation generation.

Priority: Medium

### ME-5: Translation works only after language setup, but there is no first-run language wizard

Translation creation route exists (`routes/web.php:478`) and the detail page can create translation drafts (`resources/views/app/content/show.blade.php:181`). However, target availability depends on brand/account language configuration. There is no onboarding step to choose markets/locales before the user discovers translation controls.

Priority: Medium

### ME-6: Reporting visibility is mostly empty for new tenants

Analytics and reporting routes exist, and content detail has GA4/Search Console blocks. A new customer sees empty states until Google integrations, GA4 properties, Search Console sites, and sync jobs are configured. This is expected, but onboarding should set expectations and provide next steps.

Priority: Medium

### ME-7: Focused test suite has one journey-adjacent failure

`AuthenticationTest::test_login_form_can_be_rendered` expects old login copy (`tests/Feature/AuthenticationTest.php:20`, `tests/Feature/AuthenticationTest.php:24`). Current view renders different copy. This is minor technically, but it shows first-login coverage is not aligned with the current customer-facing screen.

Priority: Medium

## Low Findings

### LO-1: Demo credentials are exposed in login form

The email input defaults to `alpha.owner@example.com`. This is useful for demos but inappropriate for production onboarding unless gated by environment.

Priority: Low

### LO-2: Several settings screens disclose placeholder implementation status

Properties, team invitations, email providers, social profile sharing, content audits, and generation all use placeholder copy. This helps internal development but weakens customer confidence in a pilot journey.

Priority: Low

### LO-3: Brand settings has competitor placeholder inside brand card

The brand settings screen includes a competitor tracking placeholder. It is harmless but adds noise to first brand setup because the page cannot edit the brand anyway.

Priority: Low

## Missing Steps

- Public signup route and form.
- Pilot signup submit/store flow.
- Invite user form, invite email, token acceptance, and password setup.
- Forgot password flow.
- Optional OAuth login flow if the Google button remains visible.
- Customer-facing account creation or admin-assisted provisioning handoff.
- Customer-facing brand create/edit.
- First-run workspace checklist.
- Default module/credit provisioning for new accounts.
- Property creation and verification.
- Publishing channel creation.
- Channel selection during content creation/publishing.
- Connector setup wizard with health check and token copy guidance.
- Public output verification step after publish.
- Reporting setup checklist for GA4/Search Console.

## UX Friction

- Marketing CTA says start/trial but lands on login.
- Login screen is oriented to returning users.
- Dead auth links/buttons invite errors at exactly the first moment of trust.
- Dashboard is information-dense before a user knows what to do.
- Setup tasks live across Settings, Content, Distribution, Connectors, Integrations, and Reporting without a guided order.
- Publishing terminology mixes fake/manual/connector paths.
- Connector registration exposes low-level capabilities and status choices to customers.
- Public blog output is not clearly connected to the customer-owned property/channel.

## Broken or Blocked Flows

- New visitor to account: blocked.
- Pilot signup to admin review: blocked at public submit step.
- Admin creates account to customer login: blocked unless user is manually created and given credentials out of band.
- Customer creates brand: blocked.
- Customer invites teammate: blocked.
- Customer creates property: blocked.
- Customer creates publishing channel: blocked.
- Customer publishes through real connector: blocked until admin/customer manually stitches together channel, connector, token, capability, and external connector runtime.

## Existing Strengths

- Tenant-aware authenticated shell, account/brand resolution, module gating, and permission system are in place.
- Knowledge Center can capture brand profile, products, services, and narratives once a brand exists.
- Content asset creation/edit/show workflows are substantial.
- Audit, lifecycle, answer block, translation, generation, and publishing-action foundations exist.
- Public marketing blog can render published/approved article content assets.
- Connector API, connector token, publishing queue, and completion callback foundations exist.
- Admin Control Center can provision accounts, brands, modules, credits, and memberships manually.

## Suggested Priority Order

1. Critical: implement or intentionally remove self-serve signup/pilot signup claims.
2. Critical: add customer provisioning path covering account, brand, owner user, modules, credits, and first brand membership.
3. Critical: add invitation/password setup.
4. Critical: add property and publishing channel creation.
5. High: add first-run checklist from dashboard through first article.
6. High: turn connector setup into a guided wizard or hide advanced connector details behind an admin mode.
7. Medium: make publishing destination explicit on content creation/detail.
8. Medium: connect public blog output to customer property/channel or label current output as demo/global.
9. Medium: align tests with the intended first-login copy and add journey-level tests for signup/provision/content/publish.

