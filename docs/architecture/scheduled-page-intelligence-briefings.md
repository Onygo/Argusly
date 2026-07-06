# Scheduled Page Intelligence Briefings

This is a preparation contract only. Phase 20 does not implement recurring delivery, email delivery, or new report types.

## Existing Boundary

`App\Services\PageIntelligence\Reports\ReportBuilder` is the generation boundary for manual, API, and future scheduled callers. Scheduler/API callers must pass through this boundary so tenant validation, installed market-pack validation, idempotency, provenance, and transaction-safe snapshot allocation remain consistent.

## Future Contract

`App\Contracts\PageIntelligence\ScheduledBriefingContract` reserves the future scheduling entry point:

- prepare an existing report type for an existing workspace/site scope
- accept a caller-supplied idempotency key per scheduled request
- return a `PageIntelligenceReport` snapshot
- never deliver email directly
- never create recurring schedules inside report generation

## Delivery Non-Goals

The following work is intentionally deferred:

- recurring schedule storage
- cron or queue dispatch for schedules
- email generation and delivery
- customer-recipient management
- new report types
- PDF binary rendering or external PDF provider integration

## Export Contract

Reports expose a PDF-safe HTML route at `app.page-intelligence.reports.export`. The route renders with `layouts.export-pdf`, independent of the app shell. Future PDF jobs should render from this route/layout and update the report artifact metadata fields:

- `artifact_type`
- `artifact_storage_path`
- `artifact_status`
- `artifact_generated_at`
- `artifact_checksum`
