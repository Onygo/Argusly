<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentTranslation;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('repairs stale translation locks with apply mode', function () {
    $user = User::query()->create([
        'name' => 'Stale Lock User',
        'email' => 'stale-lock-' . Str::random(8) . '@example.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $organization = Organization::query()->create([
        'name' => 'Stale Lock Org',
        'slug' => 'stale-lock-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'primary_user_id' => $user->id,
    ]);

    $user->organization_id = $organization->id;
    $user->role = 'owner';
    $user->save();

    $workspace = Workspace::query()->create([
        'name' => 'Stale Lock Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en', 'nl', 'de'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Stale Lock Site',
        'site_url' => 'https://stale-lock.example.com',
        'allowed_domains' => ['stale-lock.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Source content',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'origin_type' => 'manual',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
        'is_source_locale' => true,
    ]);

    $content->forceFill(['family_id' => $content->id])->save();

    Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Source brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    config()->set('translation.processing_lock_ttl_seconds', 60);

    $stale = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_PROCESSING,
        'requested_by_user_id' => $user->id,
    ]);

    $stale->forceFill([
        'updated_at' => now()->subMinutes(15),
        'created_at' => now()->subMinutes(15),
    ])->saveQuietly();

    $fresh = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'de',
        'status' => ContentTranslation::STATUS_QUEUED,
        'requested_by_user_id' => $user->id,
    ]);

    $fresh->forceFill([
        'updated_at' => now()->subSeconds(30),
        'created_at' => now()->subSeconds(30),
    ])->saveQuietly();

    Artisan::call('translation:repair-stale-locks', [
        '--apply' => true,
        '--limit' => 50,
    ]);

    expect($stale->fresh()->status)->toBe(ContentTranslation::STATUS_FAILED)
        ->and($stale->fresh()->displayStatus())->toBe('stale')
        ->and($stale->fresh()->job_id)->toBeNull()
        ->and($stale->fresh()->isStaleFailure())->toBeTrue()
        ->and($stale->fresh()->isActiveLock())->toBeFalse()
        ->and($fresh->fresh()->status)->toBe(ContentTranslation::STATUS_QUEUED);
});

it('shows stale locks in dry run without modifying them', function () {
    $user = User::query()->create([
        'name' => 'Dry Run User',
        'email' => 'dry-run-' . Str::random(8) . '@example.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $organization = Organization::query()->create([
        'name' => 'Dry Run Org',
        'slug' => 'dry-run-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'primary_user_id' => $user->id,
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Dry Run Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en', 'nl'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Dry Run Site',
        'site_url' => 'https://dry-run.example.com',
        'allowed_domains' => ['dry-run.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Dry run content',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'origin_type' => 'manual',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
        'is_source_locale' => true,
    ]);

    config()->set('translation.processing_lock_ttl_seconds', 60);

    $stale = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_PROCESSING,
        'requested_by_user_id' => $user->id,
        'job_id' => 'stale-job-to-preserve',
    ]);

    $stale->forceFill([
        'updated_at' => now()->subMinutes(30),
        'created_at' => now()->subMinutes(30),
    ])->saveQuietly();

    // Dry run - no --apply flag
    Artisan::call('translation:repair-stale-locks', [
        '--limit' => 50,
    ]);

    $output = Artisan::output();

    expect($output)->toContain('Dry run')
        ->and($stale->fresh()->status)->toBe(ContentTranslation::STATUS_PROCESSING)
        ->and($stale->fresh()->job_id)->toBe('stale-job-to-preserve');
});

it('reports no stale locks when all translations are fresh', function () {
    $user = User::query()->create([
        'name' => 'Fresh User',
        'email' => 'fresh-' . Str::random(8) . '@example.com',
        'password' => bcrypt('password'),
    ]);

    $organization = Organization::query()->create([
        'name' => 'Fresh Org',
        'slug' => 'fresh-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'primary_user_id' => $user->id,
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Fresh Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en', 'nl'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Fresh Site',
        'site_url' => 'https://fresh.example.com',
        'allowed_domains' => ['fresh.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Fresh content',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'origin_type' => 'manual',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
        'is_source_locale' => true,
    ]);

    config()->set('translation.processing_lock_ttl_seconds', 3600);

    // Fresh processing translation - updated recently
    ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_PROCESSING,
        'requested_by_user_id' => $user->id,
    ]);

    Artisan::call('translation:repair-stale-locks', [
        '--apply' => true,
    ]);

    $output = Artisan::output();

    expect($output)->toContain('No stale translation locks detected');
});

it('repairs failed stale locks that still report already processing without a job reference', function () {
    $user = User::query()->create([
        'name' => 'Failed Stale User',
        'email' => 'failed-stale-' . Str::random(8) . '@example.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $organization = Organization::query()->create([
        'name' => 'Failed Stale Org',
        'slug' => 'failed-stale-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'primary_user_id' => $user->id,
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Failed Stale Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en', 'nl'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Failed Stale Site',
        'site_url' => 'https://failed-stale.example.com',
        'allowed_domains' => ['failed-stale.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Failed stale content',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'origin_type' => 'manual',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
        'is_source_locale' => true,
    ]);

    config()->set('translation.processing_lock_ttl_seconds', 60);

    $translation = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_FAILED,
        'requested_by_user_id' => $user->id,
        'job_id' => null,
        'error_message' => "A translation to 'Dutch' is already processing.",
    ]);

    $translation->forceFill([
        'updated_at' => now()->subMinutes(45),
        'created_at' => now()->subMinutes(45),
    ])->saveQuietly();

    Artisan::call('translation:repair-stale-locks', [
        '--apply' => true,
        '--failed-only' => true,
    ]);

    $translation->refresh();

    expect($translation->status)->toBe(ContentTranslation::STATUS_FAILED)
        ->and($translation->job_id)->toBeNull()
        ->and($translation->isStaleFailure())->toBeTrue()
        ->and($translation->displayErrorMessage())->toContain('already processing');
});
