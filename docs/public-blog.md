# Public Blog (Marketing Site)

The public blog is available at:

- `/blog`
- `/blog/{slug}`
- `/blog/rss.xml`
- optional filters: `/blog/tag/{tag}` and `/blog/category/{category}`

## Source of truth

The blog uses the PublishLayer connector API as primary source.
If connector retrieval is unavailable and fallback is enabled, it falls back to connector-synchronized content already stored in PublishLayer core tables (`contents` + `content_versions`).
No separate posts CMS table is introduced.

Only published article content is exposed publicly.

## Configuration

Set the marketing blog source in `.env`:

```env
PL_MARKETING_BLOG_SOURCE_MODE=workspace
PL_MARKETING_BLOG_SOURCE_ID=

PUBLISHLAYER_PUBLIC_BLOG_USE_CONNECTOR=true
PUBLISHLAYER_PUBLIC_BLOG_CONNECTOR_ENDPOINT=/v1/public/blog/posts
PUBLISHLAYER_PUBLIC_BLOG_FALLBACK_TO_LOCAL=true
PUBLISHLAYER_PUBLIC_BLOG_MAX_POSTS=300
```

`PL_MARKETING_BLOG_SOURCE_MODE` supports `workspace` or `site`.
If mode/id are missing or invalid, the marketing blog returns no posts by default (safe no-leak behavior).
`PUBLISHLAYER_PUBLIC_BLOG_CONNECTOR_ENDPOINT` should point to a PublishLayer API endpoint returning a list of posts (`[]`, `{data: []}`, or `{posts: []}`).
Source scope options are read from `config/marketing.php` (`blog_source` section).

## Caching

- Blog list/detail/taxonomy data is cached for 15 minutes.
- Cache keys include locale + page + filters.
- If cache tags are supported, `public_blog` tag is used.

To force refresh:

```bash
php artisan cache:clear
```

## Publishing flow

1. Create and publish content inside PublishLayer.
2. Public blog fetches published content via the connector API endpoint.
3. If connector fetch fails and fallback is enabled, it reads local synchronized `contents` + `content_versions`.
4. Public blog renders with SEO metadata.
