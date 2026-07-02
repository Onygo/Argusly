<?php

it('keeps drawer adoption classes in the support interaction namespace', function () {
    foreach ([
        app_path('Support/Interaction/DrawerOpenAction.php'),
        app_path('Support/Interaction/DrawerDescriptor.php'),
        app_path('Support/Interaction/DrawerTarget.php'),
        app_path('Support/Interaction/DrawerResourceAdapter.php'),
        app_path('Support/Interaction/DrawerActionAdapter.php'),
        app_path('Support/Interaction/DrawerHistoryAdapter.php'),
        app_path('Support/Interaction/DrawerMetadataBuilder.php'),
    ] as $file) {
        expect(file_exists($file))->toBeTrue()
            ->and(file_get_contents($file))->toContain('namespace App\\Support\\Interaction;');
    }
});

it('keeps drawer adoption infrastructure decoupled from controllers jobs services and views', function () {
    $forbidden = [
        'App\\Http\\Controllers',
        'App\\Jobs',
        'App\\Services',
        'Illuminate\\View',
        'resources/views',
        'dispatch(',
        'dispatchSync(',
        'handle(',
    ];

    foreach (glob(app_path('Support/Interaction/Drawer*.php')) as $file) {
        $source = file_get_contents($file);

        foreach ($forbidden as $needle) {
            expect($source)
                ->not->toContain($needle, sprintf('%s should not depend on or execute %s', basename($file), $needle));
        }
    }
});

it('adds drawer adoption blade helpers without migrating production pages', function () {
    foreach ([
        resource_path('views/components/drawer-link.blade.php'),
        resource_path('views/components/drawer-button.blade.php'),
        resource_path('views/components/drawer-preview.blade.php'),
    ] as $file) {
        expect(file_exists($file))->toBeTrue();
    }

    $productionRoots = [
        resource_path('views/app'),
        resource_path('views/admin'),
    ];

    foreach ($productionRoots as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            expect($source)
                ->not->toContain('<x-drawer-link')
                ->not->toContain('<x-drawer-button')
                ->not->toContain('<x-drawer-preview');
        }
    }
});

it('documents the universal drawer adoption layer', function () {
    $doc = strtolower(file_get_contents(base_path('docs/universal-drawer-adoption-layer.md')));

    expect($doc)
        ->toContain('purpose')
        ->toContain('architecture')
        ->toContain('fallback strategy')
        ->toContain('route fallback')
        ->toContain('progressive enhancement')
        ->toContain('resource mapping')
        ->toContain('action mapping')
        ->toContain('history strategy')
        ->toContain('url strategy')
        ->toContain('future js enhancement')
        ->toContain('future accessibility');
});
