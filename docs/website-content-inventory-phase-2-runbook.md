# Website Content Inventory Phase 2 Runbook

Date: 2026-07-10

## Scope

Phase 2 discovers public website pages from existing analytics observations and existing Page Intelligence sitemap sources. It reuses `MonitoredPage` for observed evidence, `Content` for approved marketing assets, and `ContentPageLink` for traceability.

This phase does not add a tracker endpoint, crawler, analytics collector, website content table, automatic promotion rule, campaign activation flow, social activation flow, or newsletter activation flow.

## Rollout Order

1. Run migrations:

   ```bash
   php artisan migrate
   ```

2. Review defaults in `config/website_content_inventory.php`, especially excluded paths, query parameter handling, schedule cadence, queue names, and `auto_promotion_enabled=false`.

3. Dry-run analytics-observed discovery for the first workspace or site:

   ```bash
   php artisan website-content:discover-observed-pages --workspace={workspace-id-or-key} --dry-run
   ```

4. Create sitemap sources for verified client sites:

   ```bash
   php artisan website-content:setup-sitemaps --workspace={workspace-id-or-key} --dry-run
   php artisan website-content:setup-sitemaps --workspace={workspace-id-or-key} --discover
   ```

5. Enable analytics-observed discovery:

   ```bash
   php artisan website-content:discover-observed-pages --workspace={workspace-id-or-key}
   ```

6. Process queues used by Page Intelligence and inventory discovery. Defaults are `page_intelligence_discover` and `page_intelligence_fetch`.

7. Refresh stale or unfetched inventory pages:

   ```bash
   php artisan website-content:refresh-pages --workspace={workspace-id-or-key}
   ```

8. Check diagnostics before expanding scope:

   ```bash
   php artisan argusly:diagnostics --workspace={workspace-id-or-key}
   ```

9. Operators review the Page Intelligence Content Inventory tab and activate selected eligible pages manually.

## Scheduled Commands

Schedules are configured in `routes/console.php` and controlled by `config/website_content_inventory.php`.

- `website-content:discover-observed-pages --limit=...`: hourly by default.
- `website-content:setup-sitemaps --discover`: daily by default.
- `website-content:refresh-pages --limit=...`: every four hours by default.

All scheduled commands use overlap guards. Keep schedules disabled or scoped during first production validation if queue capacity is uncertain.

## Safety Checks

- Only verified client site domains should be enrolled.
- Sensitive routes and account-like paths stay excluded by default.
- Query strings are stripped unless explicitly allowlisted.
- Cross-domain sitemap setup remains blocked unless an operator changes the default.
- Manual activation is required before a `Content` record becomes campaign-ready.
- Diagnostics should show no unexpected excluded-path volume, persistent failures, or cross-domain sitemap warnings before broad rollout.

## Rollback

Pause discovery and refresh by disabling the schedule config values or stopping the scheduled commands. Existing `MonitoredPage` evidence and `ContentPageLink` rows are additive and can remain in place.

If schema rollback is required during deployment validation, rollback the inventory migrations before any irreversible downstream export is introduced:

```bash
php artisan migrate:rollback --step=3
```

No tracker, JavaScript, campaign, social, newsletter, or connector rollback is needed for Phase 2 because those surfaces are reused without new write paths.
