<?php

use App\Enums\SupportedLanguage;
use App\Models\Content;
use App\Services\Seo\CanonicalUrlService;

it('localizes legacy public blog live urls for non-default content locales', function (): void {
    $content = (new Content())->forceFill([
        'type' => 'article',
        'language' => SupportedLanguage::NL->value,
        'publish_url_key' => 'privacy-regulations-and-crawling-mistakes-teams-should-avoid',
    ]);

    $url = app(CanonicalUrlService::class)->liveUrlForContent(
        $content,
        'https://argusly.com/blog/privacy-regulations-and-crawling-mistakes-teams-should-avoid?utm_source=test'
    );

    expect($url)->toBe('https://argusly.com/nl/blog/privacy-regulations-and-crawling-mistakes-teams-should-avoid?utm_source=test');
});

it('infers the slug from legacy public blog live urls when publish metadata is missing', function (): void {
    $content = (new Content())->forceFill([
        'type' => 'article',
        'language' => SupportedLanguage::NL->value,
    ]);

    $url = app(CanonicalUrlService::class)->liveUrlForContent(
        $content,
        'https://argusly.com/blog/privacy-regulations-and-crawling-mistakes-teams-should-avoid'
    );

    expect($url)->toBe('https://argusly.com/nl/blog/privacy-regulations-and-crawling-mistakes-teams-should-avoid');
});

it('infers localized slugs from configured public blog segments', function (): void {
    config([
        'marketing_routing.locales' => ['en', 'nl', 'de'],
        'marketing_routing.segments.blog.de' => 'artikel',
    ]);

    app('router')->get('/de/artikel/{slug}', [
        'as' => 'localized.de.public.blog.show',
        'uses' => fn () => null,
    ]);

    $content = (new Content())->forceFill([
        'type' => 'article',
        'language' => SupportedLanguage::DE->value,
    ]);

    $url = app(CanonicalUrlService::class)->liveUrlForContent(
        $content,
        'https://argusly.com/artikel/ki-sichtbarkeit-leitfaden'
    );

    expect($url)->toBe('https://argusly.com/de/artikel/ki-sichtbarkeit-leitfaden');
});

it('keeps external live urls unchanged', function (): void {
    $content = (new Content())->forceFill([
        'type' => 'article',
        'language' => SupportedLanguage::NL->value,
        'publish_url_key' => 'privacy-regulations-and-crawling-mistakes-teams-should-avoid',
    ]);

    $url = app(CanonicalUrlService::class)->liveUrlForContent(
        $content,
        'https://customer.example.com/blog/privacy-regulations-and-crawling-mistakes-teams-should-avoid'
    );

    expect($url)->toBe('https://customer.example.com/blog/privacy-regulations-and-crawling-mistakes-teams-should-avoid');
});

it('localizes legacy public blog live urls for newly configured locales', function (): void {
    config([
        'marketing_routing.locales' => ['en', 'nl', 'de'],
        'marketing_routing.segments.blog.de' => 'blog',
    ]);

    app('router')->get('/de/blog/{slug}', [
        'as' => 'localized.de.public.blog.show',
        'uses' => fn () => null,
    ]);

    $slug = 'ki-sichtbarkeit-leitfaden';
    $content = (new Content())->forceFill([
        'type' => 'article',
        'language' => SupportedLanguage::DE->value,
        'publish_url_key' => $slug,
    ]);

    $url = app(CanonicalUrlService::class)->liveUrlForContent(
        $content,
        'https://argusly.com/blog/' . $slug
    );

    expect($url)->toBe('https://argusly.com/de/blog/' . $slug);
});
