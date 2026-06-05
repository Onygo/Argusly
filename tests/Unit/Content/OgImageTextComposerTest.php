<?php

use App\Services\Images\OgImageTextComposer;

it('composes keyword and title once for normal input', function () {
    $result = app(OgImageTextComposer::class)->compose(
        'Practical guide for B2B search visibility',
        'Authority Engineering'
    );

    expect($result['keyword'])->toBe('Authority Engineering')
        ->and($result['title'])->toBe('Practical guide for B2B search visibility');
});

it('keeps long titles intact and normalized for downstream clamping', function () {
    $result = app(OgImageTextComposer::class)->compose(
        "   This   is   a very long title that should remain available for wrapping and clamping in the renderer   ",
        'AI Strategy'
    );

    expect($result['keyword'])->toBe('AI Strategy')
        ->and($result['title'])->toBe('This is a very long title that should remain available for wrapping and clamping in the renderer');
});

it('falls back to title only when keyword is missing', function () {
    $result = app(OgImageTextComposer::class)->compose(
        'Only title should render',
        null
    );

    expect($result['keyword'])->toBe('')
        ->and($result['title'])->toBe('Only title should render');
});

it('omits keyword line when keyword already appears in title', function () {
    $result = app(OgImageTextComposer::class)->compose(
        'AI Governance: practical playbook for scale',
        'AI Governance',
        true
    );

    expect($result['keyword'])->toBe('')
        ->and($result['title'])->toBe('AI Governance: practical playbook for scale');
});

it('uses keyword once when title is missing', function () {
    $result = app(OgImageTextComposer::class)->compose(
        null,
        'Edge Delivery'
    );

    expect($result['keyword'])->toBe('')
        ->and($result['title'])->toBe('Edge Delivery');
});
