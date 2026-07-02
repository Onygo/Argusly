<?php

it('keeps drawer support classes in the support interaction namespace', function () {
    foreach ([
        app_path('Support/Interaction/Drawer.php'),
        app_path('Support/Interaction/DrawerContext.php'),
        app_path('Support/Interaction/DrawerRegistry.php'),
        app_path('Support/Interaction/DrawerState.php'),
        app_path('Support/Interaction/DrawerResolver.php'),
    ] as $file) {
        expect(file_exists($file))->toBeTrue()
            ->and(file_get_contents($file))->toContain('namespace App\\Support\\Interaction;');
    }
});

it('does not couple drawer support classes to controllers, blade views, jobs, or business services', function () {
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

it('keeps drawer blade components generic and model-free', function () {
    $forbidden = [
        'App\\Models',
        'Illuminate\\Database',
        '::query(',
        'DB::',
        'app(',
        'resolve(',
    ];

    foreach (glob(resource_path('views/components/drawer/*.blade.php')) as $file) {
        $source = file_get_contents($file);

        foreach ($forbidden as $needle) {
            expect($source)
                ->not->toContain($needle, sprintf('%s should not query models or resolve services', basename($file)));
        }
    }
});

it('documents the universal right drawer engine boundaries', function () {
    $doc = strtolower(file_get_contents(base_path('docs/universal-right-drawer.md')));

    expect($doc)
        ->toContain('purpose')
        ->toContain('non-goals')
        ->toContain('architecture')
        ->toContain('drawer lifecycle')
        ->toContain('resource integration')
        ->toContain('action integration')
        ->toContain('application shell integration')
        ->toContain('focus behavior')
        ->toContain('escape behavior')
        ->toContain('deep-link strategy')
        ->toContain('history strategy')
        ->toContain('future ai explanation drawer')
        ->toContain('deferred drawer migrations');
});
