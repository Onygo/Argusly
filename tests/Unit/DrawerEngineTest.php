<?php

use App\Support\Interaction\Action;
use App\Support\Interaction\ActionRegistry;
use App\Support\Interaction\Drawer;
use App\Support\Interaction\DrawerContext;
use App\Support\Interaction\DrawerRegistry;
use App\Support\Interaction\DrawerResolver;
use App\Support\Interaction\DrawerState;
use App\Support\Interaction\Resource;
use App\Support\Interaction\ResourceRegistry;
use App\Support\Interaction\ResourceType;

it('resolves the drawer contract metadata', function () {
    $drawer = Drawer::make('content.inspect')
        ->resource('content', 123, 'content.123')
        ->mode(DrawerState::MODE_INSPECT)
        ->modal()
        ->width('lg')
        ->title('Content detail')
        ->subtitle('Content')
        ->description('Inspect content metadata.')
        ->tabs([['key' => 'overview', 'label' => 'Overview']])
        ->sections([['title' => 'Summary']])
        ->footerActions([['key' => 'content.open', 'label' => 'Open']])
        ->loadingState(['title' => 'Loading content'])
        ->emptyState(['title' => 'No content'])
        ->errorState(['title' => 'Content unavailable'])
        ->focusReturnTarget('#content-row-123')
        ->keyboardEscape(enabled: true, closesDrawer: true, strategy: 'close')
        ->deepLink(['strategy' => 'query', 'parameter' => 'drawer'])
        ->history(['push' => true])
        ->ai(['explainable' => true])
        ->resourceMetadata(['source' => 'resource-registry'])
        ->actionMetadata(['surface' => 'drawer'])
        ->state(DrawerState::open(DrawerState::MODE_INSPECT));

    $resolved = $drawer->resolve(DrawerContext::make());

    expect($resolved)
        ->toMatchArray([
            'key' => 'content.inspect',
            'resource_type' => 'content',
            'resource_key' => 'content.123',
            'resource_id' => 123,
            'mode' => 'inspect',
            'modal' => true,
            'width' => 'lg',
            'title' => 'Content detail',
            'subtitle' => 'Content',
            'description' => 'Inspect content metadata.',
            'focus_return_target' => '#content-row-123',
        ])
        ->and($resolved['tabs'])->toHaveCount(1)
        ->and($resolved['sections'])->toHaveCount(1)
        ->and($resolved['footer_actions'])->toHaveCount(1)
        ->and($resolved['loading_state']['title'])->toBe('Loading content')
        ->and($resolved['empty_state']['title'])->toBe('No content')
        ->and($resolved['error_state']['title'])->toBe('Content unavailable')
        ->and($resolved['keyboard_escape']['strategy'])->toBe('close')
        ->and($resolved['deep_link']['strategy'])->toBe('query')
        ->and($resolved['history']['push'])->toBeTrue()
        ->and($resolved['ai']['explainable'])->toBeTrue()
        ->and($resolved['resource_metadata']['source'])->toBe('resource-registry')
        ->and($resolved['action_metadata']['surface'])->toBe('drawer');
});

it('registers drawers and rejects duplicate drawer keys', function () {
    $registry = DrawerRegistry::make()->register(Drawer::make('site.preview'));

    expect($registry->has('site.preview'))->toBeTrue()
        ->and($registry->get('site.preview'))->toBeInstanceOf(Drawer::class)
        ->and(fn () => $registry->register(Drawer::make('site.preview')))
        ->toThrow(LogicException::class, 'site.preview');
});

it('carries drawer context metadata and registry context', function () {
    $resourceRegistry = ResourceRegistry::make();
    $actionRegistry = ActionRegistry::make();

    $context = DrawerContext::make([
        'page_key' => 'app.content.index',
        'route_name' => 'app.content.index',
        'workspace_id' => 10,
        'resource_type' => 'content',
        'resource_key' => 'content.10',
        'resource_id' => 10,
        'action_key' => 'content.inspect',
        'mode' => 'preview',
        'resource_registry' => $resourceRegistry,
        'action_registry' => $actionRegistry,
        'permissions' => ['drawer.open' => true],
        'metadata' => ['origin' => 'row-action'],
    ]);

    expect($context->workspaceId)->toBe('10')
        ->and($context->permission('drawer.open'))->toBeTrue()
        ->and($context->metadata('origin'))->toBe('row-action')
        ->and($context->resourceRegistry)->toBe($resourceRegistry)
        ->and($context->actionRegistry)->toBe($actionRegistry)
        ->and($context->resourceKey)->toBe('content.10')
        ->and($context->toResourceContext()->resourceType)->toBe('content')
        ->and($context->toActionContext()->drawer['mode'])->toBe('preview');
});

it('models drawer modes and state flags', function () {
    $loading = DrawerState::loading(DrawerState::MODE_PREVIEW, 'Loading preview');
    $empty = DrawerState::empty(DrawerState::MODE_READONLY);
    $error = DrawerState::error(DrawerState::MODE_EDIT, 'Edit unavailable');

    expect($loading->toArray())
        ->toMatchArray(['mode' => 'preview', 'open' => true, 'loading' => true, 'interactive' => false])
        ->and($empty->empty)->toBeTrue()
        ->and($error->error)->toBeTrue()
        ->and($error->canEdit())->toBeFalse()
        ->and(DrawerState::open(DrawerState::MODE_EDIT)->canEdit())->toBeTrue();
});

it('resolves a registered drawer through the drawer resolver', function () {
    $registry = DrawerRegistry::make()->register(
        Drawer::make('brief.inspect')
            ->resource('brief', 7, 'brief.7')
            ->title(fn (DrawerContext $context): string => 'Brief #' . $context->resourceId)
            ->state(DrawerState::open())
    );

    $resolved = (new DrawerResolver($registry))->resolve('brief.inspect', DrawerContext::make([
        'resource_id' => 7,
    ]));

    expect($resolved['key'])->toBe('brief.inspect')
        ->and($resolved['title'])->toBe('Brief #7')
        ->and($resolved['state']['open'])->toBeTrue();
});

it('returns empty and error drawer states safely', function () {
    $resolver = new DrawerResolver(DrawerRegistry::make());

    $empty = $resolver->resolve(null, DrawerContext::make());
    $error = $resolver->resolve('missing.drawer', DrawerContext::make());

    expect($empty['state']['empty'])->toBeTrue()
        ->and($empty['empty_state']['title'])->toBe('No drawer selected')
        ->and($error['state']['error'])->toBeTrue()
        ->and($error['error_state']['title'])->toBe('Drawer unavailable');
});

it('attaches resource and action metadata without executing actions', function () {
    $resources = ResourceRegistry::make()
        ->registerType(ResourceType::make('content', 'Content'))
        ->registerResource(
            Resource::make('content.42', 'content', 42)
                ->title('Content 42')
                ->drawer('content.drawer', 'inspect', 'lg')
                ->metadata(['origin' => 'resource-registry'])
        );

    $actions = ActionRegistry::make()->register(
        Action::make('content.inspect', 'Inspect content', 'open')
            ->drawer('content.drawer')
            ->visibleIn(Action::SURFACE_DRAWER)
            ->metadata(['origin' => 'action-registry'])
    );

    $drawers = DrawerRegistry::make()->register(
        Drawer::make('content.drawer')
            ->resource('content', 42, 'content.42')
            ->footerActions([['key' => 'content.inspect', 'label' => 'Inspect']])
            ->state(DrawerState::open())
    );

    $resolved = (new DrawerResolver($drawers, $resources, $actions))->resolve(
        'content.drawer',
        DrawerContext::make([
            'resource_key' => 'content.42',
            'resource_type' => 'content',
            'resource_id' => 42,
            'action_keys' => ['content.inspect'],
        ])
    );

    expect($resolved['resolved_resource'])
        ->toMatchArray(['key' => 'content.42', 'type' => 'content', 'id' => 42, 'title' => 'Content 42'])
        ->and($resolved['resolved_actions']['content.inspect'])
        ->toMatchArray([
            'key' => 'content.inspect',
            'label' => 'Inspect content',
            'execution_mode' => Action::EXECUTION_LINK,
            'drawer' => ['target' => 'content.drawer', 'mode' => 'inspect', 'width' => 'md', 'modal' => false],
            'metadata' => ['origin' => 'action-registry'],
        ]);
});

it('can resolve drawer metadata from resource registry drawer targets', function () {
    $resources = ResourceRegistry::make()
        ->registerType(ResourceType::make('site', 'Site'))
        ->registerResource(
            Resource::make('site.5', 'site', 5)
                ->title('Example site')
                ->subtitle('Site')
                ->drawer('site.inspect', 'inspect', 'lg', ['source' => 'resource-drawer'])
        );

    $resolved = (new DrawerResolver(resources: $resources))->resolve(
        'site.inspect',
        DrawerContext::make(['resource_key' => 'site.5'])
    );

    expect($resolved)
        ->toMatchArray([
            'key' => 'site.inspect',
            'resource_type' => 'site',
            'resource_key' => 'site.5',
            'resource_id' => 5,
            'title' => 'Example site',
            'subtitle' => 'Site',
            'width' => 'lg',
        ])
        ->and($resolved['metadata']['source'])->toBe('resource-drawer');
});
