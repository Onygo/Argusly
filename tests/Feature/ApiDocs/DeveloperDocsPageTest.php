<?php

use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createAuthenticatedDeveloperUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Test Org',
        'slug' => 'test-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Test Org BV',
        'billing_address_line1' => 'Test Street 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Test Workspace',
        'display_name' => 'Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Test Site',
        'site_url' => 'https://test-'.Str::random(6).'.example.com',
        'base_url' => 'https://test-'.Str::random(6).'.example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'test-docs-plan'],
        [
            'name' => 'Test Plan',
            'slug' => 'test-docs-plan',
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
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    return User::query()->create([
        'name' => 'Test Developer User',
        'email' => 'dev-docs-'.Str::random(5).'@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'email_verified_at' => now(),
        'email_code_verified_at' => now(),
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);
}

beforeEach(function () {
    // Generate OpenAPI spec for docs pages
    $this->artisan('publishlayer:generate-openapi');
});

afterEach(function () {
    // Clean up generated files
    $files = [
        base_path('docs/openapi/publishlayer.yaml'),
        base_path('docs/postman/publishlayer-collection.json'),
        base_path('docs/postman/publishlayer-environment.json'),
    ];

    foreach ($files as $file) {
        if (File::exists($file)) {
            File::delete($file);
        }
    }
});

test('developer docs index page loads', function () {
    $user = createAuthenticatedDeveloperUser();

    $response = $this->actingAs($user)
        ->get(route('app.developer.docs.index'));

    $response->assertOk();
    $response->assertViewIs('app.developer.docs.index');
});

test('developer docs page shows api reference when spec exists', function () {
    $user = createAuthenticatedDeveloperUser();

    $response = $this->actingAs($user)
        ->get(route('app.developer.docs.index'));

    $response->assertOk();
    $response->assertSee('API Reference');
});

test('developer docs page shows warning when spec missing', function () {
    // Delete the OpenAPI spec
    File::delete(base_path('docs/openapi/publishlayer.yaml'));

    $user = createAuthenticatedDeveloperUser();

    $response = $this->actingAs($user)
        ->get(route('app.developer.docs.index'));

    $response->assertOk();
    $response->assertSee('not generated yet');
});

test('developer docs page filters by tag', function () {
    $user = createAuthenticatedDeveloperUser();

    $response = $this->actingAs($user)
        ->get(route('app.developer.docs.index', ['tag' => 'Briefs']));

    $response->assertOk();
    $response->assertViewHas('activeTag', 'Briefs');
});

test('developer docs downloads page loads', function () {
    $user = createAuthenticatedDeveloperUser();

    $response = $this->actingAs($user)
        ->get(route('app.developer.docs.downloads'));

    $response->assertOk();
    $response->assertViewIs('app.developer.docs.downloads');
    $response->assertSee('Downloads');
});

test('openapi spec download works when file exists', function () {
    $user = createAuthenticatedDeveloperUser();

    $response = $this->actingAs($user)
        ->get(route('app.developer.docs.download.openapi'));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/x-yaml');
});

test('openapi spec download returns 404 when file missing', function () {
    File::delete(base_path('docs/openapi/publishlayer.yaml'));

    $user = createAuthenticatedDeveloperUser();

    $response = $this->actingAs($user)
        ->get(route('app.developer.docs.download.openapi'));

    $response->assertNotFound();
});

test('postman collection download works when file exists', function () {
    // Generate postman files
    $this->artisan('publishlayer:generate-postman');

    $user = createAuthenticatedDeveloperUser();

    $response = $this->actingAs($user)
        ->get(route('app.developer.docs.download.postman-collection'));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/json');
});

test('postman environment download works when file exists', function () {
    // Generate postman files
    $this->artisan('publishlayer:generate-postman');

    $user = createAuthenticatedDeveloperUser();

    $response = $this->actingAs($user)
        ->get(route('app.developer.docs.download.postman-environment'));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/json');
});

test('developer docs pages require authentication', function () {
    $response = $this->get(route('app.developer.docs.index'));

    $response->assertRedirect(route('login'));
});

test('existing developer portal docs tab shows link to api reference', function () {
    $user = createAuthenticatedDeveloperUser();

    $response = $this->actingAs($user)
        ->get(route('app.developer.docs'));

    $response->assertOk();
    $response->assertSee('API Reference');
    $response->assertSee(route('app.developer.docs.index'));
});
