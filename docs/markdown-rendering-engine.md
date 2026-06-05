# Markdown Rendering Engine

PublishLayer now generates canonical Markdown artifacts for publishable content.

## Design decisions

- `content_render_artifacts` remains the single persistence layer for machine-readable render output.
- `MarkdownRenderer` produces deterministic Markdown first, then derives canonical HTML from that Markdown.
- Markdown generation is locale-aware and resolves the artifact locale from the content language unless an explicit locale is requested.
- Rendering removes non-content UI fragments such as cookie banners, forms, widgets, and admin artifacts before conversion.
- The rendered artifact includes reader-visible metadata when available: locale, publish date, author attribution, FAQ, CTA, and SEO metadata.

## Generation flow

1. `GenerateContentMarkdownJob` or `publishlayer:markdown:rebuild` loads the content item.
2. `MarkdownEligibilityService` confirms the content is public-eligible.
3. `MarkdownRenderer` converts canonical HTML into normalized Markdown.
4. `MarkdownArtifactService` stores both the Markdown and canonical HTML snapshot, plus checksum, excerpt, source, version, and locale.

## Trigger points

Markdown regeneration is queued when:

- the content record changes in a markdown-relevant way
- the active `ContentVersion` body or meta changes
- the active `ContentRevision` HTML or meta changes
- `ContentSeo` fields change

The queue target is `markdown`.

## Preview

Internal users can preview the current Markdown output at:

- `GET /content/{content}/markdown-preview`

This preview renders the current canonical content state and shows the stored artifact metadata alongside the fresh Markdown snapshot.
