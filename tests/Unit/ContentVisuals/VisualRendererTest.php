<?php

use App\Services\Content\ContentRenderer;
use App\Services\ContentVisuals\VisualRenderer;

it('replaces visual placeholders with deterministic chart markup', function (): void {
    $html = '<h2>Channels</h2><p>Context.</p><figure data-asset-key="ai-visibility"></figure>';
    $plan = [
        'featured' => null,
        'version' => 1,
        'assets' => [
            [
                'asset_key' => 'ai-visibility',
                'type' => 'bar_chart',
                'caption' => 'AI visibility by channel.',
                'alt_text' => 'Bar chart showing AI visibility by channel.',
                'structured_data' => [
                    'title' => 'AI visibility',
                    'data' => [
                        ['label' => 'Google', 'value' => 42],
                        ['label' => 'ChatGPT', 'value' => 18],
                    ],
                ],
            ],
        ],
    ];

    $rendered = app(VisualRenderer::class)->replacePlaceholders($html, $plan);
    $sanitized = (string) app(ContentRenderer::class)->sanitizeHtmlFragment($rendered);

    expect($sanitized)->toContain('argusly-visual')
        ->and($sanitized)->toContain('argusly-chart')
        ->and($sanitized)->toContain('AI visibility by channel.')
        ->and($sanitized)->toContain('width:100%');
});

it('leaves unknown placeholders publishable', function (): void {
    $html = '<p>Body.</p><figure data-asset-key="missing"></figure>';
    $plan = ['featured' => null, 'version' => 1, 'assets' => []];

    $rendered = app(VisualRenderer::class)->replacePlaceholders($html, $plan);

    expect($rendered)->toContain('data-asset-key="missing"');
});
