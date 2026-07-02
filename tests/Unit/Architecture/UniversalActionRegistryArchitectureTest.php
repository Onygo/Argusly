<?php

it('keeps the universal action registry in the support interaction namespace', function () {
    foreach ([
        app_path('Support/Interaction/Action.php'),
        app_path('Support/Interaction/ActionGroup.php'),
        app_path('Support/Interaction/ActionRegistry.php'),
        app_path('Support/Interaction/ActionContext.php'),
        app_path('Support/Interaction/ActionPolicyResolver.php'),
    ] as $file) {
        expect(file_exists($file))->toBeTrue()
            ->and(file_get_contents($file))->toContain('namespace App\\Support\\Interaction;');
    }
});

it('does not couple action registry primitives to controllers, views, jobs, or business services', function () {
    $forbidden = [
        'App\\Http\\Controllers',
        'App\\Jobs',
        'App\\Services',
        'Illuminate\\View',
        'resources/views',
    ];

    foreach (glob(app_path('Support/Interaction/*.php')) as $file) {
        $source = file_get_contents($file);

        foreach ($forbidden as $needle) {
            expect($source)
                ->not->toContain($needle, sprintf('%s should not depend on %s', basename($file), $needle));
        }
    }
});

it('documents every registry primitive required by the interaction framework', function () {
    $doc = strtolower(file_get_contents(base_path('docs/universal-action-registry.md')));

    expect($doc)
        ->toContain('action')
        ->toContain('actiongroup')
        ->toContain('actionregistry')
        ->toContain('actioncontext')
        ->toContain('actionpolicyresolver')
        ->toContain('authorization')
        ->toContain('visibility')
        ->toContain('disabled reasons')
        ->toContain('confirmation metadata')
        ->toContain('bulk support')
        ->toContain('drawer support')
        ->toContain('ai explainability metadata');
});
