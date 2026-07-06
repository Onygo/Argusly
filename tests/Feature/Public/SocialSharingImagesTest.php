<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['argusly.launch.soft_launch_mode' => false]);
});

it('renders complete social image metadata on important marketing routes', function (string $path, string $expectedImage) {
    $response = $this
        ->withServerVariables(['HTTP_HOST' => 'argusly.local'])
        ->get($path);

    $expectedPath = preg_quote($expectedImage, '#');
    $html = $response->getContent();

    $response->assertOk()
        ->assertSee('<meta property="og:image:width" content="1200" />', false)
        ->assertSee('<meta property="og:image:height" content="630" />', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image" />', false);

    expect($html)
        ->toMatch('#<meta property="og:image" content="https?://[^"]+' . $expectedPath . '" />#')
        ->toMatch('#<meta name="twitter:image" content="https?://[^"]+' . $expectedPath . '" />#');
})->with([
    'homepage' => ['/en', '/images/social/argusly-og-ai-visibility.jpg'],
    'blog index' => ['/en/blog', '/images/social/argusly-og-opportunity-intelligence.jpg'],
    'agentic marketing' => ['/en/agentic-marketing', '/images/social/argusly-og-agentic-marketing.jpg'],
    'market page' => ['/en/industries/it-services-saas', '/images/social/argusly-og-growth-intelligence.jpg'],
    'solution page' => ['/en/solutions/ai-visibility', '/images/social/argusly-og-autonomous-marketing.jpg'],
]);
