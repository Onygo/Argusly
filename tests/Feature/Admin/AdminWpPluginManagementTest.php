<?php

use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\PluginRelease;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('shows wordpress site plugin version status on admin dashboard based on heartbeat fields', function () {
    $admin = makeAdminPluginUser('admin');

    $organization = Organization::query()->create([
        'name' => 'Plugin Org',
        'slug' => 'plugin-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Plugin Workspace',
        'organization_id' => $organization->id,
    ]);

    ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'connector_platform' => 'wp',
        'name' => 'WP Client Site',
        'site_url' => 'https://wp-plugin.example.com',
        'base_url' => 'https://wp-plugin.example.com',
        'allowed_domains' => ['wp-plugin.example.com'],
        'status' => 'connected',
        'is_active' => true,
        'last_heartbeat_at' => now()->subMinutes(2),
        'wp_version' => '6.7.1',
        'plugin_version' => '1.0.0',
        'connector_version' => '1.0.0',
    ]);

    PluginRelease::query()->create([
        'version' => '1.2.0',
        'min_wp_version' => '6.0',
        'tested_wp_version' => '6.7',
        'zip_storage_path' => 'plugin-releases/publishlayer-wordpress-plugin-1.2.0.zip',
        'is_security_release' => false,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee('WordPress plugin management')
        ->assertSee('WP Client Site')
        ->assertSee('1.0.0')
        ->assertSee('outdated');
});

it('allows superadmin to upload wordpress plugin release from admin dashboard', function () {
    Storage::fake('local');
    config(['publishlayer.plugin_updates.disk' => 'local']);

    $superadmin = makeAdminPluginUser('superadmin');

    // Create a real ZIP file for testing
    $zipPath = sys_get_temp_dir() . '/test-plugin-' . uniqid() . '.zip';
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('publishlayer/plugin.php', '<?php // Plugin code');
    $zip->close();

    $uploadedFile = new UploadedFile(
        $zipPath,
        'publishlayer-wordpress-plugin.zip',
        'application/zip',
        null,
        true
    );

    $this->actingAs($superadmin)
        ->post(route('admin.dashboard.plugin-releases.store'), [
            'version' => '1.3.0',
            'min_wp_version' => '6.0',
            'tested_wp_version' => '6.8',
            'is_security_release' => '1',
            'archive' => $uploadedFile,
        ])
        ->assertRedirect(route('admin.dashboard'))
        ->assertSessionHas('status', 'WordPress plugin release uploaded.');

    $release = PluginRelease::query()->where('version', '1.3.0')->first();
    expect($release)->not->toBeNull()
        ->and($release->is_security_release)->toBeTrue();

    expect(Storage::disk('local')->exists((string) $release->zip_storage_path))->toBeTrue();

    @unlink($zipPath);
});

it('blocks non-superadmin from uploading wordpress plugin release from admin dashboard', function () {
    Storage::fake('local');
    config(['publishlayer.plugin_updates.disk' => 'local']);

    $admin = makeAdminPluginUser('admin');

    $this->actingAs($admin)
        ->post(route('admin.dashboard.plugin-releases.store'), [
            'version' => '1.4.0',
            'archive' => UploadedFile::fake()->create('publishlayer-wordpress-plugin.zip', 120, 'application/zip'),
        ])
        ->assertForbidden();
});

it('allows admin to download plugin release archive from dashboard', function () {
    Storage::fake('local');
    config(['publishlayer.plugin_updates.disk' => 'local']);

    $admin = makeAdminPluginUser('admin');

    Storage::disk('local')->put('plugin-releases/publishlayer-wordpress-plugin-1.5.0.zip', 'release-bytes');

    $release = PluginRelease::query()->create([
        'version' => '1.5.0',
        'min_wp_version' => '6.0',
        'tested_wp_version' => '6.8',
        'zip_storage_path' => 'plugin-releases/publishlayer-wordpress-plugin-1.5.0.zip',
        'is_security_release' => false,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard.plugin-releases.download', $release))
        ->assertOk()
        ->assertDownload('publishlayer-wordpress-plugin-1.5.0.zip');
});

it('allows superadmin to delete an older wordpress plugin release and archive', function () {
    Storage::fake('local');
    config(['publishlayer.plugin_updates.disk' => 'local']);

    $superadmin = makeAdminPluginUser('superadmin');

    Storage::disk('local')->put('plugin-releases/publishlayer-wordpress-plugin-1.5.0.zip', 'old-release');
    Storage::disk('local')->put('plugin-releases/publishlayer-wordpress-plugin-1.6.0.zip', 'latest-release');

    $oldRelease = PluginRelease::query()->create([
        'version' => '1.5.0',
        'min_wp_version' => '6.0',
        'tested_wp_version' => '6.8',
        'zip_storage_path' => 'plugin-releases/publishlayer-wordpress-plugin-1.5.0.zip',
        'is_security_release' => false,
    ]);

    PluginRelease::query()->create([
        'version' => '1.6.0',
        'min_wp_version' => '6.0',
        'tested_wp_version' => '6.8',
        'zip_storage_path' => 'plugin-releases/publishlayer-wordpress-plugin-1.6.0.zip',
        'is_security_release' => false,
    ]);

    $this->actingAs($superadmin)
        ->delete(route('admin.dashboard.plugin-releases.destroy', $oldRelease))
        ->assertRedirect(route('admin.dashboard'))
        ->assertSessionHas('status', 'WordPress plugin release deleted.');

    expect(PluginRelease::query()->where('version', '1.5.0')->exists())->toBeFalse();
    expect(Storage::disk('local')->exists('plugin-releases/publishlayer-wordpress-plugin-1.5.0.zip'))->toBeFalse();
    expect(Storage::disk('local')->exists('plugin-releases/publishlayer-wordpress-plugin-1.6.0.zip'))->toBeTrue();
});

it('prevents deleting the latest wordpress plugin release', function () {
    Storage::fake('local');
    config(['publishlayer.plugin_updates.disk' => 'local']);

    $superadmin = makeAdminPluginUser('superadmin');

    Storage::disk('local')->put('plugin-releases/publishlayer-wordpress-plugin-1.6.0.zip', 'latest-release');

    $latestRelease = PluginRelease::query()->create([
        'version' => '1.6.0',
        'min_wp_version' => '6.0',
        'tested_wp_version' => '6.8',
        'zip_storage_path' => 'plugin-releases/publishlayer-wordpress-plugin-1.6.0.zip',
        'is_security_release' => false,
    ]);

    $this->actingAs($superadmin)
        ->delete(route('admin.dashboard.plugin-releases.destroy', $latestRelease))
        ->assertRedirect(route('admin.dashboard'))
        ->assertSessionHasErrors('dashboard');

    expect(PluginRelease::query()->where('version', '1.6.0')->exists())->toBeTrue();
    expect(Storage::disk('local')->exists('plugin-releases/publishlayer-wordpress-plugin-1.6.0.zip'))->toBeTrue();
});

it('blocks non-superadmin from deleting wordpress plugin releases', function () {
    Storage::fake('local');
    config(['publishlayer.plugin_updates.disk' => 'local']);

    $admin = makeAdminPluginUser('admin');

    $release = PluginRelease::query()->create([
        'version' => '1.5.0',
        'min_wp_version' => '6.0',
        'tested_wp_version' => '6.8',
        'zip_storage_path' => 'plugin-releases/publishlayer-wordpress-plugin-1.5.0.zip',
        'is_security_release' => false,
    ]);

    $this->actingAs($admin)
        ->delete(route('admin.dashboard.plugin-releases.destroy', $release))
        ->assertForbidden();
});

it('shows wordpress plugin status columns on admin sites page', function () {
    $admin = makeAdminPluginUser('admin');

    $organization = Organization::query()->create([
        'name' => 'Sites Status Org',
        'slug' => 'sites-status-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Sites Status Workspace',
        'organization_id' => $organization->id,
    ]);

    ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'connector_platform' => 'wp',
        'name' => 'Status Site',
        'site_url' => 'https://status-site.example.com',
        'base_url' => 'https://status-site.example.com',
        'allowed_domains' => ['status-site.example.com'],
        'status' => 'connected',
        'is_active' => true,
        'last_heartbeat_at' => now()->subMinutes(5),
        'wp_version' => '6.7.2',
        'plugin_version' => '1.0.0',
        'connector_version' => '1.0.0',
    ]);

    PluginRelease::query()->create([
        'version' => '1.1.0',
        'min_wp_version' => '6.0',
        'tested_wp_version' => '6.8',
        'zip_storage_path' => 'plugin-releases/publishlayer-wordpress-plugin-1.1.0.zip',
        'is_security_release' => false,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.sites'))
        ->assertOk()
        ->assertSee('WP version')
        ->assertSee('Plugin version')
        ->assertSee('Plugin status')
        ->assertSee('Status Site')
        ->assertSee('outdated');
});

function makeAdminPluginUser(string $adminRole): User
{
    $organization = Organization::query()->create([
        'name' => 'Admin Plugin Org ' . Str::lower(Str::random(4)),
        'slug' => 'admin-plugin-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $isAdmin = in_array($adminRole, ['admin', 'superadmin'], true);

    return User::query()->create([
        'name' => ucfirst($adminRole) . ' User',
        'email' => $adminRole . '+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => $isAdmin,
        'admin_role' => $adminRole,
    ]);
}
