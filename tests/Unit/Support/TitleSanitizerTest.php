<?php

use App\Support\TitleSanitizer;
use Illuminate\Support\Str;

it('keeps generated titles within the database limit unchanged', function () {
    $title = 'AI cybersecurity architecture for content teams';

    expect(TitleSanitizer::normalize($title))->toBe($title);
});

it('shortens oversized generated titles deterministically and readably', function () {
    $title = implode(' ', array_fill(0, 45, 'architectural security layer'));

    $result = TitleSanitizer::normalizeWithMetadata($title);

    expect($result['was_shortened'])->toBeTrue()
        ->and(mb_strlen($result['title']))->toBeLessThanOrEqual(TitleSanitizer::MAX_LENGTH)
        ->and($result['title'])->not->toEndWith(' ')
        ->and($result['title'])->not->toEndWith(',')
        ->and($result['title'])->not->toEndWith(':')
        ->and($result['title'])->toContain('architectural security');

    expect(TitleSanitizer::normalize($title))->toBe($result['title']);
});

it('still produces a usable slug after title shortening', function () {
    $title = implode(' ', array_fill(0, 40, 'AI cybersecurity as an architectural layer'));
    $persistedTitle = TitleSanitizer::normalize($title);
    $slug = Str::slug($persistedTitle);

    expect($slug)->not->toBe('')
        ->and(mb_strlen($persistedTitle))->toBeLessThanOrEqual(TitleSanitizer::MAX_LENGTH)
        ->and($slug)->toContain('ai-cybersecurity');
});
