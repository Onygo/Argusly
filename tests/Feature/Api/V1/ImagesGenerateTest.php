<?php

use App\Jobs\GenerateContentFeaturedImageJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\SiteToken;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('resolves images generate route and requires auth', function () {
    $this->postJson('/api/v1/images/generate', [
        'draft_id' => (string) Str::uuid(),
    ])->assertStatus(401);
});

it('queues featured image generation for a tenant-scoped draft', function () {
    Queue::fake();

    $organization = Organization::create([
        'name' => 'Image Org',
        'slug' => 'image-org',
        'status' => 'active',
    ]);

    $workspace = Workspace::create([
        'name' => 'Image Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Image Site',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
    ]);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Image Draft Content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'api',
        'external_key' => (string) Str::uuid(),
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Image Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Image Draft',
        'output_type' => 'kb_article',
    ]);

    $plain = 'pl_site_' . Str::random(48);
    SiteToken::create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['drafts:write'],
        'revoked' => false,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-Argusly-Site' => 'example.com',
    ])->postJson('/api/v1/images/generate', [
        'draft_id' => (string) $draft->id,
    ]);

    $response->assertStatus(202)
        ->assertJsonStructure([
            'ok',
            'image_id',
            'status',
            'draft_id',
            'content_id',
        ]);

    Queue::assertPushed(GenerateContentFeaturedImageJob::class);

    $this->assertDatabaseHas('content_images', [
        'content_id' => (string) $content->id,
        'type' => 'featured',
        'status' => 'queued',
    ]);
});

it('falls back to content id when draft id no longer resolves', function () {
    Queue::fake();

    $organization = Organization::create([
        'name' => 'Image Fallback Org',
        'slug' => 'image-fallback-org',
        'status' => 'active',
    ]);

    $workspace = Workspace::create([
        'name' => 'Image Fallback Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Image Fallback Site',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
    ]);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Image Fallback Content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'api',
        'external_key' => (string) Str::uuid(),
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Image Fallback Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Image Fallback Draft',
        'output_type' => 'kb_article',
    ]);

    $plain = 'pl_site_' . Str::random(48);
    SiteToken::create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['drafts:write'],
        'revoked' => false,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-Argusly-Site' => 'example.com',
    ])->postJson('/api/v1/images/generate', [
        'draft_id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('ok', true)
        ->assertJsonPath('content_id', (string) $content->id);

    Queue::assertPushed(GenerateContentFeaturedImageJob::class);
});

it('releases stale image generation lock and requeues generation', function () {
    Queue::fake();

    $organization = Organization::create([
        'name' => 'Image Stale Org',
        'slug' => 'image-stale-org',
        'status' => 'active',
    ]);

    $workspace = Workspace::create([
        'name' => 'Image Stale Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Image Stale Site',
        'site_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
    ]);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 50,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Image Stale Content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'api',
        'external_key' => (string) Str::uuid(),
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Image Stale Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => 'Image Stale Draft',
        'output_type' => 'kb_article',
    ]);

    $stale = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'status' => 'generating',
        'prompt' => 'stale',
    ]);
    ContentImage::query()
        ->whereKey($stale->id)
        ->update(['updated_at' => Carbon::now()->subMinutes(30)]);

    $plain = 'pl_site_' . Str::random(48);
    SiteToken::create([
        'client_site_id' => $site->id,
        'token_hash' => hash('sha256', $plain),
        'scopes' => ['drafts:write'],
        'revoked' => false,
    ]);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $plain,
        'X-Argusly-Site' => 'example.com',
    ])->postJson('/api/v1/images/generate', [
        'draft_id' => (string) $draft->id,
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('ok', true);

    $this->assertDatabaseHas('content_images', [
        'id' => (string) $stale->id,
        'status' => 'failed',
    ]);

    Queue::assertPushed(GenerateContentFeaturedImageJob::class);
});
