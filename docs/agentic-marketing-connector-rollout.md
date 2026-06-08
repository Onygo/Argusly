# Agentic Marketing Connector Rollout

## Migration Notes

- Run the new migrations before enabling agentic connector delivery. They add structured output, locale, canonical, hreflang, schema, AI visibility, and metadata fields to `argusly_articles`.
- Argusly stores connector capabilities from heartbeat payloads in `client_sites.capabilities.agentic`.
- Legacy connector payloads remain supported. Missing policy data is treated as guided draft-safe execution.
- Autonomous publishing remains disabled by default for both WordPress and Laravel connectors.
- Laravel connector environments should add:
  - `ARGUSLY_AUTONOMOUS_ALLOWED=false`
  - `ARGUSLY_ALLOWED_OPERATIONS=create,update,draft`
  - `ARGUSLY_DEFAULT_STATUS=draft`
  - `ARGUSLY_ALLOW_SCHEMA_UPDATES=false`
  - `ARGUSLY_ALLOW_INTERNAL_LINK_UPDATES=false`
  - `ARGUSLY_REQUIRE_SIGNATURE=true`

## Production Checklist

- Verify every connected site has refreshed heartbeat capabilities.
- Confirm autonomous publishing is explicitly off unless contractually enabled for that customer/site.
- Confirm sync endpoints require signatures and reject stale timestamps.
- Validate idempotency keys are present on all new agentic actions.
- Test guided draft/preview before approving publish on one staging WordPress site and one staging Laravel site.
- Enable schema and internal link updates per site only after rendering QA.
- Monitor `AgenticActionRun.output_snapshot.connector_feedback` and publication `meta.last_result` for rejected, blocked, and failed actions.
- Roll out autonomous publishing one site at a time with strict operation limits.
