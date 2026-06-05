# Marketing Localized Routing

Localized marketing URLs are generated from two separate sources of truth:

- `config/marketing_routing.php` defines translated route segments such as `pricing`, `legal`, and `knowledge_base`.
- `marketing_page_translations.slug` defines the localized slug for DB-backed marketing topic pages.

Route section translations and page slugs should stay separate. Shared sections belong in config; page-specific slugs belong in the translation records.

## Add a New Language

1. Add the locale to `config/marketing_routing.php`.
2. Add translated route segments for every configured marketing section.
3. Add locale copy in `lang/<locale>/public.php`.
4. Add `marketing_page_translations` rows for every DB-backed page in that locale.
5. Run the feature tests covering localized URL generation, canonical tags, hreflang tags, redirects, and sitemap output.

In local and test environments, missing route segment translations fail fast through `MarketingRouteSegments::assertConfigured()`.

## Add a New Marketing Page

1. Create or seed a `marketing_pages` record with a stable `key`.
2. Add one `marketing_page_translations` record per locale with `title`, `slug`, `seo_title`, `meta_description`, and `canonical_path`.
3. Fill the `content` payload used by `resources/views/public/marketing-topic.blade.php`.
4. Link to the page with `LocalizedMarketingUrl::page($pageKey, $locale)` or `LocalizedMarketingUrl::page($pageModel, $locale)`.
5. Add or update tests for page resolution, canonical output, hreflang output, and sitemap inclusion.
