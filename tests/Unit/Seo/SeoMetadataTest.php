<?php

use App\Support\SeoMetadata;

it('normalizes focus keyword aliases into primary_keyword', function () {
    $normalized = SeoMetadata::normalize([
        'focus_keyword' => '  ai governance workflow  ',
    ]);

    expect($normalized['primary_keyword'])->toBe('ai governance workflow');
});

it('merges primary keyword from the first non-empty source', function () {
    $merged = SeoMetadata::merge(
        [
            'primary_keyword' => '',
        ],
        [
            'seo' => [
                'focus_keyword' => 'First match',
            ],
        ],
        [
            'primary_keyword' => 'Second match',
        ],
    );

    expect($merged['primary_keyword'])->toBe('First match');
});

it('keeps primary_keyword nullable when no focus keyword is provided', function () {
    $merged = SeoMetadata::merge(['seo_title' => 'Only title']);

    expect($merged['primary_keyword'])->toBeNull()
        ->and($merged['seo_title'])->toBe('Only title');
});

it('normalizes robots directives and schema type fields', function () {
    $normalized = SeoMetadata::normalize([
        'robots' => 'noindex, follow',
        'schema' => ['type' => 'HowTo'],
    ]);

    expect($normalized['robots_index'])->toBeFalse()
        ->and($normalized['robots_follow'])->toBeTrue()
        ->and($normalized['schema_type'])->toBe('HowTo');
});

it('merges robots and schema while preserving explicit false values', function () {
    $merged = SeoMetadata::merge(
        [
            'robots_index' => false,
        ],
        [
            'robots_index' => true,
            'robots_follow' => true,
            'schema_type' => 'Article',
        ],
    );

    expect($merged['robots_index'])->toBeFalse()
        ->and($merged['robots_follow'])->toBeTrue()
        ->and($merged['schema_type'])->toBe('Article');
});

it('resolves content context with typed seo columns before legacy content_seo', function () {
    $resolved = SeoMetadata::resolveForContentContext([
        'primary_keyword' => 'typed keyword',
        'seo_title' => 'Typed title',
        'seo_meta_description' => 'Typed description',
        'robots_index' => false,
        'robots_follow' => true,
        'schema_type' => 'Article',
        'seo' => [
            'primary_keyword' => 'legacy keyword',
            'meta_title' => 'Legacy title',
            'meta_description' => 'Legacy description',
            'robots_index' => true,
            'robots_follow' => false,
            'schema_type' => 'HowTo',
        ],
    ]);

    expect($resolved['primary_keyword'])->toBe('typed keyword')
        ->and($resolved['seo_title'])->toBe('Typed title')
        ->and($resolved['seo_meta_description'])->toBe('Typed description')
        ->and($resolved['robots_index'])->toBeFalse()
        ->and($resolved['robots_follow'])->toBeTrue()
        ->and($resolved['schema_type'])->toBe('Article');
});

it('resolves draft context using content_seo fallback when typed columns are empty', function () {
    $resolved = SeoMetadata::resolveForDraftContext(
        [
            'content' => [
                'primary_keyword' => '',
                'seo_title' => '',
                'seo_meta_description' => '',
                'robots_index' => null,
                'robots_follow' => null,
                'schema_type' => '',
                'seo' => [
                    'primary_keyword' => 'legacy keyword',
                    'meta_title' => 'Legacy title',
                    'meta_description' => 'Legacy description',
                    'robots_index' => false,
                    'robots_follow' => false,
                    'schema_type' => 'HowTo',
                ],
            ],
            'meta' => [
                'primary_keyword' => 'draft meta keyword',
            ],
            'brief' => [
                'primary_keyword' => 'brief keyword',
            ],
        ],
        [
            'primary_keyword' => 'payload keyword',
        ],
    );

    expect($resolved['primary_keyword'])->toBe('legacy keyword')
        ->and($resolved['seo_title'])->toBe('Legacy title')
        ->and($resolved['seo_meta_description'])->toBe('Legacy description')
        ->and($resolved['robots_index'])->toBeFalse()
        ->and($resolved['robots_follow'])->toBeFalse()
        ->and($resolved['schema_type'])->toBe('HowTo');
});
