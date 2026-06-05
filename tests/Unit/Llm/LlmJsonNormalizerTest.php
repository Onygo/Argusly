<?php

use App\Services\Llm\LlmJsonNormalizer;

it('decodes a valid structured html payload', function () {
    $normalizer = new LlmJsonNormalizer();

    $decoded = $normalizer->decode('{"title":null,"content_html":"<p>Hello world</p>","change_summary":"Added CTA","seo":{"seo_title":null,"seo_meta_description":null,"seo_h1":null}}', 'openai');

    expect($decoded)->toBeArray()
        ->and($decoded['content_html'])->toBe('<p>Hello world</p>')
        ->and($decoded['change_summary'])->toBe('Added CTA');
});

it('strips markdown fences around json payloads', function () {
    $normalizer = new LlmJsonNormalizer();

    $decoded = $normalizer->decode("```json\n{\"content_html\":\"<p>Book a demo</p>\",\"change_summary\":\"Added CTA\"}\n```", 'openai');

    expect($decoded)->toBe([
        'content_html' => '<p>Book a demo</p>',
        'change_summary' => 'Added CTA',
    ]);
});

it('repairs embedded newlines inside html json strings', function () {
    $normalizer = new LlmJsonNormalizer();

    $decoded = $normalizer->decode("{\"content_html\":\"<p>Line 1\nLine 2</p>\",\"change_summary\":\"Added CTA\"}", 'openai');

    expect($decoded)->toBe([
        'content_html' => "<p>Line 1\nLine 2</p>",
        'change_summary' => 'Added CTA',
    ]);
});

it('repairs illegal control characters inside json strings', function () {
    $normalizer = new LlmJsonNormalizer();

    $decoded = $normalizer->decode("{\"content_html\":\"<p>Line 1\x0BLine 2</p>\",\"change_summary\":\"Added CTA\"}", 'openai');

    expect($decoded)->toBeArray()
        ->and($decoded['content_html'])->toContain('Line 1')
        ->and($decoded['content_html'])->toContain('Line 2')
        ->and($decoded['change_summary'])->toBe('Added CTA');
});

it('extracts the first json object from surrounding prose', function () {
    $normalizer = new LlmJsonNormalizer();

    $decoded = $normalizer->decode("Here is your result:\n{\"content_html\":\"<p>CTA</p>\",\"change_summary\":\"Added CTA\"}\nThanks.", 'openai');

    expect($decoded)->toBe([
        'content_html' => '<p>CTA</p>',
        'change_summary' => 'Added CTA',
    ]);
});

it('recovers truncated json by completing open structures', function () {
    $normalizer = new LlmJsonNormalizer();

    // Truncated after content_html value but missing closing braces
    $decoded = $normalizer->decode('{"title":null,"content_html":"<p>Hello world</p>","change_summary":"Added CTA"', 'openai');

    expect($decoded)->toBeArray()
        ->and($decoded['content_html'])->toBe('<p>Hello world</p>')
        ->and($decoded['change_summary'])->toBe('Added CTA');
});

it('recovers truncated json with nested objects', function () {
    $normalizer = new LlmJsonNormalizer();

    // Truncated before closing the seo object and outer object
    $decoded = $normalizer->decode('{"title":null,"content_html":"<p>CTA added</p>","change_summary":"Added CTA","seo":{"seo_title":null,"seo_meta_description":"Test"', 'openai');

    expect($decoded)->toBeArray()
        ->and($decoded['content_html'])->toBe('<p>CTA added</p>')
        ->and($decoded['seo'])->toBeArray()
        ->and($decoded['seo']['seo_meta_description'])->toBe('Test');
});

it('extracts a specific field value from malformed json', function () {
    $normalizer = new LlmJsonNormalizer();

    // JSON that's malformed but has the content_html field intact
    $value = $normalizer->extractFieldValue('{"title":null,"content_html":"<p>Valid HTML content</p>","change_summary":"Added', 'content_html');

    expect($value)->toBe('<p>Valid HTML content</p>');
});

it('extracts a truncated field value from malformed json', function () {
    $normalizer = new LlmJsonNormalizer();

    // Field value that was truncated without closing quote
    $value = $normalizer->extractFieldValue('{"content_html":"<p>Valid HTML content here', 'content_html');

    expect($value)->toBe('<p>Valid HTML content here');
});

it('returns null when extracting a non existent field', function () {
    $normalizer = new LlmJsonNormalizer();

    $value = $normalizer->extractFieldValue('{"title":"test"}', 'content_html');

    expect($value)->toBeNull();
});

it('detects truncated json correctly', function () {
    $normalizer = new LlmJsonNormalizer();

    expect($normalizer->isTruncatedJson('{"complete":"object"}'))->toBeFalse()
        ->and($normalizer->isTruncatedJson('{"truncated":"value'))->toBeTrue()
        ->and($normalizer->isTruncatedJson('{"truncated":{"nested":"value"'))->toBeTrue()
        ->and($normalizer->isTruncatedJson('not json at all'))->toBeFalse()
        ->and($normalizer->isTruncatedJson(''))->toBeFalse();
});

it('provides detailed diagnostics when decode fails', function () {
    $normalizer = new LlmJsonNormalizer();

    $result = $normalizer->decodeWithDiagnostics('not valid json', 'openai');

    expect($result['decoded'])->toBeNull()
        ->and($result['error'])->not->toBeNull();
});

it('indicates recovery strategy used in diagnostics', function () {
    $normalizer = new LlmJsonNormalizer();

    // Test with markdown fenced JSON that needs extraction
    $result = $normalizer->decodeWithDiagnostics("```json\n{\"content_html\":\"<p>test</p>\"}\n```", 'openai');

    expect($result['decoded'])->toBeArray()
        ->and($result['decoded']['content_html'])->toBe('<p>test</p>');
});

it('handles escaped html entities inside json strings', function () {
    $normalizer = new LlmJsonNormalizer();

    $decoded = $normalizer->decode('{"content_html":"<p>Test &amp; verify</p>","change_summary":"Added CTA"}', 'openai');

    expect($decoded)->toBeArray()
        ->and($decoded['content_html'])->toBe('<p>Test &amp; verify</p>');
});

it('handles multiline html inside json strings', function () {
    $normalizer = new LlmJsonNormalizer();

    $decoded = $normalizer->decode("{\"content_html\":\"<h1>Title</h1>\\n<p>Paragraph one.</p>\\n<p>Paragraph two.</p>\",\"change_summary\":\"Added CTA\"}", 'openai');

    expect($decoded)->toBeArray()
        ->and($decoded['content_html'])->toContain('Title')
        ->and($decoded['content_html'])->toContain('Paragraph one')
        ->and($decoded['change_summary'])->toBe('Added CTA');
});
