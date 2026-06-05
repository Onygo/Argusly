<?php

use App\Jobs\TranslateDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('reports duplicate queued translation jobs in dry run mode', function () {
    [$content, $draft, $user] = makeQueuedDuplicateTranslationContext();

    DB::table('jobs')->insert([
        [
            'queue' => 'default',
            'payload' => encodeQueuedDuplicateJobPayload(new TranslateDraftJob(
                sourceDraftId: (string) $draft->id,
                targetLanguage: 'nl',
                userId: (string) $user->id,
                translationRequestId: (string) Str::uuid(),
                sourceContentId: (string) $content->id,
            )),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->subMinute()->timestamp,
        ],
        [
            'queue' => 'default',
            'payload' => encodeQueuedDuplicateJobPayload(new TranslateDraftJob(
                sourceDraftId: (string) $draft->id,
                targetLanguage: 'nl',
                userId: (string) $user->id,
                translationRequestId: (string) Str::uuid(),
                sourceContentId: (string) $content->id,
            )),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ],
    ]);

    Artisan::call('content:translation-clear-queued-duplicates', [
        'contentId' => (string) $content->id,
        'locale' => 'nl',
        '--dry-run' => true,
    ]);

    expect(Artisan::output())->toContain('Dry run')
        ->and(DB::table('jobs')->count())->toBe(2);
});

it('removes older duplicate queued translation jobs in force mode', function () {
    [$content, $draft, $user] = makeQueuedDuplicateTranslationContext();

    DB::table('jobs')->insert([
        [
            'queue' => 'default',
            'payload' => encodeQueuedDuplicateJobPayload(new TranslateDraftJob(
                sourceDraftId: (string) $draft->id,
                targetLanguage: 'nl',
                userId: (string) $user->id,
                translationRequestId: (string) Str::uuid(),
                sourceContentId: (string) $content->id,
            )),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->subMinute()->timestamp,
        ],
        [
            'queue' => 'default',
            'payload' => encodeQueuedDuplicateJobPayload(new TranslateDraftJob(
                sourceDraftId: (string) $draft->id,
                targetLanguage: 'nl',
                userId: (string) $user->id,
                translationRequestId: (string) Str::uuid(),
                sourceContentId: (string) $content->id,
            )),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ],
    ]);

    Artisan::call('content:translation-clear-queued-duplicates', [
        'contentId' => (string) $content->id,
        'locale' => 'nl',
        '--force' => true,
    ]);

    expect(Artisan::output())->toContain('Removed older queued jobs')
        ->and(DB::table('jobs')->count())->toBe(1);
});

function makeQueuedDuplicateTranslationContext(): array
{
    $user = User::query()->create([
        'name' => 'Duplicate Queue User',
        'email' => 'duplicate-queue-' . Str::random(8) . '@example.com',
        'password' => bcrypt('password'),
    ]);

    $organization = Organization::query()->create([
        'name' => 'Duplicate Queue Org',
        'slug' => 'duplicate-queue-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'primary_user_id' => $user->id,
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Duplicate Queue Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en', 'nl'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Duplicate Queue Site',
        'site_url' => 'https://duplicate-queue.example.com',
        'allowed_domains' => ['duplicate-queue.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Duplicate Queue Source',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'origin_type' => 'manual',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
        'is_source_locale' => true,
    ]);

    Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Duplicate Queue Brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $draft = $content->drafts()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) Brief::query()->where('content_id', $content->id)->value('id'),
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Duplicate Queue Draft',
        'language' => 'en',
        'draft_type' => 'original',
        'output_type' => 'kb_article',
        'content_html' => '<p>Source content.</p>',
    ]);

    return [$content->fresh(), $draft->fresh(), $user];
}

function encodeQueuedDuplicateJobPayload(TranslateDraftJob $job): string
{
    return json_encode([
        'uuid' => (string) Str::uuid(),
        'displayName' => TranslateDraftJob::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'attempts' => 0,
        'data' => [
            'commandName' => TranslateDraftJob::class,
            'command' => serialize($job),
        ],
    ], JSON_UNESCAPED_SLASHES);
}
