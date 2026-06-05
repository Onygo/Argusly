<?php

use App\Models\BrandVoice;
use App\Models\CompanyProfile;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BrandVoiceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('enforces unique company profile per workspace', function () {
    $organization = Organization::create([
        'name' => 'Org',
        'slug' => 'org-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);
    $workspace = Workspace::create([
        'name' => 'Workspace',
        'organization_id' => $organization->id,
    ]);

    CompanyProfile::create([
        'workspace_id' => $workspace->id,
        'company_name' => 'Acme One',
    ]);

    expect(fn () => CompanyProfile::create([
        'workspace_id' => $workspace->id,
        'company_name' => 'Acme Two',
    ]))->toThrow(QueryException::class);
});

it('set default unsets other brand voices in the same workspace', function () {
    $organization = Organization::create([
        'name' => 'Org Two',
        'slug' => 'org-two-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
    ]);
    $workspace = Workspace::create([
        'name' => 'Workspace Two',
        'organization_id' => $organization->id,
    ]);

    $voiceA = BrandVoice::create([
        'workspace_id' => $workspace->id,
        'name' => 'Voice A',
        'default_language' => 'en',
        'is_default' => true,
    ]);
    $voiceB = BrandVoice::create([
        'workspace_id' => $workspace->id,
        'name' => 'Voice B',
        'default_language' => 'en',
        'is_default' => false,
    ]);

    app(BrandVoiceService::class)->setDefault($workspace, (string) $voiceB->id);

    expect($voiceA->fresh()->is_default)->toBeFalse();
    expect($voiceB->fresh()->is_default)->toBeTrue();
});

it('blocks non admin users from changing company profile and brand voices', function () {
    $organization = Organization::create([
        'name' => 'Org Three',
        'slug' => 'org-three-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Org Three BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);
    $workspace = Workspace::create([
        'name' => 'Workspace Three',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'test-plan'],
        [
            'name' => 'Test Plan',
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

    $editor = User::create([
        'name' => 'Editor',
        'email' => 'editor+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'editor',
        'approved_at' => now(),
        'active' => true,
    ]);

    $voice = BrandVoice::create([
        'workspace_id' => $workspace->id,
        'name' => 'Voice',
        'default_language' => 'en',
        'is_default' => true,
    ]);

    // Test new brand routes
    $this->actingAs($editor)
        ->post(route('app.brand.company-profile.upsert'), [
            'company_name' => 'Blocked Co',
        ])
        ->assertStatus(403);

    $this->actingAs($editor)
        ->post(route('app.brand.voices.store'), [
            'name' => 'Blocked Voice',
            'default_language' => 'en',
        ])
        ->assertStatus(403);

    $this->actingAs($editor)
        ->post(route('app.brand.voices.update', $voice), [
            'name' => 'Blocked Update',
            'default_language' => 'en',
        ])
        ->assertStatus(403);

    // Legacy settings routes should still work for backwards compatibility
    $this->actingAs($editor)
        ->post('/settings/company-profile', [
            'company_name' => 'Blocked Co',
        ])
        ->assertStatus(403);

    $this->actingAs($editor)
        ->post('/settings/brand-voices', [
            'name' => 'Blocked Voice',
            'default_language' => 'en',
        ])
        ->assertStatus(403);
});
