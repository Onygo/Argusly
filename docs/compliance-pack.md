# Argusly Compliance Pack

This pack tracks the launch-level public compliance surface. It is not legal advice; final publication should be reviewed by the accountable business owner or counsel.

## Public Documents

- Privacy Policy: `/en/legal/privacy`
- Terms: `/en/legal/terms`
- Security: `/en/legal/security`
- AI transparency: `/en/legal/ai-transparency`
- Cookies: `/en/legal/cookies`
- Subprocessors: `/en/legal/subprocessors`

The Dutch equivalents live under `/nl/juridisch/...`.

## DPA Checklist

- Identify Argusly as processor where customers submit or connect customer-controlled content.
- Identify Argusly as controller for account administration, billing, security logs, product analytics, and direct marketing.
- Include subprocessors from `config/legal.php`.
- Cover international transfers for AI providers, infrastructure, monitoring, email, and payments.
- Include breach notification, deletion/export, audit cooperation, and return/deletion on termination.

## AI Transparency Checklist

- Explain where AI is used: briefing, draft generation, image generation, content analysis, recommendations, analytics interpretation, and agentic marketing workflows.
- State that users remain responsible for review and publication approvals.
- Document provider categories without implying that every provider is used for every workspace.
- Disclose that prompts, generated outputs, metadata, and operational logs may be processed to provide the service.
- Avoid claims that training opt-out, zero retention, or private models apply unless contractually configured.

## Cookie And Tracking Checklist

- Keep the cookies page aligned with actual first-party session cookies and Argusly analytics behavior.
- Do not list advertising cookies unless they are actually deployed.
- Document retention for analytics events in `config/analytics.php` and per-site retention settings.
- Confirm consent requirements before adding third-party marketing tags.

## Retention Checklist

- Analytics events: `ANALYTICS_RETENTION_DAYS`, with per-site retention stored on analytics sites.
- Page intelligence artifacts: `config/page_intelligence.php` and `php artisan page-intelligence:prune`.
- GEO/LLM answer observations: `config/llm_tracking.php`, summary-only by default for page-level observations.
- Failed jobs/logs: operational retention policy owned by infrastructure.
- Account, billing, invoices, and audit logs: retain according to tax, contractual, and abuse-prevention requirements.

## Launch Sign-Off

- Legal pages render in English and Dutch.
- Subprocessor list reflects actual active providers.
- DPA template is approved and available for customers.
- Support can answer AI transparency, deletion, export, and subprocessor questions.
- Retention settings are documented in the production runbook.
