<?php

use App\Services\PageIntelligence\PageCrawlerSafetyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

it('blocks localhost and private IP URLs', function (): void {
    $safety = app(PageCrawlerSafetyService::class);

    expect(fn () => $safety->normalizeAndValidate('http://localhost/admin'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $safety->normalizeAndValidate('http://127.0.0.1/admin'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $safety->normalizeAndValidate('http://10.0.0.12/private'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $safety->normalizeAndValidate('http://169.254.169.254/latest'))->toThrow(InvalidArgumentException::class);
});

it('blocks private DNS resolution', function (): void {
    config()->set('page_intelligence.safety.dns_overrides', ['private.example' => ['10.10.10.10']]);

    expect(fn () => app(PageCrawlerSafetyService::class)->normalizeAndValidate('https://private.example/feed.xml'))
        ->toThrow(InvalidArgumentException::class, 'private or reserved');
});

it('fails closed when DNS resolution is empty', function (): void {
    config()->set('page_intelligence.safety.dns_overrides', ['empty.example' => []]);

    expect(fn () => app(PageCrawlerSafetyService::class)->normalizeAndValidate('https://empty.example/page'))
        ->toThrow(InvalidArgumentException::class, 'could not be resolved');
});

it('blocks redirect targets that resolve private', function (): void {
    config()->set('page_intelligence.safety.dns_overrides', ['public.example' => ['93.184.216.34']]);

    expect(app(PageCrawlerSafetyService::class)->normalizeAndValidate('https://public.example/page'))->toBe('https://public.example/page')
        ->and(fn () => app(PageCrawlerSafetyService::class)->validateRedirectTarget('http://127.0.0.1/secret'))
        ->toThrow(InvalidArgumentException::class);
});

it('enforces configured domain allow and deny lists', function (): void {
    config()->set('page_intelligence.safety.allow_domains', ['allowed.example']);
    config()->set('page_intelligence.safety.dns_overrides', [
        'allowed.example' => ['93.184.216.34'],
        'denied.example' => ['93.184.216.34'],
    ]);

    expect(app(PageCrawlerSafetyService::class)->normalizeAndValidate('https://allowed.example/page'))->toBe('https://allowed.example/page')
        ->and(fn () => app(PageCrawlerSafetyService::class)->normalizeAndValidate('https://denied.example/page'))
        ->toThrow(InvalidArgumentException::class, 'not allowed');
});

it('enforces robots by default', function (): void {
    Cache::flush();
    config()->set('page_intelligence.safety.dns_overrides', ['robots.example' => ['93.184.216.34']]);

    Http::fake([
        'https://robots.example/robots.txt' => Http::response("User-agent: *\nDisallow: /private\n", 200, ['Content-Type' => 'text/plain']),
    ]);

    expect(fn () => app(PageCrawlerSafetyService::class)->normalizeAndValidate('https://robots.example/private/page'))
        ->toThrow(InvalidArgumentException::class, 'robots.txt');
});

it('pins public DNS for guarded HTTP requests', function (): void {
    config()->set('page_intelligence.safety.dns_overrides', ['pinned.example' => ['93.184.216.34']]);

    $options = app(PageCrawlerSafetyService::class)->guardedHttpOptions('https://pinned.example/page');

    expect(data_get($options, 'curl.'.CURLOPT_RESOLVE))->toContain('pinned.example:443:93.184.216.34');
});
