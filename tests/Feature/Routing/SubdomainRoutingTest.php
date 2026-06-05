<?php

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Set the base domain for tests
    config(['domains.base' => 'argusly.local']);
});

/**
 * Helper to make a request to a specific subdomain.
 */
function subdomainGet(object $testCase, string $subdomain, string $path, ?User $user = null)
{
    $baseDomain = config('domains.base', 'argusly.local');
    $host = $subdomain === 'marketing' ? $baseDomain : "{$subdomain}.{$baseDomain}";
    $url = "http://{$host}" . $path;

    $request = $testCase->withHeaders(['Host' => $host]);
    if ($user) {
        $request = $request->actingAs($user);
    }
    return $request->get($url);
}

function subdomainPost(object $testCase, string $subdomain, string $path, array $data = [], ?User $user = null)
{
    $baseDomain = config('domains.base', 'argusly.local');
    $host = $subdomain === 'marketing' ? $baseDomain : "{$subdomain}.{$baseDomain}";
    $url = "http://{$host}" . $path;

    $request = $testCase->withHeaders(['Host' => $host]);
    if ($user) {
        $request = $request->actingAs($user);
    }
    return $request->post($url, $data);
}

/*
|--------------------------------------------------------------------------
| Marketing Subdomain Tests
|--------------------------------------------------------------------------
*/

describe('Marketing Subdomain', function () {
    it('redirects www marketing requests to the apex domain', function () {
        $response = $this->withHeaders(['Host' => 'www.argusly.local'])
            ->get('http://www.argusly.local/en?utm_source=test');

        $response->assertStatus(301);
        $response->assertRedirect('http://argusly.local/en?utm_source=test');
    });

    it('redirects apex domain to canonical localized landing page', function () {
        $response = subdomainGet($this, 'marketing', '/');
        $response->assertStatus(301);
        $response->assertRedirect('http://argusly.local/en');
    });

    it('redirects legacy pricing page on marketing domain to canonical localized pricing page', function () {
        $response = subdomainGet($this, 'marketing', '/prijzen');
        $response->assertStatus(301);
        $response->assertRedirect('http://argusly.local/nl/prijzen');
    });

    it('serves login page on marketing domain for backwards compatibility', function () {
        // Auth routes are available on marketing domain for backwards compatibility
        $response = subdomainGet($this, 'marketing', '/login');
        $response->assertOk();
    });

    it('routes to register page on marketing domain', function () {
        // Auth routes are available on marketing domain for backwards compatibility
        // May redirect to /login if no plans are configured, but route should exist
        $response = subdomainGet($this, 'marketing', '/register');
        expect($response->status())->toBeIn([200, 302]);
        // Should not 404
        expect($response->status())->not->toBe(404);
    });

    it('keeps legacy billing return callback reachable on marketing domain', function () {
        $response = subdomainGet($this, 'marketing', '/billing/return');
        // Route exists and should return a processing page, not a 404.
        expect($response->status())->toBe(200);
    });
});

/*
|--------------------------------------------------------------------------
| App Subdomain Tests
|--------------------------------------------------------------------------
*/

describe('App Subdomain', function () {
    it('shows login page on app subdomain', function () {
        $response = subdomainGet($this, 'app', '/login');
        $response->assertOk();
    });

    it('redirects to login for unauthenticated dashboard access', function () {
        $response = subdomainGet($this, 'app', '/dashboard');
        $response->assertRedirect();
    });

    it('routes to dashboard for authenticated user', function () {
        $user = createApprovedAppUser();
        $response = subdomainGet($this, 'app', '/dashboard', $user);
        // User may be redirected to onboarding if billing not complete, but route should match
        expect($response->status())->toBeIn([200, 302]);
        // If redirected, should be to onboarding or billing, not 404
        if ($response->status() === 302) {
            expect($response->headers->get('Location'))->not->toContain('login');
        }
    });

    it('redirects legacy /app/dashboard to /dashboard', function () {
        $user = createApprovedAppUser();
        $response = subdomainGet($this, 'app', '/app/dashboard', $user);
        $response->assertRedirect('/dashboard');
        $response->assertStatus(301);
    });
});

/*
|--------------------------------------------------------------------------
| Admin Subdomain Tests
|--------------------------------------------------------------------------
*/

describe('Admin Subdomain', function () {
    it('shows login page on admin subdomain', function () {
        $response = subdomainGet($this, 'admin', '/login');
        $response->assertOk();
    });

    it('blocks non-admin users from admin dashboard', function () {
        $user = createApprovedAppUser();
        $response = subdomainGet($this, 'admin', '/dashboard', $user);
        $response->assertStatus(403);
    });

    it('allows admin users to access admin dashboard', function () {
        $admin = createAdminUser();
        $response = subdomainGet($this, 'admin', '/dashboard', $admin);
        $response->assertOk();
    });

    it('redirects legacy /admin/dashboard to /dashboard', function () {
        $admin = createAdminUser();
        $response = subdomainGet($this, 'admin', '/admin/dashboard', $admin);
        $response->assertRedirect('/dashboard');
        $response->assertStatus(301);
    });
});

/*
|--------------------------------------------------------------------------
| API Subdomain Tests
|--------------------------------------------------------------------------
*/

describe('API Subdomain', function () {
    it('serves API endpoints on api subdomain', function () {
        $baseDomain = config('domains.base', 'argusly.local');
        $host = "api.{$baseDomain}";

        // The Mollie webhook should be accessible (even if it returns an error due to missing data)
        $response = $this->withHeaders(['Host' => $host])
            ->postJson("http://{$host}/v1/webhooks/mollie", ['id' => 'test']);

        // Should not be 404 - the route exists
        expect($response->status())->not->toBe(404);
    });
});

/*
|--------------------------------------------------------------------------
| Cross-Subdomain Tests
|--------------------------------------------------------------------------
*/

describe('Cross-Subdomain Routing', function () {
    it('does not expose app routes on marketing domain', function () {
        $response = subdomainGet($this, 'marketing', '/dashboard');
        $response->assertStatus(404);
    });

    it('does not expose admin routes on app domain', function () {
        $admin = createAdminUser();
        // admin routes should 404 on app subdomain since the route is only registered on admin subdomain
        $response = subdomainGet($this, 'app', '/organizations', $admin);
        $response->assertStatus(404);
    });

    it('marketing routes redirect on app domain for backwards compatibility', function () {
        // Marketing routes are available on all domains for backwards compatibility
        // This is intentional - public marketing content is not a security concern
        $response = subdomainGet($this, 'app', '/prijzen');
        $response->assertStatus(301);
        $response->assertRedirect('http://app.argusly.local/nl/prijzen');
    });
});

/*
|--------------------------------------------------------------------------
| Login Redirect Tests
|--------------------------------------------------------------------------
*/

describe('Login Redirects', function () {
    it('redirects admin users to admin subdomain after login', function () {
        $admin = createAdminUser('admin');
        $response = subdomainPost($this, 'app', '/login', [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('admin.argusly.local/dashboard');
    });

    it('redirects regular users to app dashboard after login', function () {
        $user = createApprovedAppUser();
        $response = subdomainPost($this, 'app', '/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect();
        // Should stay on app subdomain
        expect($response->headers->get('Location'))->toContain('/dashboard');
        expect($response->headers->get('Location'))->not->toContain('admin.');
    });

    it('redirects to marketing domain after logout', function () {
        $user = createApprovedAppUser();
        $response = subdomainPost($this, 'app', '/logout', [], $user);

        $response->assertRedirect();
        expect($response->headers->get('Location'))->toContain('argusly.local');
        expect($response->headers->get('Location'))->not->toContain('app.');
    });
});

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function createApprovedAppUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Test Org ' . Str::lower(Str::random(4)),
        'slug' => 'test-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Test User',
        'email' => 'user+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => false,
        'admin_role' => 'user',
    ]);
}

function createAdminUser(string $adminRole = 'admin'): User
{
    $organization = Organization::query()->create([
        'name' => 'Admin Org ' . Str::lower(Str::random(4)),
        'slug' => 'admin-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => ucfirst($adminRole) . ' User',
        'email' => $adminRole . '+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => $adminRole,
    ]);
}
