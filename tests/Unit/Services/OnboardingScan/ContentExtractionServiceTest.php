<?php

use App\Services\OnboardingScan\ContentExtractionService;

describe('ContentExtractionService', function () {

    it('extracts title from HTML', function () {
        $service = new ContentExtractionService();

        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head><title>Test Page Title</title></head>
<body><h1>Page Content</h1></body>
</html>
HTML;

        $result = $service->extractFromHtml($html, 'https://example.com');

        expect($result['title'])->toBe('Test Page Title');
    });

    it('extracts meta description', function () {
        $service = new ContentExtractionService();

        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Test</title>
    <meta name="description" content="This is the meta description for SEO.">
</head>
<body></body>
</html>
HTML;

        $result = $service->extractFromHtml($html, 'https://example.com');

        expect($result['meta_description'])->toBe('This is the meta description for SEO.');
    });

    it('extracts headings hierarchy', function () {
        $service = new ContentExtractionService();

        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head><title>Test</title></head>
<body>
    <h1>Main Heading</h1>
    <h2>Section One</h2>
    <p>Content here</p>
    <h2>Section Two</h2>
    <h3>Subsection</h3>
</body>
</html>
HTML;

        $result = $service->extractFromHtml($html, 'https://example.com');

        expect($result['headings'])->toHaveCount(4);
        expect($result['headings'][0])->toBe(['level' => 1, 'text' => 'Main Heading']);
        expect($result['headings'][1])->toBe(['level' => 2, 'text' => 'Section One']);
        expect($result['headings'][2])->toBe(['level' => 2, 'text' => 'Section Two']);
        expect($result['headings'][3])->toBe(['level' => 3, 'text' => 'Subsection']);
    });

    it('strips script and style elements from content', function () {
        $service = new ContentExtractionService();

        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Test</title>
    <style>.hidden { display: none; }</style>
</head>
<body>
    <script>alert('hello');</script>
    <p>This is the main content.</p>
    <script>console.log('test');</script>
</body>
</html>
HTML;

        $result = $service->extractFromHtml($html, 'https://example.com');

        expect($result['main_content'])->toContain('main content');
        expect($result['main_content'])->not->toContain('alert');
        expect($result['main_content'])->not->toContain('console.log');
    });

    it('detects hex colors from inline styles', function () {
        $service = new ContentExtractionService();

        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <style>
        .primary { color: #ff5500; }
        .secondary { background: #336699; }
        .accent { border-color: #ff5500; }
    </style>
</head>
<body>
    <div style="color: #123456;">Content</div>
</body>
</html>
HTML;

        $result = $service->extractFromHtml($html, 'https://example.com');

        expect($result['detected_colors'])->toHaveKey('#ff5500');
        expect($result['detected_colors'])->toHaveKey('#336699');
        expect($result['detected_colors'])->toHaveKey('#123456');
    });

    it('detects technical stack indicators', function () {
        $service = new ContentExtractionService();

        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Test</title>
    <link rel="stylesheet" href="/wp-content/themes/theme/style.css">
</head>
<body>
    <script src="https://www.googletagmanager.com/gtag/js?id=G-XXXXX"></script>
    <div id="___gatsby"></div>
</body>
</html>
HTML;

        $result = $service->extractFromHtml($html, 'https://example.com');

        expect($result['technical_indicators'])->toContain('wordpress');
        expect($result['technical_indicators'])->toContain('google_tag_manager');
        expect($result['technical_indicators'])->toContain('gatsby');
    });

    it('calculates word count correctly', function () {
        $service = new ContentExtractionService();

        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head><title>Test</title></head>
<body>
    <p>This is a simple paragraph with exactly ten words in it.</p>
</body>
</html>
HTML;

        $result = $service->extractFromHtml($html, 'https://example.com');

        expect($result['word_count'])->toBeGreaterThan(5);
    });

    it('extracts multiple pages', function () {
        $service = new ContentExtractionService();

        $pages = [
            'homepage' => [
                'url' => 'https://example.com',
                'success' => true,
                'html' => '<html><head><title>Home</title></head><body><h1>Welcome</h1></body></html>',
            ],
            'about' => [
                'url' => 'https://example.com/about',
                'success' => true,
                'html' => '<html><head><title>About Us</title></head><body><h1>About</h1></body></html>',
            ],
            'failed' => [
                'url' => 'https://example.com/404',
                'success' => false,
                'html' => null,
            ],
        ];

        $result = $service->extract($pages);

        expect($result)->toHaveCount(2);
        expect($result['homepage']['title'])->toBe('Home');
        expect($result['about']['title'])->toBe('About Us');
        expect($result)->not->toHaveKey('failed');
    });

    it('detects Google Fonts', function () {
        $service = new ContentExtractionService();

        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&family=Open+Sans&display=swap" rel="stylesheet">
</head>
<body>
    <p style="font-family: Roboto, sans-serif;">Content</p>
</body>
</html>
HTML;

        $result = $service->extractFromHtml($html, 'https://example.com');

        expect($result['detected_fonts'])->toContain('Roboto');
    });

    it('extracts og:image', function () {
        $service = new ContentExtractionService();

        $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Test</title>
    <meta property="og:image" content="https://example.com/image.jpg">
</head>
<body></body>
</html>
HTML;

        $result = $service->extractFromHtml($html, 'https://example.com');

        expect($result['og_image'])->toBe('https://example.com/image.jpg');
    });
});
