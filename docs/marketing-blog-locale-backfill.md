# Marketing Blog Locale Backfill

## Command

Use:

```bash
php artisan marketing:backfill-blog-locales --dry-run --only-misplaced-en
php artisan marketing:backfill-blog-locales --only-misplaced-en
php artisan marketing:backfill-blog-locales --only-misplaced-en --generate-en
php artisan marketing:backfill-blog-locales --only-misplaced-en --generate-en --publish-en
php artisan marketing:backfill-blog-locales --article-id=UUID --generate-en
```

The legacy compatibility command `php artisan marketing:normalize-blog-locales` now delegates to the same backfill flow in `--only-misplaced-en` mode.

## What It Does

- Detects Dutch marketing blog content that is currently stored or routed as English.
- Repairs the source record into a Dutch source variant.
- Retargets the canonical public route to `/nl/blog/{slug}`.
- Creates or updates a legacy `/en/blog/{slug}` 301 redirect.
- Optionally generates a real English localized variant in the same content family.
- Keeps EN drafts out of the public index and sitemap until `--publish-en` is used.

## Idempotency

- Source repair updates the same `contents` row instead of cloning it.
- Legacy redirects are created with `updateOrCreate()` on `source_path`.
- EN variants are reused by locale within the same localization family.
- `--skip-if-en-exists` prevents duplicate EN variants.
- `--refresh-existing-en` updates the existing EN variant instead of creating another row.
- Queue mode uses `GenerateMarketingBlogTranslationJob`, which is unique per source content + target locale.
- Translation refresh only creates a new version/revision when the translated body or stored localized metadata actually changed.

## Rollback

- The original Dutch source content is never discarded; the repair happens in place on the existing source-of-truth record.
- EN generation creates or refreshes a separate localized variant, so reverting EN does not require touching the Dutch source.
- If rollback is needed after a repair run:
  - remove or deactivate the `marketing_blog_redirects` row for the affected slug
  - restore the prior `contents` locale/routing fields from backup or database snapshot
  - delete or unpublish the generated EN variant if it should not remain live
- `--dry-run` should be used before bulk runs to inspect what would change.
