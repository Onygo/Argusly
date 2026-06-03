# Argusly Navigation Route Migration Plan

Date: 2026-05-31

## Goal

The workspace navigation now uses canonical routes that match the approved information architecture while preserving existing route names for application compatibility. Legacy GET URLs remain available as permanent redirects and record `route.deprecated` activity events for signed-in users.

## Canonical Workspace Routes

| Area | Canonical route pattern | Legacy route pattern |
| --- | --- | --- |
| Intelligence notifications | `/intelligence/notifications` | `/notifications` |
| Visibility search | `/visibility/search` | `/search-performance` |
| Research topics | `/research/topics/*` | `/topics/*` |
| Research mentions | `/research/mentions/*` | `/mentions/*` |
| Research competitors | `/research/competitors` | `/competitors` |
| Research sources | `/research/sources/*` | `/sources/*` |
| Content distribution | `/content/distribution` | `/distribution` |
| Marketing campaigns | `/marketing/campaigns/*` | `/campaigns/*` |
| Marketing calendar | `/marketing/calendar/*` | `/calendar/*` |
| Marketing social posts | `/marketing/social-posts/*` | `/social-posts/*` |
| Agents automations | `/agents/automations` | `/automations` |
| Reporting analytics | `/reporting/analytics` | `/analytics` |
| Reporting reports | `/reporting/reports/*` | `/reports/*` |
| Developer domain events | `/admin/developer-tools/domain-events` | `/admin/domain-events` |

## Compatibility Rules

- Existing route names are intentionally preserved where controllers, tests and Blade forms already depend on them.
- Old GET URLs redirect to the canonical workspace URL.
- Existing POST, PUT, PATCH and DELETE routes remain stable to avoid disrupting forms and workflows.
- Deprecated route usage is logged through `ActivityLogger` with event `route.deprecated`.
- Bookmarks continue to resolve.

## Deprecation Policy

Phase 1 keeps all legacy URLs alive.

Phase 2 can add dashboard reporting for deprecated route usage volume.

Phase 3 can remove legacy redirects only after usage has been zero for a sustained period and external links have been updated.

