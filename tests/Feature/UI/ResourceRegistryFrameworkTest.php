<?php

use App\Models\User;
use App\Support\Interaction\Action;
use App\Support\Interaction\ActionContext;
use App\Support\Interaction\ActionRegistry;
use App\Support\Interaction\Resource;
use App\Support\Interaction\ResourceContext;
use App\Support\Interaction\ResourceRegistry;
use App\Support\Interaction\ResourceRelationship;
use App\Support\Interaction\ResourceType;
use Illuminate\Support\Facades\Gate;

it('registers resource types and resources while rejecting duplicate keys', function () {
    $registry = ResourceRegistry::make()
        ->register(ResourceType::make(ResourceType::CONTENT, 'Content')->model('App\\Models\\Content')->primaryRoute('app.content.show'))
        ->register(ResourceType::make(ResourceType::DRAFT, 'Draft')->model('App\\Models\\Draft')->primaryRoute('app.drafts.show'));

    $registry->register(
        Resource::make('content:123', ResourceType::CONTENT, 123)
            ->model('App\\Models\\Content')
            ->title('Universal content')
            ->primaryRoute('app.content.show', ['content' => 123])
    );

    expect($registry->hasType(ResourceType::CONTENT))->toBeTrue()
        ->and($registry->has('content:123'))->toBeTrue()
        ->and($registry->type(ResourceType::CONTENT)->toArray()['model'])->toBe('App\\Models\\Content')
        ->and(fn () => $registry->register(ResourceType::make(ResourceType::CONTENT, 'Duplicate')))
        ->toThrow(LogicException::class, ResourceType::CONTENT)
        ->and(fn () => $registry->register(Resource::make('content:123', ResourceType::CONTENT, 456)))
        ->toThrow(LogicException::class, 'content:123')
        ->and(fn () => $registry->register(Resource::make('unknown:1', 'unknown', 1)))
        ->toThrow(LogicException::class, 'unknown');
});

it('models the initial resource type catalog against existing Argusly references', function () {
    $registry = ResourceRegistry::withInitialTypes();

    expect(array_keys($registry->types()))->toBe([
        'content',
        'draft',
        'brief',
        'campaign',
        'opportunity',
        'research_project',
        'signal_detection',
        'monitored_page',
        'competitor',
        'llm_tracking_query',
        'seo_audit',
        'site',
        'organization',
        'workspace',
        'user',
        'queue_job',
        'failed_job',
    ]);

    $registry->assertAllTypesMapToExistingReferences();
});

it('resolves title, subtitle, status, icon, and primary URL metadata', function () {
    $registry = ResourceRegistry::make()
        ->register(ResourceType::make(ResourceType::CONTENT, 'Content')->model('App\\Models\\Content')->primaryRoute('app.content.show'))
        ->register(
            Resource::make('content:123', ResourceType::CONTENT, 123)
                ->model('App\\Models\\Content')
                ->title(fn (ResourceContext $context): string => 'Article '.$context->resourceId)
                ->subtitle('English / WordPress')
                ->status(['label' => 'Ready', 'tone' => 'success'])
                ->icon('file-text')
                ->primaryRoute('app.content.show', fn (ResourceContext $context): array => ['content' => $context->resourceId])
        );

    $resource = $registry->resolve(
        'content:123',
        ResourceContext::make(['resource_type' => ResourceType::CONTENT, 'resource_id' => 123])
    );

    expect($resource)
        ->toMatchArray([
            'key' => 'content:123',
            'type' => ResourceType::CONTENT,
            'id' => 123,
            'title' => 'Article 123',
            'subtitle' => 'English / WordPress',
            'status' => ['label' => 'Ready', 'tone' => 'success'],
            'icon' => 'file-text',
        ])
        ->and($resource['primary_route'])->toMatchArray([
            'name' => 'app.content.show',
            'parameters' => ['content' => 123],
            'exists' => true,
        ])
        ->and($resource['primary_url'])->toContain('/content/123');
});

it('keeps relationships descriptive and preserves available Action Registry key references', function () {
    $resourceRegistry = ResourceRegistry::make()
        ->register(ResourceType::make(ResourceType::BRIEF, 'Brief')->model('App\\Models\\Brief')->primaryRoute('app.briefs.show'))
        ->register(ResourceType::make(ResourceType::SITE, 'Site')->model('App\\Models\\ClientSite')->primaryRoute('app.sites.show'))
        ->register(
            Resource::make('brief:42', ResourceType::BRIEF, 42)
                ->model('App\\Models\\Brief')
                ->title('Landing page brief')
                ->primaryRoute('app.briefs.show', ['brief' => 42])
                ->actions('app.briefs.open', 'app.briefs.generate-draft')
                ->relationship(
                    ResourceRelationship::make('site', 'belongs_to', ResourceType::SITE)
                        ->resourceId(9)
                        ->resourceKey('site:9')
                        ->title('Main site')
                        ->metadata(['source' => 'client_site_id'])
                )
        );

    $actionRegistry = ActionRegistry::make()
        ->register(Action::make('app.briefs.open', 'Open brief')->route('app.briefs.show', ['brief' => 42]))
        ->register(Action::make('app.briefs.generate-draft', 'Generate draft', 'generate')->route('app.briefs.generate-draft', ['brief' => 42], 'POST'));

    $resourceRegistry->assertAvailableActionsExist($actionRegistry);

    $resource = $resourceRegistry->resolve('brief:42', ResourceContext::make());

    expect($resource['available_actions'])->toBe(['app.briefs.open', 'app.briefs.generate-draft'])
        ->and($resource['relationships'][0])->toMatchArray([
            'key' => 'site',
            'type' => 'belongs_to',
            'resource_type' => ResourceType::SITE,
            'resource_id' => 9,
            'resource_key' => 'site:9',
            'title' => 'Main site',
            'metadata' => ['source' => 'client_site_id'],
        ]);
});

it('does not expose policy-denied resources and resolves permission metadata for visible resources', function () {
    Gate::define('view-resource-registry-test', fn (User $user, object $target): bool => (bool) $target->allowed);
    Gate::define('update-resource-registry-test', fn (User $user, object $target): bool => (bool) $target->editable);

    $user = new User(['role' => 'owner']);
    $user->id = 10;

    $registry = ResourceRegistry::make()
        ->register(ResourceType::make(ResourceType::OPPORTUNITY, 'Opportunity')->model('App\\Models\\Opportunity')->primaryRoute('app.opportunities.show'))
        ->register(
            Resource::make('opportunity:visible', ResourceType::OPPORTUNITY, 1)
                ->model('App\\Models\\Opportunity')
                ->title('Visible opportunity')
                ->primaryRoute('app.opportunities.show', ['opportunity' => 1])
                ->policy('view-resource-registry-test', (object) ['allowed' => true, 'editable' => true])
                ->permission('update', ['ability' => 'update-resource-registry-test', 'target' => (object) ['editable' => true]])
                ->permission('archive', false)
        )
        ->register(
            Resource::make('opportunity:hidden', ResourceType::OPPORTUNITY, 2)
                ->model('App\\Models\\Opportunity')
                ->title('Hidden opportunity')
                ->primaryRoute('app.opportunities.show', ['opportunity' => 2])
                ->policy('view-resource-registry-test', (object) ['allowed' => false, 'editable' => false])
                ->permission('update', true)
        );

    $visible = $registry->forContext(ResourceContext::make(['user' => $user]));

    expect($visible)->toHaveCount(1)
        ->and($visible[0]['key'])->toBe('opportunity:visible')
        ->and($visible[0]['permissions'])->toBe(['update' => true, 'archive' => false])
        ->and($registry->resolve('opportunity:hidden', ResourceContext::make(['user' => $user])))->toBeNull()
        ->and($registry->resolve('opportunity:hidden', ResourceContext::make(['user' => $user]), includeHidden: true)['authorized'])->toBeFalse();
});

it('preserves drawer, preview, history, AI, search, and notification metadata', function () {
    $registry = ResourceRegistry::make()
        ->register(ResourceType::make(ResourceType::SEO_AUDIT, 'SEO audit')->model('App\\Models\\SeoAudit')->primaryRoute('app.sites.seo-audits.show'))
        ->register(
            Resource::make('seo_audit:88', ResourceType::SEO_AUDIT, 88)
                ->model('App\\Models\\SeoAudit')
                ->title('June audit')
                ->primaryUrl('/app/sites/4/insights/audits/88')
                ->drawer('seo-audit-detail', width: 'lg', metadata: ['section' => 'issues'])
                ->preview(['summary_fields' => ['score', 'issue_count']])
                ->history(['events' => ['created', 'fix_generated'], 'timeline_key' => 'seo_audit'])
                ->ai(['explainability' => ['inputs' => ['crawl', 'issue_severity'], 'safe_to_summarize' => true]])
                ->search(['tokens' => ['seo', 'audit'], 'rank' => 'site-scoped'])
                ->notification(['channels' => ['in_app'], 'template' => 'seo_audit_completed'])
        );

    $resource = $registry->resolve('seo_audit:88', ResourceContext::make());

    expect($resource['drawer'])->toMatchArray(['target' => 'seo-audit-detail', 'mode' => 'inspect', 'width' => 'lg'])
        ->and($resource['primary_url'])->toBe('/app/sites/4/insights/audits/88')
        ->and($resource['preview']['summary_fields'])->toBe(['score', 'issue_count'])
        ->and($resource['history']['timeline_key'])->toBe('seo_audit')
        ->and($resource['ai']['explainability']['safe_to_summarize'])->toBeTrue()
        ->and($resource['search']['tokens'])->toBe(['seo', 'audit'])
        ->and($resource['notification']['template'])->toBe('seo_audit_completed');
});

it('asserts resource endpoint references and action references without executing actions', function () {
    $valid = ResourceRegistry::make()
        ->register(ResourceType::make(ResourceType::CONTENT, 'Content')->model('App\\Models\\Content')->primaryRoute('app.content.show'))
        ->register(
            Resource::make('content:7', ResourceType::CONTENT, 7)
                ->model('App\\Models\\Content')
                ->title('Mapped content')
                ->primaryRoute('app.content.show', ['content' => 7])
                ->actions('content.open')
        );

    $invalidRoute = ResourceRegistry::make()
        ->register(ResourceType::make(ResourceType::CONTENT, 'Content')->model('App\\Models\\Content')->primaryRoute('app.content.show'))
        ->register(
            Resource::make('content:missing-route', ResourceType::CONTENT, 8)
                ->model('App\\Models\\Content')
                ->title('Missing route')
                ->primaryRoute('missing.resource.route')
        );

    $valid->assertAllResourcesMapToExistingReferences();
    $valid->assertAvailableActionsExist(ActionRegistry::make()->register(
        Action::make('content.open', 'Open content')->route('app.content.show', ['content' => 7])
    ));

    expect(fn () => $invalidRoute->assertAllResourcesMapToExistingReferences())
        ->toThrow(LogicException::class, 'content:missing-route')
        ->and(fn () => $valid->assertAvailableActionsExist(ActionRegistry::make()))
        ->toThrow(LogicException::class, 'content.open');
});

it('bridges resource context to action context without duplicating action registry logic', function () {
    $resourceContext = ResourceContext::make([
        'surface' => Action::SURFACE_DRAWER,
        'resource_type' => ResourceType::DRAFT,
        'resource_id' => 55,
        'workspace_id' => 'workspace-1',
        'permissions' => ['draft.open' => true],
    ]);

    $actionContext = $resourceContext->toActionContext();

    expect($actionContext)->toBeInstanceOf(ActionContext::class)
        ->and($actionContext->surface)->toBe(Action::SURFACE_DRAWER)
        ->and($actionContext->resourceType)->toBe(ResourceType::DRAFT)
        ->and($actionContext->resourceId)->toBe(55)
        ->and($actionContext->workspaceId)->toBe('workspace-1')
        ->and($actionContext->permission('draft.open'))->toBeTrue();
});
