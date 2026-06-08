# SEO Audit Dashboard Notes

## Scoring Rules
Per-page SEO score starts at `100` and deducts points once per issue code:

- `title_missing`: `-25`
- `title_long`: `-10`
- `meta_description_missing`: `-15`
- `canonical_missing`: `-10`
- `h1_missing`: `-15`
- `broken_links_detected`: `-10`

Final page score is clamped to `0..100`.

Overall run SEO Health Score is the equal-weight average of all analysed page scores.

## Score Levels
- `80..100`: Good
- `60..79`: Needs improvement
- `0..59`: Poor

## Where To Adjust
- Deduction weights and score levels:
  - `app/Services/SeoAudit/SeoAuditScoreCalculator.php`
- Dashboard composition (summary, priority fixes, grouped issues, page table, history):
  - `app/Services/SeoAudit/SeoAuditRunDashboardPresenter.php`
- Run detail UI layout:
  - `resources/views/app/sites/seo-audits/show.blade.php`
- Controller wiring and query-string filters:
  - `app/Http/Controllers/App/AppSiteSeoAuditController.php`

## Query Parameters
The run detail page persists and supports:

- `scope`: `argusly` | `other` | `all`
- `issue_filter`: `all` | `argusly` | `other` | `actionable` | `not_actionable`
- `issue_type`: issue code filter
- `ai_show_all`: `0|1`
- `focus_page_id`: preselects AI-fix candidates for a specific page

Legacy `page_scope` links are still accepted and mapped to the new scope handling.
