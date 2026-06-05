# Content Metrics Notes

## Required events
- `Avg Scroll` is computed from `scroll_depth` events, using the **max depth per session** and then averaging those session maxima per URL.
- `Avg Read` is computed from `read_time` events (`seconds`) stored per `session_id` and averaged per URL.
- A plain `pageview` alone does not produce scroll/read averages.

## Time window in Learnings
- The Learnings table is filtered by the selected range (`7`, `14`, `30`, `90` days) for page-level trending rows.
- Scroll/read values are resolved from tracked session aggregates for the URLs shown in that range.

## `—` versus `0.0`
- `—` means there is not enough metric input yet (no scroll/read sessions, or no derived score row yet).
- `0.0` is only shown when a stored/computed metric value is actually zero.
