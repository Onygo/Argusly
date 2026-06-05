<?php

use App\Support\LocaleHelper;

it('filters visible locales to supported user-facing values', function () {
    expect(LocaleHelper::visibleLocales([
        'en',
        'x-default',
        null,
        'nl-NL',
        'internal-preview',
        'EN',
        'de',
    ]))->toBe(['en', 'nl']);
});

it('filters visible locale urls to supported published locales', function () {
    expect(LocaleHelper::visibleLocaleUrls([
        'nl' => 'https://example.com/nl/blog/bronpost',
        'x-default' => 'https://example.com/en/blog/source-post',
        'en-US' => 'https://example.com/en/blog/source-post',
        'internal-preview' => 'https://example.com/preview',
        'de' => '',
    ]))->toBe([
        'nl' => 'https://example.com/nl/blog/bronpost',
        'en' => 'https://example.com/en/blog/source-post',
    ]);
});

it('returns configured public locales only', function () {
    expect(LocaleHelper::publicLocales())->toBe(['en', 'nl']);
});
