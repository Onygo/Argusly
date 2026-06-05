<?php

use App\Services\OnboardingScan\WebsiteCrawlerService;

describe('WebsiteCrawlerService', function () {

    it('normalizes URLs correctly', function () {
        $service = new WebsiteCrawlerService();

        // Use reflection to access private method
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('normalizeUrl');
        $method->setAccessible(true);

        expect($method->invoke($service, 'https://example.com/'))->toBe('https://example.com');
        expect($method->invoke($service, 'https://example.com/page/'))->toBe('https://example.com/page');
        expect($method->invoke($service, 'example.com'))->toBe('https://example.com');
        expect($method->invoke($service, 'http://Example.COM/Path'))->toBe('http://example.com/Path');
    });

    it('extracts internal links from HTML', function () {
        $service = new WebsiteCrawlerService();

        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head><title>Test</title></head>
<body>
    <a href="/about">About</a>
    <a href="/services">Services</a>
    <a href="https://example.com/contact">Contact</a>
    <a href="https://external.com/page">External</a>
    <a href="mailto:test@example.com">Email</a>
    <a href="#section">Anchor</a>
    <a href="javascript:void(0)">JS Link</a>
</body>
</html>
HTML;

        $links = $service->extractInternalLinks($html, 'https://example.com', 'example.com');

        expect($links)->toContain('https://example.com/about');
        expect($links)->toContain('https://example.com/services');
        expect($links)->toContain('https://example.com/contact');
        expect($links)->not->toContain('https://external.com/page');
        expect($links)->not->toContain('mailto:test@example.com');
    });

    it('filters out system paths', function () {
        $service = new WebsiteCrawlerService();

        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<body>
    <a href="/about">About</a>
    <a href="/wp-admin/post.php">Admin</a>
    <a href="/wp-content/uploads/image.jpg">Image</a>
    <a href="/category/tech">Category</a>
    <a href="/tag/seo">Tag</a>
    <a href="/cart">Cart</a>
    <a href="/checkout">Checkout</a>
</body>
</html>
HTML;

        $links = $service->extractInternalLinks($html, 'https://example.com', 'example.com');

        expect($links)->toContain('https://example.com/about');
        expect($links)->not->toContain('https://example.com/wp-admin/post.php');
        expect($links)->not->toContain('https://example.com/category/tech');
        expect($links)->not->toContain('https://example.com/tag/seo');
        expect($links)->not->toContain('https://example.com/cart');
    });

    it('prioritizes important pages', function () {
        $service = new WebsiteCrawlerService();

        $urls = [
            'https://example.com/random-page',
            'https://example.com/blog/some-post',
            'https://example.com/about',
            'https://example.com/services',
            'https://example.com/deep/nested/page',
            'https://example.com/contact',
            'https://example.com/team',
        ];

        $prioritized = $service->prioritizePages($urls);

        // About should be prioritized highly
        $aboutIndex = array_search('https://example.com/about', $prioritized);
        $randomIndex = array_search('https://example.com/random-page', $prioritized);

        expect($aboutIndex)->toBeLessThan($randomIndex);

        // Services should be near the top
        $servicesIndex = array_search('https://example.com/services', $prioritized);
        expect($servicesIndex)->toBeLessThan(3);
    });

    it('resolves relative URLs correctly', function () {
        $service = new WebsiteCrawlerService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('resolveUrl');
        $method->setAccessible(true);

        expect($method->invoke($service, 'https://example.com/page', '/about'))
            ->toBe('https://example.com/about');

        expect($method->invoke($service, 'https://example.com/blog/post', 'related'))
            ->toBe('https://example.com/blog/related');

        expect($method->invoke($service, 'https://example.com', '//cdn.example.com/image.jpg'))
            ->toBe('https://cdn.example.com/image.jpg');

        expect($method->invoke($service, 'https://example.com', 'https://other.com/page'))
            ->toBe('https://other.com/page');
    });

    it('deduplicates extracted links', function () {
        $service = new WebsiteCrawlerService();

        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<body>
    <a href="/about">About</a>
    <a href="/about">About Again</a>
    <a href="https://example.com/about">About Full URL</a>
</body>
</html>
HTML;

        $links = $service->extractInternalLinks($html, 'https://example.com', 'example.com');

        // Should only have one /about link
        $aboutLinks = array_filter($links, fn ($l) => str_contains($l, 'about'));
        expect(count($aboutLinks))->toBe(1);
    });
});
