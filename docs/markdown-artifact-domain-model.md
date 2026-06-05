# Markdown Artifact Domain Model

PublishLayer now has a dedicated markdown artifact layer for publishable content.

## Architecture decisions

- Canonical markdown persistence lives in `content_render_artifacts`, not `content_publications`.
- `content_publications` stays delivery-specific and remote-system specific.
- One artifact row exists per `content_id` + `markdown_locale`.
- The artifact row stores both canonical HTML and canonical markdown state so connectors can later consume one normalized record.
- The artifact also stores `content_version_id` so staleness can be detected when the content source changes.

## Why a dedicated table

- Markdown is locale-aware, while delivery records are destination-aware.
- A single content item can have multiple locales and multiple delivery targets.
- Keeping render artifacts separate avoids coupling caching and regeneration to WordPress/Laravel delivery state.

## Stored fields

Each artifact stores:

- `rendered_html`
- `rendered_markdown`
- `markdown_checksum`
- `markdown_generated_at`
- `markdown_version`
- `markdown_locale`
- `markdown_status`
- `markdown_source`
- `markdown_excerpt`

Additional linkage:

- `content_id`
- `content_version_id`
- `meta`

## Status model

- `pending`: content is eligible, but canonical markdown has not been generated yet.
- `ready`: canonical markdown exists and can be consumed later by delivery layers.
- `stale`: the source content changed after the last ready artifact.
- `ineligible`: content must not expose markdown because it is not publicly publishable.
- `failed`: reserved for future generation failures.

## Eligibility rules

Markdown artifacts are only eligible for publicly publishable content.

Current foundational rules:

- Exclude content in `brief_received`, `brief`, `draft`, `review`, or `archived`.
- Exclude content with `publish_status` in `draft`, `private`, `internal`, or `system`.
- Exclude future private/system/internal content types or sources if they appear.
- Exclude content with a remote publication explicitly marked `private`.
- Allow content in `approved`, `ready_to_deliver`, `scheduled`, `published`, or `delivered`, and content with publish status `scheduled`, `publishing`, or `published`.

## Rebuild flow

Use:

```bash
php artisan publishlayer:markdown:rebuild --sync
```

Optional flags:

- `--content=<uuid>` to rebuild a single content item
- `--locale=<code>` to override locale resolution
- `--force` to force a rebuild pass
- `--queue=<name>` for queued dispatch

The rebuild command currently does not render markdown. It only:

- evaluates eligibility
- snapshots canonical HTML from the current content revision/version
- updates locale-specific artifact rows
- marks ready artifacts as `stale` when their backing content version changed

## Future extension points

- Add a deterministic markdown renderer without changing the persistence model.
- Expose artifact delivery to connectors and APIs from a single canonical source.
- Add artifact generation failure telemetry and retry policies.
- Support alternate render profiles while keeping `content_render_artifacts` canonical for the default profile.
