<?php

use App\Models\BrandVoice;
use App\Models\CompanyProfile;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->organization = Organization::create([
        'name' => 'Brand Test Org',
        'slug' => 'brand-test-org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Brand Test Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $this->workspace = Workspace::create([
        'name' => 'Brand Test Workspace',
        'organization_id' => $this->organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'brand-test-plan'],
        [
            'name' => 'Brand Test Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->organization->id,
        'workspace_id' => $this->workspace->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $this->owner = User::create([
        'name' => 'Owner',
        'email' => 'owner+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $this->organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->editor = User::create([
        'name' => 'Editor',
        'email' => 'editor+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $this->organization->id,
        'role' => 'editor',
        'approved_at' => now(),
        'active' => true,
    ]);

    BrandVoice::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Default Voice',
        'default_language' => 'en',
        'is_default' => true,
    ]);
});

it('allows authorized user to access company profile page', function () {
    $this->actingAs($this->owner)
        ->get(route('app.brand.company-profile'))
        ->assertOk()
        ->assertSee('Company Profile')
        ->assertSee('Brand Test Workspace')
        ->assertSee('Generate with AI')
        ->assertSee('Fill manually');
});

it('allows authorized user to access brand voices page', function () {
    $this->actingAs($this->owner)
        ->get(route('app.brand.voices'))
        ->assertOk()
        ->assertSee('Brand Voices')
        ->assertSee('Default Voice')
        ->assertSee('Generate with AI')
        ->assertSee('Fill manually');
});

it('shows ai-first setup entry points on persona pages', function () {
    $this->actingAs($this->owner)
        ->get(route('app.brand.personas'))
        ->assertOk()
        ->assertSee('Generate with AI')
        ->assertSee('Fill manually');

    $this->actingAs($this->owner)
        ->get(route('app.brand.team-members'))
        ->assertOk()
        ->assertSee('Generate with AI')
        ->assertSee('Fill manually');
});

it('allows editor to view brand pages but not edit', function () {
    $this->actingAs($this->editor)
        ->get(route('app.brand.company-profile'))
        ->assertOk()
        ->assertSee('Read-only');

    $this->actingAs($this->editor)
        ->get(route('app.brand.voices'))
        ->assertOk();
});

it('denies unauthenticated access to brand pages', function () {
    $this->get(route('app.brand.company-profile'))
        ->assertRedirect(route('login'));

    $this->get(route('app.brand.voices'))
        ->assertRedirect(route('login'));
});

it('allows owner to create company profile', function () {
    $this->actingAs($this->owner)
        ->post(route('app.brand.company-profile.upsert'), [
            'company_name' => 'Test Company',
            'industry' => 'Technology',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('company_profiles', [
        'workspace_id' => $this->workspace->id,
        'company_name' => 'Test Company',
        'industry' => 'Technology',
    ]);
});

it('allows owner to create brand voice', function () {
    $this->actingAs($this->owner)
        ->post(route('app.brand.voices.store'), [
            'name' => 'New Voice',
            'default_language' => 'nl',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('brand_voices', [
        'workspace_id' => $this->workspace->id,
        'name' => 'New Voice',
        'default_language' => 'nl',
    ]);
});

it('allows owner to update brand voice', function () {
    $voice = BrandVoice::where('workspace_id', $this->workspace->id)->first();

    $this->actingAs($this->owner)
        ->post(route('app.brand.voices.update', $voice), [
            'name' => 'Updated Voice',
            'default_language' => 'de',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('brand_voices', [
        'id' => $voice->id,
        'name' => 'Updated Voice',
        'default_language' => 'de',
    ]);
});

it('allows owner to delete brand voice when another exists', function () {
    $defaultVoice = BrandVoice::where('workspace_id', $this->workspace->id)->first();

    $newVoice = BrandVoice::create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Second Voice',
        'default_language' => 'en',
        'is_default' => false,
    ]);

    $this->actingAs($this->owner)
        ->delete(route('app.brand.voices.delete', $newVoice))
        ->assertRedirect();

    $this->assertDatabaseMissing('brand_voices', [
        'id' => $newVoice->id,
    ]);
});

it('redirects old settings company-profile URL to new brand location', function () {
    $this->actingAs($this->owner)
        ->get('https://app.argusly.local/settings/company-profile')
        ->assertRedirect(route('app.brand.company-profile'));
});

it('redirects old settings brand-voices URL to new brand location', function () {
    $this->actingAs($this->owner)
        ->get('https://app.argusly.local/settings/brand-voices')
        ->assertRedirect(route('app.brand.voices'));
});

it('shows brand settings hint on settings page', function () {
    $this->actingAs($this->owner)
        ->get(route('app.settings'))
        ->assertOk()
        ->assertSee('Brand')
        ->assertSee('Company profile')
        ->assertSee('Brand voices');
});

it('has tab navigation between company profile and brand voices', function () {
    $this->actingAs($this->owner)
        ->get(route('app.brand.company-profile'))
        ->assertOk()
        ->assertSee('Company Profile')
        ->assertSee('Brand Voices');

    $this->actingAs($this->owner)
        ->get(route('app.brand.voices'))
        ->assertOk()
        ->assertSee('Company Profile')
        ->assertSee('Brand Voices');
});
