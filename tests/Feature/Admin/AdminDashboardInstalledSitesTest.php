<?php

use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\PluginRelease;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('loads the admin dashboard with no wordpress sites', function () {
    $admin = makeAdminDashboardSitesUser();

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('No WordPress sites found yet.');
});

it('renders installed site rows safely when site relation data is incomplete', function () {
    $html = view('admin.dashboard', adminDashboardViewData([
        [
            'site' => null,
            'site_name' => 'Orphaned WP Site',
            'installed_version' => '1.0.0',
            'status' => 'outdated',
        ],
    ]))->render();

    expect($html)->toContain('Orphaned WP Site')
        ->and($html)->toContain('n/a')
        ->and($html)->toContain('never')
        ->and($html)->toContain('outdated');
});

it('loads the admin dashboard with normal wordpress site rows', function () {
    $admin = makeAdminDashboardSitesUser();

    $organization = Organization::query()->create([
        'name' => 'Dashboard Sites Org',
        'slug' => 'dashboard-sites-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Dashboard Sites Workspace',
        'organization_id' => $organization->id,
    ]);

    ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'connector_platform' => 'wp',
        'name' => 'Healthy WP Site',
        'site_url' => 'https://healthy-wp.example.com',
        'base_url' => 'https://healthy-wp.example.com',
        'allowed_domains' => ['healthy-wp.example.com'],
        'status' => 'connected',
        'is_active' => true,
        'last_heartbeat_at' => now()->subMinutes(5),
        'wp_version' => '6.7.2',
        'plugin_version' => '1.2.0',
        'connector_version' => '1.2.0',
    ]);

    PluginRelease::query()->create([
        'version' => '1.2.0',
        'min_wp_version' => '6.0',
        'tested_wp_version' => '6.8',
        'zip_storage_path' => 'plugin-releases/argusly-wordpress-plugin-1.2.0.zip',
        'is_security_release' => false,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('Healthy WP Site')
        ->assertSee('Dashboard Sites Org')
        ->assertSee('Dashboard Sites Workspace')
        ->assertSee('6.7.2')
        ->assertSee('up to date');
});

function makeAdminDashboardSitesUser(): User
{
    $organization = Organization::query()->create([
        'name' => 'Admin Dashboard Sites Org ' . Str::lower(Str::random(4)),
        'slug' => 'admin-dashboard-sites-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => 'Admin Dashboard Sites User',
        'email' => 'admin-dashboard-sites+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);
}

function adminDashboardViewData(array $wpSiteVersions = []): array
{
    return [
        'pendingOrganizations' => 0,
        'pendingUsers' => 0,
        'orgsOnHold' => 0,
        'activitySummary' => [],
        'activitySummaryCards' => [],
        'clientActivityCards' => [],
        'activityLabels' => ['last_activity_at' => 'Last activity'],
        'onboarding' => [
            'new_registrations_7d' => 0,
            'activated_7d' => 0,
            'avg_minutes_to_first_value' => null,
            'phase_rows' => collect(),
        ],
        'latestWpPluginRelease' => null,
        'pluginReleases' => collect(),
        'wpSiteVersions' => collect($wpSiteVersions),
        'seoDashboard' => [
            'sitemap_url' => 'https://example.com/sitemap.xml',
            'robots_url' => 'https://example.com/robots.txt',
            'canonical_audit_status' => 'Not run',
            'last_audit_run' => null,
            'pages_missing_metadata' => 0,
            'pages_missing_alt_text' => 0,
            'pages_excluded_from_index' => 0,
            'recommendations' => [],
        ],
        'errors' => new ViewErrorBag(),
    ];
}
