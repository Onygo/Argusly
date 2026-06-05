<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('llms.txt content', function () {
    it('returns valid text response', function () {
        config(['publishlayer.launch.soft_launch_mode' => false]);

        $this->get('/llms.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('# PublishLayer');
    });

    it('includes important public pages', function () {
        config(['publishlayer.launch.soft_launch_mode' => false]);

        $response = $this->get('/llms.txt');
        $content = $response->getContent();

        expect($content)
            ->toContain('## Important pages')
            ->toContain('## Markdown resources')
            ->toContain('Homepage')
            ->toContain('Product overview')
            ->toContain('Platform features')
            ->toContain('Pricing')
            ->toContain('About us')
            ->toContain('Contact')
            ->toContain('Legal hub')
            ->toContain('Privacy policy')
            ->toContain('Terms of service')
            ->toContain('Security')
            ->toContain('/en/ai-search.md');
    });

    it('does not include removed product-updates page', function () {
        config(['publishlayer.launch.soft_launch_mode' => false]);

        $response = $this->get('/llms.txt');
        $content = $response->getContent();

        expect($content)
            ->not->toContain('product-updates')
            ->not->toContain('Product updates');
    });

    it('does not include auth or admin pages', function () {
        config(['publishlayer.launch.soft_launch_mode' => false]);

        $response = $this->get('/llms.txt');
        $content = $response->getContent();

        expect($content)
            ->not->toContain('/login')
            ->not->toContain('/register')
            ->not->toContain('/admin')
            ->not->toContain('/app/')
            ->not->toContain('/billing');
    });

    it('respects early access mode by hiding full marketing pages', function () {
        config(['publishlayer.launch.soft_launch_mode' => true]);

        $response = $this->get('/llms.txt');
        $content = $response->getContent();

        // Should still include always-visible pages
        expect($content)
            ->toContain('Homepage')
            ->toContain('Product overview')
            ->toContain('About us')
            ->toContain('Contact')
            ->toContain('Legal hub');

        // Should not include pages that require full marketing mode
        expect($content)
            ->not->toContain('Platform features')
            ->not->toContain('Pricing')
            ->not->toContain('Blog')
            ->not->toContain('Roadmap');
    });

    it('shows full marketing pages when not in early access mode', function () {
        config(['publishlayer.launch.soft_launch_mode' => false]);

        $response = $this->get('/llms.txt');
        $content = $response->getContent();

        expect($content)
            ->toContain('Platform features')
            ->toContain('Pricing')
            ->toContain('Blog')
            ->toContain('Roadmap');
    });

    it('returns consistent content for llms-full.txt variant', function () {
        config(['publishlayer.launch.soft_launch_mode' => false]);

        $response = $this->get('/llms-full.txt');

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('# PublishLayer')
            ->assertSee('## Important pages');
    });
});
