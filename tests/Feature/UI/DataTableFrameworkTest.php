<?php

use Illuminate\Support\Facades\Blade;

it('renders the reusable data table component contract', function () {
    $html = Blade::render(<<<'BLADE'
        <x-data-table label="Content table" description="A table for content rows." density="compact">
            <x-slot:search>
                <input name="q" value="visibility">
            </x-slot:search>
            <x-slot:filters>
                <select name="status"><option>Ready</option></select>
            </x-slot:filters>
            <x-slot:actions>
                <a href="/create">Create</a>
            </x-slot:actions>
            <x-slot:bulkActions>
                <x-data-table.bulk-actions>
                    <button>Delete selected</button>
                </x-data-table.bulk-actions>
            </x-slot:bulkActions>
            <x-data-table.header sticky>
                <x-data-table.row>
                    <x-data-table.cell heading>Name</x-data-table.cell>
                    <x-data-table.cell heading align="right">Status</x-data-table.cell>
                    <x-data-table.cell heading>Actions</x-data-table.cell>
                </x-data-table.row>
            </x-data-table.header>
            <tbody>
                <x-data-table.row interactive>
                    <x-data-table.cell label="Name">Launch plan</x-data-table.cell>
                    <x-data-table.cell label="Status" align="right">
                        <x-data-table.badge tone="success" label="Ready" />
                    </x-data-table.cell>
                    <x-data-table.cell label="Actions">
                        <x-data-table.actions>
                            <a href="/open" aria-label="Open Launch plan">Open</a>
                        </x-data-table.actions>
                    </x-data-table.cell>
                </x-data-table.row>
            </tbody>
            <x-slot:pagination>
                <nav>Pagination</nav>
            </x-slot:pagination>
        </x-data-table>
    BLADE);

    expect($html)
        ->toContain('pl-data-table')
        ->toContain('aria-label="Content table"')
        ->toContain('A table for content rows.')
        ->toContain('pl-data-table-toolbar')
        ->toContain('name="q"')
        ->toContain('name="status"')
        ->toContain('pl-data-table-bulk-actions')
        ->toContain('pl-data-table__header--sticky')
        ->toContain('pl-data-table__row--interactive')
        ->toContain('data-label="Name"')
        ->toContain('pl-data-table-badge--success')
        ->toContain('aria-label="Row actions"')
        ->toContain('Pagination');
});

it('renders data table empty and loading states', function () {
    $emptyHtml = Blade::render(<<<'BLADE'
        <x-data-table label="Empty table">
            <x-data-table.header>
                <x-data-table.row>
                    <x-data-table.cell heading>Name</x-data-table.cell>
                </x-data-table.row>
            </x-data-table.header>
            <tbody>
                <x-data-table.empty colspan="1" title="Nothing here" description="Try another filter." />
            </tbody>
        </x-data-table>
    BLADE);

    $loadingHtml = Blade::render(<<<'BLADE'
        <x-data-table label="Loading table" loading :skeleton-rows="2" />
    BLADE);

    expect($emptyHtml)
        ->toContain('Nothing here')
        ->toContain('Try another filter.')
        ->toContain('colspan="1"');

    expect($loadingHtml)
        ->toContain('pl-loading-skeleton__row');
});

it('migrates a high-value admin table page to the universal data table', function () {
    $adminPage = file_get_contents(resource_path('views/admin/queues/index.blade.php'));

    expect($adminPage)
        ->toContain('<x-data-table label="Queue overview"')
        ->toContain('<x-data-table label="Failed queue jobs"')
        ->toContain('<x-data-table.bulk-actions>')
        ->toContain('form="failed-bulk-delete-form"')
        ->not->toContain('<table');
});

it('migrates a high-value app table page to the universal data table', function () {
    $appPage = file_get_contents(resource_path('views/app/content/index.blade.php'));

    expect($appPage)
        ->toContain('<x-data-table label="Content lifecycle table"')
        ->toContain('<x-data-table.bulk-actions')
        ->toContain('data-content-tree-row')
        ->toContain('data-delete-trigger')
        ->not->toContain('<table');
});

it('migrates an operational admin table from the second batch', function () {
    $adminPage = file_get_contents(resource_path('views/admin/agent-runs/index.blade.php'));

    expect($adminPage)
        ->toContain('<x-data-table label="Agent runs"')
        ->toContain('<x-data-table.badge')
        ->toContain('<x-slot:pagination>{{ $rows->links() }}</x-slot:pagination>')
        ->not->toContain('<table');
});

it('migrates a programmatic app table from the second batch', function () {
    $appPage = file_get_contents(resource_path('views/app/programmatic-brief-blueprints/index.blade.php'));

    expect($appPage)
        ->toContain('<x-data-table label="Programmatic brief blueprints"')
        ->toContain('<x-data-table.empty colspan="7" title="No brief blueprints prepared yet" />')
        ->toContain('<x-slot:pagination>{{ $blueprints->links() }}</x-slot:pagination>')
        ->not->toContain('<table');
});

it('migrates a secondary app content table from the third batch', function () {
    $appPage = file_get_contents(resource_path('views/app/sites/competitors/index.blade.php'));

    expect($appPage)
        ->toContain('<x-data-table label="High-performing entities to consider"')
        ->toContain('<x-data-table label="Competitor list"')
        ->toContain('<x-data-table.actions align="start">')
        ->toContain('route(\'app.sites.competitors.candidates.accept\'')
        ->not->toContain('<table');
});

it('migrates a secondary admin content table from the third batch', function () {
    $adminPage = file_get_contents(resource_path('views/admin/contact-submissions/index.blade.php'));

    expect($adminPage)
        ->toContain('<x-data-table label="Contact submissions"')
        ->toContain('<x-data-table.badge tone="success" label="sent" />')
        ->toContain('<x-data-table.cell class="pb-3 text-xs text-textSecondary" colspan="9">')
        ->toContain('<x-slot:pagination>{{ $submissions->links() }}</x-slot:pagination>')
        ->not->toContain('<table');
});

it('migrates an admin billing table from the workspace batch', function () {
    $adminPage = file_get_contents(resource_path('views/admin/billing/index.blade.php'));

    expect($adminPage)
        ->toContain('<x-data-table label="Billing organizations"')
        ->toContain('<x-data-table.badge :tone="$row[\'payment_health\'] === \'healthy\'')
        ->toContain('route(\'admin.organizations.billing\', $row[\'organization\'])')
        ->not->toContain('<table');
});

it('migrates an app workspace site table from the workspace batch', function () {
    $appPage = file_get_contents(resource_path('views/app/sites.blade.php'));

    expect($appPage)
        ->toContain('<x-data-table label="Connected sites"')
        ->toContain('@include(\'app.sites.partials.row-actions\', [\'site\' => $site])')
        ->toContain('<x-slot:pagination>{{ $sites->links() }}</x-slot:pagination>')
        ->not->toContain('<table');
});

it('migrates low-risk detail and report tables to the universal data table', function () {
    $files = [
        'views/app/research/show.blade.php' => '<x-data-table label="Research sources"',
        'views/app/programmatic-publication-plans/show.blade.php' => '<x-data-table label="Publication plan items"',
        'views/app/content/batches/show.blade.php' => '<x-data-table label="Batch items"',
        'views/app/sites/llm-tracking/partials/sources.blade.php' => '<x-data-table label="Source citations"',
    ];

    foreach ($files as $file => $dataTableLabel) {
        $view = file_get_contents(resource_path($file));

        expect($view)
            ->toContain($dataTableLabel)
            ->toContain('<x-data-table.empty')
            ->not->toContain('<table');
    }
});
