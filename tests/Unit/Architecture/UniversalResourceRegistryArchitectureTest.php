<?php

it('keeps the universal resource registry in the support interaction namespace', function () {
    foreach ([
        app_path('Support/Interaction/Resource.php'),
        app_path('Support/Interaction/ResourceType.php'),
        app_path('Support/Interaction/ResourceRegistry.php'),
        app_path('Support/Interaction/ResourceContext.php'),
        app_path('Support/Interaction/ResourceRelationship.php'),
        app_path('Support/Interaction/ResourceResolver.php'),
    ] as $file) {
        expect(file_exists($file))->toBeTrue()
            ->and(file_get_contents($file))->toContain('namespace App\\Support\\Interaction;');
    }
});

it('does not couple resource registry primitives to controllers, views, jobs, or business services', function () {
    $forbidden = [
        'App\\Http\\Controllers',
        'App\\Jobs',
        'App\\Services',
        'Illuminate\\View',
        'resources/views',
    ];

    foreach (glob(app_path('Support/Interaction/Resource*.php')) as $file) {
        $source = file_get_contents($file);

        foreach ($forbidden as $needle) {
            expect($source)
                ->not->toContain($needle, sprintf('%s should not depend on %s', basename($file), $needle));
        }
    }
});

it('keeps the resource registry descriptive and separate from action execution', function () {
    $forbidden = [
        '->execute(',
        'dispatch(',
        'dispatchSync(',
        'Bus::',
        'Artisan::call',
        'Http::',
    ];

    foreach (glob(app_path('Support/Interaction/Resource*.php')) as $file) {
        $source = file_get_contents($file);

        foreach ($forbidden as $needle) {
            expect($source)
                ->not->toContain($needle, sprintf('%s should not execute behavior through %s', basename($file), $needle));
        }
    }
});

it('documents every resource registry primitive and integration boundary', function () {
    $doc = strtolower(file_get_contents(base_path('docs/universal-resource-registry.md')));

    expect($doc)
        ->toContain('resource')
        ->toContain('resourcetype')
        ->toContain('resourceregistry')
        ->toContain('resourcecontext')
        ->toContain('resourcerelationship')
        ->toContain('resourceresolver')
        ->toContain('action registry')
        ->toContain('command palette')
        ->toContain('global search')
        ->toContain('right drawer')
        ->toContain('explainable ai')
        ->toContain('deferred resources')
        ->toContain('test strategy');
});
