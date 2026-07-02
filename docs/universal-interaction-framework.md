# Universal Interaction Framework Architecture

Date: 2026-06-30

## Purpose

The Universal Interaction Framework defines one reusable interaction layer for authenticated Argusly app and admin surfaces. It is an architecture document only. It does not implement toolbar behavior, search, drawers, command handling, shortcuts, saved views, exports, notifications, or feature workflows.

The framework sits above the existing Application Shell and Universal DataTable foundations:

- Application Shell owns page regions and global persistent surfaces.
- DataTable owns record-list presentation and table-scoped interaction regions.
- Workspaces provide tenant, workspace, site, role, and feature context.
- Feature pages register capabilities, state, and domain actions with the shared interaction layer.

## Goals

- Make every user action discoverable through the same action semantics.
- Let toolbar actions, row actions, bulk actions, context menus, command palette commands, sticky actions, and keyboard shortcuts call the same underlying action contract.
- Standardize search, selection, drawer, confirmation, loading, empty, error, notification, saved-view, export, focus, and history behavior.
- Preserve current controllers, policies, forms, routes, and business logic during migration.
- Provide future Explainable AI surfaces with stable context about what the user is viewing, selecting, filtering, and doing.

## Non-Goals

- Do not replace Laravel Blade, existing controllers, or existing POST/GET semantics.
- Do not add client-side application state as a first migration step.
- Do not create feature-specific interaction systems.
- Do not change authorization, workspace scoping, destructive action behavior, pagination, export limits, or notification delivery rules.
- Do not make AI explanations responsible for action execution.

## Core Model

The framework is built around five shared primitives.

| Primitive | Responsibility | Examples |
| --- | --- | --- |
| Interaction Surface | A region where users discover or trigger actions. | Toolbar, command palette, context menu, row actions, sticky footer, drawer header. |
| Interaction Context | The current page, workspace, resource type, selected records, filters, drawer state, and permissions. | `app.drafts.index`, workspace ID, selected draft IDs, active saved view. |
| Action Contract | A normalized description of an action and how it behaves. | Create draft, export CSV, mark read, retry job, open drawer, explain row. |
| State Contract | A normalized description of UI state independent of the rendering surface. | Loading, empty, partial error, selected, dirty, pending confirmation. |
| Feedback Contract | How the framework informs the user after a state transition. | Toast, banner, inline error, notification, audit event, history entry. |

## Interaction Surfaces

### Toolbar

Toolbars are the primary local command surface for a page, panel, or table.

- Page toolbars belong in Application Shell `primaryActions`, `filterBar`, or `footerActions`.
- Table toolbars belong in DataTable `search`, `filters`, `actions`, `toolbar`, and `bulkActions` slots.
- Toolbars should render actions from the shared Action Contract rather than hand-owning semantics.
- Toolbars can show search, filters, saved views, exports, primary actions, and secondary action menus.
- Toolbars should not own persistent state directly; they read/write the Interaction Context.

### Global Search

Global Search finds entities across the current accessible scope.

- Scope: authenticated user, organization, workspace, selected site when relevant, and admin permissions.
- Input opens from the Application Shell, not from individual pages.
- Results are grouped by resource type and include only authorized destinations.
- Result selection is a navigation action.
- Global Search may include recent entities and saved views, but must not include destructive commands.

### Quick Search

Quick Search filters the active surface only.

- Scope: current table, list, panel, drawer, or workspace section.
- Shortcut: `/` when focus is not inside an editable control.
- State is expressed as normal query parameters when it affects server-rendered results.
- It may use local in-page filtering only when the page already owns all visible records.
- Quick Search never crosses workspace or page boundaries.

### Bulk Actions

Bulk Actions operate on the Selection Model.

- Bulk action bars appear only when one or more selectable records are selected.
- Bulk actions must declare whether they support all selected records, only eligible selected records, or all matching filtered records.
- Destructive or irreversible bulk actions always require Confirmation Flows.
- Bulk actions must report skipped, failed, and completed counts separately.
- Bulk actions should write an interaction history entry when they mutate records.

### Context Menu

Context menus expose contextual actions at pointer or keyboard position.

- Context menu actions are a filtered view of the same Action Contract used by row action menus and the command palette.
- They must be keyboard reachable through `Shift+F10` or the platform context-menu key where available.
- They must never be the only way to perform an important action.
- They should be used for dense surfaces, rows, cards, timeline items, and AI evidence snippets.

### Right Drawer

The Right Drawer is the shared detail, preview, inspect, and lightweight edit surface.

- The drawer belongs to the Application Shell `detailDrawer` region.
- A drawer may be non-modal for inspection or modal for blocking edit flows.
- Drawer open state is part of Interaction Context and History.
- Drawers must declare their resource, mode, origin surface, and close behavior.
- Drawer actions use the same Action Contract as page and table actions.
- A drawer must never silently change selected records unless its action explicitly declares that behavior.

### Command Palette

The Command Palette is a global action launcher.

- Shortcut: `Ctrl+K` on Windows/Linux and `Cmd+K` on macOS.
- It shows authorized actions from the current Interaction Context.
- It can show navigation, creation, search, saved-view, export, drawer, and explain actions.
- It must not expose actions hidden by policy or workspace state.
- Destructive commands route into Confirmation Flows instead of executing immediately.
- The palette must rank current-context actions above global navigation.

### Keyboard Shortcuts

Shortcuts are owned by the Application Shell shortcut broker and delegated to active surfaces.

Reserved shortcuts:

| Shortcut | Action |
| --- | --- |
| `Cmd/Ctrl+K` | Open Command Palette. |
| `/` | Focus Quick Search for the active surface. |
| `Cmd/Ctrl+Shift+F` | Open Global Search. |
| `Esc` | Close the active menu, popover, drawer, preview, or confirmation layer. |
| `?` | Open shortcut reference when focus is not editable. |
| `A` then `A` | Select all visible rows in the active selectable surface. |
| `Shift+F10` | Open context menu for focused item. |
| `Enter` | Activate the focused primary item or action. |
| `Space` | Toggle selection for focused selectable item. |
| Arrow keys | Move within menus, command results, grid-like result sets, and previews. |

Shortcut rules:

- Text inputs, textareas, editors, code blocks with copy behavior, and contenteditable regions own normal typing.
- A modal confirmation or modal drawer temporarily owns shortcuts inside its focus trap.
- Feature pages may register additional shortcuts only through the shared broker.
- The same command must not have conflicting shortcuts in the same context.

### Saved Views

Saved Views persist stateful ways of seeing records.

- Saved views store query, filters, sort, density, visible columns when supported, and optional grouped filter presets.
- Saved views do not store selected rows, CSRF data, page numbers by default, transient flash state, confirmation state, or drawer state.
- Saved views are scoped by table key or surface key plus user, organization, workspace, and site when relevant.
- Applying a saved view should produce a normal URL whenever the target surface is server-rendered.
- Default saved views are explicit and reversible.

### Exports

Exports convert the current visible or declared dataset scope into an external artifact.

- Export actions must declare format, scope, authorization, workspace boundary, estimated size, and sync/async behavior.
- Export scope defaults to current filters and search, not current page number.
- Exported columns are defined by domain services, not by scraping table markup.
- Export actions are visible only when the actor can access every exported field.
- Long-running exports create a Notification when ready.

### Notifications

Notifications are a feedback and re-entry surface, not a general action transport.

- Notifications may reference actions but should not hide critical workflow state from the page.
- Notification links must resolve through normal authorization and workspace checks.
- Notification read/unread state is separate from action completion state.
- Toasts communicate immediate results; durable notifications communicate work completed later or attention required.

### Confirmation Flows

Confirmation Flows guard irreversible, destructive, expensive, high-volume, or externally visible actions.

- Confirmation copy is generated from the Action Contract and domain metadata.
- Required confirmations declare severity: low, medium, high, destructive.
- Confirmation must show affected scope, irreversible consequences, and whether background work will be queued.
- Bulk confirmations must show selected count and eligibility count.
- High-risk destructive actions may require typed confirmation.
- Confirmations must return focus to the triggering surface after cancel or completion.

### Selection Model

The Selection Model is the shared state for selected records.

- Selection is scoped to a surface key, resource type, workspace/site scope, and filter state.
- A selected item is represented by stable resource identity, not row index.
- Selection supports visible-page selection, all-visible selection, and all-matching selection only when explicitly supported.
- Changing workspace, site, saved view, resource type, or incompatible filters clears selection.
- Disabled rows must declare why they cannot be selected.
- Selection state must be accessible to bulk actions, command palette, context menu, sticky actions, and Explainable AI.

### Hover Preview

Hover Preview provides lightweight inspection without committing to navigation.

- Hover previews are optional progressive enhancement.
- Keyboard users receive equivalent focus preview or drawer access.
- Previews must be dismissible with `Esc` and must not trap focus.
- Previews may show summary, status, owner, timestamps, evidence, and quick actions.
- Previews must not contain complex forms or destructive actions.

### Quick Actions

Quick Actions are high-frequency actions placed near the object they affect.

- Quick actions must be safe, highly predictable, and reversible where possible.
- Destructive quick actions require Confirmation Flows.
- Quick actions should use icon buttons with accessible names where the surrounding context is clear.
- Quick actions are candidates for command palette and keyboard binding only after they are stable.

### Action Menus

Action Menus group secondary or overflow actions.

- Menus render authorized actions from the Action Contract.
- Menu order is consistent: view/open, inspect/preview, edit, workflow actions, export/share, explain, destructive.
- Destructive actions are visually separated and require confirmation.
- Menus support keyboard navigation, typeahead when useful, and `Esc` close behavior.

### Sticky Actions

Sticky Actions keep important actions available during long workflows.

- Sticky actions belong to Application Shell `footerActions`, DataTable `bulkActions`, or a drawer footer.
- Sticky actions mirror the same Action Contract as their non-sticky equivalents.
- Sticky actions must not obscure table pagination, form errors, or notifications.
- Sticky actions should appear only when they materially reduce repeated scrolling or protect workflow completion.

### Focus Management

Focus Management is a shared accessibility and usability contract.

- Opening a modal drawer, command palette, context menu, action menu, confirmation, or notification menu moves focus into that surface.
- Closing returns focus to the invoker unless the invoker was removed.
- Non-modal drawers preserve page navigation while exposing an obvious close target.
- Focus traps apply only to modal surfaces.
- Hidden surfaces are removed from tab order and accessibility tree.
- Loading replacements preserve focus where possible or move focus to the nearest stable parent.

### History

History records how the user arrived at the current interaction state.

- Browser history owns route, query, saved-view application, and drawer deep links when supported.
- Interaction history owns recent commands, recent searches, recently opened records, completed actions, and explainability context.
- Undo is not implied by history. Actions must explicitly declare `undoable` before offering undo.
- Drawer, command palette, and saved-view history should be useful to the user but must not leak data across workspace boundaries.

## Shared Contracts

### Action Contract

Each action should be described by shared metadata before being rendered in any surface.

Required fields:

- Stable key.
- Label.
- Verb: view, create, update, delete, approve, reject, retry, export, explain, select, open, close, search, navigate.
- Scope: global, page, workspace, table, row, selection, drawer, notification.
- Target resource type and optional resource ID.
- Authorization requirement.
- Execution mode: link, form submit, async request, queued job, local state transition.
- Loading behavior.
- Success feedback.
- Failure feedback.

Optional fields:

- Description.
- Icon.
- Shortcut.
- Confirmation requirement.
- Destructive severity.
- Disabled reason.
- Bulk eligibility rule.
- Export format.
- Drawer target.
- History behavior.
- Explainability context.
- Audit metadata.

### Accessibility Contract

All interaction surfaces must provide:

- Programmatic name.
- Keyboard access.
- Visible focus state.
- Correct role or native element.
- Escape behavior for transient surfaces.
- Announced loading, empty, error, and success states when state changes asynchronously.
- Focus return after close, cancel, or submit.
- No pointer-only actions.
- No color-only status meaning.
- Reduced-motion-compatible transitions.
- Touch target sizing appropriate for mobile.

### State Management Contract

State is split by ownership.

| State | Owner | Persistence |
| --- | --- | --- |
| Route, filters, search, sort, pagination | Controller/page URL | Query string. |
| Workspace, organization, selected site | Workspaces/Application Shell | Session, route, or explicit context. |
| Selection | Active surface | In-memory first; cleared on incompatible context changes. |
| Drawer open resource | Application Shell | URL when deep-linkable; otherwise local state. |
| Command palette input | Application Shell | Ephemeral, optional recent history. |
| Saved views | Saved View service | Database. |
| Export jobs | Export service | Database/queue/notification. |
| Notifications | Notification service | Database. |
| Form dirty state | Form surface | Local until submitted. |
| Confirmation state | Confirmation surface | Ephemeral. |

State rules:

- Server-rendered state should remain URL-addressable when it changes the visible dataset.
- Ephemeral state should clear when workspace, route, or authorization context changes.
- Cross-surface state is shared through Interaction Context, not through duplicated component props.
- Framework state must never bypass Laravel policies or feature gates.

### Keyboard Shortcut Contract

Shortcuts resolve in this priority order:

1. Active modal confirmation.
2. Active modal drawer.
3. Open menu, popover, command palette, or global search.
4. Focused component with local keyboard behavior.
5. Active selectable surface.
6. Page-level registered shortcuts.
7. Application Shell global shortcuts.

The shortcut broker must be able to explain why a shortcut is unavailable: disabled action, missing permission, focus inside input, no active surface, or conflicting modal state.

### Action Semantics Contract

All actions use consistent semantics across surfaces.

- View/navigate actions do not mutate state.
- Open actions reveal drawer, preview, menu, or palette state.
- Create/update/delete actions require server authorization and form/request validation.
- Destructive actions require confirmation.
- Export actions do not mutate domain records.
- Explain actions generate explanation context but do not execute domain mutations.
- Retry/resync/queue actions disclose background execution and notify on long completion.
- Disabled actions remain discoverable only when the disabled reason helps the user act.

### Loading Semantics Contract

Loading states are explicit and scoped.

- Global loading is reserved for full-page navigation or app-level blocking work.
- Surface loading applies to a table, drawer, menu, saved-view list, export action, or search result group.
- Action loading applies to the specific button/menu item/command that was triggered.
- Skeletons are used for initial surface loading.
- Spinners or progress labels are used for short action execution.
- Queued jobs move from loading to accepted/pending feedback, then completion notification.
- Loading must not remove context needed to understand what is happening.

### Empty State Semantics Contract

Empty states explain the current absence.

| Empty Type | Meaning | Preferred Action |
| --- | --- | --- |
| New | No records exist yet. | Create, connect, or import. |
| Filtered | Records exist but not under current filters/search. | Clear filters or change saved view. |
| Unauthorized | Records may exist but actor cannot view them. | Explain access or request admin help. |
| Unconfigured | Required setup is missing. | Configure workspace/site/integration. |
| Processing | Data will appear after background work. | Show status or refresh affordance. |
| Unsupported | Capability is not available for this scope. | Explain requirement or upgrade path. |

Empty states must not use a generic "no data" message when the system can distinguish the cause.

### Error Handling Contract

Errors are scoped and actionable.

- Field errors stay next to fields.
- Action errors stay near the triggering action and may also create a toast.
- Surface errors replace only the failed surface, not the full page.
- Drawer errors remain inside the drawer unless the drawer cannot load its target.
- Export errors explain whether nothing was created, a partial file exists, or retry is possible.
- Bulk errors separate completed, skipped, and failed records.
- Authorization errors use normal Laravel authorization behavior and should not expose hidden records.
- Unexpected errors include support-safe correlation metadata where available.

## Component Ownership

| Component/Surface | Primary Owner | Consumes | Notes |
| --- | --- | --- | --- |
| Application Shell | Platform UI | Interaction Context, shortcut broker, global search, command palette, notifications, right drawer, history | Owns persistent global surfaces. |
| DataTable | Platform UI | Selection model, toolbar contract, quick search, saved views, exports, bulk actions, row actions | Owns table-scoped interactions only. |
| Workspaces | Workspace domain | Workspace context, organization/site scope, permissions, feature flags | Defines valid interaction boundaries. |
| Feature Pages | Product domains | Action registration, domain copy, validation, controller routes | Own domain behavior and policy checks. |
| Notification Service | Platform/domain services | Feedback contract, queued job completion, unread state | Durable feedback and re-entry. |
| Export Services | Domain services | Export action metadata, filters, authorization, file generation | Defines exportable columns and limits. |
| Saved View Service | Platform UI/domain boundary | Allowed filter schema, user/workspace scoping | Stores view state, not selected rows. |
| Explainable AI | AI/product layer | Interaction Context, selected resources, filters, action metadata, history | Explains context and recommendations, not hidden data. |

## Integration With Application Shell

The Application Shell becomes the root interaction host.

- Mount global search, command palette, notification menu, shortcut reference, and shared right drawer in shell-level regions.
- Expose shell regions as interaction surfaces: breadcrumb, page header, description, primary actions, filter bar, KPI section, main content, detail drawer, footer actions.
- Maintain the active Interaction Context for the current route and workspace.
- Broker global shortcuts and delegate to the active surface.
- Provide focus return for shell-owned surfaces.
- Preserve current `layouts.app` and `layouts.admin` behavior during migration.

## Integration With DataTable

DataTable becomes the canonical record-list interaction surface.

- Each table gets a stable table key.
- Toolbar, quick search, filters, saved views, exports, row actions, action menus, bulk actions, selection, loading, empty, and error states map to the shared contracts.
- Row actions and bulk actions resolve from the same Action Contract.
- Selection state is table-scoped and cleared when table key, workspace, filters, saved view, or route context becomes incompatible.
- DataTable exports use server-side domain definitions rather than rendered cells.
- DataTable remains Blade-first and behavior-preserving until a later phase explicitly adds progressive enhancement.

## Integration With Workspaces

Workspaces define interaction boundaries.

- Workspace context is included in every Interaction Context.
- Actions, searches, saved views, exports, notifications, and history are scoped to the active workspace/site when applicable.
- Switching workspace clears incompatible selection, drawer, quick search, and local command state.
- Global Search and Command Palette must not show inaccessible workspace records or commands.
- Saved views can be user-only, workspace-scoped, or site-scoped depending on the table key.
- Notifications must re-check workspace access when opened.

## Integration With Future Explainable AI

Explainable AI should consume the framework instead of inventing a parallel context model.

Explainable AI receives:

- Current route and page title.
- Active workspace/site/organization scope.
- Active surface key and table key.
- Search, filters, sort, and saved view.
- Selected resource IDs and selection mode.
- Visible action metadata and disabled reasons.
- Drawer target, if open.
- Recent interaction history relevant to the current surface.
- Error/loading/empty state, if present.

Explainable AI may provide:

- "Why am I seeing this?" explanations for filtered records and recommendations.
- "What changed?" explanations after actions complete.
- "What can I do next?" suggestions from visible authorized actions.
- "Why is this action disabled?" explanations from disabled reasons.
- Evidence previews for AI-generated recommendations.

Explainable AI must not:

- Reveal hidden records, hidden actions, or policy-denied data.
- Execute destructive actions.
- Override confirmation requirements.
- Store sensitive interaction history outside its declared retention boundary.

## Migration Order

1. Approve this architecture document.
2. Inventory existing local interactions across app/admin pages: toolbars, search, bulk bars, row actions, confirmation forms, drawers, menus, exports, notifications, and shortcuts.
3. Define stable keys for pages, tables, drawers, and resource types.
4. Introduce the Action Contract as documentation and test fixtures only.
5. Map Application Shell regions to Interaction Surfaces without changing behavior.
6. Map DataTable toolbars, row actions, bulk actions, empty states, loading states, and errors to the shared vocabulary.
7. Standardize Selection Model for pages that already have checkbox-driven bulk actions.
8. Standardize Confirmation Flows for existing destructive forms.
9. Add shortcut broker architecture and reserved shortcut documentation before implementing shortcuts.
10. Add Command Palette and Global Search contracts after action keys and workspace scoping are stable.
11. Add Saved Views for the highest-value DataTable surfaces after filter schemas are stable.
12. Add Export contracts per domain service after saved filter scopes are reliable.
13. Migrate local right-drawer patterns into the Application Shell `detailDrawer` contract.
14. Add notifications integration for queued exports and long-running actions.
15. Add hover previews, context menus, and quick actions only where the shared action registry is already in use.
16. Add Explainable AI context providers after page/table/action/saved-view/selection keys are stable.

## Suggested First Surfaces

Start with surfaces already aligned with Application Shell and DataTable migration work:

- `app.drafts.index`: quick search, saved views, exports, row actions, bulk actions, explainable selected context.
- `app.briefs.index`: quick search, saved views, exports, row actions, command palette actions.
- `admin.users.index`: global search result target, table actions, drawer candidate, confirmation standardization.
- `admin.llm.monitor`: filters, saved views, exports, error/loading semantics, explainability context.
- `app.content.index`: selection model and bulk action semantics only after existing checkbox behavior is fully preserved.

## Acceptance Criteria

The architecture is ready for implementation planning when:

- Every listed interaction surface maps to a shared contract.
- Application Shell, DataTable, Workspaces, and future Explainable AI have clear ownership boundaries.
- Action, accessibility, state, keyboard, loading, empty, and error semantics are defined once.
- Migration order preserves current behavior and avoids feature implementation in the architecture phase.
- Future work can add components incrementally without creating separate interaction systems per feature.
