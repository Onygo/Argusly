# Universal Action Registry Adoption Audit

Date: 2026-06-30

## Purpose

This audit inventories existing Argusly UI actions that can eventually be described by the Universal Action Registry without changing current Blade, route, controller, policy, form, or business behavior.

Reference documents:

- `docs/universal-action-registry.md`
- `docs/universal-interaction-framework.md`
- `docs/universal-data-table-migration.md`

The recommended adoption path is metadata-first: register existing routes, forms, policies, confirmation copy, and surface visibility while preserving current execution paths.

## Risk Rubric

| Risk | Meaning | Adoption posture |
| --- | --- | --- |
| Low | GET navigation, detail links, reset links, non-mutating downloads, simple local drawer open actions. | Safe first registry metadata candidates. |
| Medium | Non-destructive POST, queued/heavy jobs, state transitions with clear eligibility, feature-gated workflow actions. | Register after route/form metadata and disabled reasons are explicit. |
| High | Destructive forms, bulk mutations, admin billing/account controls, impersonation, external publishing, API key regeneration. | Defer until confirmation, typed confirmation, selection, and policy metadata are complete. |
| Local | Inline edit panels, native details disclosures, JavaScript-managed modals/drawers, preview dialogs, complex multi-form workflows. | Keep local for now; register only their stable outer navigation actions. |

## Current Guard Rails

Admin UI actions inherit `auth`, `admin.area`, `support.context:admin`, `support.readonly`, and then route-group gates such as `can:admin-area-manage-approvals`, `can:admin-area-view-sites`, `can:admin-area-manage-billing`, or `can:admin-area-superadmin`.

App UI actions inherit `auth`, `app.locale`, `support.context:app`, `support.readonly`, `email.code.verified`, `user.approved`, `user.org`, and `onboarding.billing`. Several sections add feature gates such as `ensure.feature.enabled:signal_intelligence`, `ensure.feature.enabled:agentic_marketing`, `ensure.feature.enabled:research_layer`, `ensure.feature.enabled:network_linking`, and `ensure.feature.enabled:content_network_analysis`.

Heavy actions are already route-middleware guarded with `protect.heavy:*` in the route layer. The registry should record these as disabled/explainability metadata only where the action is visible.

## Action Key Convention

Use route-like keys with a stable page/surface/resource vocabulary:

- Page toolbar: `{area}.{page}.{verb}`, for example `admin.queues.open-system-health`.
- Row action: `{area}.{resource}.{verb}`, for example `admin.llm-request.open`.
- Bulk action: `{area}.{resource}.bulk-{verb}`, for example `app.content.bulk-schedule`.
- Drawer action: `{area}.{resource}.drawer-{mode}`, for example `admin.user.drawer-edit`.
- Local-only action marker: `{area}.{page}.local-{behavior}`, for audit metadata only, not for first registry execution.

Prefer matching existing route names when they are already stable. Add suffixes only when one route has multiple UI meanings.

## Page-by-Page Inventory

### Admin Pages

| Page | Existing actions | Surfaces | Route/form mapping | Policy/gate mapping | Risk | Recommendation |
| --- | --- | --- | --- | --- | --- | --- |
| `admin/queues/index` | Open system health, jump to translations/failed jobs, queue flush, retry all, delete all failed jobs, delete older jobs, repair stale translation locks dry-run/execute, translation retry/force-reset/release-lock/mark-failed/failed-job retry/delete, pending details/requeue/delete, failed details/retry/delete, failed bulk delete, failed filter retry-all. | Page toolbar, table row, table bulk, filter toolbar, confirmation forms. | `admin.system-health.index`, `admin.queues.index`, `admin.queues.pending.flush`, `admin.queues.retry-all`, `admin.queues.destroy-bulk`, `admin.queues.delete-older`, `admin.queues.translations.*`, `admin.queues.pending.*`, `admin.queues.show`, `admin.queues.retry`, `admin.queues.destroy`, form `failed-bulk-delete-form`. | `can:admin-area-manage-approvals`, some superadmin-only translation routes, `protect.heavy:report` on retry endpoints. | High | Start only with GET navigation/detail links and the existing documented failed bulk-delete contract as a metadata test fixture. Defer operational POST adoption until confirmation and disabled reasons cover queue, translation, and selection state. |
| `admin/queues/pending-missing` | Back to queues, nearby pending detail, failed job detail. | Page toolbar, row/detail links. | `admin.queues.index`, `admin.queues.pending.show`, `admin.queues.show`. | `can:admin-area-manage-approvals`. | Low | Safe first batch for route-backed links. |
| `admin/users/index` | Filters/reset, row view, row edit drawer open, approve, disable, activate, inline role update, drawer save/toggle active/approve. | Filter bar, row actions, local drawer, drawer forms. | `admin.users`, `admin.users.show`, `admin.users.update`, `admin.users.role.update`, `admin.users.approve`, `admin.users.disable`, `admin.users.activate`. | Manage approvals for view/status, superadmin for update/role. | Medium | Register `view`, `approve`, `activate`, `disable` as row/form metadata later. Keep edit drawer and drawer forms local until drawer action support is rendered by shell. |
| `admin/llm/monitor` | Filters/reset, request details. | Filter bar, row action. | `admin.llm.monitor`, `admin.llm.monitor.show`. | `can:admin-area-manage-approvals`. | Low | Safe first batch for row/detail and reset actions. |
| `admin/early-access/index` | Filters/reset, invite pilot user, add existing user, view signup, mark reviewed, approve, send invite, resend invite, reject. | Page forms, row actions. | `admin.early-access.index`, `.invite-pilot-user`, `.add-existing-user`, `.show`, `.review`, `.approve`, `.send-invite`, `.resend-invite`, `.reject`. | `can:admin-area-manage-approvals`. | Medium | Register row GET view and non-destructive lifecycle forms after disabled reasons reflect signup state. Defer invite/add forms until page toolbar/action form conventions exist. |
| `admin/agent-runs/index` | Filters/reset only. | Filter bar. | `admin.agent-runs.index`. | `can:admin-area-manage-approvals`. | Low | Saved-view candidate; no row registry action needed yet. |
| `admin/agentic-action-runs/index` | Filters only. | Filter bar. | `admin.agentic-action-runs.index`. | `can:admin-area-manage-approvals`. | Low | Saved-view candidate. |
| `admin/campaigns/index` | Filters, campaign detail link. | Filter bar, row link. | `admin.campaigns.index`, `admin.campaigns.show`. | `can:admin-area-manage-approvals`. | Low | Safe row `open` candidate. |
| `admin/campaigns/show` | Back to campaigns. | Page toolbar. | `admin.campaigns.index`. | `can:admin-area-manage-approvals`. | Low | Safe page navigation candidate. |
| `admin/feature-flags/index` | Create flag, row update/toggle. | Page form, row inline form. | `admin.feature-flags.store`, `admin.feature-flags.update` with PATCH. | `can:admin-area-manage-approvals`. | Medium | Register row update/toggle later with resource-state disabled reasons. Keep inline create form local. |
| `admin/invoices/index` | Filters/reset, download invoice, refund invoice. | Filter bar, row actions, export-like download. | `admin.invoices.index`, `admin.invoices.download`, `admin.invoices.refund`. | `can:admin-area-manage-billing`. | High | Register `download` as export/download candidate. Defer refund until confirmation metadata and refund eligibility are explicit. |
| `admin/query-intent/index` | Debug query intent. | Page form. | `admin.query-intent.debug`. | `can:admin-area-manage-approvals`. | Medium | Keep local until async/heavy feedback pattern exists. |
| `admin/drafts/index` | Delete draft. | Row destructive form. | `admin.drafts.destroy` DELETE. | `can:admin-area-superadmin`. | High | Defer; destructive admin content deletion needs confirmation metadata and policy target mapping. |
| `admin/briefs/index` | Delete brief. | Row destructive form. | `admin.briefs.destroy` DELETE. | `can:admin-area-superadmin`. | High | Defer with same reasoning as drafts. |
| `admin/contact-submissions/index` | Resend contact submission mail. | Row form. | `admin.contact-submissions.resend`. | `can:admin-area-superadmin`. | Medium | Candidate after delivery-status disabled reasons are explicit. |
| `admin/sites/index` | Site/table actions are mostly navigation and existing mobile-card workflow. | Row actions, mobile local workflow. | `admin.sites`, `admin.sites.index`, site-related routes in page. | `can:admin-area-view-sites`. | Medium | Register stable GET actions only. Keep mobile card workflow local. |
| `admin/company-intelligence/index` | Read-only intelligence table/list actions. | Page/table navigation. | `admin.company-intelligence.index`. | `can:admin-area-manage-approvals`. | Low | Saved-view/export candidate if dataset export is added later. |
| `admin/billing/index` | Open invoice registry, update invoice issuer profile, view organization billing. | Page primary, page form, row action. | `admin.invoices.index`, `admin.billing.invoice-issuer.update`, `admin.organizations.billing`. | `can:admin-area-manage-billing`. | Medium | Register GET links first. Keep issuer form local. |
| `admin/billing/show` and billing partials | Mandate recheck, renewal retry, force cancel subscription, hold organization, grant monthly credits, grant credits, local details drawer. | Page actions, drawer, destructive/operational forms. | `admin.organizations.billing.*`, `admin.organizations.hold`. | `can:admin-area-manage-billing`; organization hold also manage approvals route. | High | Defer operational forms. Drawer can be registered later as `admin.billing.drawer-*` local/drawer metadata only. |
| `admin/organizations/show` | Back, update org/legal profile, early-bird grant/extend/convert/end, user approve/disable/activate, workspace display-name update, workspace notifications, impersonate workspace, open billing, org activate/hold/archive/unarchive, regenerate API key, delete organization link. | Page toolbar, forms, row actions, destructive confirmations. | `admin.organizations.*`, `admin.users.*`, `admin.workspaces.*`, `admin.organizations.billing`, `admin.organizations.confirm-delete`. | Mix of manage approvals and superadmin; API key regeneration has `protect.heavy:heavy`. | High | Register low-risk links only: back, user view, workspace notifications, billing, delete-confirm page link. Defer mutations, impersonation, and API key regeneration. |
| `admin/mos-providers/index` | Open system health. | Page toolbar. | `admin.system-health.index`. | `can:admin-area-manage-approvals`. | Low | Safe page navigation candidate. |
| `admin/system-health/index` | Open MOS providers, open queues, view queue. | Page toolbar, row link. | `admin.mos-providers.index`, `admin.queues.index`. | `can:admin-area-manage-approvals`. | Low | Safe first batch. |
| `admin/credit-reservations/index` | Expire stale, filters/reset, bulk release, reservation detail links. | Page form, filter bar, checkbox bulk form, row links. | `admin.credit-reservations.expire-stale`, `.bulk-release`, `.index`, `.show`. | `can:admin-area-manage-billing`. | High | Keep local until selection model and bulk confirmation are available. Row detail links are safe later. |
| `admin/llm/settings` | Global settings update, test connection, rule upsert, audit filters, local disclosure. | Inline edit forms, heavy test form, details. | `admin.llm.settings.*`. | `can:admin-area-superadmin`, `protect.heavy:ai` on test. | Local | Keep local. Dense settings forms are not first registry candidates. |
| `admin/editorial-taxonomy/index` | Set selection, create/update/delete set, assignments update, type filters, create/update/delete items in details. | Sidebar navigation, inline forms, row details. | `admin.editorial-taxonomy.*`. | View route under site gate; mutations under manage approvals. | Local/High | Keep local due inline edit/details lifecycle. Register only set navigation later. |

### App Pages

| Page | Existing actions | Surfaces | Route/form mapping | Policy/gate mapping | Risk | Recommendation |
| --- | --- | --- | --- | --- | --- | --- |
| `app/content/index` | Create from briefing, batch create, calendar, content-network link, quick create form, filters/reset/presets, bulk schedule/sync, open content, translate, restore, delete modal/form, variant open/restore/delete. | Page primary, filter bar, saved-view-like presets, table row, mobile cards, bulk actions, local delete modal. | `app.content.create`, `app.content.batches.create`, `app.content.calendar`, `app.content-network.index`, `app.content.store`, `app.content.index`, `app.content.schedule-bulk`, `app.content.sync-bulk`, `app.content.show`, `app.content.translate`, `app.content.restore`, `app.content.delete`. | Base app middleware; content-network feature gate for linked page. | High | First register page GET actions and row `open`. Defer quick create, translate, restore/delete, mobile cards, and bulk actions until confirmation/selection/local modal behavior is modeled. Saved-view candidate for filters/presets. |
| `app/sites/llm-tracking/index` | All sites, site setup, generate starter queries, run first check, filters/reset, query-set update/toggle/create, query open, query activate/deactivate, run now, create query. | Page toolbar, setup CTA, filter bar, details forms, row actions. | `app.insights.index`, `app.sites.show`, `app.sites.llm-tracking.*`, query-set routes. | Base app plus site scope; store/run-now use `protect.heavy:ai`. | Medium | Register page GET links and row `open` first. Defer run-now, toggles, create/update forms until AI/heavy disabled reasons are explicit. |
| `app/competitor-intelligence/index` | Manage competitors, filters, analyze, import. | Page toolbar, filter bar, page forms. | `app.sites.competitors.index`, `app.sites.competitor-intelligence.index`, `.analyze`, `.import`. | Base app/site scope. | Medium | Register manage link. Defer analyze/import until async feedback metadata exists. Export candidate for opportunity JSON endpoint, not current UI. |
| `app/social-distribution/index` | Connect LinkedIn, Instagram integration link, update/delete accounts, request variants, create draft from content, delete/update/approve/unapprove/schedule variants, queue publication, local LinkedIn preview dialog. | Page forms, row/card forms, details sections, local modal confirmation/preview. | `app.agentic-marketing.distribution.*`, `app.settings.integrations.instagram`. | `ensure.feature.enabled:agentic_marketing`; external publishing semantics. | High/Local | Defer most. Register integration navigation only. Keep preview dialog, scheduling, publication queue, account delete, and variant lifecycle local until confirmation/external visibility metadata exists. |
| `app/signal-intelligence/index` | Run signal intelligence, setup/open LLM tracking, filters/reset, review detection, mark reviewing, dismiss, resolve. | Page primary, filter bar, row actions. | `app.signal-intelligence.run`, `.detections.show`, `.detections.review`, `.dismiss`, `.resolve`, `app.sites.llm-tracking.index`, `app.setup.index`. | `ensure.feature.enabled:signal_intelligence`; run has `protect.heavy:report`. | Medium | Register row review/open first. Defer run/dismiss/resolve until lifecycle-state disabled reasons and confirmations are defined. |
| `app/programmatic-brief-blueprints/index` | Filters, blueprint detail link. | Filter bar, row link. | `app.programmatic-brief-blueprints.index`, `.show`. | `ensure.feature.enabled:agentic_marketing`. | Low | Safe row `open` and saved-view candidate. |
| `app/programmatic-draft-requests/index` | Filters, draft request detail, brief link. | Filter bar, row links. | `app.programmatic-draft-requests.index`, `.show`, `app.content.workspace.show`. | `ensure.feature.enabled:agentic_marketing`. | Low | Safe row links and saved-view candidate. |
| `app/programmatic-draft-reviews/index` | Filters, review detail. | Filter bar, row link. | `app.programmatic-draft-reviews.index`, `.show`. | `ensure.feature.enabled:agentic_marketing`. | Low | Safe row `open` and saved-view candidate. |
| `app/programmatic-publication-plans/index` | Filters, plan detail, growth program link. | Filter bar, row links. | `app.programmatic-publication-plans.index`, `.show`, `app.growth-programs.show`. | `ensure.feature.enabled:agentic_marketing`. | Low | Safe row links and saved-view candidate. |
| `app/programmatic-publication-readiness/index` | Filters, readiness detail, growth program link. | Filter bar, row links. | `app.programmatic-publication-readiness.index`, `.show`, `app.growth-programs.show`. | `ensure.feature.enabled:agentic_marketing`. | Low | Safe row links and saved-view candidate. |
| `app/drafts/index` | Draft detail links, empty-state create/open content. | Row links, empty-state actions. | `app.drafts.show`, `app.briefs.create`, `app.content.index`. | Base app; `/drafts` list redirects to content inbox. | Low | Safe route-backed links. |
| `app/briefs/index` | New brief, filters/reset, brief detail, empty-state create/batch/reset. | Page primary, filter bar, row links, empty-state actions. | `app.briefs.create`, `app.briefs`, `app.briefs.show`, `app.content.batches.create`. | Base app; `/briefs` list redirects to content inbox. | Low | Safe route-backed links; saved-view candidate for filters. |
| `app/research/index` | New research project, project open, start research. | Page primary, row actions. | `app.research.create`, `app.research.show`, `app.research.start`. | `ensure.feature.enabled:research_layer`; start has `protect.heavy:report`. | Medium | Register new/open first. Defer start until queued/heavy disabled reason is explicit. |
| `app/sites/seo-audits/index` | All sites, site setup, run audit, open audit. | Page toolbar, page form, row action. | `app.insights.index`, `app.sites.show`, `app.sites.seo-audits.run`, `.show`. | Base app/site scope; run has `protect.heavy:audit`. | Medium | Register page links and row open first. Defer run audit until heavy action metadata exists. |
| `app/sites/competitors/index` | All sites, site setup, context update, candidate accept/ignore, create competitor, competitor toggle, evidence details. | Page toolbar, page form, row actions, details. | `app.insights.index`, `app.sites.show`, `app.sites.competitors.*`. | Base app/site scope. | Medium/Local | Register page links. Defer candidate and toggle forms until state/eligibility copy is modeled; keep evidence details local. |
| `app/sites` | Manage billing link, create site, download WP plugin, row setup/actions. | Alert link, page form, download, row actions. | `app.billing.index`, `app.sites.store`, `app.sites.wordpress-plugin.download`, site routes. | Base app; billing route; site ownership. | Medium | Register download/navigation first. Keep site creation and row setup workflow local. |
| `app/network-linking/index` | Update profile, request permission, approve/revoke permissions. | Page forms, row actions. | `app.network-linking.profile.update`, `.permissions.request`, `.permissions.approve`, `.permissions.revoke`. | `ensure.feature.enabled:network_linking`, `ensure.feature:link_intelligence`. | Medium | Defer until feature is stable and permission eligibility states are explicit. |
| `app/agentic-marketing/index` | Approvals/orchestration/campaign clusters/content opportunities/create objective links, execution settings form, filters, action show, objective show/edit, approve/dismiss/execute/retry actions. | Page primary, filter bar, row actions, forms. | `app.agentic-marketing.*`, `app.settings.agentic-marketing-execution.update`. | `ensure.feature.enabled:agentic_marketing`. | High | Register navigation links first. Defer action lifecycle forms because approve/execute/retry semantics need state, run, and external-effect metadata. |
| `app/agentic-marketing/workflows/index` | Run workflow, create rules, create overrides, clear override. | Page form, local forms. | `app.agentic-marketing.workflows.*`. | `ensure.feature.enabled:agentic_marketing`. | Medium/Local | Keep local until workflow-rule forms and execution feedback are standardized. |
| `app/content/lifecycle/index` | Analyze, content links, filter cards, load more, JavaScript-driven lifecycle action/reject/assign forms. | Page primary, cards, local JS forms. | `app.content.lifecycle.*`, `app.content.show`. | Base app; content lifecycle routes. | Local/High | Register content links and filter cards later. Keep lifecycle mutation forms local until JS action routing and drawer/detail behavior are migrated. |
| `app/content/series/index` | New chain, filters, show, details menu with duplicate/archive/delete. | Page primary, filter bar, local details action menu. | `app.content.series.create`, `.show`, `.duplicate`, `.archive`, `.destroy`. | Base app; generate routes are heavy on detail pages. | High/Local | Register new/show first. Defer details menu mutation/delete until menu and confirmation rendering exist. |
| `app/content-network/index` | Filters, run analysis dry/current, content links. | Filter bar, page forms, local report tables. | `app.content-network.index`, `.run`, `app.content.show`. | `ensure.feature.enabled:content_network_analysis`; run also `ensure.feature:content_network_analysis_enabled`. | Medium/Local | Register content links later. Keep run/report controls local. |
| `app/settings/image-presets/index` | Back/settings, create preset, set default, edit, delete preset. | Page primary, row actions. | `app.settings`, `app.settings.image-presets.*`. | Base app/organization scope. | High | Register settings/create/edit links first. Defer set-default/delete until default eligibility and destructive confirmation metadata exist. |

## Surface Mapping

| Registry surface | Current UI sources | First candidate actions |
| --- | --- | --- |
| `toolbar` | Page-header links and CTAs, reset links, page-level navigation. | `admin.system-health.open-queues`, `admin.queues.open-system-health`, `app.content.open-calendar`, `app.research.create`, `app.sites.seo-audits.open-site-setup`. |
| `row` | `x-data-table.actions`, row title links, compact action buttons. | `admin.llm-request.open`, `admin.campaign.open`, `app.research-project.open`, `app.programmatic-brief-blueprint.open`, `app.seo-audit.open`. |
| `bulk` | `x-data-table.bulk-actions`, checkbox forms. | Later: `admin.failed-job.bulk-delete`, `app.content.bulk-schedule`, `app.content.bulk-sync`, `admin.credit-reservation.bulk-release`. |
| `drawer` | Admin users drawer, admin billing drawer, local preview/details drawers. | Later: `admin.user.drawer-edit`, `admin.billing.drawer-payment`, `admin.billing.drawer-wallet`. |
| `context_menu` | Details menus and row overflow patterns. | Later: mirror row `open/edit/duplicate/archive/delete` actions after menu renderer exists. |
| `command_palette` | Future global launcher. | Only low-risk GET navigation/actions from current context; no destructive, external publish, impersonation, or hidden policy-denied actions. |
| `quick` | Inline per-resource forms and icon buttons. | Defer until stability: run-now, retry, approve, resolve, restore. |
| `notification` | Not audited as a rendering surface here. | Future re-entry actions should resolve through the same route-backed actions. |

## Policy, Route, and Form Mapping

| Action family | Existing guard | Route/form examples | Registry metadata needed |
| --- | --- | --- | --- |
| Admin approval/user lifecycle | `can:admin-area-manage-approvals`; some update routes require `can:admin-area-superadmin`. | `admin.users.approve`, `.disable`, `.activate`, `admin.early-access.*`, `admin.organizations.*`. | Policy ability, target resource, visible states, disabled reasons for already-approved/inactive states. |
| Admin billing | `can:admin-area-manage-billing`. | `admin.invoices.download`, `.refund`, `admin.organizations.billing.*`, `admin.credit-reservations.*`. | Billing ability, organization target, confirmation severity, idempotency copy, external provider status. |
| Admin queue operations | `can:admin-area-manage-approvals`; superadmin for translation repair routes; `protect.heavy:report` on retries. | `admin.queues.retry`, `.retry-all`, `.destroy`, `.destroy-bulk`, `.translations.*`. | Queue/resource identity, heavy-action reason, confirmation copy, bulk eligibility, selection metadata. |
| App content/library | Base app middleware and workspace/site ownership. | `app.content.*`, `app.drafts.show`, `app.briefs.*`. | Workspace/site scope, content resource type, lifecycle status disabled reasons, delete/restore confirmation. |
| App AI/heavy jobs | Feature gate plus `protect.heavy:ai/report/audit` where present. | `app.sites.llm-tracking.run-now`, `app.sites.seo-audits.run`, `app.research.start`, `app.signal-intelligence.run`. | Execution mode `queued`, disabled reasons for quota/readiness, feedback/history metadata. |
| App agentic marketing/social | `ensure.feature.enabled:agentic_marketing`. | `app.agentic-marketing.actions.*`, `app.agentic-marketing.distribution.*`. | Human-review state, external visibility warning, confirmation severity, account eligibility, publication timing. |
| App feature-gated intelligence | Feature-specific middleware. | `app.signal-intelligence.*`, `app.network-linking.*`, `app.content-network.*`. | Feature visibility, readiness disabled reasons, resource-specific policy target. |

## Export and Saved-View Candidates

Export/download candidates:

- `admin.invoices.download` as a single-record download action.
- `app.sites.wordpress-plugin.download` as a setup download action.
- `app.sites.competitor-intelligence.opportunities` JSON endpoint as a future export candidate, not currently a visible export button.
- Developer docs download routes are outside this focused page audit but fit the same export/download contract.

Saved-view candidates:

- `admin.agent-runs.index`, `admin.agentic-action-runs.index`, `admin.llm.monitor`, `admin.invoices.index`, `admin.early-access.index`, and `admin.queues.index`.
- `app.content.index` because it has search, filters, status/inbox presets, site/series/automation filters, sort, and pagination.
- Programmatic index pages because they are mostly filter plus detail-link tables.
- `app.signal-intelligence.index`, `app.sites.llm-tracking.index`, `app.briefs`, `app.research.index`.

Saved views should not store selected rows, current page number by default, CSRF data, delete modal state, details disclosure state, or drawer state.

## First Implementation Batch

Batch 1 should register low-risk metadata only and leave Blade rendering untouched until a later integration pass.

| Proposed key | Label | Surface | Route | Method | Risk | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| `admin.queues.open-system-health` | System health | `toolbar`, `command_palette` | `admin.system-health.index` | GET | Low | Page-level navigation from queues. |
| `admin.queues.open-failed-jobs` | Failed jobs | `toolbar` | `admin.queues.index` with `focus_failed=1` | GET | Low | Anchor/query navigation only. |
| `admin.pending-job.open` | Open pending job | `row`, `context_menu` | `admin.queues.pending.show` | GET | Low | Row/detail link. |
| `admin.failed-job.open` | Open failed job | `row`, `context_menu` | `admin.queues.show` | GET | Low | Row/detail link. |
| `admin.llm-request.open` | Details | `row`, `context_menu` | `admin.llm.monitor.show` | GET | Low | Simple row detail. |
| `admin.campaign.open` | Open campaign | `row`, `context_menu` | `admin.campaigns.show` | GET | Low | Simple row detail. |
| `admin.invoice.download` | Download invoice | `row`, `context_menu` | `admin.invoices.download` | GET | Low | Export/download metadata candidate. |
| `admin.system-health.open-queues` | Open queues | `toolbar`, `row` | `admin.queues.index` | GET | Low | Current system-health links. |
| `app.content.create` | New content | `toolbar`, `command_palette` | `app.content.create` | GET | Low | Existing page primary. |
| `app.content.open-calendar` | Calendar | `toolbar`, `command_palette` | `app.content.calendar` | GET | Low | Existing page primary. |
| `app.content.open` | Open content | `row`, `context_menu` | `app.content.show` | GET | Low | Table and mobile card link. |
| `app.programmatic-brief-blueprint.open` | Open blueprint | `row`, `context_menu` | `app.programmatic-brief-blueprints.show` | GET | Low | Represents similar programmatic detail links. |
| `app.programmatic-draft-request.open` | Open draft request | `row`, `context_menu` | `app.programmatic-draft-requests.show` | GET | Low | Simple row detail. |
| `app.programmatic-draft-review.open` | Open draft review | `row`, `context_menu` | `app.programmatic-draft-reviews.show` | GET | Low | Simple row detail. |
| `app.programmatic-publication-plan.open` | Open publication plan | `row`, `context_menu` | `app.programmatic-publication-plans.show` | GET | Low | Simple row detail. |
| `app.programmatic-publication-readiness.open` | Open readiness item | `row`, `context_menu` | `app.programmatic-publication-readiness.show` | GET | Low | Simple row detail. |
| `app.research-project.create` | New research project | `toolbar`, `command_palette` | `app.research.create` | GET | Low | Feature-gated visibility. |
| `app.research-project.open` | Open research project | `row`, `context_menu` | `app.research.show` | GET | Low | Simple row detail. |
| `app.seo-audit.open` | Open SEO audit | `row`, `context_menu` | `app.sites.seo-audits.show` | GET | Low | Site-scoped row detail. |
| `app.llm-tracking-query.open` | Open query | `row`, `context_menu` | `app.sites.llm-tracking.show` | GET | Low | Site-scoped row detail. |

Batch 1 should include endpoint mapping assertions only. It should not introduce a toolbar renderer, change any Blade action markup, or migrate form execution.

## Batch 2 Candidates

After Batch 1 validates route-backed metadata, adopt medium-risk form-backed actions that are already simple and state-bounded:

- `admin.early-access.mark-reviewed`, `.approve`, `.send-invite`, `.resend-invite`, `.reject`.
- `admin.user.approve`, `.activate`, `.disable`.
- `app.signal-detection.mark-reviewing`, `.dismiss`, `.resolve`.
- `app.research-project.start`.
- `app.seo-audit.run`.
- `app.llm-tracking-query.run-now`, `.toggle`.
- `app.sites.competitor-candidate.accept`, `.ignore`, and `app.site-competitor.toggle`.

Each Batch 2 action needs explicit disabled reasons and, for queued/heavy work, history/feedback metadata.

## Deferred Actions

| Action family | Reason to defer |
| --- | --- |
| Destructive admin content deletes (`admin.drafts.destroy`, `admin.briefs.destroy`) | High-impact destructive DELETE forms need centralized confirmation metadata and policy target mapping. |
| Queue deletes, retry-all, flush, translation force reset, failed-job bulk delete | Operational blast radius, selection state, queue filters, and destructive confirmation copy need to be represented precisely. |
| Credit-reservation bulk release and expire stale | Checkbox-driven bulk form and billing impact require selection model, eligibility, and confirmation metadata. |
| Invoice refund and billing force cancel/grant credits | Financial/accounting side effects need confirmation severity, idempotency copy, provider status, and policy target mapping. |
| Organization hold/archive/unarchive/delete, workspace impersonation, API key regeneration | Admin account safety and support-mode implications are too high for first-pass registry rendering. |
| Admin users drawer and billing drawer | Current drawers are local JavaScript surfaces. Register after Application Shell drawer rendering consumes action metadata. |
| LLM settings, editorial taxonomy, feature-flag create/update forms | Dense inline edit forms and native details panels should remain local until form action rendering patterns exist. |
| App content delete/restore/translate/bulk schedule/bulk sync | Current content index includes mobile-card alternatives, local delete modal, selection-driven bulk forms, and status-specific eligibility. |
| Social distribution scheduling, publication queue, account delete, variant approve/unapprove/delete | Externally visible publishing flow and local LinkedIn preview dialog require confirmation and preview contracts first. |
| Agentic marketing approve/dismiss/execute/retry | Workflow state, action run ownership, retry semantics, and external effects need richer disabled/explainability metadata. |
| Content lifecycle JS forms | Forms are populated by local JavaScript and depend on task-specific modal/panel behavior. |
| Content series details action menu | Native details menu should wait for shared action menu/context menu rendering and destructive confirmation support. |
| Network linking permission actions | Feature is gated and permission eligibility should be explicit before registry adoption. |

## Confirmation Flow Candidates

Immediate candidates for future `confirm()` metadata:

- Queue flush/delete/retry-all/delete older and translation force-reset/release-lock/mark-failed.
- Failed job delete and failed bulk delete.
- Credit reservation bulk release and stale expiration.
- Invoice refund and subscription force cancel.
- Organization hold/archive/unarchive/delete and API key regeneration.
- Content delete, series delete, image preset delete.
- External publishing queue/schedule and social account deletion.

High-risk candidates should include typed confirmation only where accidental execution would break integrations or permanently remove customer data, for example organization delete and API key regeneration.

## Actions That Should Remain Local For Now

- Native `<details>` disclosures used for filters, evidence, taxonomy edit panels, social draft sections, and content variants.
- JavaScript-managed delete modal on the content index.
- LinkedIn preview dialog and its confirm-to-submit behavior.
- Admin users and billing local drawers.
- Inline settings/editorial/feature-flag forms where the form itself is the page content.
- Mobile card alternate workflows that intentionally differ from desktop table action cells.

These can still be documented in local audit metadata, but they should not be registry-rendered until the shell, drawer, confirmation, selection, and menu renderers exist.
