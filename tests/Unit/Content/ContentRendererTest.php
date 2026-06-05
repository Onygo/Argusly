<?php

use App\Services\Content\ContentRenderer;

it('renders plain text blocks as paragraphs', function () {
    $html = app(ContentRenderer::class)
        ->renderToHtml("First paragraph.\n\nSecond paragraph.")
        ->toHtml();

    expect($html)
        ->toContain('<p>First paragraph.</p>')
        ->toContain('<p>Second paragraph.</p>');
});

it('renders markdown headings and emphasis', function () {
    $html = app(ContentRenderer::class)
        ->renderToHtml("# Heading\n\nThis is **bold** and *italic*.")
        ->toHtml();

    expect($html)
        ->toContain('<h1>Heading</h1>')
        ->toContain('<strong>bold</strong>')
        ->toContain('<em>italic</em>');
});

it('renders unordered and ordered lists', function () {
    $html = app(ContentRenderer::class)
        ->renderToHtml("- One\n- Two\n\n1. First\n2. Second")
        ->toHtml();

    expect($html)
        ->toContain('<ul>')
        ->toContain('<li>One</li>')
        ->toContain('<ol>')
        ->toContain('<li>First</li>');
});

it('preserves safe links and hardens external attributes', function () {
    $html = app(ContentRenderer::class)
        ->renderToHtml('[Docs](https://example.com/docs "Read docs")')
        ->toHtml();

    expect($html)
        ->toContain('href="https://example.com/docs"')
        ->toContain('title="Read docs"')
        ->toContain('target="_blank"')
        ->toContain('rel="nofollow noopener noreferrer"');
});

it('removes unsafe script tags and inline handlers', function () {
    $html = app(ContentRenderer::class)
        ->sanitizeHtmlFragment('<p onclick="alert(1)">Safe</p><script>alert(1)</script>')
        ->toHtml();

    expect($html)
        ->toContain('<p>Safe</p>')
        ->not->toContain('<script>')
        ->not->toContain('onclick=')
        ->not->toContain('alert(1)');
});

it('removes javascript href values', function () {
    $html = app(ContentRenderer::class)
        ->sanitizeHtmlFragment('<a href="javascript:alert(1)" title="Unsafe">Click</a>')
        ->toHtml();

    expect($html)
        ->toContain('<a title="Unsafe">Click</a>')
        ->not->toContain('href=')
        ->not->toContain('javascript:');
});

it('returns empty html for empty input', function () {
    $html = app(ContentRenderer::class)->renderToHtml(null)->toHtml();

    expect($html)->toBe('');
});

it('supports markdown table rendering', function () {
    $html = app(ContentRenderer::class)
        ->renderToHtml("| Name | Value |\n| --- | --- |\n| A | 1 |")
        ->toHtml();

    expect($html)
        ->toContain('<table>')
        ->toContain('<th>Name</th>')
        ->toContain('<td>1</td>');
});

it('keeps safe html structures while sanitizing attributes', function () {
    $html = app(ContentRenderer::class)
        ->sanitizeHtmlFragment('<h2>Subheading</h2><p>Body with <code>inline</code>.</p><iframe src="https://x.test"></iframe>')
        ->toHtml();

    expect($html)
        ->toContain('<h2>Subheading</h2>')
        ->toContain('<code>inline</code>')
        ->not->toContain('<iframe');
});

it('repairs common mojibake punctuation before rendering', function () {
    $input = 'If you are already using AI for first drafts, the next step is not '
        . "\xC3\xA2\xC2\x80\xC2\x9C" . 'more prompts' . "\xC3\xA2\xC2\x80\xC2\x9D"
        . ' or ' . "\xC3\xA2\xC2\x80\xC2\x9C" . 'a better model.' . "\xC3\xA2\xC2\x80\xC2\x9D";

    $html = app(ContentRenderer::class)->renderToHtml($input)->toHtml();

    expect($html)
        ->toContain('not “more prompts” or “a better model.”')
        ->not->toContain("\xC3\xA2\xC2\x80\xC2\x9C");
});
