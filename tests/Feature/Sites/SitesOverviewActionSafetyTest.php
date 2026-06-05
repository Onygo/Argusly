<?php

use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\SiteToken;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Sites\SiteApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('shows only safe actions in the sites overview', function () {
    [$owner, , $site] = makeSitesActionSafetyContext();

    $this->actingAs($owner)
        ->get(route('app.sites'))
        ->assertOk()
        ->assertSee($site->name)
        ->assertSee('View setup details')
        ->assertSee('Test connection')
        ->assertDontSee('Regenerate key')
        ->assertDontSee('Disable site')
        ->assertDontSee('Remove site');
});

it('paginates the sites overview instead of loading every site at once', function () {
    [$owner, , $site, $workspace] = makeSitesActionSafetyContext();

    foreach (range(1, 18) as $index) {
        ClientSite::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'wordpress',
            'name' => 'Extra Site '.$index,
            'site_url' => 'https://extra-site-'.$index.'.example.com',
            'base_url' => 'https://extra-site-'.$index.'.example.com',
            'allowed_domains' => ['extra-site-'.$index.'.example.com'],
            'is_active' => true,
            'status' => 'connected',
        ]);
    }

    $response = $this->actingAs($owner)->get(route('app.sites'));

    $response->assertOk()
        ->assertSee('Extra Site 1')
        ->assertDontSee('Extra Site 18');

    $sites = $response->viewData('sites');
    expect($sites->count())->toBe(15)
        ->and($sites->hasMorePages())->toBeTrue();
});

it('shows danger zone actions on site detail for authorized managers', function () {
    [$owner, , $site] = makeSitesActionSafetyContext();

    $this->actingAs($owner)
        ->get(route('app.sites.show', $site))
        ->assertOk()
        ->assertSee('Danger zone')
        ->assertSee('Regenerate key')
        ->assertSee('Confirm regenerate key')
        ->assertSee('Disable site')
        ->assertSee('Remove site');
});

it('keeps key regeneration working through the guarded detail flow', function () {
    [$owner, , $site] = makeSitesActionSafetyContext();

    [$token, $plain] = app(SiteApiKeyService::class)->createForSite($site, app(SiteApiKeyService::class)->defaultScopes());
    expect($plain)->toStartWith('pl_site_');

    $this->actingAs($owner)
        ->post(route('app.sites.regenerate-key', $site))
        ->assertRedirect(route('app.sites.show', $site))
        ->assertSessionHas('status', 'Site key regenerated. Previous keys were revoked.')
        ->assertSessionHas('site_plain_key');

    $activeTokens = SiteToken::query()
        ->where('client_site_id', $site->id)
        ->where('revoked', false)
        ->count();

    expect($activeTokens)->toBe(1);
    expect((bool) $token->fresh()->revoked)->toBeTrue();
});

it('keeps disable and remove actions working from detail page routes', function () {
    [$owner, , $site] = makeSitesActionSafetyContext();

    $this->actingAs($owner)
        ->post(route('app.sites.toggle', $site))
        ->assertRedirect()
        ->assertSessionHas('status', 'Site status updated.');

    expect((string) $site->fresh()->status)->toBe('disabled');

    $this->actingAs($owner)
        ->post(route('app.sites.toggle', $site))
        ->assertRedirect()
        ->assertSessionHas('status', 'Site status updated.');

    expect((string) $site->fresh()->status)->not->toBe('disabled');

    $this->actingAs($owner)
        ->delete(route('app.sites.destroy', $site))
        ->assertRedirect(route('app.sites'))
        ->assertSessionHas('status', 'Site removed.');

    $this->assertSoftDeleted('client_sites', ['id' => $site->id]);
});

it('keeps authorization boundaries for danger actions', function () {
    [, $editor, $site] = makeSitesActionSafetyContext();

    $this->actingAs($editor)
        ->get(route('app.sites.show', $site))
        ->assertOk()
        ->assertDontSee('Danger zone')
        ->assertDontSee('Confirm regenerate key')
        ->assertDontSee('Remove site');

    $this->actingAs($editor)
        ->post(route('app.sites.regenerate-key', $site))
        ->assertStatus(403);

    $this->actingAs($editor)
        ->post(route('app.sites.toggle', $site))
        ->assertStatus(403);

    $this->actingAs($editor)
        ->delete(route('app.sites.destroy', $site))
        ->assertStatus(403);
});

function makeSitesActionSafetyContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Sites Safety Org',
        'slug' => 'sites-safety-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Sites Safety Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Sites Safety Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'sites-safety-test-plan'],
        [
            'name' => 'Sites Safety Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $owner = User::query()->create([
        'name' => 'Sites Owner',
        'email' => 'sites-owner+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $editor = User::query()->create([
        'name' => 'Sites Editor',
        'email' => 'sites-editor+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'editor',
        'active' => true,
        'approved_at' => now(),
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Safety Site',
        'site_url' => 'https://safety.example.com',
        'base_url' => 'https://safety.example.com',
        'allowed_domains' => ['safety.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$owner, $editor, $site, $workspace, $organization];
}
