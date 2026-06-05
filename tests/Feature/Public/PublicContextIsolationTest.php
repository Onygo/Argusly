<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\PublicSiteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('renders the public landing page the same way for guests and impersonated admin sessions', function () {
    config(['publishlayer.launch.soft_launch_mode' => false]);

    $organization = Organization::query()->create([
        'name' => 'Public Boundary Org',
        'slug' => 'public-boundary-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $admin = User::factory()->create([
        'organization_id' => $organization->id,
        'is_admin' => true,
        'admin_role' => 'superadmin',
        'approved_at' => now(),
    ]);

    $guestResponse = $this->get(route('landing'));
    $guestResponse
        ->assertOk()
        ->assertViewIs('public.landing');

    $authResponse = $this->actingAs($admin)
        ->withSession([
            'admin_impersonator_id' => (string) $admin->id,
            'impersonated_workspace_id' => (string) Str::uuid(),
            'public_locale' => 'nl',
        ])
        ->get(route('landing'));

    $authResponse
        ->assertOk()
        ->assertViewIs('public.landing');
});

it('ignores session based public locale state and resolves guest locale from request inputs only', function () {
    config(['publishlayer.launch.soft_launch_mode' => false]);

    $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->withSession(['public_locale' => 'nl', 'public_lang' => 'nl'])
        ->get(route('landing'))
        ->assertOk()
        ->assertSee('lang="en"', false);
});

it('keeps llms output scoped to the current request host instead of shared session or cache state', function () {
    config([
        'publishlayer.launch.soft_launch_mode' => false,
        'llms.base_url' => null,
        'llms.cache_ttl' => 60,
    ]);

    config(['app.url' => 'https://alpha.example.test']);
    $alpha = $this->get('https://alpha.example.test/llms.txt');
    $alpha->assertOk();
    expect(app(PublicSiteContext::class)->host)->toBe('alpha.example.test');

    config(['app.url' => 'https://beta.example.test']);
    $beta = $this->withSession([
        'admin_impersonator_id' => (string) Str::uuid(),
        'impersonated_workspace_id' => (string) Str::uuid(),
    ])->get('https://beta.example.test/llms.txt');
    $beta->assertOk();

    expect($alpha->getContent())->not->toBe('');
    expect(app(PublicSiteContext::class)->host)->toBe('beta.example.test');
    expect(app(PublicSiteContext::class)->scopeKey)->toBe('beta.example.test');
});

it('serves robots and sitemap endpoints as a guest', function () {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
        ->assertSee('User-agent: *')
        ->assertSee('Sitemap: ');

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
});
