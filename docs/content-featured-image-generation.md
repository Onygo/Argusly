# Featured Image Generation (MVP)

## Overview
- Adds AI generated featured images for `Content` records in the app portal.
- Adds OG image generation (1200x630) from the active featured image with a small Argusly logo in the bottom-right corner.
- Scope is limited to featured images only.
- Flow is asynchronous and requires a running queue worker.

## Enablement
Set environment variables (optional defaults shown in `config/argusly.php`):

- `ARGUSLY_AI_IMAGE_PROVIDER=openai`
- `ARGUSLY_AI_IMAGE_CREDIT_COST=6`
- `ARGUSLY_IMAGES_DISK=content_images`
- `ARGUSLY_IMAGES_PATH=content-images`
- `ARGUSLY_AI_IMAGE_OPENAI_BASE_URL=https://api.openai.com/v1`
- `ARGUSLY_AI_IMAGE_MODEL=gpt-image-1`
- `ARGUSLY_AI_IMAGE_SIZE=1536x1024`
- `ARGUSLY_AI_IMAGE_QUALITY=medium`
- `ARGUSLY_AI_IMAGE_TIMEOUT_SECONDS=90`
- `OPENAI_API_KEY=...`
- `ARGUSLY_OG_LOGO_PATH=/absolute/path/to/logo.png` (optional override)
- `ARGUSLY_OG_LOGO_MAX_WIDTH=150`
- `ARGUSLY_OG_LOGO_MARGIN=32`

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
- OG flow uses the featured image as a clean 1200x630 cover crop and places only a small Argusly logo at the bottom-right.
- OG generation uses `0` extra credits, but may create a featured background first if missing.

## OG template options
- Template options are centralized in `config/og_image.php`.
- Use env overrides for logo asset path, max width, and margin when needed.

## Storage
- Files are stored on configured disk under:
  - `content-images/{content_id}/...`
- Production deploys must recreate the public links after code updates:

```bash
mkdir -p storage/app/public/content-images
php artisan storage:link --force
php artisan argusly:diagnostics
```

- `argusly:diagnostics` should show `images.public_link` as `linked`.
