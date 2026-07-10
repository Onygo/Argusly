# Public Blog (Marketing Site)

The public blog is available at:

- `/blog`
- `/blog/{slug}`
- `/blog/rss.xml`
- optional filters: `/blog/tag/{tag}` and `/blog/category/{category}`

## Source of truth

The blog uses the Argusly connector API as primary source.
No separate posts CMS table is introduced.

Only published article content is exposed publicly.

## Configuration

Set the marketing blog source in `.env`:

```env
ARGUSLY_MARKETING_BLOG_SOURCE_MODE=workspace
ARGUSLY_MARKETING_BLOG_SOURCE_ID=

ARGUSLY_PUBLIC_BLOG_USE_CONNECTOR=true
ARGUSLY_PUBLIC_BLOG_CONNECTOR_ENDPOINT=/v1/public/blog/posts
ARGUSLY_PUBLIC_BLOG_FALLBACK_TO_LOCAL=true
ARGUSLY_PUBLIC_BLOG_MAX_POSTS=300
```

`ARGUSLY_MARKETING_BLOG_SOURCE_MODE` supports `workspace` or `site`.
Legacy `PL_MARKETING_BLOG_SOURCE_*` and `PUBLISHLAYER_*` source env keys are still read as fallbacks, but new deployments should use the `ARGUSLY_` names.
If mode/id are missing or invalid, the marketing blog returns no posts by default (safe no-leak behavior).
`ARGUSLY_PUBLIC_BLOG_CONNECTOR_ENDPOINT` should point to an Argusly API endpoint returning a list of posts (`[]`, `{data: []}`, or `{posts: []}`).
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

1. Create and publish content inside Argusly.
2. Public blog fetches published content via the connector API endpoint.
3. The public blog renders synchronized `contents` + `content_versions`.
4. Public blog renders with SEO metadata.
