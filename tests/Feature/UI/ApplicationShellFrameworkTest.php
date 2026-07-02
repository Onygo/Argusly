<?php

use Illuminate\Support\Facades\Blade;

it('renders the reusable application shell regions and primitive components', function () {
    $html = Blade::render(<<<'BLADE'
        <x-app-shell
            title="Content operations"
            description="Plan, filter, measure, and publish from one workspace."
            :breadcrumbs="[
                ['label' => 'Home', 'url' => '/app'],
                ['label' => 'Content operations'],
            ]"
        >
            <x-slot:primaryActions>
                <a href="/app/content/create" class="pl-btn-primary">Create</a>
            </x-slot:primaryActions>
            <x-slot:filterBar>
                <input class="pl-input" name="q" value="visibility">
            </x-slot:filterBar>
            <x-slot:metricSection>
                <x-metric-section title="Pipeline health">
                    <x-metric-card label="Ready" value="12" icon="check-circle" tone="success" />
                </x-metric-section>
            </x-slot:metricSection>
            <x-section-container title="Main content">
                <x-empty-state title="Nothing queued" description="Published work and pending drafts will appear here." />
            </x-section-container>
            <x-slot:detailDrawer>
                <x-drawer-container open title="Draft detail">Drawer body</x-drawer-container>
            </x-slot:detailDrawer>
            <x-slot:footerActions>
                <button class="pl-btn-secondary">Save</button>
            </x-slot:footerActions>
        </x-app-shell>
    BLADE);

    expect($html)
        ->toContain('data-app-shell')
        ->toContain('data-shell-region="breadcrumb"')
        ->toContain('data-shell-region="page-header"')
        ->toContain('data-shell-region="primary-actions"')
        ->toContain('data-shell-region="filter-bar"')
        ->toContain('data-shell-region="kpi-section"')
        ->toContain('data-shell-region="main-content"')
        ->toContain('data-shell-region="detail-drawer"')
        ->toContain('data-shell-region="footer-actions"')
        ->toContain('Pipeline health')
        ->toContain('Nothing queued')
        ->toContain('Draft detail');
});

it('migrates the app and admin layouts through the shared shell contract', function () {
    $appLayout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $adminLayout = file_get_contents(resource_path('views/layouts/admin.blade.php'));

    foreach ([$appLayout, $adminLayout] as $layout) {
        expect($layout)
            ->toContain('<x-app-shell')
            ->toContain("@hasSection('breadcrumb')")
            ->toContain("@hasSection('pageHeader')")
            ->toContain("@hasSection('primaryActions')")
            ->toContain("@hasSection('filterBar')")
            ->toContain("@hasSection('metricSection')")
            ->toContain("@hasSection('detailDrawer')")
            ->toContain("@hasSection('footerActions')")
            ->toContain("@yield('content')");
    }
});

it('has migrated P1 app pages using shell sections and metric components', function () {
    $appPage = file_get_contents(resource_path('views/app/content/lifecycle/index.blade.php'));

    expect($appPage)
        ->toContain("@section('pageHeader')")
        ->toContain("@section('primaryActions')")
        ->toContain("@section('filterBar')")
        ->toContain("@section('metricSection')")
        ->toContain('<x-metric-section')
        ->toContain('<x-metric-card');
});

it('has migrated P1 admin pages using shell sections and metric components', function () {
    $adminPage = file_get_contents(resource_path('views/admin/early-access/index.blade.php'));

    expect($adminPage)
        ->toContain("@section('pageHeader')")
        ->toContain("@section('primaryActions')")
        ->toContain("@section('filterBar')")
        ->toContain("@section('metricSection')")
        ->toContain('<x-metric-section')
        ->toContain('<x-metric-card');
});

it('has migrated P2 app pages using shell sections and safe metric/filter extraction', function () {
    $appPage = file_get_contents(resource_path('views/app/human-content/dashboard.blade.php'));

    expect($appPage)
        ->toContain("@section('pageHeader')")
        ->toContain("@section('primaryActions')")
        ->toContain("@section('filterBar')")
        ->toContain("@section('metricSection')")
        ->toContain('<x-metric-section')
        ->toContain('<x-metric-card')
        ->not->toContain('<h1');
});

it('has migrated P2 admin pages using shell sections and safe metric/filter extraction', function () {
    $adminPage = file_get_contents(resource_path('views/admin/agent-runs/index.blade.php'));

    expect($adminPage)
        ->toContain("@section('pageHeader')")
        ->toContain("@section('filterBar')")
        ->toContain("@section('metricSection')")
        ->toContain('<x-metric-section')
        ->toContain('<x-metric-card')
        ->not->toContain('<h1');
});

it('has migrated P3 app pages using shell sections', function () {
    $appPage = file_get_contents(resource_path('views/app/content/automations/index.blade.php'));

    expect($appPage)
        ->toContain("@section('pageHeader')")
        ->toContain("@section('primaryActions')")
        ->toContain("@section('filterBar')")
        ->toContain(':show-heading="false"')
        ->not->toContain('<h1');
});

it('has migrated P3 admin pages using shell sections', function () {
    $adminPage = file_get_contents(resource_path('views/admin/agentic-action-runs/index.blade.php'));

    expect($adminPage)
        ->toContain("@section('pageHeader')")
        ->toContain("@section('filterBar')")
        ->not->toContain('<h1');
});

it('renders filter bars with optional title and actions', function () {
    $html = Blade::render(<<<'BLADE'
        <x-filter-bar title="Filters" description="Refine the queue">
            <x-slot:actions>
                <a href="/reset">Reset</a>
            </x-slot:actions>
            <input name="q" value="agent">
        </x-filter-bar>
    BLADE);

    expect($html)
        ->toContain('pl-filter-bar')
        ->toContain('Filters')
        ->toContain('Refine the queue')
        ->toContain('Reset')
        ->toContain('value="agent"');
});

it('renders filter bars without optional title or actions', function () {
    $html = Blade::render(<<<'BLADE'
        <x-filter-bar>
            <select name="status"><option>All</option></select>
        </x-filter-bar>
    BLADE);

    expect($html)
        ->toContain('pl-filter-bar')
        ->toContain('name="status"')
        ->not->toContain('pl-filter-bar__header');
});
