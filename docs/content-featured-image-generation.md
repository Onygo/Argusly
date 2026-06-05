# Featured Image Generation (MVP)

## Overview
- Adds AI generated featured images for `Content` records in the app portal.
- Adds OG image generation (1200x630) with server-side title overlay rendering.
- Scope is limited to featured images only.
- Flow is asynchronous and requires a running queue worker.

## Enablement
Set environment variables (optional defaults shown in `config/publishlayer.php`):

- `PUBLISHLAYER_AI_IMAGE_PROVIDER=openai`
- `PUBLISHLAYER_AI_IMAGE_CREDIT_COST=6`
- `PUBLISHLAYER_AI_IMAGE_STORAGE_DISK=public`
- `PUBLISHLAYER_AI_IMAGE_OPENAI_BASE_URL=https://api.openai.com/v1`
- `PUBLISHLAYER_AI_IMAGE_MODEL=gpt-image-1`
- `PUBLISHLAYER_AI_IMAGE_SIZE=1536x1024`
- `PUBLISHLAYER_AI_IMAGE_QUALITY=medium`
- `PUBLISHLAYER_AI_IMAGE_TIMEOUT_SECONDS=90`
- `OPENAI_API_KEY=...`
- `PUBLISHLAYER_OG_FONT_PATH=/absolute/path/to/font.ttf` (optional override)

## Queue requirement
Run a worker for the generation queue:

```bash
php artisan queue:work --queue=generation,default --timeout=3600
```

## UX flow
- Open content detail page and go to `Images` tab.
- Click `Generate featured image`.
- Status goes from `queued` -> `generating` -> `ready` (or `failed`).
- Credits are debited when generation succeeds.
- Click `Push to WordPress featured image` when ready and connector refs are available.
- Click `Generate OG image` to render a branded social image.
- OG flow uses a textless background and a fixed server-side template:
  - keyword line once (optional when it already appears in title),
  - title line once,
  - adaptive dark overlay for readability,
  - Arial/Helvetica Bold system font paths (or custom `PUBLISHLAYER_OG_FONT_PATH`).
- OG generation uses `0` extra credits, but may create a featured background first if missing.

## OG template options
- Template options are centralized in `config/og_image.php`.
- Use env overrides for spacing, font sizes, and overlay opacity when needed.

## Storage
- Files are stored on configured disk under:
  - `content-images/{content_id}/...`
