<?php

use App\Jobs\PublishToWordPressJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentPublishTarget;
use App\Models\ContentSeries;
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

$makeSeriesPublishContext = function (string $siteType): array {
    $organization = Organization::query()->create([
        'name' => 'Series Publish Org',
        'slug' => 'series-publish-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Series Publish Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series Publish Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => $siteType,
        'name' => 'Series Publish Site',
        'site_url' => 'https://series-publish.example.com',
        'base_url' => 'https://series-publish.example.com',
        'allowed_domains' => ['series-publish.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'series-publish-plan-' . Str::random(6),
        'name' => 'Series Publish Plan',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 5,
        'limits' => ['users' => 5],
        'is_active' => true,
    ]);

    $subscription = Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 5,
        'status' => 'active',
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addMonth(),
    ]);

    $organization->update(['active_subscription_id' => $subscription->id]);

    $user = User::query()->create([
        'name' => 'Series Publish Owner',
        'email' => 'series-publish-owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $series = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'Series Publish',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance workflow',
        'supporting_keywords' => ['governance policy'],
        'articles_count' => 1,
        'status' => 'ready',
        'created_by' => $user->id,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'series_id' => $series->id,
        'title' => 'Series publish content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Series publish brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'delivery_status' => 'pending',
        'title' => 'Series publish draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Draft body</p>',
    ]);

    return [$user, $series, $content, $site];
};

it('queues wordpress publishing for series articles on wordpress sites', function () use ($makeSeriesPublishContext) {
    [$user, $series, $content] = $makeSeriesPublishContext('wordpress');

    Bus::fake();

    $this->actingAs($user)
        ->post(route('app.content.series.publish', $series))
        ->assertRedirect();

    Bus::assertDispatched(PublishToWordPressJob::class, function (PublishToWordPressJob $job) use ($content): bool {
        return (string) $job->contentId === (string) $content->id
            && filled($job->publicationId);
    });

    expect((string) $series->fresh()->status)->toBe('scheduled');
    expect((string) $content->fresh()->publish_status)->toBe('publishing');

    $publication = ContentPublication::query()
        ->where('content_id', (string) $content->id)
        ->where('provider', ContentPublication::PROVIDER_WORDPRESS)
        ->first();

    expect($publication)->not->toBeNull();
});

it('publishes the requested wordpress locale variant without rescheduling the source', function () use ($makeSeriesPublishContext) {
    [$user, $series, $content, $site] = $makeSeriesPublishContext('wordpress');

    $content->forceFill([
        'family_id' => (string) $content->id,
        'language' => 'en',
        'is_source_locale' => true,
        'status' => 'published',
        'publish_status' => 'published',
        'scheduled_publish_at' => null,
    ])->save();

    $translatedContent = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $content->workspace_id,
        'client_site_id' => $site->id,
        'series_id' => $series->id,
        'title' => 'Serie publicatie content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'language' => 'nl',
        'family_id' => (string) $content->id,
        'translation_source_content_id' => (string) $content->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $translatedContent->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Serie publicatie brief',
        'language' => 'nl',
        'output_type' => 'kb_article',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $translatedContent->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'delivery_status' => 'pending',
        'title' => 'Serie publicatie draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Vertaalde tekst</p>',
    ]);

    Bus::fake();

    $this->actingAs($user)
        ->post(route('app.content.publish-now', $content), ['locale' => 'nl'])
        ->assertRedirect()
        ->assertSessionHas('status');

    Bus::assertDispatched(PublishToWordPressJob::class, function (PublishToWordPressJob $job) use ($translatedContent): bool {
        return (string) $job->contentId === (string) $translatedContent->id;
    });

    Bus::assertNotDispatched(PublishToWordPressJob::class, function (PublishToWordPressJob $job) use ($content): bool {
        return (string) $job->contentId === (string) $content->id;
    });

    $content->refresh();
    $translatedContent->refresh();

    expect((string) $content->publish_status)->toBe('published')
        ->and($content->scheduled_publish_at)->toBeNull()
        ->and((string) $translatedContent->publish_status)->toBe('publishing')
        ->and($translatedContent->scheduled_publish_at)->toBeNull();
});

it('publishes series articles immediately for laravel sites', function () use ($makeSeriesPublishContext) {
    [$user, $series, $content, $site] = $makeSeriesPublishContext('laravel');

    Bus::fake();

    $this->actingAs($user)
        ->post(route('app.content.series.publish', $series))
        ->assertRedirect();

    Bus::assertNotDispatched(PublishToWordPressJob::class);

    $content->refresh();
    $series->refresh();

    expect((string) $content->publish_status)->toBe('published')
        ->and((string) $content->status)->toBe('published')
        ->and((string) $content->delivery_status)->toBe('delivered')
        ->and((string) $content->published_url)->toContain((string) $site->site_url);

    expect((string) $series->status)->toBe('published');

    $target = ContentPublishTarget::query()
        ->where('content_id', (string) $content->id)
        ->where('target_type', 'laravel')
        ->first();

    expect($target)->not->toBeNull()
        ->and((string) $target?->sync_status)->toBe('pending')
        ->and((string) $target?->seo_sync_status)->toBe('pending')
        ->and((string) $target?->seo_sync_mode)->toBe('pull')
        ->and((string) data_get($target?->meta, 'publish_confirmation'))->toBe('local_only')
        ->and((string) data_get($target?->meta, 'remote_sync_status'))->toBe('pending');
});

it('publishes remaining translated variants for locked published series', function () use ($makeSeriesPublishContext) {
    [$user, $series, $content, $site] = $makeSeriesPublishContext('laravel');

    $content->forceFill([
        'family_id' => (string) $content->id,
        'language' => 'en',
        'is_source_locale' => true,
        'status' => 'published',
        'publish_status' => 'published',
    ])->save();

    $translatedContent = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $content->workspace_id,
        'client_site_id' => $site->id,
        'title' => 'Serie publicatie content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'language' => 'nl',
        'family_id' => (string) $content->id,
        'translation_source_content_id' => (string) $content->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $translatedContent->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Serie publicatie brief',
        'language' => 'nl',
        'output_type' => 'kb_article',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $translatedContent->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'delivery_status' => 'pending',
        'title' => 'Serie publicatie draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Vertaalde tekst</p>',
    ]);

    $series->forceFill([
        'status' => ContentSeries::STATUS_PUBLISHED,
        'is_locked' => true,
    ])->save();

    $this->actingAs($user)
        ->post(route('app.content.series.publish', $series))
        ->assertRedirect()
        ->assertSessionHas('status');

    $content->refresh();
    $translatedContent->refresh();
    $series->refresh();

    expect((string) $content->publish_status)->toBe('published')
        ->and((string) $translatedContent->publish_status)->toBe('published')
        ->and((string) $translatedContent->status)->toBe('published')
        ->and((string) $series->status)->toBe(ContentSeries::STATUS_PUBLISHED)
        ->and((bool) $series->is_locked)->toBeTrue();
});
