<?php

use App\Services\Content\ContentRenderNormalizer;

it('cleans empty paragraphs keyword dumps duplicate resources and preserves semantic lists', function () {
    $html = implode('', [
        '<p>Intro with <a href="/en/blog/ai-search">AI search</a> inline.</p>',
        '<p> </p><p>&nbsp;</p>',
        '<p>Google SGE</p><p>Bing Copilot</p><p>ChatGPT</p><p>Perplexity</p>',
        '<p>Wil je dieper ingaan op hoe je dit in jouw WordPress-omgeving kunt inrichten, bekijk dan ook onze aanvullende resources: <a href="/en/blog/related">Related article 1</a>.</p>',
        '<h2>Checklist</h2><ul><li>Keep bullets</li><li><strong>Preserve</strong> emphasis</li></ul>',
        '<blockquote><p>Keep quotes intact.</p></blockquote>',
    ]);

    $normalized = app(ContentRenderNormalizer::class)->normalize($html, [
        'context' => 'test',
        'inline_links_active' => true,
    ]);

    expect($normalized)
        ->toContain('<p>Intro with <a href="/en/blog/ai-search">AI search</a> inline.</p>')
        ->toContain('<ul><li>Keep bullets</li><li><strong>Preserve</strong> emphasis</li></ul>')
        ->toContain('<blockquote><p>Keep quotes intact.</p></blockquote>')
        ->not->toContain('<p> </p>')
        ->not->toContain('<p>&nbsp;</p>')
        ->not->toContain('Google SGE')
        ->not->toContain('Bing Copilot')
        ->not->toContain('aanvullende resources')
        ->not->toContain('/en/blog/related');
});

it('removes legacy placeholder resource sections when inline links are not active', function () {
    $section = '<p><strong>Related reading:</strong> <a href="/en/blog/one">Related article 1</a>.</p>';
    $normalized = app(ContentRenderNormalizer::class)->normalize(
        '<p>Article body without inline anchors.</p>' . $section . $section,
        ['context' => 'test', 'inline_links_active' => false],
    );

    expect($normalized)
        ->toContain('Article body without inline anchors.')
        ->not->toContain('Related reading:')
        ->not->toContain('/en/blog/one');
});

it('removes old related article list blocks and their intro copy', function () {
    $html = implode('', [
        '<p>Article body stays visible.</p>',
        '<p>To go deeper into building a resilient content engine and structuring your site for semantic SEO, explore these resources:</p>',
        '<ul>',
        '<li><a href="/en/blog/one">Related article 1</a></li>',
        '<li><a href="/en/blog/two">Related article 2</a></li>',
        '<li><a href="/en/blog/three">Related article 3</a></li>',
        '</ul>',
        '<p><strong>Related reading:</strong><a href="/en/blog/one">Related article 1</a> · <a href="/en/blog/two">Related article 2</a></p>',
    ]);

    $normalized = app(ContentRenderNormalizer::class)->normalize($html, [
        'context' => 'test',
        'inline_links_active' => false,
    ]);

    expect($normalized)
        ->toContain('Article body stays visible.')
        ->not->toContain('To go deeper')
        ->not->toContain('Related article 1')
        ->not->toContain('Related reading:');
});

it('keeps generated resource sections when anchor labels are meaningful', function () {
    $section = '<p><strong>Related reading:</strong> <a href="/en/blog/one">Semantic SEO guide</a>.</p>';
    $normalized = app(ContentRenderNormalizer::class)->normalize(
        '<p>Article body without inline anchors.</p>' . $section . $section,
        ['context' => 'test', 'inline_links_active' => false],
    );

    expect(substr_count($normalized, 'Related reading:'))->toBe(1)
        ->and($normalized)->toContain('/en/blog/one')
        ->and($normalized)->toContain('Semantic SEO guide');
});

it('is idempotent and does not create isolated anchor-only paragraphs', function () {
    $html = '<p>Intro <a href="/en/blog/target">target link</a> text.</p><p><a href="/en/blog/orphan">Orphan link</a></p><p>Closing paragraph.</p>';

    $first = app(ContentRenderNormalizer::class)->normalize($html, [
        'context' => 'test',
        'inline_links_active' => true,
    ]);
    $second = app(ContentRenderNormalizer::class)->normalize($first, [
        'context' => 'test',
        'inline_links_active' => true,
    ]);

    expect($first)->toBe($second)
        ->and($first)->toContain('href="/en/blog/target"')
        ->and($first)->not->toContain('href="/en/blog/orphan"');
});
