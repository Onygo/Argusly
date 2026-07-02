<?php

use App\Services\ContentVisuals\VisualPlanService;

it('normalizes visual plan metadata for generated drafts', function (): void {
    $plan = app(VisualPlanService::class)->normalize([
        'featured' => [
            'prompt' => '<b>Clean editorial hero</b>',
            'alt_text' => 'Hero alt',
        ],
        'assets' => [
            [
                'asset_key' => 'Market Map 1',
                'type' => 'bar chart',
                'caption' => 'Visibility by channel',
                'structured_data' => [
                    'title' => 'AI visibility',
                    'data' => [
                        ['label' => 'Google', 'value' => 42],
                        ['label' => 'ChatGPT', 'value' => 18],
                    ],
                ],
            ],
            [
                'asset_key' => 'Market Map 1',
                'type' => 'image',
            ],
        ],
    ]);

    expect($plan['featured']['prompt'])->toBe('Clean editorial hero')
        ->and($plan['assets'])->toHaveCount(1)
        ->and($plan['assets'][0]['asset_key'])->toBe('market-map-1')
        ->and($plan['assets'][0]['type'])->toBe('bar_chart')
        ->and($plan['assets'][0]['structured_data']['data'][0]['value'])->toBe(42.0);
});

it('stores normalized plans in meta and removes empty plans', function (): void {
    $service = app(VisualPlanService::class);

    $meta = $service->putInMeta(['generation' => ['model' => 'x']], [
        'assets' => [
            ['asset_key' => 'Stat', 'type' => 'stat_card', 'caption' => 'A useful stat'],
        ],
    ]);

    expect(data_get($meta, 'visual_plan.assets.0.asset_key'))->toBe('stat')
        ->and(data_get($meta, 'generation.model'))->toBe('x');

    $empty = $service->putInMeta($meta, []);

    expect($empty)->not->toHaveKey('visual_plan');
});
