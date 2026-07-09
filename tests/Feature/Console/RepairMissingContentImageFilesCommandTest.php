<?php

use App\Models\ContentImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('restores missing content image files from an old release or backup directory', function (): void {
    Storage::fake('content_images');
    config()->set('argusly.images.disk', 'content_images');

    $backupRoot = storage_path('framework/testing/content-image-backup-'.Str::random(8));
    $sourcePath = $backupRoot.'/public/content-images/69202942-01c3-4275-95f5-3c24285ebb6e/20260708133925-featured-y7kuyd04.png';

    File::ensureDirectoryExists(dirname($sourcePath));
    File::put($sourcePath, 'restored-image-bytes');

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'featured',
        'source' => ContentImage::SOURCE_GENERATED,
        'provider' => 'openai',
        'status' => 'ready',
        'is_active' => true,
        'image_path' => 'content-images/69202942-01c3-4275-95f5-3c24285ebb6e/20260708133925-featured-y7kuyd04.png',
        'image_url' => 'https://app.argusly.com/content-images/69202942-01c3-4275-95f5-3c24285ebb6e/20260708133925-featured-y7kuyd04.png',
        'credit_cost' => 0,
    ]);

    $this->artisan('content-images:repair-missing-files', [
        '--id' => [(string) $image->id],
        '--search' => [$backupRoot],
        '--restore' => true,
    ])
        ->expectsOutputToContain('files_restored')
        ->assertExitCode(0);

    Storage::disk('content_images')->assertExists('content-images/69202942-01c3-4275-95f5-3c24285ebb6e/20260708133925-featured-y7kuyd04.png');
    expect(Storage::disk('content_images')->get('content-images/69202942-01c3-4275-95f5-3c24285ebb6e/20260708133925-featured-y7kuyd04.png'))
        ->toBe('restored-image-bytes');

    File::deleteDirectory($backupRoot);
});

it('reports restorable files without copying them during audit mode', function (): void {
    Storage::fake('content_images');
    config()->set('argusly.images.disk', 'content_images');

    $backupRoot = storage_path('framework/testing/content-image-backup-'.Str::random(8));
    $sourcePath = $backupRoot.'/storage/app/public/content-images/restorable/featured.png';

    File::ensureDirectoryExists(dirname($sourcePath));
    File::put($sourcePath, 'backup-image-bytes');

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'featured',
        'source' => ContentImage::SOURCE_GENERATED,
        'provider' => 'openai',
        'status' => 'ready',
        'is_active' => true,
        'image_path' => 'storage/content-images/restorable/featured.png',
        'credit_cost' => 0,
    ]);

    $this->artisan('content-images:repair-missing-files', [
        '--id' => [(string) $image->id],
        '--search' => [$backupRoot],
    ])
        ->expectsOutputToContain('Restorable files found')
        ->assertExitCode(1);

    Storage::disk('content_images')->assertMissing('content-images/restorable/featured.png');

    File::deleteDirectory($backupRoot);
});

it('infers sibling release directories from the supplied current release path', function (): void {
    Storage::fake('content_images');
    config()->set('argusly.images.disk', 'content_images');

    $deployRoot = storage_path('framework/testing/content-image-releases-'.Str::random(8));
    $currentRelease = $deployRoot.'/releases/20260709_003';
    $oldRelease = $deployRoot.'/releases/20260708_002';
    $sourcePath = $oldRelease.'/public/content-images/release-asset/featured.png';

    File::ensureDirectoryExists($currentRelease);
    File::ensureDirectoryExists(dirname($sourcePath));
    File::put($sourcePath, 'old-release-image-bytes');

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'featured',
        'source' => ContentImage::SOURCE_GENERATED,
        'provider' => 'openai',
        'status' => 'ready',
        'is_active' => true,
        'image_path' => 'content-images/release-asset/featured.png',
        'credit_cost' => 0,
    ]);

    $this->artisan('content-images:repair-missing-files', [
        '--id' => [(string) $image->id],
        '--search' => [$currentRelease],
        '--restore' => true,
    ])
        ->expectsOutputToContain('files_restored')
        ->assertExitCode(0);

    Storage::disk('content_images')->assertExists('content-images/release-asset/featured.png');
    expect(Storage::disk('content_images')->get('content-images/release-asset/featured.png'))
        ->toBe('old-release-image-bytes');

    File::deleteDirectory($deployRoot);
});
