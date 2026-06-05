<?php

use App\Services\PluginUpdates\PluginArchiveUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('PluginArchiveUploadService', function () {
    it('returns server limits', function () {
        $service = new PluginArchiveUploadService();
        $limits = $service->getServerLimits();

        expect($limits)->toBeArray()
            ->and($limits)->toHaveKeys(['upload_max_filesize', 'post_max_size', 'memory_limit', 'max_input_time']);
    });

    it('analyzes request with no file as blocked', function () {
        $service = new PluginArchiveUploadService();
        $request = Request::create('/test', 'POST', [], [], [], [
            'HTTP_CONTENT_LENGTH' => '5000000',
        ]);

        $analysis = $service->analyzeUploadRequest($request);

        expect($analysis['can_proceed'])->toBeFalse()
            ->and($analysis['diagnosis'])->toContain('blocked by server');
    });

    it('analyzes request with empty POST as no file', function () {
        $service = new PluginArchiveUploadService();
        $request = Request::create('/test', 'POST');

        $analysis = $service->analyzeUploadRequest($request);

        expect($analysis['can_proceed'])->toBeFalse()
            ->and($analysis['diagnosis'])->toContain('No file was included');
    });

    it('validates valid zip archive', function () {
        $service = new PluginArchiveUploadService();

        // Create a real ZIP file
        $zipPath = sys_get_temp_dir() . '/test-plugin.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('plugin/main.php', '<?php // plugin code');
        $zip->close();

        $file = new UploadedFile(
            $zipPath,
            'test-plugin.zip',
            'application/zip',
            null,
            true
        );

        $result = $service->validateArchive($file);

        expect($result['valid'])->toBeTrue()
            ->and($result['error'])->toBeNull()
            ->and($result['file_info']['extension'])->toBe('zip');

        @unlink($zipPath);
    });

    it('rejects non-zip files', function () {
        $service = new PluginArchiveUploadService();

        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $result = $service->validateArchive($file);

        expect($result['valid'])->toBeFalse()
            ->and($result['error'])->toContain('Invalid file type');
    });

    it('rejects files exceeding max size', function () {
        $service = new PluginArchiveUploadService();

        // Create a fake file larger than max size
        $file = UploadedFile::fake()->create('large.zip', 60000, 'application/zip'); // 60MB

        $result = $service->validateArchive($file);

        expect($result['valid'])->toBeFalse()
            ->and($result['error'])->toContain('too large');
    });

    it('rejects zip with unsafe paths (zip slip)', function () {
        $service = new PluginArchiveUploadService();

        // Create a ZIP with a path traversal attempt
        $zipPath = sys_get_temp_dir() . '/malicious.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('../../../etc/passwd', 'malicious content');
        $zip->close();

        $file = new UploadedFile(
            $zipPath,
            'malicious.zip',
            'application/zip',
            null,
            true
        );

        $result = $service->validateArchive($file);

        expect($result['valid'])->toBeFalse()
            ->and($result['error'])->toContain('unsafe paths');

        @unlink($zipPath);
    });

    it('rejects empty zip archives', function () {
        $service = new PluginArchiveUploadService();

        // Create an "empty" ZIP by adding and then deleting a file
        // This creates a valid ZIP structure with no files
        $zipPath = sys_get_temp_dir() . '/empty-' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('temp.txt', 'temp');
        $zip->close();

        // Re-open and delete the temp file to make it "empty"
        $zip->open($zipPath);
        $zip->deleteIndex(0);
        $zip->close();

        // Check if file exists after ZipArchive operations
        if (! file_exists($zipPath)) {
            // If the file doesn't exist (some PHP versions delete empty zips),
            // create a minimal invalid zip for testing
            file_put_contents($zipPath, 'PK');
        }

        $file = new UploadedFile(
            $zipPath,
            'empty.zip',
            'application/zip',
            null,
            true
        );

        $result = $service->validateArchive($file);

        // Should fail either as empty or invalid
        expect($result['valid'])->toBeFalse();

        @unlink($zipPath);
    });

    it('stores archive and creates release', function () {
        Storage::fake('local');
        config(['publishlayer.plugin_updates.disk' => 'local']);

        $service = new PluginArchiveUploadService();

        // Create a valid ZIP
        $zipPath = sys_get_temp_dir() . '/plugin-' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('publishlayer/plugin.php', '<?php // plugin');
        $zip->close();

        $file = new UploadedFile(
            $zipPath,
            'publishlayer-plugin.zip',
            'application/zip',
            null,
            true
        );

        $result = $service->storeArchive($file, [
            'version' => '2.0.0',
            'min_wp_version' => '6.0',
            'tested_wp_version' => '6.8',
            'is_security_release' => true,
        ]);

        expect($result['success'])->toBeTrue()
            ->and($result['release'])->not->toBeNull()
            ->and($result['release']->version)->toBe('2.0.0')
            ->and($result['release']->is_security_release)->toBeTrue()
            ->and($result['storage_path'])->toContain('plugin-releases/');

        expect(Storage::disk('local')->exists($result['storage_path']))->toBeTrue();

        @unlink($zipPath);
    });
});

describe('Upload diagnostics endpoint', function () {
    it('returns server diagnostics for superadmin', function () {
        Storage::fake('local');
        config(['publishlayer.plugin_updates.disk' => 'local']);

        $superadmin = createSuperadminUser();

        $this->actingAs($superadmin)
            ->getJson(route('admin.dashboard.upload-diagnostics'))
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'server_limits' => ['upload_max_filesize', 'post_max_size'],
                'storage' => ['disk', 'writable'],
                'recommendations',
                'timestamp',
            ]);
    });

    it('blocks non-superadmin from diagnostics', function () {
        $admin = createPluginArchiveAdminUser();

        $this->actingAs($admin)
            ->getJson(route('admin.dashboard.upload-diagnostics'))
            ->assertForbidden();
    });
});

function createSuperadminUser(): \App\Models\User
{
    $organization = \App\Models\Organization::query()->create([
        'name' => 'Test Org ' . \Illuminate\Support\Str::random(4),
        'slug' => 'test-org-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return \App\Models\User::query()->create([
        'name' => 'Superadmin User',
        'email' => 'superadmin+' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'superadmin',
    ]);
}

function createPluginArchiveAdminUser(): \App\Models\User
{
    $organization = \App\Models\Organization::query()->create([
        'name' => 'Admin Org ' . \Illuminate\Support\Str::random(4),
        'slug' => 'admin-org-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return \App\Models\User::query()->create([
        'name' => 'Admin User',
        'email' => 'admin+' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => 'admin',
    ]);
}
