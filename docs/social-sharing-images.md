# Social Sharing Images

Argusly renders Open Graph and Twitter image metadata for public marketing pages from one shared resolver. The goal is a stable, branded preview for LinkedIn, Facebook, X, Teams, Slack, WhatsApp, and similar platforms.

## Image Hierarchy

The resolver uses this order:

1. Explicit page social image, passed as `ogImage` or `socialImage`.
2. Blog article image, when the blog metadata supplies `ogImage`.
3. Campaign, market, service, or other semantic page type image from `config/argusly_social.php`.
4. Route-name mapping from `config/argusly_social.php`.
5. Deterministic fallback variant based on canonical URL, route name, or current URL.
6. Final global fallback image.

## Why Images Are Not Random

Social platforms cache URL metadata and images aggressively. True random selection per request can make the same page show different previews depending on which crawler saw it first. Argusly uses deterministic selection so the same URL always resolves to the same fallback image.

## Adding Variants

Add a 1200 x 630 JPG to `public/images/social`, then register it in `config/argusly_social.php` under `variants`. If it should be the default for a route group or page type, add it to `route_type_mapping` or `page_type_mapping`.

## Overriding Per Page

Pass an absolute or site-relative image URL to the shared head partial:

```blade
@include('public.partials.seo-head', [
    'metaTitle' => $metaTitle,
    'metaDescription' => $metaDescription,
    'canonicalUrl' => $canonicalUrl,
    'ogImage' => '/images/social/custom-page-image.jpg',
])
```

The partial converts site-relative paths to absolute URLs and only emits populated image tags.

## Testing Previews

After deployment, test important URLs with:

- LinkedIn Post Inspector: https://www.linkedin.com/post-inspector/
- Facebook Sharing Debugger: https://developers.facebook.com/tools/debug/

Use each debugger's scrape/refresh action after changing an image, because existing previews may be cached.
