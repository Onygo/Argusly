<?php

use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
});

function createDropdownTestContext(string $role = 'owner'): array
{
    $organization = Organization::create([
        'name' => 'Dropdown Test Org',
        'slug' => 'dropdown-test-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::create([
        'name' => 'Dropdown Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Test Site',
        'site_url' => 'https://test-' . Str::random(6) . '.example.com',
        'allowed_domains' => ['test.example.com'],
        'is_active' => true,
    ]);

    $user = User::create([
        'name' => 'Test User',
        'email' => 'dropdown-test+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => $role,
        'email_verified_at' => now(),
        'approved_at' => now(),
        'active' => true,
    ]);

    return [$organization, $workspace, $site, $user];
}

describe('New Content dropdown', function () {
    it('renders "Generate from URL" option for users with create permission', function () {
        [, , , $user] = createDropdownTestContext('owner');

        $response = $this->actingAs($user)->get(route('app.content.index'));

        $response->assertStatus(200);
        $response->assertSee('Generate from URL');
        $response->assertSee(route('app.content.create') . '#source-briefing', false);
    });

    it('renders "Generate multiple articles" option', function () {
        [, , , $user] = createDropdownTestContext('owner');

        $response = $this->actingAs($user)->get(route('app.content.index'));

        $response->assertStatus(200);
        $response->assertSee('Generate multiple articles');
        $response->assertSee(route('app.content.batches.create'), false);
    });

    it('does not show New Content dropdown for viewers', function () {
        [, , , $user] = createDropdownTestContext('viewer');

        $response = $this->actingAs($user)->get(route('app.content.index'));

        $response->assertStatus(200);
        $response->assertDontSee('Generate from URL');
        $response->assertDontSee('Generate multiple articles');
    });

    it('shows New Content dropdown for editors', function () {
        [, , , $user] = createDropdownTestContext('editor');

        $response = $this->actingAs($user)->get(route('app.content.index'));

        $response->assertStatus(200);
        $response->assertSee('Generate from URL');
        $response->assertSee('Generate multiple articles');
    });
});

describe('Generate from URL route access', function () {
    it('allows access to create page for users with create permission', function () {
        [, , , $user] = createDropdownTestContext('owner');

        $response = $this->actingAs($user)->get(route('app.content.create'));

        $response->assertStatus(200);
        $response->assertSee('Generate brief from URL');
    });

    it('denies access to create page for viewers', function () {
        [, , , $user] = createDropdownTestContext('viewer');

        $response = $this->actingAs($user)->get(route('app.content.create'));

        $response->assertForbidden();
    });

    it('denies access to create page for reviewers', function () {
        [, , , $user] = createDropdownTestContext('reviewer');

        $response = $this->actingAs($user)->get(route('app.content.create'));

        $response->assertForbidden();
    });

    it('allows access for admin users', function () {
        [, , , $user] = createDropdownTestContext('admin');

        $response = $this->actingAs($user)->get(route('app.content.create'));

        $response->assertStatus(200);
        $response->assertSee('Generate brief from URL');
    });
});

describe('Generate from URL preview route access', function () {
    it('denies preview access for unauthorized users', function () {
        [, , , $user] = createDropdownTestContext('viewer');

        $response = $this->actingAs($user)->post(route('app.content.create.from-url.preview'), [
            'source_url' => 'https://example.com/article',
        ]);

        $response->assertForbidden();
    });

    it('allows preview access for users with create permission', function () {
        [, , , $user] = createDropdownTestContext('editor');

        // The actual preview might fail due to HTTP mocking, but we're testing authorization
        $response = $this->actingAs($user)->post(route('app.content.create.from-url.preview'), [
            'source_url' => 'https://example.com/article',
        ]);

        // Should not be forbidden - validation/network error is fine
        expect($response->status())->not->toBe(403);
    });
});
