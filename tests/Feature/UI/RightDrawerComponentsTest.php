<?php

use Illuminate\Support\Facades\Blade;

it('renders the drawer component as an accessible region with resolved metadata', function () {
    $html = Blade::render(<<<'BLADE'
        <x-drawer.drawer :drawer="[
            'key' => 'draft.inspect',
            'mode' => 'inspect',
            'modal' => false,
            'width' => 'lg',
            'title' => 'Draft detail',
            'subtitle' => 'Draft',
            'description' => 'Inspect the selected draft.',
            'tabs' => [
                ['key' => 'overview', 'label' => 'Overview'],
            ],
            'sections' => [
                ['title' => 'Summary', 'items' => [['label' => 'Status', 'value' => 'Ready']]],
            ],
            'footer_actions' => [
                ['key' => 'draft.close', 'label' => 'Close'],
            ],
            'focus_return_target' => '#draft-row-1',
            'keyboard_escape' => ['enabled' => true, 'closes_drawer' => true],
            'state' => ['open' => true, 'loading' => false, 'empty' => false, 'error' => false],
        ]" />
    BLADE);

    expect($html)
        ->toContain('role="region"')
        ->toContain('data-drawer="draft.inspect"')
        ->toContain('data-drawer-mode="inspect"')
        ->toContain('data-focus-return-target="#draft-row-1"')
        ->toContain('Draft detail')
        ->toContain('Overview')
        ->toContain('Summary')
        ->toContain('Status')
        ->toContain('Close');
});

it('renders the drawer header with close button support', function () {
    $html = Blade::render(<<<'BLADE'
        <x-drawer.drawer-header
            title="Research project"
            subtitle="Research"
            description="Readonly research metadata."
            title-id="drawer-title"
            description-id="drawer-description"
            focus-return-target="#research-row"
        />
    BLADE);

    expect($html)
        ->toContain('id="drawer-title"')
        ->toContain('Research project')
        ->toContain('Readonly research metadata.')
        ->toContain('aria-label="Close drawer"')
        ->toContain('data-focus-return-target="#research-row"');
});

it('renders drawer tabs from metadata', function () {
    $html = Blade::render(<<<'BLADE'
        <x-drawer.drawer-tabs :tabs="[
            ['key' => 'overview', 'label' => 'Overview'],
            ['key' => 'history', 'label' => 'History'],
        ]" active="history" />
    BLADE);

    expect($html)
        ->toContain('role="tablist"')
        ->toContain('data-drawer-tab="overview"')
        ->toContain('data-drawer-tab="history"')
        ->toContain('aria-selected="true"');
});

it('renders drawer sections with definition metadata', function () {
    $html = Blade::render(<<<'BLADE'
        <x-drawer.drawer-section :section="[
            'title' => 'Signals',
            'description' => 'Signals attached to this resource.',
            'items' => [
                ['label' => 'Score', 'value' => '84'],
            ],
        ]" />
    BLADE);

    expect($html)
        ->toContain('Signals')
        ->toContain('Signals attached to this resource.')
        ->toContain('Score')
        ->toContain('84');
});

it('renders drawer footer actions as inert buttons', function () {
    $html = Blade::render(<<<'BLADE'
        <x-drawer.drawer-footer :actions="[
            ['key' => 'drawer.inspect', 'label' => 'Inspect'],
            ['key' => 'drawer.disabled', 'label' => 'Disabled', 'disabled' => true],
        ]" />
    BLADE);

    expect($html)
        ->toContain('data-drawer-action="drawer.inspect"')
        ->toContain('Inspect')
        ->toContain('Disabled')
        ->toContain('disabled');
});

it('renders the drawer loading state with accessible text', function () {
    $html = Blade::render(<<<'BLADE'
        <x-drawer.drawer-loading :state="['title' => 'Loading detail', 'description' => 'Fetching metadata.']" />
    BLADE);

    expect($html)
        ->toContain('role="status"')
        ->toContain('Loading detail')
        ->toContain('Fetching metadata.');
});

it('renders the drawer empty state with accessible text', function () {
    $html = Blade::render(<<<'BLADE'
        <x-drawer.drawer-empty :state="['title' => 'No resource selected', 'description' => 'Pick a row first.']" />
    BLADE);

    expect($html)
        ->toContain('role="status"')
        ->toContain('No resource selected')
        ->toContain('Pick a row first.');
});

it('renders the drawer error state with accessible text', function () {
    $html = Blade::render(<<<'BLADE'
        <x-drawer.drawer-error :state="['title' => 'Drawer failed', 'description' => 'The drawer could not resolve.']" />
    BLADE);

    expect($html)
        ->toContain('role="alert"')
        ->toContain('Drawer failed')
        ->toContain('The drawer could not resolve.');
});

it('renders safely when optional slots and metadata are omitted', function () {
    $html = Blade::render(<<<'BLADE'
        <x-drawer.drawer :drawer="[
            'key' => 'empty.contract',
            'state' => ['open' => true, 'loading' => false, 'empty' => false, 'error' => false],
        ]" />
    BLADE);

    expect($html)
        ->toContain('data-drawer="empty.contract"')
        ->toContain('aria-label="Close drawer"')
        ->not->toContain('Undefined');
});
