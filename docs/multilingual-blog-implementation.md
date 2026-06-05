# Multilingual Blog Implementation

## Model choice

- `contents` remains the locale-specific publishable record.
- `translation_source_content_id` links translated variants back to the source variant.
- `is_source_locale` and `translation_source_locale` make the source chain explicit.
- `translation_generated_at`, `translation_source_updated_at`, and `translation_source_version_id` support refresh tracking and outdated detection.
- `content_publications.locale` keeps room for destination-level localized publications later.

## Why this shape

- It reuses the existing Argusly content/draft/version flow instead of introducing a second blog-only content model.
- Locale routing, SEO, hreflang, sitemap, and language switching can all resolve against one content family.
- Adding more locales later is data-driven: another localized `content` row can join the same source chain without controller/view branching.

## Migration path

- Existing content rows are backfilled as source variants.
- Existing translated draft chains are converted into explicit content-to-content translation links where possible.
- Existing public NL slugs stay stable because public blog slug resolution now prefers `publish_url_key` and stored slug metadata over title-derived slugs.

## Public rendering rules

- `/nl/blog/{slug}` and `/en/blog/{slug}` resolve on `locale + slug`.
- Missing locale variants return a real `404`.
- Canonical URLs always point to the marketing-site localized route.
- `hreflang` and language switch links are emitted only for published sibling variants.

## Translation flow

- Content-level translate/refresh actions resolve the source variant, then reuse the existing draft translation pipeline.
- If no suitable source draft exists, Argusly bootstraps one from the current content version so legacy NL blog content can still be translated.
- Refreshing an existing locale variant updates the same localized content record instead of creating a disconnected duplicate.
