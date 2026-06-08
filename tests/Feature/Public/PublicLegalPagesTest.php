<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders all canonical legal pages', function () {
    $paths = [
        route('public.legal.index'),
        route('public.legal.privacy'),
        route('public.legal.terms'),
        route('public.legal.security'),
        route('public.legal.cookies'),
        route('public.legal.subprocessors'),
    ];

    foreach ($paths as $path) {
        $this->get($path)->assertOk();
    }
});

it('shows the legal sidebar navigation on legal pages', function () {
    $this->get(route('public.legal.privacy'))
        ->assertOk()
        ->assertSee('Legal hub')
        ->assertSee('Privacy')
        ->assertSee('Terms')
        ->assertSee('Security')
        ->assertSee('Cookies')
        ->assertSee('Subprocessors');
});

it('renders the current privacy overview without unsupported documentation claims', function () {
    $this->get(route('public.legal.privacy'))
        ->assertOk()
        ->assertSee('Data ownership')
        ->assertSee('Processing roles')
        ->assertSee('Subprocessors page')
        ->assertDontSee('Full privacy statement available here');
});

it('renders the verified subprocessors that are actually used by the platform', function () {
    $this->get(route('public.legal.subprocessors'))
        ->assertOk()
        ->assertSee('Anthropic')
        ->assertSee('Google AI')
        ->assertSee('Mailgun')
        ->assertSee('Mistral AI')
        ->assertSee('Mollie')
        ->assertSee('OpenAI')
        ->assertSee('Sentry');
});

it('renders the current cookies and first-party monitoring disclosures', function () {
    $this->get(route('public.legal.cookies'))
        ->assertOk()
        ->assertSee('Argusly')
        ->assertSee('performance monitoring')
        ->assertSee('sessionStorage')
        ->assertDontSee('Consent management')
        ->assertDontSee('Advertising cookies');
});

it('renders a realistic security posture without enterprise overclaiming', function () {
    $this->get(route('public.legal.security'))
        ->assertOk()
        ->assertSee('Security is built into the platform design and development process.')
        ->assertSee('Role based permissions and restricted admin access')
        ->assertSee('Rate limiting on public submissions, analytics, and API endpoints')
        ->assertSee('Application monitoring, logging, and error tracking')
        ->assertDontSee('Enterprise security documentation available upon request');
});
