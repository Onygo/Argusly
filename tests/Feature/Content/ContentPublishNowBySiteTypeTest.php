<?php

use App\Jobs\PublishToWordPressJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makePublishNowContext(string $siteType): array
{
    $organization = Organization::query()->create([
        'name' => 'Publish Now Org',
        'slug' => 'publish-now-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Publish Now Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Publish Now Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => $siteType,
        'name' => 'Publish Now Site',
        'site_url' => 'https://publish-now.example.com',
        'base_url' => 'https://publish-now.example.com',
        'allowed_domains' => ['publish-now.example.com'],
        'is_active' => true,
        'status' => 'connected',
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
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Immediate Publish Content',
        'primary_keyword' => 'immediate publish keyword',
        'type' => 'article',
        'status' => 'draft',
        'source' => $siteType,
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Brief for publish now',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready_to_deliver',
        'delivery_status' => 'pending',
        'title' => 'Draft for publish now',
        'seo_title' => 'Draft SEO title',
        'seo_meta_description' => 'Draft SEO description',
        'seo_canonical' => 'https://publish-now.example.com/blog/immediate-publish-content',
        'seo_og_image' => 'https://cdn.example.com/og.png',
        'output_type' => 'kb_article',
        'content_html' => '<h1>Hello</h1>',
    ]);

    return [$user, $site, $content, $draft];
}

it('queues wordpress publish job for wordpress sites', function () {
    [$user, , $content] = makePublishNowContext('wordpress');

    Bus::fake();

    $this->actingAs($user)
        ->post(route('app.content.publish-now', $content))
        ->assertRedirect();

    Bus::assertDispatched(PublishToWordPressJob::class, function (PublishToWordPressJob $job) use ($content): bool {
        return $job->contentId === (string) $content->id;
    });

    $content->refresh();
    expect($content->publish_status)->toBe('publishing');
});

it('publishes immediately for laravel sites without wordpress job', function () {
    [$user, $site, $content, $draft] = makePublishNowContext('laravel');

    Bus::fake();

    $this->actingAs($user)
        ->post(route('app.content.publish-now', $content))
        ->assertRedirect()
        ->assertSessionHas('status', 'Content marked as published locally. Laravel connector sync is pending.');

    Bus::assertNotDispatched(PublishToWordPressJob::class);

    $content->refresh();
    $draft->refresh();

    expect($content->publish_status)->toBe('published')
        ->and($content->status)->toBe('published')
        ->and($content->delivery_status)->toBe('delivered')
        ->and($content->publish_error)->toBeNull()
        ->and($content->scheduled_publish_at)->toBeNull()
        ->and((string) $content->published_url)->toContain((string) $site->site_url);

    expect($draft->status)->toBe('delivered')
        ->and($draft->delivery_status)->toBe('delivered')
        ->and($draft->acked_at)->not->toBeNull();

    $this->assertDatabaseHas('content_publish_targets', [
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'target_type' => 'laravel',
        'sync_status' => 'pending',
        'seo_sync_status' => 'pending',
        'seo_sync_mode' => 'pull',
    ]);

    $target = \App\Models\ContentPublishTarget::query()
        ->where('content_id', (string) $content->id)
        ->where('client_site_id', (string) $site->id)
        ->where('target_type', 'laravel')
        ->first();

    expect((string) data_get($target?->meta, 'meta_title'))->toBe('Draft SEO title');
    expect((string) data_get($target?->meta, 'meta_description'))->toBe('Draft SEO description');
    expect((string) data_get($target?->meta, 'canonical_url'))->toContain('/blog/immediate-publish-content');
    expect((string) data_get($target?->meta, 'og_image'))->toBe('https://cdn.example.com/og.png');
    expect((string) data_get($target?->meta, 'focus_keyword'))->toBe('immediate publish keyword');
    expect((string) data_get($target?->meta, 'publish_confirmation'))->toBe('local_only');
    expect((string) data_get($target?->meta, 'remote_sync_status'))->toBe('pending');
    expect((string) data_get($target?->meta, 'published_url_source'))->toBe('draft.seo_canonical');
    expect((bool) data_get($target?->meta, 'published_url_confirmed'))->toBeFalse();
});

it('normalizes legacy laravel blog urls before storing publish now URLs', function () {
    [$user, , $content, $draft] = makePublishNowContext('laravel');

    $content->forceFill([
        'published_url' => 'https://argusly.com/blog/immediate-publish-content',
    ])->save();

    $draft->forceFill([
        'seo_canonical' => null,
        'meta' => [],
    ])->save();

    $this->actingAs($user)
        ->post(route('app.content.publish-now', $content))
        ->assertRedirect();

    $content->refresh();

    expect((string) $content->published_url)->toBe('https://argusly.com/en/blog/immediate-publish-content');
});

it('localizes same-site laravel blog urls before storing publish now URLs', function () {
    [$user, , $content, $draft] = makePublishNowContext('laravel');

    $content->forceFill([
        'language' => 'nl',
        'published_url' => 'https://publish-now.example.com/blog/immediate-publish-content',
    ])->save();

    $draft->forceFill([
        'seo_canonical' => null,
        'meta' => [],
    ])->save();

    $this->actingAs($user)
        ->post(route('app.content.publish-now', $content))
        ->assertRedirect();

    expect((string) $content->fresh()->published_url)->toBe('https://publish-now.example.com/nl/blog/immediate-publish-content');
});

it('tracks guessed laravel published urls as local-only pending sync', function () {
    [$user, $site, $content, $draft] = makePublishNowContext('laravel');

    $content->update([
        'published_url' => null,
        'seo_canonical' => null,
    ]);

    $draft->update([
        'seo_canonical' => null,
        'meta' => [],
    ]);

    $this->actingAs($user)
        ->post(route('app.content.publish-now', $content))
        ->assertRedirect();

    $target = \App\Models\ContentPublishTarget::query()
        ->where('content_id', (string) $content->id)
        ->where('client_site_id', (string) $site->id)
        ->where('target_type', 'laravel')
        ->firstOrFail();

    expect((string) data_get($target->meta, 'published_url_source'))->toBe('site.slug_guess')
        ->and((string) data_get($target->meta, 'publish_confirmation'))->toBe('local_only')
        ->and((string) data_get($target->meta, 'remote_sync_status'))->toBe('pending')
        ->and((string) $content->fresh()->published_url)->toBe('https://publish-now.example.com/en/blog/immediate-publish-content');
});

it('tracks guessed laravel knowledge base urls with locale prefix', function () {
    [$user, $site, $content, $draft] = makePublishNowContext('laravel');

    $content->update([
        'type' => 'knowledge_base',
        'language' => 'nl',
        'published_url' => null,
        'seo_canonical' => null,
    ]);

    $draft->update([
        'seo_canonical' => null,
        'meta' => [],
    ]);

    $this->actingAs($user)
        ->post(route('app.content.publish-now', $content))
        ->assertRedirect();

    $target = \App\Models\ContentPublishTarget::query()
        ->where('content_id', (string) $content->id)
        ->where('client_site_id', (string) $site->id)
        ->where('target_type', 'laravel')
        ->firstOrFail();

    expect((string) data_get($target->meta, 'published_url_source'))->toBe('site.slug_guess')
        ->and((string) $content->fresh()->published_url)->toBe('https://publish-now.example.com/nl/kennisbank/immediate-publish-content');
});
