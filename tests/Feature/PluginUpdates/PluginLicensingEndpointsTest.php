<?php

use App\Models\LicenseKey;
use App\Models\Organization;
use App\Models\PluginRelease;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function createActiveWorkspaceWithLicense(): array
{
    $organization = Organization::query()->create([
        'name' => 'Plugin Org',
        'slug' => 'plugin-org',
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Plugin Workspace',
        'organization_id' => $organization->id,
    ]);

    $plainLicenseKey = 'pl_lic_' . bin2hex(random_bytes(12));

    $license = LicenseKey::query()->create([
        'license_key_hash' => hash('sha256', $plainLicenseKey),
        'workspace_id' => $workspace->id,
        'status' => 'active',
        'expires_at' => now()->addDays(30),
    ]);

    return [$organization, $workspace, $license, $plainLicenseKey];
}

function signedPluginPost(string $uri, array $payload, string $clientSecret, ?int $timestamp = null)
{
    $timestamp = $timestamp ?? now()->timestamp;
    $rawBody = json_encode($payload);
    $signature = hash_hmac('sha256', $timestamp . '.' . $rawBody, $clientSecret);

    return test()->call(
        'POST',
        $uri,
        [],
        [],
        [],
        [
            'HTTP_X_PL_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_PL_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $rawBody
    );
}

it('registers plugin domain for a valid active license', function () {
    [, $workspace, , $plainLicenseKey] = createActiveWorkspaceWithLicense();

    $response = $this->postJson('/api/v1/plugin/register-domain', [
        'license_key' => $plainLicenseKey,
        'domain' => 'example.com',
        'site_url' => 'https://example.com',
        'wp_version' => '6.7',
        'plugin_version' => '1.0.0',
    ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonStructure([
            'client_secret',
            'workspace' => ['id', 'organization_id', 'organization_status'],
        ]);

    $this->assertDatabaseHas('workspace_domains', [
        'workspace_id' => $workspace->id,
        'domain' => 'example.com',
    ]);
});

it('returns forbidden when registering domain with invalid license', function () {
    $response = $this->postJson('/api/v1/plugin/register-domain', [
        'license_key' => 'invalid-license',
        'domain' => 'example.com',
        'site_url' => 'https://example.com',
        'wp_version' => '6.7',
        'plugin_version' => '1.0.0',
    ]);

    $response->assertStatus(403);
});

it('returns update metadata when newer compatible plugin release exists', function () {
    [, , , $plainLicenseKey] = createActiveWorkspaceWithLicense();

    $registerResponse = $this->postJson('/api/v1/plugin/register-domain', [
        'license_key' => $plainLicenseKey,
        'domain' => 'example.com',
        'site_url' => 'https://example.com',
        'wp_version' => '6.7',
        'plugin_version' => '1.0.0',
    ])->assertOk();

    PluginRelease::query()->create([
        'version' => '1.2.0',
        'min_wp_version' => '6.0',
        'tested_wp_version' => '6.7',
        'zip_storage_path' => 'plugin-releases/publishlayer-1.2.0.zip',
        'is_security_release' => false,
    ]);

    $clientSecret = (string) $registerResponse->json('client_secret');

    $response = signedPluginPost('/api/v1/plugin/check-update', [
        'license_key' => $plainLicenseKey,
        'domain' => 'example.com',
        'wp_version' => '6.7',
        'plugin_version' => '1.0.0',
    ], $clientSecret);

    $response->assertOk()
        ->assertJsonPath('update_available', true)
        ->assertJsonPath('version', '1.2.0')
        ->assertJsonStructure(['download_token', 'download_url']);

    $downloadPath = (string) parse_url((string) $response->json('download_url'), PHP_URL_PATH);
    $encodedToken = rawurlencode((string) $response->json('download_token'));

    expect($downloadPath)
        ->toEndWith('/' . $encodedToken)
        ->and(substr($downloadPath, -strlen($encodedToken)))
        ->not->toContain('/');
});

it('returns update_available false for unknown domain even with valid signature', function () {
    [, , $license, $plainLicenseKey] = createActiveWorkspaceWithLicense();

    $secret = app(\App\Services\PluginUpdates\LicenseKeyService::class)
        ->deriveClientSecret($license, 'unknown.example');

    $response = signedPluginPost('/api/v1/plugin/check-update', [
        'license_key' => $plainLicenseKey,
        'domain' => 'unknown.example',
        'wp_version' => '6.7',
        'plugin_version' => '1.0.0',
    ], $secret);

    $response->assertOk()
        ->assertJsonPath('update_available', false);
});

it('streams plugin zip with valid token and blocks when license becomes inactive', function () {
    [, , $license, $plainLicenseKey] = createActiveWorkspaceWithLicense();

    $registerResponse = $this->postJson('/api/v1/plugin/register-domain', [
        'license_key' => $plainLicenseKey,
        'domain' => 'example.com',
        'site_url' => 'https://example.com',
        'wp_version' => '6.7',
        'plugin_version' => '1.0.0',
    ])->assertOk();

    $release = PluginRelease::query()->create([
        'version' => '1.3.0',
        'min_wp_version' => '6.0',
        'tested_wp_version' => '6.7',
        'zip_storage_path' => 'plugin-releases/publishlayer-1.3.0.zip',
        'is_security_release' => true,
    ]);

    Storage::disk('local')->put($release->zip_storage_path, 'zip-binary-content');

    $clientSecret = (string) $registerResponse->json('client_secret');
    $checkResponse = signedPluginPost('/api/v1/plugin/check-update', [
        'license_key' => $plainLicenseKey,
        'domain' => 'example.com',
        'wp_version' => '6.7',
        'plugin_version' => '1.0.0',
    ], $clientSecret)->assertOk();

    $downloadUrl = (string) $checkResponse->json('download_url');

    $downloadResponse = $this->get($downloadUrl);
    $downloadResponse->assertOk();
    expect($downloadResponse->streamedContent())->toBe('zip-binary-content');

    $license->update(['status' => 'suspended']);

    $blockedResponse = $this->get($downloadUrl);
    $blockedResponse->assertStatus(403);
});
