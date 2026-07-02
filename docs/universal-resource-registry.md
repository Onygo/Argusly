# Universal Resource Registry

Date: 2026-06-30

## Purpose

The Universal Resource Registry gives Argusly one reusable Resource Contract for the entities that actions, navigation, tables, drawers, search, notifications, history, and AI explanations refer to.

The registry is descriptive only. It does not execute actions, mutate models, replace controllers, change policies, or introduce new business behavior. Resource metadata must point back to existing models, named routes, policies, or URLs.

## Primitives

### Resource

`App\Support\Interaction\Resource` is the canonical resource contract.

It describes:

- Stable resource key.
- Resource type.
- Resource ID.
- Title, subtitle, status, and icon.
- Primary route and URL metadata.
- Right drawer metadata.
- Available action key references.
- Permission metadata.
- Descriptive relationships.
- Preview metadata.
- History metadata.
- AI explainability metadata.
- Search metadata.
- Notification metadata.
- Existing model class or model instance.
- Policy or authorization callback for visibility.

### ResourceType

`App\Support\Interaction\ResourceType` defines the resource vocabulary. Initial types are:

- `content`
- `draft`
- `brief`
- `campaign`
- `opportunity`
- `research_project`
- `signal_detection`
- `competitor`
- `llm_tracking_query`
- `seo_audit`
- `site`
- `organization`
- `workspace`
- `user`
- `queue_job`
- `failed_job`

`ResourceType::initialTypes()` maps these types to existing Argusly model classes and primary named routes where those routes exist today.

### ResourceRegistry

`App\Support\Interaction\ResourceRegistry` stores resource types and resources. It rejects duplicate resource keys, rejects duplicate type keys, and rejects resources whose type has not been registered.

It resolves resources for a `ResourceContext` and hides unauthorized resources by default. This is important for Global Search, Command Palette, hover previews, and AI explanations: policy-denied resources must not leak through metadata.

### ResourceContext

`App\Support\Interaction\ResourceContext` carries the current user, surface, page key, route name, workspace, organization, site, resource type, resource ID, permission hints, and metadata.

It can produce an `ActionContext` so resource consumers can ask the Action Registry for action metadata without duplicating action logic.

### ResourceRelationship

`App\Support\Interaction\ResourceRelationship` describes links between resources. Relationships are intentionally descriptive in this phase. They can say that a draft belongs to a brief, content belongs to a site, a tracking query belongs to a site, or a failed job relates to a workspace, but they do not load, sync, repair, or mutate related records.

### ResourceResolver

`App\Support\Interaction\ResourceResolver` evaluates resource authorization, permission metadata, and callbacks. By default it delegates policy checks to Laravel Gate. It is replaceable for tests and future indexing pipelines.

### Metadata Providers

`App\Support\Interaction\InteractionMetadataProvider` lets production metadata be registered outside Blade, controllers, jobs, and business services.

Batch 1 providers are:

- `App\Support\Interaction\Providers\AppContentInteractionProvider`
- `App\Support\Interaction\Providers\AppResearchInteractionProvider`
- `App\Support\Interaction\Providers\AppSiteInteractionProvider`
- `App\Support\Interaction\Providers\AppSignalInteractionProvider`

Each provider exposes supported resource types, route-backed action keys, safe model-to-resource factories, descriptive relationships, and policy-aware visibility metadata. `App\Support\Interaction\AppInteractionRegistry` composes the providers for tests and future consumers, but it does not boot any UI surface or execute any action.

## Resource Contract

A resource should be registered once and consumed many times:

```php
Resource::make('content:123', ResourceType::CONTENT, 123)
    ->model(App\Models\Content::class)
    ->title('How AI search changes content strategy')
    ->subtitle('English / WordPress')
    ->status(['label' => 'Ready', 'tone' => 'success'])
    ->icon('file-text')
    ->primaryRoute('app.content.show', ['content' => 123])
    ->drawer('content-detail', width: 'lg')
    ->actions('app.content.open', 'app.content.publish-now')
    ->policy('view', $content)
    ->permission('update', ['ability' => 'update', 'target' => $content])
    ->relationship(
        ResourceRelationship::make('site', 'belongs_to', ResourceType::SITE)
            ->resourceId($content->client_site_id)
    )
    ->preview(['summary_fields' => ['status', 'score']])
    ->history(['timeline_key' => 'content'])
    ->ai(['explainability' => ['inputs' => ['brief', 'draft', 'performance']]])
    ->search(['tokens' => ['title', 'topic', 'site']])
    ->notification(['template' => 'content_ready']);
```

Consumers receive resolved arrays. They should preserve the `key`, `type`, `id`, `primary_url`, `available_actions`, and metadata blocks rather than re-inferring resource meaning locally.

## Resource Type Convention

Use lower snake case nouns. Prefer the product concept over the database table name when the concept is stable:

- Good: `research_project`, `signal_detection`, `llm_tracking_query`.
- Avoid route-shaped keys such as `app_content_show`.
- Avoid action-shaped keys such as `publish_content`.

Resource keys should use `type:id` unless a natural stable namespace is clearer, for example `content:123`, `draft:55`, `queue_job:default:991`.

## Relationship Convention

Relationships should name the relationship role, type, target resource type, optional target ID, optional target resource key, and optional metadata.

Common relationship types:

- `belongs_to`
- `contains`
- `generated_from`
- `related_to`
- `owned_by`
- `scoped_to`

Relationships must remain descriptive in this phase. Do not create relationship loaders, repair logic, synchronization, or write behavior in the registry.

## Integration With Action Registry

Resources reference actions by Action Registry keys only:

```php
->actions('app.briefs.open', 'app.briefs.generate-draft')
```

They must not redefine action labels, forms, routes, confirmations, or execution modes. The Action Registry remains the source of truth for what a user can do. The Resource Registry only says which action keys are relevant to the resource.

`ResourceRegistry::assertAvailableActionsExist($actionRegistry)` can be used in tests or adoption checks to catch stale action references.

## Integration With Command Palette

The Command Palette should index visible resources for the current `ResourceContext`, then ask the Action Registry for visible actions through `ResourceContext::toActionContext()`.

The palette should not show policy-denied resources. It should rank current page, workspace, and site resources above global matches, but the ranking engine should live outside the registry.

## Integration With Global Search

Global Search can consume the resource `search` metadata for token fields, ranking hints, and result grouping. The registry does not perform search queries. It supplies a common result shape after existing search providers have found authorized candidates.

Search results should use `primary_url` for navigation and `preview` metadata for compact result summaries.

## Integration With Right Drawer

The Right Drawer can use resource `drawer` metadata to know the target drawer, mode, width, and resource identity. Drawer rendering, loading, and mutation remain Application Shell or page-controller responsibilities.

Drawer action buttons should come from the Action Registry using the resource context.

## Integration With Explainable AI

Explainable AI can use resource `ai`, `history`, `preview`, and `relationships` metadata to explain why a resource is relevant, what signals contributed to it, and what visible actions are available.

AI surfaces must only use visible authorized resources and visible authorized actions. They must not reveal hidden resource titles, denied relationships, hidden actions, or policy-only state.

## First Resource Adoption Candidates

Batch 1 registers metadata-only providers for resources that already have strong model, route, and policy or scope anchors:

- `content` via `App\Models\Content`, `app.content.show`, and `ContentPolicy`.
- `draft` via `App\Models\Draft`, `app.drafts.show`, and `DraftPolicy`.
- `brief` via `App\Models\Brief`, `app.briefs.show`, and `BriefPolicy`.
- `research_project` via `App\Models\ResearchProject`, `app.research.show`, and `ResearchProjectPolicy`.
- `signal_detection` via `App\Models\SignalDetection` and `app.signal-intelligence.detections.show`.
- `site` via `App\Models\ClientSite`, `app.sites.show`, and existing organization/site scope checks.
- `llm_tracking_query` via `App\Models\LlmTrackingQuery`, `app.sites.llm-tracking.show`, and existing site scope checks.
- `seo_audit` via `App\Models\SeoAudit`, `app.sites.seo-audits.show`, and existing site scope checks.

Batch 1 resource factories attach only open/navigation action keys, primary URLs, preview/search/history/AI metadata, and descriptive relationships. They do not add drawers, loaders, search UI, command palette UI, controller calls, route changes, model changes, or write behavior.

## Deferred Resources

Defer resources that still need clearer ownership, detail routes, or stable policy surfaces:

- `opportunity`, `campaign`, `competitor`, and programmatic resource families.
- `queue_job` and `failed_job`, except as safe framework fixtures.
- Fine-grained content assets such as answer blocks, image versions, render artifacts, and revisions.
- Intermediate analytics rows and rollups.
- Low-level signal intelligence entities, mentions, scores, and feed items.
- Billing ledger rows and payment provider internals.
- Integration-specific webhook delivery attempts.
- Temporary wizard state and onboarding scan state.

These can become resources later when there is a clear model, route or URL, policy, and consumer need.

## Test Strategy

Current tests cover:

- Resource registration.
- Duplicate resource key rejection.
- Resource type registration.
- Initial resource type reference mapping.
- Title, subtitle, status, and icon metadata.
- Primary URL metadata.
- Relationship metadata.
- Available action key references.
- Policy-aware visibility metadata.
- AI, search, preview, history, drawer, and notification metadata.
- Architecture boundaries that keep the registry out of controllers, views, jobs, business services, and action execution.
- First-batch metadata providers, proving resource URLs, available action keys, hidden unauthorized resources, descriptive-only relationships, and metadata-only action behavior.

Future adoption tests should be added beside the surface that first consumes a resource. They should assert that the consuming surface uses visible resolved resources and Action Registry keys rather than local duplicated metadata.
