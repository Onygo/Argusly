<?php

use App\Jobs\TranslateDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentTranslation;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Translation\TranslationLockRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('shows stale processing locks in the admin translations overview', function () {
    $superadmin = makePlatformAdmin('superadmin');
    [$content, $draft] = makeAdminTranslationContext();

    config()->set('translation.processing_lock_ttl_seconds', 60);

    $translation = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_PROCESSING,
        'requested_by_user_id' => $superadmin->id,
        'job_id' => '12345',
    ]);

    $translation->forceFill([
        'updated_at' => now()->subMinutes(20),
        'created_at' => now()->subMinutes(20),
    ])->saveQuietly();

    $this->actingAs($superadmin)
        ->get(route('admin.queues.index', ['focus_translations' => 1]))
        ->assertOk()
        ->assertSee('Translations')
        ->assertSee('Source content')
        ->assertSee((string) $translation->id)
        ->assertSee('Stale')
        ->assertSee('12345');
});

it('releases a translation lock from admin', function () {
    $superadmin = makePlatformAdmin('superadmin');
    [$content, $draft] = makeAdminTranslationContext();

    $translation = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_PROCESSING,
        'requested_by_user_id' => $superadmin->id,
        'job_id' => 'queue-job-12',
    ]);

    $this->actingAs($superadmin)
        ->post(route('admin.queues.translations.release-lock', $translation))
        ->assertRedirect(route('admin.queues.index', ['focus_translations' => 1]));

    $translation->refresh();

    expect($translation->status)->toBe(ContentTranslation::STATUS_FAILED)
        ->and($translation->job_id)->toBeNull()
        ->and($translation->error_message)->toBe('Stale lock cleared successfully.');
});

it('force resets a stale translation and retries it', function () {
    $superadmin = makePlatformAdmin('superadmin');
    [$content, $draft] = makeAdminTranslationContext();

    config()->set('translation.processing_lock_ttl_seconds', 60);
    Queue::fake();

    $translation = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_PROCESSING,
        'requested_by_user_id' => $superadmin->id,
        'job_id' => 'stuck-job-88',
    ]);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => encodeFailedTranslationJobPayload(new TranslateDraftJob(
            sourceDraftId: (string) $draft->id,
            targetLanguage: 'nl',
            userId: (string) $superadmin->id,
            translationRequestId: (string) $translation->id,
        ), attempts: 2),
        'exception' => 'RuntimeException: translation exploded',
        'failed_at' => now(),
    ]);

    $this->actingAs($superadmin)
        ->post(route('admin.queues.translations.force-reset-and-retry', $translation))
        ->assertRedirect(route('admin.queues.index', ['focus_translations' => 1]));

    $translation->refresh();

    expect($translation->status)->toBe(ContentTranslation::STATUS_QUEUED)
        ->and($translation->job_id)->toBeNull()
        ->and($translation->error_message)->toBeNull()
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    Queue::assertPushed(TranslateDraftJob::class, function (TranslateDraftJob $job) use ($translation, $draft): bool {
        return $job->sourceDraftId === (string) $draft->id
            && $job->targetLanguage === 'nl'
            && $job->translationRequestId === (string) $translation->id;
    });
});

it('retries an existing failed translation without creating a duplicate locale row', function () {
    $superadmin = makePlatformAdmin('superadmin');
    [$content, $draft] = makeAdminTranslationContext();

    Queue::fake();

    $translatedContent = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $content->workspace_id,
        'client_site_id' => $content->client_site_id,
        'title' => 'Dutch translation',
        'language' => 'nl',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'translation',
        'origin_type' => 'manual',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
        'family_id' => $content->id,
        'translation_source_content_id' => $content->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
    ]);

    $translation = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'target_content_id' => (string) $translatedContent->id,
        'status' => ContentTranslation::STATUS_FAILED,
        'requested_by_user_id' => $superadmin->id,
        'error_message' => 'Previous provider failure',
    ]);

    $this->actingAs($superadmin)
        ->post(route('admin.queues.translations.retry', $translation))
        ->assertRedirect(route('admin.queues.index', ['focus_translations' => 1]));

    expect(ContentTranslation::query()->where('content_id', (string) $content->id)->where('target_locale', 'nl')->count())->toBe(1);

    $translation->refresh();

    expect($translation->status)->toBe(ContentTranslation::STATUS_QUEUED)
        ->and($translation->target_content_id)->toBe((string) $translatedContent->id)
        ->and($translation->error_message)->toBeNull();

    Queue::assertPushed(TranslateDraftJob::class, function (TranslateDraftJob $job) use ($translation, $draft, $translatedContent): bool {
        return $job->sourceDraftId === (string) $draft->id
            && $job->targetLanguage === 'nl'
            && $job->translationRequestId === (string) $translation->id
            && $job->targetContentId === (string) $translatedContent->id;
    });
});

it('shows linked failed queue jobs for a translation', function () {
    $superadmin = makePlatformAdmin('superadmin');
    [$content, $draft] = makeAdminTranslationContext();

    $translation = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_FAILED,
        'requested_by_user_id' => $superadmin->id,
        'error_message' => 'Previous failure',
    ]);

    $failedJobId = DB::table('failed_jobs')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => encodeFailedTranslationJobPayload(new TranslateDraftJob(
            sourceDraftId: (string) $draft->id,
            targetLanguage: 'nl',
            userId: (string) $superadmin->id,
            translationRequestId: (string) $translation->id,
        ), attempts: 3),
        'exception' => 'RuntimeException: translation failed hard',
        'failed_at' => now(),
    ]);

    $this->actingAs($superadmin)
        ->get(route('admin.queues.index', ['focus_translations' => 1]))
        ->assertOk()
        ->assertSee('linked failed job(s)')
        ->assertSee((string) $failedJobId)
        ->assertSee('3');
});

it('shows insufficient credits diagnostics for a failed translation', function () {
    $superadmin = makePlatformAdmin('superadmin');
    [$content, $draft] = makeAdminTranslationContext();

    $translation = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_FAILED,
        'failure_reason' => ContentTranslation::FAILURE_REASON_INSUFFICIENT_CREDITS,
        'required_credits' => 6,
        'available_credits' => 0,
        'entitlement_source' => 'client_site_allocation',
        'requested_by_user_id' => $superadmin->id,
        'error_message' => 'Not enough credits to translate this article. Required: 6, available: 0.',
    ]);

    $this->actingAs($superadmin)
        ->get(route('admin.queues.index', ['focus_translations' => 1]))
        ->assertOk()
        ->assertSee('Not enough credits')
        ->assertSee('Required 6')
        ->assertSee('Available 0')
        ->assertSee('Entitlement client_site_allocation');
});

it('allows only superadmin to run translation recovery actions', function () {
    $admin = makePlatformAdmin('admin');
    $superadmin = makePlatformAdmin('superadmin');
    [$content, $draft] = makeAdminTranslationContext();

    $translation = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_PROCESSING,
        'requested_by_user_id' => $superadmin->id,
        'job_id' => 'guarded-job',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.queues.translations.release-lock', $translation))
        ->assertStatus(403);

    $translation->refresh();

    expect($translation->status)->toBe(ContentTranslation::STATUS_PROCESSING)
        ->and($translation->job_id)->toBe('guarded-job');
});

it('does not show recovery actions for completed translations', function () {
    $superadmin = makePlatformAdmin('superadmin');
    [$content] = makeAdminTranslationContext();

    ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_COMPLETED,
        'requested_by_user_id' => $superadmin->id,
    ]);

    $this->actingAs($superadmin)
        ->get(route('admin.queues.index', ['focus_translations' => 1]))
        ->assertOk()
        ->assertSee('Completed translations do not need recovery.')
        ->assertDontSee('Force reset + retry')
        ->assertDontSee('Release lock');
});

it('rejects retrying a completed translation from recovery actions', function () {
    $superadmin = makePlatformAdmin('superadmin');
    [$content] = makeAdminTranslationContext();

    $translation = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_COMPLETED,
        'requested_by_user_id' => $superadmin->id,
    ]);

    $this->actingAs($superadmin)
        ->from(route('admin.queues.index', ['focus_translations' => 1]))
        ->post(route('admin.queues.translations.retry', $translation))
        ->assertRedirect(route('admin.queues.index', ['focus_translations' => 1]))
        ->assertSessionHas('status', 'Completed translations cannot enter the recovery flow.');

    expect($translation->fresh()->status)->toBe(ContentTranslation::STATUS_COMPLETED);
});

it('does not release an active non-stale translation lock during repair', function () {
    $superadmin = makePlatformAdmin('superadmin');
    [$content, $draft] = makeAdminTranslationContext();

    config()->set('translation.processing_lock_ttl_seconds', 3600);

    $translation = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_PROCESSING,
        'requested_by_user_id' => $superadmin->id,
        'job_id' => 'active-job-1',
    ]);

    $this->actingAs($superadmin)
        ->post(route('admin.queues.translations.repair-stale-locks'), ['apply' => 1])
        ->assertRedirect(route('admin.queues.index', ['focus_translations' => 1]));

    expect($translation->fresh()->status)->toBe(ContentTranslation::STATUS_PROCESSING)
        ->and($translation->fresh()->job_id)->toBe('active-job-1');
});

it('uses the shared repair service from both command and admin actions', function () {
    $superadmin = makePlatformAdmin('superadmin');

    $mock = \Mockery::mock(TranslationLockRepairService::class);
    $mock->shouldReceive('findStaleTranslations')
        ->once()
        ->with(25, true)
        ->andReturn(collect());
    app()->instance(TranslationLockRepairService::class, $mock);

    Artisan::call('translation:repair-stale-locks', ['--limit' => 25]);

    $mock = \Mockery::mock(TranslationLockRepairService::class);
    $mock->shouldReceive('repair')
        ->once()
        ->with(250, true, true)
        ->andReturn([
            'found_count' => 1,
            'fixed_count' => 1,
            'retried_count' => 0,
            'rows' => collect(),
        ]);
    app()->instance(TranslationLockRepairService::class, $mock);

    $this->actingAs($superadmin)
        ->post(route('admin.queues.translations.repair-stale-locks'), ['apply' => 1])
        ->assertRedirect(route('admin.queues.index', ['focus_translations' => 1]));
});

function makePlatformAdmin(string $role): User
{
    $organization = Organization::query()->create([
        'name' => 'Platform Admin Org ' . Str::lower(Str::random(5)),
        'slug' => 'platform-admin-org-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    return User::query()->create([
        'name' => ucfirst($role) . ' Platform Admin',
        'email' => $role . '-platform-' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'is_admin' => true,
        'admin_role' => $role,
    ]);
}

function makeAdminTranslationContext(): array
{
    $user = User::query()->create([
        'name' => 'Translation Owner',
        'email' => 'translation-owner-' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $organization = Organization::query()->create([
        'name' => 'Translation Context Org',
        'slug' => 'translation-context-' . Str::lower(Str::random(8)),
        'status' => 'active',
        'approved_at' => now(),
        'primary_user_id' => $user->id,
    ]);

    $user->forceFill(['organization_id' => $organization->id])->save();

    $workspace = Workspace::query()->create([
        'name' => 'Translation Admin Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en', 'nl'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Translation Admin Site',
        'site_url' => 'https://translation-admin.example.com',
        'allowed_domains' => ['translation-admin.example.com'],
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

    $brief = Brief::query()->create([
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
        'primary_keyword' => 'source keyword',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Source draft',
        'language' => 'en',
        'draft_type' => 'original',
        'output_type' => 'kb_article',
        'content_html' => '<h1>Source draft</h1><p>Original text.</p>',
        'seo_title' => 'Source draft',
        'seo_meta_description' => 'Source description',
    ]);

    \App\Models\CreditWallet::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'workspace_id' => (string) $workspace->id,
        'balance_cached' => 25,
        'reserved_cached' => 0,
        'used_cached' => 0,
    ]);

    return [$content, $draft];
}

function encodeFailedTranslationJobPayload(TranslateDraftJob $job, int $attempts = 1): string
{
    return json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => TranslateDraftJob::class,
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
        'attempts' => $attempts,
        'data' => [
            'commandName' => TranslateDraftJob::class,
            'command' => serialize($job),
        ],
    ], JSON_UNESCAPED_SLASHES);
}
