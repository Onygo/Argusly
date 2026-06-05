<?php

use App\Support\Analytics\AnalyticsUrlKey;

it('normalizes tracked urls consistently for key matching', function () {
    expect(AnalyticsUrlKey::normalizeUrl(' https://EXAMPLE.com/Article/?utm=123#section '))
        ->toBe('https://example.com/article');

    expect(AnalyticsUrlKey::normalizeUrl('https://example.com/article/'))
        ->toBe('https://example.com/article');

    expect(AnalyticsUrlKey::normalizeUrl('https://example.com/article'))
        ->toBe('https://example.com/article');

    expect(AnalyticsUrlKey::fromUrl('https://EXAMPLE.com/Article/?utm=123#section'))
        ->toBe('example.com/article');
});

