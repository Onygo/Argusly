<?php

use App\Support\Interaction\DrawerMetadataBuilder;
use App\Support\Interaction\DrawerState;
use App\Support\Interaction\DrawerTarget;
use App\Support\Interaction\ResourceType;
use Illuminate\Support\Facades\Blade;

function drawerAdoptionDescriptor(): array
{
    return DrawerMetadataBuilder::make()->build(
        DrawerTarget::make('content.inspect', DrawerState::MODE_PREVIEW, 'lg')
            ->forResource(ResourceType::CONTENT, 123, 'content:123')
            ->forAction('content.inspect')
            ->withHref('/app/content/123'),
        [
            'resource' => [
                'key' => 'content:123',
                'type' => ResourceType::CONTENT,
                'id' => 123,
                'title' => 'Universal content',
                'subtitle' => 'Ready content',
                'status' => ['label' => 'Ready', 'tone' => 'success'],
            ],
        ],
    )->toArray();
}

it('renders drawer links as href-backed anchors with future enhancement metadata', function () {
    $descriptor = drawerAdoptionDescriptor();

    $html = Blade::render(<<<'BLADE'
        <x-drawer-link :descriptor="$descriptor">Inspect content</x-drawer-link>
    BLADE, ['descriptor' => $descriptor]);

    expect($html)
        ->toContain('<a')
        ->toContain('href="/app/content/123"')
        ->toContain('data-drawer-trigger="link"')
        ->toContain('data-drawer-target="content.inspect"')
        ->toContain('data-drawer-mode="preview"')
        ->toContain('data-drawer-resource-key="content:123"')
        ->toContain('data-command-palette-ready="true"')
        ->toContain('data-context-menu-ready="true"')
        ->toContain('data-hover-preview-ready="true"')
        ->toContain('aria-haspopup="dialog"')
        ->toContain('Inspect content');
});

it('renders drawer buttons as link fallbacks when an href exists', function () {
    $descriptor = drawerAdoptionDescriptor();

    $html = Blade::render(<<<'BLADE'
        <x-drawer-button :descriptor="$descriptor" />
    BLADE, ['descriptor' => $descriptor]);

    expect($html)
        ->toContain('<a')
        ->toContain('role="button"')
        ->toContain('href="/app/content/123"')
        ->toContain('data-drawer-trigger="button"')
        ->toContain('Universal content');
});

it('renders drawer buttons as inert buttons when no fallback exists', function () {
    $html = Blade::render(<<<'BLADE'
        <x-drawer-button target="future.drawer" label="Open future drawer" />
    BLADE);

    expect($html)
        ->toContain('<button')
        ->toContain('type="button"')
        ->toContain('data-drawer-target="future.drawer"')
        ->toContain('Open future drawer');
});

it('renders drawer previews with href fallback and badge metadata', function () {
    $descriptor = drawerAdoptionDescriptor();

    $html = Blade::render(<<<'BLADE'
        <x-drawer-preview :descriptor="$descriptor">Preview metadata only.</x-drawer-preview>
    BLADE, ['descriptor' => $descriptor]);

    expect($html)
        ->toContain('href="/app/content/123"')
        ->toContain('data-drawer-trigger="preview"')
        ->toContain('Universal content')
        ->toContain('Ready content')
        ->toContain('Ready')
        ->toContain('Preview metadata only.');
});

