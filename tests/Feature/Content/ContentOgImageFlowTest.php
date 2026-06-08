<?php

use App\Jobs\GenerateContentOgImageJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentImage;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\ImageGenerationService;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates og image record and dispatches job from content images tab action', function () {
    Queue::fake();

    [$user, $content, $site] = createOgContext();
    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 12,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'test_og_funding']
    );

    $this->actingAs($user)
        ->post(route('app.content.images.og.generate', $content))
        ->assertRedirect();

    $og = ContentImage::query()
        ->where('content_id', $content->id)
        ->where('type', ImageGenerationService::OG_TYPE)
        ->latest('created_at')
        ->first();

    expect($og)->not->toBeNull()
        ->and($og->status)->toBe('queued');

    Queue::assertPushed(GenerateContentOgImageJob::class, function (GenerateContentOgImageJob $job) use ($og) {
        return (string) $job->contentImageId === (string) $og->id;
    });
});

it('blocks og generation without enough credits when featured background is missing', function () {
    [$user, $content] = createOgContext();

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->post(route('app.content.images.og.generate', $content))
        ->assertRedirect(route('app.content.show', ['content' => $content, 'tab' => 'images']))
        ->assertSessionHasErrors(['image_generate']);
});

it('creates featured fallback and renders ready og image when no featured exists', function () {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('GD extension is not available.');
    }

    Storage::fake('public');
    config([
        'argusly.ai.images.storage_disk' => 'public',
        'argusly.ai.images.openai.api_key' => 'test-key',
    ]);

    [$user, $content, $site] = createOgContext();
    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 25,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'test_og_with_fallback']
    );

    $pngBinary = makePngBinary(1600, 1000, [58, 90, 130]);
    Http::fake([
        '*/images/generations' => Http::response([
            'data' => [
                ['b64_json' => base64_encode($pngBinary)],
            ],
        ], 200),
    ]);

    $og = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'og',
        'status' => 'queued',
        'provider' => 'pl-renderer',
        'credit_cost' => 0,
    ]);

    Bus::dispatchSync(new GenerateContentOgImageJob((string) $og->id));

    $og->refresh();
    expect($og->status)->toBe('ready')
        ->and($og->image_path)->not->toBeNull();

    $featured = ContentImage::query()
        ->where('content_id', $content->id)
        ->where('type', 'featured')
        ->latest('created_at')
        ->first();

    expect($featured)->not->toBeNull()
        ->and($featured->status)->toBe('ready');
});

it('pushes og image to wordpress webhook using existing signed connector flow', function () {
    [$user, $content] = createOgContext(withDraftConnectorRefs: true);

    $og = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'og',
        'status' => 'ready',
        'is_active' => true,
        'provider' => 'pl-renderer',
        'image_path' => 'content-images/test-og.png',
        'image_url' => 'https://cdn.example.test/content-images/test-og.png',
        'credit_cost' => 0,
    ]);

    Http::fake([
        'https://wp.example.com/pl-webhook' => Http::response(['ok' => true], 200),
    ]);

    $this->actingAs($user)
        ->post(route('app.content.images.og.push', $content))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($content, $og) {
        if ($request->url() !== 'https://wp.example.com/pl-webhook') {
            return false;
        }

        $payload = json_decode((string) $request->body(), true);

        return data_get($payload, 'event') === 'content.og_image'
            && data_get($payload, 'content_id') === (string) $content->id
            && data_get($payload, 'og_image_url') === (string) $og->image_url;
    });
});

it('allows og image push action for laravel site type when connector is configured', function () {
    [$user, $content, $site] = createOgContext(withDraftConnectorRefs: true);
    $site->update(['type' => 'laravel']);

    $og = ContentImage::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'og',
        'status' => 'ready',
        'is_active' => true,
        'provider' => 'pl-renderer',
        'image_path' => 'content-images/test-og-laravel.png',
        'image_url' => 'https://cdn.example.test/content-images/test-og-laravel.png',
        'credit_cost' => 0,
    ]);

    Http::fake([
        'https://wp.example.com/pl-webhook' => Http::response(['ok' => true], 200),
    ]);

    $this->actingAs($user)
        ->post(route('app.content.images.og.push', $content))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Http::assertSent(function (\Illuminate\Http\Client\Request $request) use ($content, $og) {
        if ($request->url() !== 'https://wp.example.com/pl-webhook') {
            return false;
        }

        $payload = json_decode((string) $request->body(), true);

        return data_get($payload, 'event') === 'content.og_image'
            && data_get($payload, 'content_id') === (string) $content->id
            && data_get($payload, 'og_image_url') === (string) $og->image_url;
    });
});

/**
 * @return array{0:User,1:Content,2:ClientSite}
 */
function createOgContext(bool $withDraftConnectorRefs = false): array
{
    $organization = Organization::query()->create([
        'name' => 'OG Feature Org',
        'slug' => 'og-feature-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'OG Feature Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_postal_code' => '1000AA',
        'billing_city' => 'Amsterdam',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'OG Feature Workspace',
        'organization_id' => $organization->id,
        'visual_settings' => [
            'og_theme' => 'dark',
            'og_accent_hex' => '#dcf365',
            'og_bg_overlay' => 'gradient',
            'og_font' => 'inter',
        ],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'OG Site',
        'site_url' => 'https://og.example.com',
        'allowed_domains' => ['og.example.com'],
        'is_active' => true,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'test-plan'],
        [
            'name' => 'Test Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Scaling AI Content With Governance',
        'primary_keyword' => 'ai content governance',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
        'wp_post_id' => '987',
    ]);

    $draftMeta = [];
    if ($withDraftConnectorRefs) {
        $draftMeta['client_refs'] = [
            'draft_webhook_url' => 'https://wp.example.com/pl-webhook',
            'draft_webhook_secret' => 'supersecret',
            'wp_post_id' => '987',
        ];
    }

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $content->id,
        'status' => 'queued',
        'progress' => 0,
        'title' => 'OG Brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'status' => 'ready',
        'title' => 'Draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Draft</p>',
        'meta' => $draftMeta,
        'links' => [],
        'credit_cost' => 6,
    ]);

    $user = User::query()->create([
        'name' => 'OG Owner',
        'email' => 'og-owner-'.Str::random(6).'@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    return [$user, $content, $site];
}

function makePngBinary(int $width, int $height, array $rgb): string
{
    $img = imagecreatetruecolor($width, $height);
    $color = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
    imagefilledrectangle($img, 0, 0, $width, $height, $color);

    ob_start();
    imagepng($img);
    $binary = (string) ob_get_clean();
    imagedestroy($img);

    return $binary;
}
