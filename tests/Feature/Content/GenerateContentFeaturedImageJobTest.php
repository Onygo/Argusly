<?php

use App\Jobs\GenerateContentFeaturedImageJob;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\CreditLedgerEntry;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('stores generated featured image and debits credits via wallet', function () {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('GD extension is not available.');
    }

    Storage::fake('content_images');

    $organization = Organization::query()->create([
        'name' => 'Image Job Org',
        'slug' => 'image-job-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Image Job Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Image Job Site',
        'site_url' => 'https://image-job.example.com',
        'allowed_domains' => ['image-job.example.com'],
        'is_active' => true,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Image Job Content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
    ]);

    User::query()->create([
        'name' => 'Image Job User',
        'email' => 'image-job-user-'.Str::random(6).'@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 15,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'test_job_image_funding']
    );

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'prompt' => 'Generate a clean modern hero image.',
        'status' => 'queued',
        'credit_cost' => 5,
    ]);

    config([
        'argusly.images.disk' => 'content_images',
        'argusly.ai.images.openai.api_key' => 'test-key',
    ]);

    Http::fake([
        '*/images/generations' => Http::response([
            'data' => [
                ['b64_json' => base64_encode(makeFeaturedTestPng())],
            ],
        ], 200),
    ]);

    Bus::dispatchSync(new GenerateContentFeaturedImageJob((string) $image->id));

    $image->refresh();

    expect($image->status)->toBe('ready')
        ->and($image->provider)->toBe('openai')
        ->and($image->image_path)->not->toBeNull()
        ->and($image->image_url)->toContain('/content-images/')
        ->and($image->image_url)->not->toContain('/storage/content-images/')
        ->and($image->original_path)->toBe((string) $image->image_path)
        ->and($image->medium_path)->not->toBeNull()
        ->and($image->thumbnail_path)->not->toBeNull();

    Storage::disk('content_images')->assertExists((string) $image->image_path);
    Storage::disk('content_images')->assertExists((string) $image->medium_path);
    Storage::disk('content_images')->assertExists((string) $image->thumbnail_path);
    if (function_exists('imagewebp')) {
        expect($image->medium_webp_path)->not->toBeNull()
            ->and($image->thumbnail_webp_path)->not->toBeNull();
        Storage::disk('content_images')->assertExists((string) $image->medium_webp_path);
        Storage::disk('content_images')->assertExists((string) $image->thumbnail_webp_path);
    }

    $walletSummary = app(CreditWalletService::class)->getSummary((string) $site->id);
    expect((int) $walletSummary['available'])->toBe(10);
});

it('does not send response_format for gpt image models', function () {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('GD extension is not available.');
    }

    Storage::fake('content_images');

    $organization = Organization::query()->create([
        'name' => 'Image Job Org',
        'slug' => 'image-job-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Image Job Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Image Job Site',
        'site_url' => 'https://image-job.example.com',
        'allowed_domains' => ['image-job.example.com'],
        'is_active' => true,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Image Job Content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
    ]);

    User::query()->create([
        'name' => 'Image Job User',
        'email' => 'image-job-user-'.Str::random(6).'@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 15,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'test_job_image_funding']
    );

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'prompt' => 'Generate a clean modern hero image.',
        'status' => 'queued',
        'credit_cost' => 5,
    ]);

    config([
        'argusly.ai.images.storage_disk' => 'public',
        'argusly.ai.images.openai.api_key' => 'test-key',
        'argusly.ai.images.openai.model' => 'gpt-image-1',
    ]);

    Http::fake([
        '*/images/generations' => Http::response([
            'data' => [
                ['b64_json' => base64_encode(makeFeaturedTestPng())],
            ],
        ], 200),
    ]);

    Bus::dispatchSync(new GenerateContentFeaturedImageJob((string) $image->id));

    Http::assertSent(function (Request $request) {
        if (! str_ends_with($request->url(), '/images/generations')) {
            return false;
        }

        $payload = $request->data();

        return ! array_key_exists('response_format', $payload);
    });
});

it('stores generated featured image with gemini provider', function () {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('GD extension is not available.');
    }

    Storage::fake('content_images');

    $organization = Organization::query()->create([
        'name' => 'Gemini Image Org',
        'slug' => 'gemini-image-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Gemini Image Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Gemini Image Site',
        'site_url' => 'https://gemini-image.example.com',
        'allowed_domains' => ['gemini-image.example.com'],
        'is_active' => true,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Gemini Image Content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
    ]);

    User::query()->create([
        'name' => 'Gemini Image User',
        'email' => 'gemini-image-user-'.Str::random(6).'@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 15,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'test_job_image_funding_gemini']
    );

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'prompt' => 'Generate a clean modern hero image.',
        'status' => 'queued',
        'credit_cost' => 5,
    ]);

    config([
        'llm.default_provider' => 'openai',
        'argusly.ai.images.storage_disk' => 'public',
        'argusly.ai.images.gemini.api_key' => 'gemini-test-key',
        'argusly.ai.images.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        'argusly.ai.images.gemini.model' => 'gemini-2.5-flash-image-preview',
    ]);

    \App\Models\LlmGlobalSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_text_provider' => 'openai',
            'default_image_provider' => 'gemini',
            'default_text_model_map' => [
                'openai' => 'gpt-4.1-mini',
                'anthropic' => 'claude-3-5-sonnet-latest',
                'gemini' => 'gemini-2.0-flash',
            ],
            'default_image_model_map' => [
                'openai' => 'gpt-image-1',
                'anthropic' => '',
                'gemini' => 'gemini-2.5-flash-image-preview',
            ],
            'timeout_seconds' => 180,
            'retry_max' => 2,
            'retry_backoff_ms' => 800,
        ]
    );

    Http::fake([
        'https://generativelanguage.googleapis.com/v1beta/models/*:generateContent*' => Http::response([
            'responseId' => 'resp_gemini_image',
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'inlineData' => [
                                    'mimeType' => 'image/png',
                                    'data' => base64_encode(makeFeaturedTestPng()),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    Bus::dispatchSync(new GenerateContentFeaturedImageJob((string) $image->id));

    $image->refresh();

    expect($image->status)->toBe('ready')
        ->and($image->provider)->toBe('gemini')
        ->and($image->image_path)->not->toBeNull()
        ->and($image->image_url)->toContain('/content-images/')
        ->and($image->image_url)->not->toContain('/storage/content-images/')
        ->and($image->medium_path)->not->toBeNull()
        ->and($image->thumbnail_path)->not->toBeNull();

    Storage::disk('content_images')->assertExists((string) $image->image_path);
    Storage::disk('content_images')->assertExists((string) $image->medium_path);
    Storage::disk('content_images')->assertExists((string) $image->thumbnail_path);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), ':generateContent')
            && str_contains($request->url(), '/models/gemini-2.5-flash-image:generateContent')
            && str_contains($request->url(), 'key=gemini-test-key');
    });
});

it('keeps job successful when webp encoding is disabled', function () {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('GD extension is not available.');
    }

    Storage::fake('content_images');

    $organization = Organization::query()->create([
        'name' => 'Image Job Org',
        'slug' => 'image-job-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Image Job Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Image Job Site',
        'site_url' => 'https://image-job.example.com',
        'allowed_domains' => ['image-job.example.com'],
        'is_active' => true,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Image Job Content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
    ]);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 15,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'test_job_image_funding']
    );

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'prompt' => 'Generate a clean modern hero image.',
        'status' => 'queued',
        'credit_cost' => 5,
    ]);

    config([
        'argusly.ai.images.storage_disk' => 'public',
        'argusly.ai.images.openai.api_key' => 'test-key',
        'argusly.ai.images.webp.enabled' => false,
    ]);

    Http::fake([
        '*/images/generations' => Http::response([
            'data' => [
                ['b64_json' => base64_encode(makeFeaturedTestPng())],
            ],
        ], 200),
    ]);

    Bus::dispatchSync(new GenerateContentFeaturedImageJob((string) $image->id));
    $image->refresh();

    expect($image->status)->toBe('ready')
        ->and($image->medium_webp_path)->toBeNull()
        ->and($image->thumbnail_webp_path)->toBeNull();
});

it('creates unique file paths across regenerated image versions without overwriting', function () {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('GD extension is not available.');
    }

    Storage::fake('content_images');

    $organization = Organization::query()->create([
        'name' => 'Image Job Org',
        'slug' => 'image-job-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Image Job Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Image Job Site',
        'site_url' => 'https://image-job.example.com',
        'allowed_domains' => ['image-job.example.com'],
        'is_active' => true,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Image Job Content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
    ]);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 30,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'test_job_image_funding']
    );

    config([
        'argusly.ai.images.storage_disk' => 'public',
        'argusly.ai.images.openai.api_key' => 'test-key',
    ]);

    Http::fake([
        '*/images/generations' => Http::response([
            'data' => [
                ['b64_json' => base64_encode(makeFeaturedTestPng())],
            ],
        ], 200),
    ]);

    $first = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'prompt' => 'Generate first image.',
        'status' => 'queued',
        'credit_cost' => 5,
    ]);
    Bus::dispatchSync(new GenerateContentFeaturedImageJob((string) $first->id));
    $first->refresh();

    $second = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'prompt' => 'Generate second image.',
        'status' => 'queued',
        'credit_cost' => 5,
    ]);
    Bus::dispatchSync(new GenerateContentFeaturedImageJob((string) $second->id));
    $second->refresh();

    expect($first->image_path)->not->toBe($second->image_path)
        ->and($first->medium_path)->not->toBe($second->medium_path)
        ->and($first->thumbnail_path)->not->toBe($second->thumbnail_path);

    Storage::disk('content_images')->assertExists((string) $first->image_path);
    Storage::disk('content_images')->assertExists((string) $second->image_path);
    Storage::disk('content_images')->assertExists((string) $first->medium_path);
    Storage::disk('content_images')->assertExists((string) $second->medium_path);
});

it('releases reserved credits when featured image generation fails', function () {
    Storage::fake('content_images');

    $organization = Organization::query()->create([
        'name' => 'Image Fail Org',
        'slug' => 'image-fail-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Image Fail Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Image Fail Site',
        'site_url' => 'https://image-fail.example.com',
        'allowed_domains' => ['image-fail.example.com'],
        'is_active' => true,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Image Fail Content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
    ]);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 12,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'test_job_image_failure_funding']
    );

    $image = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => 'featured',
        'prompt' => 'Fail image generation.',
        'status' => 'queued',
        'credit_cost' => 6,
    ]);

    config([
        'argusly.ai.images.storage_disk' => 'public',
        'argusly.ai.images.openai.api_key' => 'test-key',
    ]);

    Http::fake([
        '*/images/generations' => Http::response(['error' => ['message' => 'provider down']], 500),
    ]);

    Bus::dispatchSync(new GenerateContentFeaturedImageJob((string) $image->id));
    $image->refresh();

    $walletSummary = app(CreditWalletService::class)->getSummary((string) $site->id);

    expect($image->status)->toBe('failed')
        ->and($image->credit_status)->toBe('released')
        ->and((int) $walletSummary['balance_cached'])->toBe(12)
        ->and((int) $walletSummary['reserved_cached'])->toBe(0)
        ->and((int) $walletSummary['available'])->toBe(12);

    expect(CreditLedgerEntry::query()
        ->where('source_type', ContentImage::class)
        ->where('source_id', $image->id)
        ->where('type', CreditWalletService::TYPE_RESERVATION)
        ->count())->toBe(1);

    expect(CreditLedgerEntry::query()
        ->where('source_type', ContentImage::class)
        ->where('source_id', $image->id)
        ->where('type', CreditWalletService::TYPE_RELEASE)
        ->count())->toBe(1);

    expect(CreditLedgerEntry::query()
        ->where('source_type', ContentImage::class)
        ->where('source_id', $image->id)
        ->where('type', CreditWalletService::TYPE_USAGE)
        ->count())->toBe(0);
});

function makeFeaturedTestPng(): string
{
    $img = imagecreatetruecolor(1280, 960);
    $color = imagecolorallocate($img, 60, 80, 120);
    imagefilledrectangle($img, 0, 0, 1280, 960, $color);

    ob_start();
    imagepng($img);
    $binary = (string) ob_get_clean();
    imagedestroy($img);

    return $binary;
}
