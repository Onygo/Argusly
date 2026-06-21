<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('returns 200 for all public marketing pages', function () {
    // Ensure full marketing mode is enabled for this test
    config(['argusly.launch.soft_launch_mode' => false]);
    $this->seed(\Database\Seeders\MarketingPageSeeder::class);

    $paths = [
        '/en',
        '/robots.txt',
        '/sitemap.xml',
        '/en/pricing',
        '/nl/prijzen',
        '/en/blog',
        '/en/blog/rss.xml',
        '/en/company/contact',
        '/en/product/overview',
        '/en/product/platform',
        '/en/company/about',
        '/en/company/contact',
        '/en/company/roadmap',
        '/en/legal',
        '/en/legal/privacy',
        '/en/legal/terms',
        '/en/legal/security',
        '/en/legal/cookies',
        '/en/legal/subprocessors',
        '/en/ai-search',
        '/en/seo',
        '/nl/llm-zichtbaarheid',
    ];

    foreach ($paths as $path) {
        $this->get($path)->assertOk();
    }
});

it('redirects legacy product routes to platform anchors', function () {
    config(['argusly.launch.soft_launch_mode' => false]);

    $this->get('/product/capabilities')->assertRedirect('/en/product/platform#capabilities');
    $this->get('/product/governance')->assertRedirect('/en/product/platform#governance');
    $this->get('/product/intelligence')->assertRedirect('/en/product/platform#intelligence');
});

it('does not emit crawlable contact tracking query links', function () {
    config(['argusly.launch.soft_launch_mode' => false]);
    $this->seed(\Database\Seeders\MarketingPageSeeder::class);

    $this->get('/en')
        ->assertOk()
        ->assertSee('/en/company/contact?subject=walkthrough#contact-form', false)
        ->assertDontSee('topic=', false)
        ->assertDontSee('source=', false)
        ->assertDontSee('cta=', false);
});

it('canonicalizes old contact tracking query urls', function () {
    config(['argusly.launch.soft_launch_mode' => false]);
    $this->seed(\Database\Seeders\MarketingPageSeeder::class);

    $this->get('/en/company/contact?topic=Contact&source=en/blog/example&cta=Contact')
        ->assertRedirect('/en/company/contact#contact-form')
        ->assertStatus(301);
});

it('prefills walkthrough contact subject from the header cta subject key', function () {
    config(['argusly.launch.soft_launch_mode' => false]);
    $this->seed(\Database\Seeders\MarketingPageSeeder::class);

    $this->get('/nl/bedrijf/contact?subject=walkthrough#contact-form')
        ->assertOk()
        ->assertSee('value="demo"', false)
        ->assertSee('selected>Walkthrough-aanvraag', false)
        ->assertSee('value="Plan een walkthrough"', false);
});

it('passes the public link audit command without missing routes or broken links', function () {
    $exitCode = Artisan::call('public:link-audit');
    $output = Artisan::output();

    expect($exitCode)->toBe(0)
        ->and($output)->not->toContain('MISSING_ROUTE')
        ->and($output)->not->toContain('MISSING_VIEW')
        ->and($output)->not->toContain('BROKEN');
});

it('renders legal terms in dutch by default and supports english switching', function () {
    $this->get('/nl/juridisch/voorwaarden')
        ->assertOk()
        ->assertSee('Algemene Voorwaarden Argusly', false)
        ->assertSee('Artikel 1. Definities', false);

    $this->get('/en/legal/terms')
        ->assertOk()
        ->assertSee('Terms and Conditions Argusly', false)
        ->assertSee('Article 1. Definitions', false);
});
