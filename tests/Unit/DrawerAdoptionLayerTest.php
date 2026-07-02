<?php

use App\Support\Interaction\Action;
use App\Support\Interaction\ActionContext;
use App\Support\Interaction\ActionRegistry;
use App\Support\Interaction\DrawerActionAdapter;
use App\Support\Interaction\DrawerHistoryAdapter;
use App\Support\Interaction\DrawerMetadataBuilder;
use App\Support\Interaction\DrawerOpenAction;
use App\Support\Interaction\DrawerResourceAdapter;
use App\Support\Interaction\DrawerState;
use App\Support\Interaction\DrawerTarget;
use App\Support\Interaction\Resource;
use App\Support\Interaction\ResourceContext;
use App\Support\Interaction\ResourceRegistry;
use App\Support\Interaction\ResourceRelationship;
use App\Support\Interaction\ResourceType;

it('builds drawer descriptors with default metadata and fallback urls', function () {
    $target = DrawerTarget::make('content.inspect', DrawerState::MODE_PREVIEW, 'lg')
        ->forResource(ResourceType::CONTENT, 123, 'content:123')
        ->withHref('/app/content/123');

    $descriptor = DrawerMetadataBuilder::make()->build($target, [
        'resource' => [
            'key' => 'content:123',
            'type' => ResourceType::CONTENT,
            'id' => 123,
            'title' => 'Pipeline article',
            'subtitle' => 'Content',
            'status' => ['label' => 'Ready', 'tone' => 'success'],
            'preview' => ['summary_fields' => ['status']],
            'ai' => ['safe_to_summarize' => true],
            'relationships' => [['key' => 'site', 'title' => 'Main site']],
            'permissions' => ['view' => true],
        ],
    ]);

    expect($descriptor->toArray())
        ->toMatchArray([
            'href' => '/app/content/123',
            'title' => 'Pipeline article',
            'subtitle' => 'Content',
            'icon' => 'panel-right-open',
            'permissions' => ['view' => true],
            'preview' => ['summary_fields' => ['status']],
            'ai' => ['safe_to_summarize' => true],
        ])
        ->and($descriptor->badges[0])->toBe(['label' => 'Ready', 'tone' => 'success'])
        ->and($descriptor->tabs)->toContain(['key' => 'overview', 'label' => 'Overview'])
        ->and($descriptor->tabs)->toContain(['key' => 'relationships', 'label' => 'Relationships'])
        ->and($descriptor->sections[0]['title'])->toBe('Overview')
        ->and($descriptor->footerActions[0]['href'])->toBe('/app/content/123')
        ->and($descriptor->history['drawer_url'])->toContain('drawer=content.inspect')
        ->and($descriptor->loading['title'])->toBe('Loading detail')
        ->and($descriptor->empty['title'])->toBe('No detail selected')
        ->and($descriptor->errors['title'])->toBe('Detail unavailable');
});

it('maps resources into drawer descriptors without loading global production resources', function () {
    $registry = ResourceRegistry::make()
        ->registerType(ResourceType::make(ResourceType::BRIEF, 'Brief'))
        ->registerResource(
            Resource::make('brief:42', ResourceType::BRIEF, 42)
                ->title('Brief detail')
                ->subtitle('Brief')
                ->primaryUrl('/app/briefs/42')
                ->drawer('brief.inspect', width: 'lg')
                ->permission('view', true)
                ->relationship(
                    ResourceRelationship::make('content', 'creates', ResourceType::CONTENT)
                        ->resourceId(77)
                        ->title('Generated content')
                )
        );

    $descriptor = DrawerResourceAdapter::make()->descriptorFromRegistry(
        $registry,
        'brief:42',
        ResourceContext::make(),
    );

    expect($descriptor)->not->toBeNull()
        ->and($descriptor->target->target)->toBe('brief.inspect')
        ->and($descriptor->target->resourceType)->toBe(ResourceType::BRIEF)
        ->and($descriptor->target->resourceKey)->toBe('brief:42')
        ->and($descriptor->href)->toBe('/app/briefs/42')
        ->and($descriptor->permissions['view'])->toBeTrue()
        ->and($descriptor->relationships[0]['title'])->toBe('Generated content');
});

it('maps drawer actions into inert open action contracts', function () {
    $registry = ActionRegistry::make()->register(
        Action::make('draft.inspect', 'Inspect draft', 'open')
            ->url('/app/drafts/9')
            ->icon('file-pen-line')
            ->resource(ResourceType::DRAFT, 9)
            ->drawer('draft.inspect', width: 'lg')
            ->visibleIn(Action::SURFACE_ROW, Action::SURFACE_DRAWER)
            ->history(['event' => 'draft.inspect'])
            ->ai(['intent' => 'inspect_draft'])
    );

    $adapter = DrawerActionAdapter::make();
    $descriptor = $adapter->descriptorFromRegistry(
        $registry,
        'draft.inspect',
        ActionContext::make(['resource_type' => ResourceType::DRAFT, 'resource_id' => 9]),
    );
    $openAction = $adapter->openActionFor($registry->resolve('draft.inspect', ActionContext::make()));

    expect($descriptor->target->target)->toBe('draft.inspect')
        ->and($descriptor->target->actionKey)->toBe('draft.inspect')
        ->and($descriptor->href)->toBe('/app/drafts/9')
        ->and($descriptor->icon)->toBe('file-pen-line')
        ->and($openAction)->toBeInstanceOf(DrawerOpenAction::class)
        ->and($openAction->toArray())
        ->toMatchArray([
            'key' => 'draft.inspect',
            'label' => 'Inspect draft',
            'execution_mode' => Action::EXECUTION_DRAWER,
            'method' => 'GET',
            'url' => '/app/drafts/9',
            'drawer' => ['target' => 'draft.inspect', 'mode' => 'inspect', 'width' => 'lg', 'modal' => false],
            'resource' => ['type' => ResourceType::DRAFT, 'id' => 9, 'key' => null],
        ]);
});

it('builds drawer history urls without mutating history by default', function () {
    $target = DrawerTarget::make('site.inspect')
        ->forResource(ResourceType::SITE, 5, 'site:5')
        ->forAction('site.inspect')
        ->withHref('/app/sites/5?tab=overview');

    $history = (new DrawerHistoryAdapter())->metadata($target);

    expect($history)
        ->toMatchArray([
            'strategy' => 'query',
            'push' => false,
            'replace' => false,
            'fallback_url' => '/app/sites/5?tab=overview',
        ])
        ->and($history['drawer_url'])->toContain('tab=overview')
        ->and($history['drawer_url'])->toContain('drawer=site.inspect')
        ->and($history['drawer_url'])->toContain('drawer_resource=site%3A5')
        ->and($history['drawer_url'])->toContain('drawer_action=site.inspect');
});
