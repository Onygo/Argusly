# Multilingual Translation System

This document describes the first production translation architecture for PublishLayer as implemented in the current codebase.

## Scope

PublishLayer now treats UI locale and content language as separate concerns:

- UI locale: English and Dutch for the app and public frontend
- Content language: normalized ISO-style short codes (`en`, `nl`, `de`, `fr`, `es`) for briefs, drafts, SEO, and publishing

English remains the platform fallback locale. Dutch browser locales resolve to Dutch. Manual EN/NL locale choice persists via session and `pl_locale` cookie.

## Architecture Summary

### Draft lineage

Each draft has exactly one content language and one draft type:

- `original`
- `translation`
- `hybrid`

Translated drafts are stored as sibling `drafts` linked by `source_draft_id` back to the original source draft.

### First-class localized records

A translated draft does not reuse the source `content` record.

Instead, translation creates:

1. a new localized `contents` row
2. a new localized `briefs` row
3. a new translated `drafts` row linked to the original source draft

This matches the current PublishLayer architecture, where publishing, SEO, scheduling, and WordPress sync are content-centric. It avoids EN/NL state collisions and keeps translated drafts fully editable and publishable.

### Translation source rule

New translations always resolve from the original lineage root draft, not from another translation. This rule is enforced in the app flow and headless translation action.

## Existing Schema Used

The codebase already contained the core multilingual schema:

### `drafts`

- `language`
- `draft_type`
- `source_draft_id`
- `translation_source_language`
- `model_used`
- existing credit tracking fields used for translation reservations and commits

### `contents`

- `language`
- SEO and publish state fields already stored per content record

### `briefs`

- `language`
- keyword and content intent fields reused for localized briefs

### `workspaces`

- `default_content_language`
- `enabled_content_languages`

### `content_publish_targets`

- `language`
- WordPress language/plugin metadata
- remote permalink / edit link fields

## New Migration

The current implementation adds lookup indexes only:

- `drafts_translation_lookup_idx` on `drafts(source_draft_id, language, draft_type)`
- `content_publish_targets_content_type_language_idx` on `content_publish_targets(content_id, target_type, language)`

No destructive schema rewrite was needed because the main multilingual columns already existed.

## Translation Flow

### Validation

The source draft must:

- be `original` or `hybrid`
- contain source content
- be in `ready`, `delivered`, or `published`

The target language must:

- be different from the source language
- be enabled for the workspace
- not already have an active translation for the same lineage root

### Generation

`TranslationService`:

1. validates source and target language
2. builds a structured translation prompt
3. uses the configured cheaper translation model
4. parses translated content and SEO suggestions
5. creates localized `content`, `brief`, and `draft` records
6. stores translation lineage and model metadata in draft meta

### SEO localization

`SeoLocalizationService` now builds localized:

- `seo_title`
- `seo_meta_description`
- `seo_h1`
- `seo_og_title`
- `seo_og_description`
- `seo_twitter_title`
- `seo_twitter_description`
- localized `slug`
- localized primary and secondary keywords

Each translated draft therefore has independent editable SEO values.

## Credits

Translations use the existing credit wallet and ledger flow.

- Credit action key: `translate.locale_version`
- Default fallback cost: `6`
- Default model fallback: `gpt-4.1-mini`

Flow:

1. reserve credits before translation
2. commit on success
3. release on failure

Ledger metadata records translation-specific context including source draft, target language, model, and token usage.

## WordPress Sync

WordPress publishing stays language-safe by syncing each localized content record independently.

- EN and NL versions never overwrite each other because translated drafts create separate `content` records
- connector and delivery payloads now include language and translation metadata
- `content_publish_targets` records remain per localized content record
- future plugin-specific linking for Polylang/WPML stays an extension point, not a hard dependency

## App UX

### Settings

Workspace settings now include:

- default content language
- enabled content languages

This is intentionally separate from UI locale selection.

### Draft detail

Draft detail now shows:

- content language
- draft type
- source draft lineage for translations
- related translations
- current WordPress sync status
- translation actions with estimated credit cost

When translating from a translation detail page, the system still dispatches from the original source draft.

## Locale Resolution

`SetPublicLocale` and `SetAppLocale` both use `LanguageResolverService`.

Resolution order:

1. explicit `?lang=` choice
2. stored session or `pl_locale` cookie
3. authenticated user preference when available
4. browser `Accept-Language`
5. fallback to `en`

Rules:

- browser locale starting with `nl` resolves to `nl`
- unsupported browser locales fall back to `en`
- manual EN/NL choice persists cleanly

## Tests Added

Coverage added for:

- localized translated content and brief creation
- translation credit commit and refund behavior
- locale persistence and browser fallback
- dispatching translations from the original lineage root
- WordPress target tracking across localized content records

## Rollout Notes

- Existing drafts continue to work unchanged
- Existing drafts without language continue to resolve through the existing `SupportedLanguage::fromStringOrDefault(...)` fallback behavior
- Existing WordPress mappings are preserved
- Translation is additive and does not replace the current draft generation pipeline

## Commands

```bash
php artisan migrate
php artisan test tests/Feature/Translation
```
