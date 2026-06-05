# PublishLayer (PL)

PublishLayer is a multi-tenant Laravel 12 platform for content operations with WordPress integration.

## Core Product Areas

- Public site: landing and pricing pages.
- Public blog: `/blog` and `/blog/{slug}` (connector-synchronized showcase content).
- Client app (`/app`): dashboard, content lifecycle, settings, taxonomy, network linking.
- Admin app (`/admin`): organizations, users, billing, site oversight, maintenance.

## Public Form Protection

Public contact submissions are protected with Google reCAPTCHA v2 checkbox verification.

- Required env vars:
  - `RECAPTCHA_SITE_KEY`
  - `RECAPTCHA_SECRET_KEY`
- Current usage:
  - Public contact form at `/contact` and `/company/contact`
- Verification flow:
  - Blade renders the widget with `config('services.recaptcha.site_key')`
  - `App\Services\Security\RecaptchaService` sends a server-side verification request to Google with the user token and remote IP when available
  - If verification fails or Google is unavailable, the submission is rejected with validation-style feedback and the contact flow stops before persistence
- Reuse for other public forms:
  - Include `<x-forms.recaptcha-script />` in the page head
  - Render `<x-forms.recaptcha />` inside the form
  - Require `g-recaptcha-response` in the form request and verify it through `RecaptchaService`
  - Apply the `throttle:public-form-submission` middleware or a stricter limiter where appropriate

## Multi-Tenant Model

- `organizations` own users and workspaces.
- `workspaces` own client sites and content.
- `client_sites` represent connected channels (WordPress).
- User access in client app is scoped by organization/workspace membership and role.

## Content Lifecycle

Unified content model:

- `contents` is the central editorial object.
- `content_versions` stores brief/draft/revision snapshots.
- Legacy `briefs` and `drafts` are still present for compatibility, with backfill support.

Typical flow:

1. WordPress plugin posts brief to `/api/v1/briefs`.
2. PL creates/updates `content` and brief records.
3. Draft generation runs via jobs.
4. Revisions are created in PL and can be restored.
5. Repush sends current content to WordPress webhook.

## Draft Compare (new)

Briefs now support multi-model draft generation and side-by-side comparison without replacing the existing single-draft flow.

- Start from brief detail (`/briefs/{brief}`) with mode:
  - single model
  - compare 2 models
  - compare multiple models
- One draft is created and queued per selected model.
- Compare runs are tracked in:
  - `draft_comparisons`
  - `draft_comparison_items`
- Comparison view includes:
  - provider/model
  - generation status
  - output preview
  - word count / read time
  - quality heuristics (brand voice match, CTA strength, structure quality)
  - per-item cost/tokens
- Users can select a winner draft and optionally queue a hybrid best version.

Implementation note:
- `drafts.brief_id` uniqueness was removed so a brief can hold multiple model variants.
- Existing single-draft idempotency remains enforced in the existing brief generation service flow.

## Image Derivatives and WebP

Content images now keep the original file plus generated derivatives:

- `original_path`
- `medium_path`
- `thumbnail_path`
- optional WebP siblings:
  - `original_webp_path`
  - `medium_webp_path`
  - `thumbnail_webp_path`

Behavior:

- UI prefers WebP URLs when available (`thumbnail_ui_url`, `medium_ui_url`).
- WordPress payloads default to safe non-WebP paths (`medium_path` then `original_path`).
- WordPress WebP is only used when the site explicitly reports support in `client_sites.capabilities`:
  - `supports_webp: true` or
  - `image_formats.webp: true`
- If the server cannot encode WebP, generation still succeeds and WebP paths remain null with a warning log.

## WordPress Connector APIs (existing)

These remain unchanged for plugin compatibility:

- `POST /api/v1/briefs`
- `GET /api/v1/briefs/{id}`
- `GET /api/v1/drafts`
- `GET /api/v1/drafts/{id}`
- `POST /api/v1/drafts/{id}/generate`
- `POST /api/v1/drafts/{id}/ack`
- `POST /api/v1/drafts/{id}/feedback`
- `POST /api/v1/events`

Auth: site token + allowed domain middleware.

## Connector Draft SEO Contract (pull-based)

For connector clients using `GET /api/v1/drafts` and `GET /api/v1/drafts/{id}`, SEO metadata is available in two forms:

- Legacy flat fields remain (`seo_title`, `seo_meta_description`, `seo_canonical`, ...)
- Normalized payload aliases are now included:
  - `primary_keyword` and `focus_keyword`
  - `meta_title`
  - `meta_description`
  - `canonical_url`
  - `og_image`
  - `seo` object with normalized SEO keys (including robots/schema fields)

This keeps backwards compatibility while giving Laravel connector consumers a single explicit SEO contract.

## Sites Setup Flow (client app)

Use `GET /app/sites` to connect and manage WordPress sites per workspace.

Flow:

1. Add site (`name`, `site_url`) in `/app/sites`.
2. PublishLayer creates a pending `client_site` and generates a site key.
3. Copy the key once and paste it in the WordPress plugin.
4. Plugin calls heartbeat and site status moves to `connected`.
5. Use brief and draft flows with site context and entitlement checks.

Site statuses:

- `pending`
- `connected`
- `error`
- `disabled`

## WordPress Auth Headers

Connector requests use:

- `Authorization: Bearer <site_key>`

Optional replay-protection headers:

- `X-PL-Timestamp: <unix_seconds>`
- `X-PL-Nonce: <unique_nonce>`

Replay protection is configurable in `config/publishlayer.php`:

- `wp_connector.require_timestamp_nonce`
- `wp_connector.timestamp_ttl_seconds`

When strict mode is disabled, older plugin versions without timestamp and nonce continue to work.

## Heartbeat Endpoint

`POST /api/wp/heartbeat`

Required body:

- `site_url`

Optional body:

- `wp_version`
- `plugin_version`
- `capabilities` (object)

Required scope on site key:

- `heartbeat:write`

## Key Rotation

In `/app/sites` and `/app/sites/{site}`, use Regenerate key to rotate credentials.

Behavior:

- New plaintext key is shown once.
- Stored keys are hashed at rest.
- Prior active keys for that site are revoked (`revoked_at` set).

## Plugin Licensing & Update APIs (new)

- `POST /api/v1/plugin/register-domain`
- `POST /api/v1/plugin/check-update`
- `GET /api/v1/plugin/download/{token}`
- `GET /api/v1/plugin/download-token/{token}`

Data model:

- `license_keys` (hashed keys, status, expiry, workspace binding)
- `workspace_domains` (registered/verified domains per workspace)
- `plugin_releases` (version metadata and zip path)

Security:

- License keys stored hashed (`sha256`).
- HMAC request signatures for update check (`X-PL-Timestamp`, `X-PL-Signature`).
- Short-lived encrypted download token (default 5 minutes).
- Rate limiting on plugin endpoints.
- Domain ownership enforcement before update/download.

## Editorial Link Intelligence

Available in app flow for editorial internal linking:

- Signal extraction (embeddings/entities).
- Relevance scoring (similarity, intent, audience overlap).
- Manual review/approve/apply workflow.
- Optional cross-domain suggestions with permission model.

## Queues & Scheduling

Queue is required for generation/delivery flows.

- Delivery dispatch scheduler: `drafts:dispatch-deliveries --limit=25` (every minute).
- Jobs include draft generation, delivery, and content signal updates.

Run workers:

```bash
php artisan queue:work --queue=ai-low,generation,agentic-marketing,intelligence,default,deliveries,billing,markdown,emails,brief-intelligence,research,content-network --timeout=3600
php artisan queue:work --queue=deliveries --timeout=120
```

## Product-Led Onboarding Flow

PublishLayer tracks onboarding progression in `onboarding_states` and uses event driven updates:

- registration
- first login
- first brief or draft
- first successful WordPress push

First value is reached when the first brief, draft, or successful WordPress push occurs. At that moment the onboarding phase moves to `activated` and a first value email is queued.

Daily inactivity checks run via:

```bash
php artisan onboarding:check-inactivity
```

Local scheduler testing:

```bash
php artisan schedule:work
```

Queue worker:

```bash
php artisan queue:work
```

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run dev
php artisan serve
```

## Useful Commands

```bash
# migrate legacy content structure
php artisan pl:migrate-content-structure

# backfill content versions from legacy briefs/drafts
php artisan pl:migrate-content-versions

# backfill link signals
php artisan link-intelligence:backfill-signals

# audit internal public-site links and route/view targets
php artisan public:link-audit

# promote an existing user to superadmin for /admin access control
php artisan pl:make-superadmin user@example.com
```

## Public Blog

Public blog documentation and configuration:

- `docs/public-blog.md`

## Tests

```bash
php artisan test
```

## Config

`config/publishlayer.php` includes:

- `admin_key`
- AI provider settings
- `plugin_updates`:
  - `disk`
  - `download_token_ttl_seconds` (defaults to 24 hours so WordPress update package URLs remain valid while the update transient is cached)
  - `signature_ttl_seconds`

`config/llm.php` includes:

- `default_provider` (`openai`, `anthropic`, `gemini`, `mistral`)
- provider credentials/base URLs/default models
- shared timeout and retry settings
- optional provider token pricing factors

Relevant env keys:

- `LLM_DEFAULT_PROVIDER`
- `OPENAI_API_KEY`, `OPENAI_BASE_URL`, `OPENAI_MODEL`
- `ANTHROPIC_API_KEY`, `ANTHROPIC_BASE_URL`, `ANTHROPIC_MODEL`, `ANTHROPIC_API_VERSION`
- `GEMINI_API_KEY`, `GEMINI_BASE_URL`, `GEMINI_MODEL`
- `MISTRAL_API_KEY`, `MISTRAL_BASE_URL`, `MISTRAL_MODEL`

Mistral integration details:

- base URL default: `https://api.mistral.ai/v1`
- chat endpoint: `POST /chat/completions`
- auth: `Authorization: Bearer {MISTRAL_API_KEY}`

Provider selection order:

1. Per-request/provider metadata override
2. Workspace override in `workspaces.visual_settings.llm.provider`
3. `LLM_DEFAULT_PROVIDER`

## Tech

- Laravel 12
- Eloquent + migrations
- Blade UI
- Queue jobs for async processing
- WordPress connector via signed webhooks and site tokens
