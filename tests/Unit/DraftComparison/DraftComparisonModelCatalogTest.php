<?php

use App\Services\DraftComparison\DraftComparisonModelCatalog;
it('returns text model options from configured providers', function () {
    $catalog = app(DraftComparisonModelCatalog::class);
    $options = $catalog->options();

    expect($options)->not->toBeEmpty();

    foreach ($options as $option) {
        expect($option)->toHaveKeys(['key', 'provider', 'provider_label', 'model', 'label']);
        expect((string) $option['key'])->toContain(':');
        expect((string) $option['provider'])->not->toBe('');
        expect((string) $option['model'])->not->toBe('');
    }
});

it('resolves valid model selections and ignores unknown values', function () {
    $catalog = app(DraftComparisonModelCatalog::class);
    $options = $catalog->options();

    $selected = [
        (string) data_get($options, '0.key'),
        'unknown-provider:model-x',
    ];

    $resolved = $catalog->resolveSelections($selected);

    expect($resolved)->toHaveCount(1);
    expect((string) $resolved[0]['key'])->toBe((string) data_get($options, '0.key'));
});
