# Universal Action Registry

Date: 2026-06-30

## Purpose

The Universal Action Registry turns Argusly user actions into one reusable Action Contract. It is an integration layer for the Application Shell, Universal DataTable, and future interaction surfaces. It does not change business logic, rewrite controllers, or introduce a new execution path.

Every registered action should point back to an existing route, form, policy, URL, or drawer target. Feature pages keep their current controllers, requests, policies, and forms. The registry only describes how those actions may be discovered, authorized, disabled, confirmed, rendered, explained, and recorded.

## Primitives

### Action

`App\Support\Interaction\Action` is the canonical action contract.

It supports:

- Stable action key, label, description, icon, verb, and shortcut.
- Execution mode: `link`, `form`, `async`, `queued`, `drawer`, or `local`.
- Existing route or URL metadata, including HTTP method and form ID.
- Authorization through policy metadata or an explicit resolver callback.
- Visibility rules for surfaces such as toolbar, row, bulk, context menu, command palette, drawer, quick action, notification, shortcut, and future AI recommendation.
- Disabled reasons that explain temporary unavailability without duplicating the action.
- Confirmation metadata for destructive, expensive, external, or high-volume actions.
- Bulk support metadata for selection mode, resource type, all-matching support, max selection, and eligibility copy.
- Drawer support metadata for target, mode, width, and modal behavior.
- History metadata for recent commands, audit-adjacent interaction history, and undo eligibility.
- AI explainability metadata for safe recommendations and "why can I do this?" explanations.

### ActionGroup

`App\Support\Interaction\ActionGroup` groups related actions without redefining them. A group may be consumed by a toolbar overflow menu, command palette section, row action menu, drawer footer, or context menu.

Duplicate action keys are rejected at group and registry boundaries.

### ActionRegistry

`App\Support\Interaction\ActionRegistry` stores action contracts for a page, table, drawer, or future global provider.

It can resolve actions for:

- Application Shell primary actions and footer actions.
- Universal DataTable toolbar actions.
- Row actions.
- Bulk actions.
- Context menus.
- Command palette entries.
- Right drawer actions.
- Quick actions.
- Notification re-entry actions.
- Keyboard shortcuts.
- Future AI recommendations.

The registry filters resolved actions by authorization, visibility, and surface. It also exposes endpoint assertions so tests can ensure registered actions map to existing routes, forms, policies, URLs, or drawers.

### ActionContext

`App\Support\Interaction\ActionContext` describes the current interaction state.

It carries:

- User.
- Surface.
- Page key and route name.
- Workspace, organization, and site scope.
- Resource type and resource ID.
- Selected IDs and selection mode.
- Filters and sort state.
- Permission hints.
- Drawer state.
- Extra metadata for future engines.

This gives shell, table, drawer, command palette, and AI layers the same vocabulary without sharing UI implementation.

### ActionPolicyResolver

`App\Support\Interaction\ActionPolicyResolver` evaluates policy and authorization metadata.

By default it delegates to Laravel Gate and policies. It is intentionally replaceable so tests, future command palette indexing, and explainability engines can evaluate action availability without binding the registry to Blade, controllers, jobs, or business services.

### Metadata Providers

`App\Support\Interaction\InteractionMetadataProvider` describes production metadata providers that can register existing route-backed actions and resource factories without changing consuming UI.

Batch 1 providers are:

- `App\Support\Interaction\Providers\AppContentInteractionProvider`
- `App\Support\Interaction\Providers\AppResearchInteractionProvider`
- `App\Support\Interaction\Providers\AppSiteInteractionProvider`
- `App\Support\Interaction\Providers\AppSignalInteractionProvider`

`App\Support\Interaction\AppInteractionRegistry` composes these providers into an `ActionRegistry` and a resource registry for explicitly supplied model instances. It does not boot UI, register routes, call controllers, dispatch jobs, or execute business actions.

## Contract Rules

- Register an action once and render it in many surfaces.
- Prefer route-backed or form-backed actions that already exist.
- Use policy metadata for server authorization and visibility filtering.
- Use disabled reasons for temporary state blockers such as empty selection, unsupported resource state, missing setup, or read-only mode.
- Use confirmation metadata instead of surface-specific destructive copy.
- Use bulk metadata for selection semantics instead of local checkbox assumptions.
- Use drawer metadata only to describe the drawer target and mode; drawer rendering remains Application Shell work.
- Use history metadata for interaction history, not as a replacement for audit logs.
- Use AI explainability metadata only for explaining visible authorized actions. It must not reveal hidden actions or policy-denied data.

## Non-Goals

- No UI implementation.
- No command palette implementation.
- No context menu implementation.
- No shortcut broker implementation.
- No controller rewrite.
- No replacement for Laravel validation, policies, or form submissions.
- No domain action catalog migration in this phase.

## First Production Metadata Batch

The first adoption batch registers safe GET metadata only. Every action below maps to an existing named route and resolves as `execution_mode: link` with method `GET`.

| Action key | Route | Policy/visibility posture |
| --- | --- | --- |
| `app.content.open` | `app.content.show` | Subject-aware `view` metadata when a subject is supplied. |
| `app.content.create` | `app.content.create` | `BriefPolicy::create` because the existing route opens the brief-backed content creation flow. |
| `app.content.open-calendar` | `app.content.calendar` | Route-backed navigation only. |
| `app.draft.open` | `app.drafts.show` | Subject-aware `view` metadata when a subject is supplied. |
| `app.brief.open` | `app.briefs.show` | Subject-aware `view` metadata when a subject is supplied. |
| `app.research-project.open` | `app.research.show` | Subject-aware `view` metadata when a subject is supplied. |
| `app.research-project.create` | `app.research.create` | `ResearchProjectPolicy::create`. |
| `app.site.open` | `app.sites.show` | Existing organization/site scope metadata. |
| `app.llm-tracking-query.open` | `app.sites.llm-tracking.show` | Existing site scope metadata. |
| `app.seo-audit.open` | `app.sites.seo-audits.show` | Existing site scope metadata. |
| `app.signal-detection.open` | `app.signal-intelligence.detections.show` | Subject-aware `SignalDetectionPolicy::view` metadata when a subject is supplied. |

No POST, destructive, queued, heavy, drawer, global search, command palette UI, Blade rendering, controller, route, policy, model, form, or business logic changes are included in this batch.

## Example

```php
use App\Support\Interaction\Action;
use App\Support\Interaction\ActionContext;
use App\Support\Interaction\ActionRegistry;

$registry = ActionRegistry::make()
    ->register(
        Action::make('admin.queues.failed.bulk-delete', 'Delete failed jobs', 'delete')
            ->form('failed-bulk-delete-form', 'admin.queues.destroy-bulk', method: 'POST')
            ->icon('trash-2')
            ->bulk(resourceType: 'failed_job')
            ->confirm(
                title: 'Delete selected failed jobs?',
                message: 'This removes the selected failed job records from the failure queue.',
                severity: 'destructive',
            )
            ->disabledWhen(
                fn (ActionContext $context): bool => ! $context->hasSelection(),
                'Select at least one failed job.',
            )
            ->visibleIn(Action::SURFACE_BULK)
            ->history(['records' => true, 'type' => 'bulk_mutation'])
            ->ai(['risk' => 'destructive'])
    );

$actions = $registry->bulkActions(
    ActionContext::make()->withSelection(['job-1', 'job-2'])
);
```

## Integration Points

Application Shell should consume resolved actions for page-level regions only: primary actions, footer actions, drawer actions, shortcut metadata, notifications, and future command palette actions.

Universal DataTable should consume resolved actions for table-scoped toolbar, row, and bulk regions. Selection state should enter through `ActionContext`, not through duplicated action definitions.

Future Toolbar Engine should render resolved action arrays and preserve the registered action key for history, confirmation, loading, and shortcut handling.

Future Command Palette should index visible authorized actions for the current `ActionContext`, ranking current-context actions above global navigation.

Future Context Menus should resolve row or selected-resource actions from the same registry used by row action menus.

Future Explainable AI should consume visible action metadata, disabled reasons, history metadata, and AI explainability metadata. It must not execute actions or expose hidden policy-denied actions.

## Testing

Architecture tests guard the namespace and dependency boundary.

Registry tests cover:

- Route-backed action resolution.
- Surface filtering.
- Authorization and visibility.
- Disabled reasons.
- Confirmation metadata.
- Bulk support.
- Drawer support.
- Duplicate action rejection.
- Endpoint mapping assertions.
- First-batch metadata providers, proving that registered actions use existing GET routes and do not execute business logic.
