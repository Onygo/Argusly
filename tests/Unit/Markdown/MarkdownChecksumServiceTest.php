<?php

use App\Services\Markdown\MarkdownChecksumService;

it('generates deterministic checksums for equivalent markdown payloads', function () {
    $service = app(MarkdownChecksumService::class);

    $first = $service->generate("# Title\r\n\r\nParagraph.", "<h1>Title</h1>\r\n<p>Paragraph.</p>", 'en', 1);
    $second = $service->generate("# Title\n\nParagraph.", "<h1>Title</h1>\n<p>Paragraph.</p>", 'en', 1);

    expect($first)->not->toBeNull()
        ->and($first)->toBe($second);
});

it('changes the checksum when locale or artifact version changes', function () {
    $service = app(MarkdownChecksumService::class);

    $base = $service->generate('# Title', '<h1>Title</h1>', 'en', 1);
    $differentLocale = $service->generate('# Title', '<h1>Title</h1>', 'nl', 1);
    $differentVersion = $service->generate('# Title', '<h1>Title</h1>', 'en', 2);

    expect($differentLocale)->not->toBe($base)
        ->and($differentVersion)->not->toBe($base);
});
