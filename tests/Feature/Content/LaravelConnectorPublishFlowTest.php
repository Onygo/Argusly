<?php

use App\Events\Agents\ContentPublished;
use App\Enums\SupportedLanguage;
use App\Jobs\PublishContentJob;
use App\Jobs\Integrations\SyncLaravelKnowledgeArticleJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentPublication;
use App\Models\ContentPublishTarget;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Integrations\LaravelConnectorPublishingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeLaravelConnectorPublishContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Laravel Publish Org',
        'slug' => 'laravel-publish-org-'.Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Laravel Publish Org BV',
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
        'name' => 'Laravel Publish Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Laravel Publish Site',
        'site_url' => 'https://publish-now.example.com',
        'base_url' => 'https://publish-now.example.com',
        'allowed_domains' => ['publish-now.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'laravel-publish-plan'],
        [
            'name' => 'Laravel Publish Plan',
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
        'source' => 'laravel',
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

function createLaravelConnectorDestinationForSite(ClientSite $site): ContentDestination
{
    return ContentDestination::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $site->workspace_id,
        'name' => 'Laravel Connector Destination',
        'type' => 'laravel',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
        'config' => [
            'billing_client_site_id' => (string) $site->id,
            'laravel_connector' => [
                'base_url' => 'https://publish-now.example.com',
                'site_id' => 'publish-now-site',
                'sync_endpoint' => '/publishlayer/sync',
                'api_key_encrypted' => Crypt::encryptString('publish-now-secret'),
                'enabled' => true,
                'mode' => 'hosted_views',
            ],
        ],
    ]);
}

it('queues the generic publish job when publishing now for a laravel destination', function () {
    [$user, $site, $content, $draft] = makeLaravelConnectorPublishContext();

    $destination = ContentDestination::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $site->workspace_id,
        'name' => 'Laravel Connector Destination',
        'type' => 'laravel',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
        'config' => [
            'billing_client_site_id' => (string) $site->id,
            'laravel_connector' => [
                'base_url' => 'https://publish-now.example.com',
                'site_id' => 'publish-now-site',
                'sync_endpoint' => '/publishlayer/sync',
                'api_key_encrypted' => Crypt::encryptString('publish-now-secret'),
                'enabled' => true,
                'mode' => 'hosted_views',
            ],
        ],
    ]);

    $content->update([
        'content_destination_id' => $destination->id,
    ]);

    $draft->update([
        'content_destination_id' => $destination->id,
    ]);

    Bus::fake();

    $this->actingAs($user)
        ->post(route('app.content.publish-now', $content))
        ->assertRedirect()
        ->assertSessionHas('status', 'Laravel publication queued.');

    Bus::assertDispatched(PublishContentJob::class, fn (PublishContentJob $job): bool => $job->contentId === (string) $content->id);
    Bus::assertNotDispatched(SyncLaravelKnowledgeArticleJob::class);

    $publication = ContentPublication::query()
        ->where('content_id', (string) $content->id)
        ->where('destination_id', (string) $destination->id)
        ->where('provider', ContentPublication::PROVIDER_LARAVEL)
        ->firstOrFail();

    expect((string) $publication->delivery_status)->toBe('pending');
});

it('queues publish for the selected laravel locale variant only', function () {
    [$user, $site, $source, $sourceDraft] = makeLaravelConnectorPublishContext();
    $destination = createLaravelConnectorDestinationForSite($site);

    $source->update([
        'content_destination_id' => $destination->id,
        'language' => SupportedLanguage::NL->value,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => true,
        'status' => 'published',
        'publish_status' => 'published',
    ]);

    $sourceDraft->update([
        'content_destination_id' => $destination->id,
        'language' => SupportedLanguage::NL->value,
    ]);

    ContentPublication::query()->create([
        'content_id' => (string) $source->id,
        'destination_id' => (string) $destination->id,
        'client_site_id' => (string) $site->id,
        'locale' => SupportedLanguage::NL->value,
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'delivery_status' => 'delivered',
        'remote_id' => 'nl-article',
        'remote_url' => 'https://publish-now.example.com/knowledge/nl/immediate-publish-content',
        'last_delivered_at' => now()->subDay(),
    ]);

    $english = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $source->workspace_id,
        'client_site_id' => (string) $source->client_site_id,
        'content_destination_id' => (string) $destination->id,
        'title' => 'Immediate Publish Content EN',
        'primary_keyword' => 'immediate publish keyword en',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'translation',
        'language' => SupportedLanguage::EN->value,
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => false,
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $english->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Brief for English publish now',
        'language' => SupportedLanguage::EN->value,
        'output_type' => 'kb_article',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $english->id,
        'client_site_id' => (string) $site->id,
        'content_destination_id' => (string) $destination->id,
        'status' => 'ready_to_deliver',
        'delivery_status' => 'pending',
        'title' => 'Draft for English publish now',
        'output_type' => 'kb_article',
        'language' => SupportedLanguage::EN->value,
        'content_html' => '<h1>Hello EN</h1>',
    ]);

    Bus::fake();

    $this->actingAs($user)
        ->post(route('app.content.publish-now', $source), ['locale' => 'en'])
        ->assertRedirect()
        ->assertSessionHas('status', 'Laravel publication queued.');

    Bus::assertDispatched(PublishContentJob::class, fn (PublishContentJob $job): bool => $job->contentId === (string) $english->id);
    Bus::assertNotDispatched(PublishContentJob::class, fn (PublishContentJob $job): bool => $job->contentId === (string) $source->id);

    expect((string) $english->fresh()->publish_status)->toBe('publishing')
        ->and((string) $source->fresh()->publish_status)->toBe('published');

    $englishPublication = ContentPublication::query()
        ->where('content_id', (string) $english->id)
        ->where('destination_id', (string) $destination->id)
        ->where('provider', ContentPublication::PROVIDER_LARAVEL)
        ->firstOrFail();

    expect((string) $englishPublication->delivery_status)->toBe('pending')
        ->and((string) $englishPublication->locale->value)->toBe(SupportedLanguage::EN->value);
});

it('allows updating a published laravel translation when it is outdated', function () {
    [$user, $site, $source, $sourceDraft] = makeLaravelConnectorPublishContext();
    $destination = createLaravelConnectorDestinationForSite($site);

    $source->update([
        'content_destination_id' => $destination->id,
        'language' => SupportedLanguage::NL->value,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => true,
        'status' => 'published',
        'publish_status' => 'published',
        'updated_at' => now(),
    ]);

    $sourceDraft->update([
        'content_destination_id' => $destination->id,
        'language' => SupportedLanguage::NL->value,
    ]);

    $english = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $source->workspace_id,
        'client_site_id' => (string) $source->client_site_id,
        'content_destination_id' => (string) $destination->id,
        'title' => 'Outdated EN Variant',
        'primary_keyword' => 'outdated en keyword',
        'type' => 'article',
        'status' => 'published',
        'source' => 'translation',
        'language' => SupportedLanguage::EN->value,
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'translation_generated_at' => now()->subDays(2),
        'translation_source_updated_at' => now()->subDays(2),
        'is_source_locale' => false,
        'delivery_status' => 'delivered',
        'publish_status' => 'published',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $english->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Brief for outdated English publish',
        'language' => SupportedLanguage::EN->value,
        'output_type' => 'kb_article',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $english->id,
        'client_site_id' => (string) $site->id,
        'content_destination_id' => (string) $destination->id,
        'status' => 'ready_to_deliver',
        'delivery_status' => 'pending',
        'title' => 'Updated outdated English draft',
        'output_type' => 'kb_article',
        'language' => SupportedLanguage::EN->value,
        'content_html' => '<h1>Updated EN</h1>',
    ]);

    $publication = ContentPublication::query()->create([
        'content_id' => (string) $english->id,
        'destination_id' => (string) $destination->id,
        'client_site_id' => (string) $site->id,
        'locale' => SupportedLanguage::EN->value,
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'remote_status' => ContentPublication::REMOTE_PUBLISHED,
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'last_delivered_at' => now()->subDay(),
    ]);

    Bus::fake();

    $this->actingAs($user)
        ->post(route('app.content.publish-now', $source), ['locale' => 'en'])
        ->assertRedirect()
        ->assertSessionHas('status', 'Laravel publication queued.');

    Bus::assertDispatched(PublishContentJob::class, fn (PublishContentJob $job): bool => $job->contentId === (string) $english->id);

    expect((string) $english->fresh()->publish_status)->toBe('publishing')
        ->and((string) $publication->fresh()->delivery_status)->toBe(ContentPublication::STATUS_PENDING)
        ->and(data_get($publication->fresh()->meta, 'recovery.reason'))->toBe('outdated_publication_update');
});

it('queues the generic publish job when manually re-publishing laravel destination content', function () {
    [$user, $site, $content, $draft] = makeLaravelConnectorPublishContext();

    $destination = ContentDestination::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $site->workspace_id,
        'name' => 'Laravel Connector Destination',
        'type' => 'laravel',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
        'config' => [
            'billing_client_site_id' => (string) $site->id,
            'laravel_connector' => [
                'base_url' => 'https://publish-now.example.com',
                'site_id' => 'publish-now-site',
                'sync_endpoint' => '/publishlayer/sync',
                'api_key_encrypted' => Crypt::encryptString('publish-now-secret'),
                'enabled' => true,
                'mode' => 'hosted_views',
            ],
        ],
    ]);

    $content->update([
        'content_destination_id' => $destination->id,
        'publish_status' => 'published',
    ]);

    $draft->update([
        'content_destination_id' => $destination->id,
    ]);

    Bus::fake();

    $this->actingAs($user)
        ->post(route('app.content.republish', $content))
        ->assertRedirect()
        ->assertSessionHas('status', 'Content queued for Laravel publication.');

    Bus::assertDispatched(PublishContentJob::class, fn (PublishContentJob $job): bool => $job->contentId === (string) $content->id);
    Bus::assertNotDispatched(SyncLaravelKnowledgeArticleJob::class);
});

it('republishes source locale content through the local laravel sync path', function () {
    [$user, $site, $content, $draft] = makeLaravelConnectorPublishContext();

    $content->forceFill([
        'status' => 'published',
        'publish_status' => 'published',
        'language' => SupportedLanguage::NL->value,
        'is_source_locale' => true,
        'published_url' => 'https://publish-now.example.com/nl/blog/immediate-publish-content',
        'seo_canonical' => 'https://publish-now.example.com/nl/blog/immediate-publish-content',
    ])->save();

    $draft->forceFill(['language' => SupportedLanguage::NL->value])->save();

    Bus::fake();
    Event::fake([ContentPublished::class]);

    $this->actingAs($user)
        ->post(route('app.content.republish', $content))
        ->assertRedirect()
        ->assertSessionHas('status', 'Content marked as published locally for Laravel.');

    $publication = ContentPublication::query()
        ->where('content_id', (string) $content->id)
        ->whereNull('destination_id')
        ->where('client_site_id', (string) $site->id)
        ->where('provider', ContentPublication::PROVIDER_LARAVEL)
        ->firstOrFail();

    expect((string) $publication->delivery_status)->toBe(ContentPublication::STATUS_DELIVERED)
        ->and((string) $publication->remote_status)->toBe(ContentPublication::REMOTE_PUBLISHED)
        ->and((string) ($publication->locale?->value ?? ''))->toBe(SupportedLanguage::NL->value)
        ->and((string) $publication->remote_id)->toBe((string) $content->id);

    Bus::assertNotDispatched(SyncLaravelKnowledgeArticleJob::class);
    Event::assertDispatched(ContentPublished::class);

    $content->refresh()->loadMissing(['currentVersion', 'currentRevision']);

    expect((string) data_get($content->currentVersion?->meta, 'draft_id'))->toBe((string) $draft->id)
        ->and((string) $content->currentVersion?->type)->toBe(\App\Models\ContentVersion::TYPE_PUBLISHED_SNAPSHOT)
        ->and((string) ($content->currentRevision?->draft_id ?? ''))->toBe((string) $draft->id);

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $content]))
        ->assertOk()
        ->assertDontSee('Unsaved draft changes')
        ->assertSee('All changes published');
});

it('republishes translated content through the local laravel sync path', function () {
    [$user, $site, $source, $sourceDraft] = makeLaravelConnectorPublishContext();

    $source->forceFill([
        'status' => 'published',
        'publish_status' => 'published',
        'language' => SupportedLanguage::NL->value,
        'is_source_locale' => true,
        'published_url' => 'https://publish-now.example.com/nl/blog/immediate-publish-content',
        'seo_canonical' => 'https://publish-now.example.com/nl/blog/immediate-publish-content',
    ])->save();

    $english = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $source->workspace_id,
        'client_site_id' => (string) $site->id,
        'title' => 'Immediate Publish Content English',
        'language' => SupportedLanguage::EN->value,
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'translation',
        'publish_url_key' => 'immediate-publish-content-english',
        'published_url' => 'https://publish-now.example.com/en/blog/immediate-publish-content-english',
        'seo_canonical' => 'https://publish-now.example.com/en/blog/immediate-publish-content-english',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $english->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'English local republish brief',
        'language' => SupportedLanguage::EN->value,
        'output_type' => 'kb_article',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $english->id,
        'client_site_id' => (string) $site->id,
        'status' => 'ready_to_deliver',
        'delivery_status' => 'pending',
        'title' => 'English local republish draft',
        'language' => SupportedLanguage::EN->value,
        'output_type' => 'kb_article',
        'content_html' => '<h1>Hello EN</h1>',
    ]);

    Bus::fake();

    $this->actingAs($user)
        ->post(route('app.content.republish', $english))
        ->assertRedirect()
        ->assertSessionHas('status', 'Content marked as published locally for Laravel.');

    $publication = ContentPublication::query()
        ->where('content_id', (string) $english->id)
        ->where('provider', ContentPublication::PROVIDER_LARAVEL)
        ->firstOrFail();

    $englishDraft = Draft::query()
        ->where('content_id', (string) $english->id)
        ->latest('created_at')
        ->firstOrFail();

    expect((string) $publication->delivery_status)->toBe(ContentPublication::STATUS_DELIVERED)
        ->and((string) $publication->remote_status)->toBe(ContentPublication::REMOTE_PUBLISHED)
        ->and((string) ($publication->locale?->value ?? ''))->toBe(SupportedLanguage::EN->value)
        ->and((string) $publication->remote_id)->toBe((string) $english->id)
        ->and((string) $source->fresh()->publish_status)->toBe('published');

    $english->refresh()->loadMissing(['currentVersion', 'currentRevision']);

    expect((string) data_get($english->currentVersion?->meta, 'draft_id'))->toBe((string) $englishDraft->id)
        ->and((string) $english->currentVersion?->type)->toBe(\App\Models\ContentVersion::TYPE_PUBLISHED_SNAPSHOT)
        ->and((string) ($english->currentRevision?->draft_id ?? ''))->toBe((string) $englishDraft->id);

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $english]))
        ->assertOk()
        ->assertDontSee('Unsaved draft changes')
        ->assertSee('All changes published');
});

it('synchronizes the canonical version after immediate local laravel publish', function () {
    [$user, $site, $content, $draft] = makeLaravelConnectorPublishContext();

    $this->actingAs($user)
        ->post(route('app.content.publish-now', $content))
        ->assertRedirect()
        ->assertSessionHas('status', 'Content marked as published locally. Laravel connector sync is pending.');

    $content->refresh()->loadMissing(['currentVersion', 'currentRevision']);

    expect((string) $content->publish_status)->toBe('published')
        ->and((string) data_get($content->currentVersion?->meta, 'draft_id'))->toBe((string) $draft->id)
        ->and((string) $content->currentVersion?->type)->toBe(\App\Models\ContentVersion::TYPE_PUBLISHED_SNAPSHOT)
        ->and((string) ($content->currentRevision?->draft_id ?? ''))->toBe((string) $draft->id);

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $content]))
        ->assertOk()
        ->assertDontSee('Unsaved draft changes')
        ->assertSee('All changes published');
});

it('local laravel republish updates an existing publication instead of duplicating it', function () {
    [$user, $site, $content, $draft] = makeLaravelConnectorPublishContext();

    $content->forceFill([
        'status' => 'published',
        'publish_status' => 'published',
        'published_url' => 'https://publish-now.example.com/blog/immediate-publish-content',
    ])->save();

    $existing = ContentPublication::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'locale' => SupportedLanguage::EN->value,
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'remote_id' => 'existing-local-id',
        'remote_status' => ContentPublication::REMOTE_DRAFT,
        'delivery_status' => ContentPublication::STATUS_PENDING,
    ]);

    $this->actingAs($user)
        ->post(route('app.content.republish', $content))
        ->assertRedirect()
        ->assertSessionHas('status', 'Content marked as published locally for Laravel.');

    expect(ContentPublication::query()
        ->where('content_id', (string) $content->id)
        ->where('provider', ContentPublication::PROVIDER_LARAVEL)
        ->count())->toBe(1)
        ->and((string) $existing->fresh()->delivery_status)->toBe(ContentPublication::STATUS_DELIVERED)
        ->and((string) $existing->fresh()->remote_id)->toBe('existing-local-id');
});

it('local laravel republish handles invalid slug-shaped data safely', function () {
    [$user, $site, $content, $draft] = makeLaravelConnectorPublishContext();

    $content->forceFill([
        'status' => 'published',
        'publish_status' => 'published',
        'publish_url_key' => 'https://publish-now.example.com/en/blog/bad-slug-source',
        'published_url' => 'https://publish-now.example.com/en/blog/bad-slug-source',
        'seo_canonical' => 'https://publish-now.example.com/en/blog/bad-slug-source',
    ])->save();

    $this->actingAs($user)
        ->post(route('app.content.republish', $content))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $publication = ContentPublication::query()
        ->where('content_id', (string) $content->id)
        ->where('provider', ContentPublication::PROVIDER_LARAVEL)
        ->firstOrFail();

    expect((string) $publication->delivery_status)->toBe(ContentPublication::STATUS_DELIVERED)
        ->and((string) $content->fresh()->publish_url_key)->toBe('bad-slug-source');
});

it('logs and surfaces model event failures during local publication sync', function () {
    [$user, $site, $content, $draft] = makeLaravelConnectorPublishContext();

    Log::spy();

    $target = ContentPublishTarget::query()->create([
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'target_type' => 'laravel',
        'target_identifier' => (string) $content->id,
        'sync_status' => 'pending',
        'meta' => [
            'publish_confirmation' => 'local_only',
            'published_url' => 'https://publish-now.example.com/blog/immediate-publish-content',
        ],
    ]);

    $publication = ContentPublication::resolveForDelivery(
        contentId: (string) $content->id,
        clientSiteId: (string) $site->id,
        provider: ContentPublication::PROVIDER_LARAVEL,
        locale: SupportedLanguage::EN,
    );

    ContentPublication::saving(function (): void {
        throw new RuntimeException('Injected publication saving failure.');
    });

    expect(fn () => app(\App\Services\Publication\LaravelPublicationBridge::class)->syncFromTarget($publication, $target, $content))
        ->toThrow(RuntimeException::class, 'Laravel publication sync failed while saving publication');

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'publication.laravel.sync_from_target.save_failed'
            && ($context['exception_message'] ?? '') === 'Injected publication saving failure.'
            && isset($context['dirty_attributes']));
});

it('syncFromTarget tolerates localized canonical and slug changes', function () {
    [$user, $site, $source, $draft] = makeLaravelConnectorPublishContext();

    $source->forceFill([
        'language' => SupportedLanguage::NL->value,
        'is_source_locale' => true,
        'publish_url_key' => 'oude-bron',
        'published_url' => 'https://publish-now.example.com/nl/blog/oude-bron',
        'seo_canonical' => 'https://publish-now.example.com/nl/blog/oude-bron',
    ])->save();

    $english = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $source->workspace_id,
        'client_site_id' => (string) $site->id,
        'title' => 'Changed English Canonical',
        'language' => SupportedLanguage::EN->value,
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'translation',
        'publish_url_key' => 'changed-english-canonical',
        'published_url' => 'https://publish-now.example.com/en/blog/changed-english-canonical',
        'seo_canonical' => 'https://publish-now.example.com/en/blog/changed-english-canonical',
    ]);

    $target = ContentPublishTarget::query()->create([
        'content_id' => (string) $english->id,
        'client_site_id' => (string) $site->id,
        'target_type' => 'laravel',
        'target_identifier' => (string) $english->id,
        'sync_status' => 'pending',
        'meta' => [
            'publish_confirmation' => 'local_only',
            'published_url' => 'https://publish-now.example.com/en/blog/changed-english-canonical',
            'canonical_url' => 'https://publish-now.example.com/en/blog/changed-english-canonical',
        ],
    ]);

    $publication = ContentPublication::resolveForDelivery(
        contentId: (string) $english->id,
        clientSiteId: (string) $site->id,
        provider: ContentPublication::PROVIDER_LARAVEL,
        locale: SupportedLanguage::EN,
    );

    app(\App\Services\Publication\LaravelPublicationBridge::class)->syncFromTarget($publication, $target, $english);

    expect((string) $publication->fresh()->delivery_status)->toBe(ContentPublication::STATUS_DELIVERED)
        ->and((string) $publication->fresh()->remote_url)->toBe('https://publish-now.example.com/en/blog/changed-english-canonical');
});

it('queues remote delete when unpublishing laravel connector content', function () {
    [$user, $site, $content, $draft] = makeLaravelConnectorPublishContext();

    $destination = ContentDestination::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $site->workspace_id,
        'name' => 'Laravel Connector Destination',
        'type' => 'laravel',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
        'config' => [
            'billing_client_site_id' => (string) $site->id,
            'laravel_connector' => [
                'base_url' => 'https://publish-now.example.com',
                'site_id' => 'publish-now-site',
                'sync_endpoint' => '/publishlayer/sync',
                'api_key_encrypted' => Crypt::encryptString('publish-now-secret'),
                'enabled' => true,
                'mode' => 'hosted_views',
            ],
        ],
    ]);

    $content->update([
        'content_destination_id' => $destination->id,
        'publish_status' => 'published',
    ]);

    $draft->update([
        'content_destination_id' => $destination->id,
    ]);

    Bus::fake();

    $this->actingAs($user)
        ->post(route('app.content.unpublish-remote', $content))
        ->assertRedirect()
        ->assertSessionHas('status', 'Remote delete queued for the Laravel connector.');

    Bus::assertDispatched(SyncLaravelKnowledgeArticleJob::class, function (SyncLaravelKnowledgeArticleJob $job) use ($content): bool {
        return $job->contentId === (string) $content->id
            && $job->triggerSource === 'app.content.unpublish-remote'
            && $job->articleStatus === 'deleted';
    });
});

it('publishes scheduled laravel destination content when the schedule time is already due', function () {
    [$user, $site, $content, $draft] = makeLaravelConnectorPublishContext();
    $destination = createLaravelConnectorDestinationForSite($site);

    $content->update(['content_destination_id' => $destination->id]);
    $draft->update(['content_destination_id' => $destination->id]);

    config(['queue.default' => 'sync']);

    Http::fake([
        'https://publish-now.example.com/publishlayer/sync' => Http::response([
            'ok' => true,
            'url' => 'https://publish-now.example.com/knowledge/immediate-publish-content',
        ], 200),
    ]);

    $this->actingAs($user)
        ->post(route('app.content.schedule', $content), [
            'scheduled_publish_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ])
        ->assertRedirect();

    $content->refresh();
    $draft->refresh();

    $publication = ContentPublication::query()
        ->where('content_id', (string) $content->id)
        ->where('destination_id', (string) $destination->id)
        ->where('provider', ContentPublication::PROVIDER_LARAVEL)
        ->firstOrFail();

    expect((string) $content->publish_status)->toBe('published')
        ->and((string) $content->status)->toBe('published')
        ->and((string) $content->delivery_status)->toBe('delivered')
        ->and($content->scheduled_publish_at)->toBeNull()
        ->and((string) $draft->delivery_status)->toBe('delivered')
        ->and((string) $publication->delivery_status)->toBe('delivered')
        ->and((string) $publication->remote_status)->toBe('published');
});

it('does not emit published-content agent events before connector delivery is confirmed', function () {
    [$user, $site, $content, $draft] = makeLaravelConnectorPublishContext();
    $destination = createLaravelConnectorDestinationForSite($site);

    $content->update(['content_destination_id' => $destination->id]);
    $draft->update(['content_destination_id' => $destination->id]);

    Bus::fake();
    Event::fake([ContentPublished::class]);

    app(LaravelConnectorPublishingService::class)->publish(
        $content,
        $draft,
        'publish_now',
        'tests.connector_publish',
    );

    Event::assertNotDispatched(ContentPublished::class);
    Bus::assertDispatched(SyncLaravelKnowledgeArticleJob::class);
});

it('publishes past-due laravel destination content on scheduler run', function () {
    [$user, $site, $content, $draft] = makeLaravelConnectorPublishContext();
    $destination = createLaravelConnectorDestinationForSite($site);

    $content->update([
        'content_destination_id' => $destination->id,
        'publish_status' => 'scheduled',
        'scheduled_publish_at' => now()->subHour(),
    ]);
    $draft->update(['content_destination_id' => $destination->id]);

    config(['queue.default' => 'sync']);

    Http::fake([
        'https://publish-now.example.com/publishlayer/sync' => Http::response(['ok' => true], 200),
    ]);

    $this->artisan('content:dispatch-scheduled-publishes --limit=10 --stale-after-minutes=15')
        ->assertExitCode(0);

    $publication = ContentPublication::query()
        ->where('content_id', (string) $content->id)
        ->where('destination_id', (string) $destination->id)
        ->where('provider', ContentPublication::PROVIDER_LARAVEL)
        ->firstOrFail();

    expect((string) $content->fresh()->publish_status)->toBe('published')
        ->and((string) $publication->fresh()->delivery_status)->toBe('delivered');
});

it('recovers stale laravel publications that were stuck on delivering', function () {
    [$user, $site, $content, $draft] = makeLaravelConnectorPublishContext();
    $destination = createLaravelConnectorDestinationForSite($site);

    $content->update([
        'content_destination_id' => $destination->id,
        'publish_status' => 'publishing',
        'scheduled_publish_at' => now()->subHour(),
    ]);
    $draft->update(['content_destination_id' => $destination->id]);

    $publication = ContentPublication::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'destination_id' => (string) $destination->id,
        'client_site_id' => (string) $site->id,
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'delivery_status' => 'processing',
        'meta' => [
            'claim' => [
                'claimed_at' => now()->subMinutes(30)->toIso8601String(),
            ],
            'dispatch' => [
                'queued_at' => now()->subMinutes(30)->toIso8601String(),
            ],
        ],
    ]);

    config(['queue.default' => 'sync']);

    Http::fake([
        'https://publish-now.example.com/publishlayer/sync' => Http::response(['ok' => true], 200),
    ]);

    $this->artisan('content:dispatch-scheduled-publishes --limit=10 --stale-after-minutes=15')
        ->assertExitCode(0);

    $publication->refresh();

    expect((string) $content->fresh()->publish_status)->toBe('published')
        ->and((string) $publication->delivery_status)->toBe('delivered')
        ->and(filled(data_get($publication->meta, 'recovery.requeued_at')))->toBeTrue();
});

it('queues bulk laravel connector sync for selected content', function () {
    [$user, $site, $content, $draft] = makeLaravelConnectorPublishContext();

    $destination = ContentDestination::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $site->workspace_id,
        'name' => 'Laravel Connector Destination',
        'type' => 'laravel',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
        'config' => [
            'billing_client_site_id' => (string) $site->id,
            'laravel_connector' => [
                'base_url' => 'https://publish-now.example.com',
                'site_id' => 'publish-now-site',
                'sync_endpoint' => '/publishlayer/sync',
                'api_key_encrypted' => Crypt::encryptString('publish-now-secret'),
                'enabled' => true,
                'mode' => 'hosted_views',
            ],
        ],
    ]);

    $content->update([
        'content_destination_id' => $destination->id,
    ]);

    $draft->update([
        'content_destination_id' => $destination->id,
    ]);

    $secondContent = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $site->workspace_id,
        'client_site_id' => $site->id,
        'content_destination_id' => $destination->id,
        'title' => 'Second Bulk Content',
        'primary_keyword' => 'bulk content',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'laravel',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
    ]);

    $secondBrief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_destination_id' => $destination->id,
        'content_id' => $secondContent->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Second bulk brief',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $secondBrief->id,
        'content_id' => $secondContent->id,
        'client_site_id' => $site->id,
        'content_destination_id' => $destination->id,
        'status' => 'ready_to_deliver',
        'delivery_status' => 'pending',
        'title' => 'Second bulk draft',
        'content_html' => '<p>Bulk sync body</p>',
    ]);

    Bus::fake();

    $this->actingAs($user)
        ->post(route('app.content.sync-bulk'), [
            'content_ids' => [(string) $content->id, (string) $secondContent->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Queued 2 Laravel connector sync(s). Skipped 0 item(s).');

    Bus::assertDispatchedTimes(PublishContentJob::class, 2);
    Bus::assertNotDispatched(SyncLaravelKnowledgeArticleJob::class);
});

it('verifies laravel routes through the generic verify action', function () {
    [$user, $site, $content, $draft] = makeLaravelConnectorPublishContext();

    $destination = ContentDestination::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $site->workspace_id,
        'name' => 'Laravel Connector Destination',
        'type' => 'laravel',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
        'config' => [
            'billing_client_site_id' => (string) $site->id,
            'laravel_connector' => [
                'base_url' => 'https://publish-now.example.com',
                'site_id' => 'publish-now-site',
                'sync_endpoint' => '/publishlayer/sync',
                'api_key_encrypted' => Crypt::encryptString('publish-now-secret'),
                'enabled' => true,
                'mode' => 'hosted_views',
            ],
        ],
    ]);

    $content->update([
        'content_destination_id' => $destination->id,
        'publish_status' => 'published',
        'published_url' => 'https://publish-now.example.com/knowledge/immediate-publish-content',
    ]);

    ContentPublication::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'destination_id' => (string) $destination->id,
        'client_site_id' => (string) $site->id,
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'remote_id' => (string) $content->id,
        'remote_url' => 'https://publish-now.example.com/knowledge/immediate-publish-content',
        'remote_status' => 'published',
        'delivery_status' => 'delivered',
        'last_delivered_at' => now(),
    ]);

    Http::fake([
        'https://publish-now.example.com/knowledge/immediate-publish-content' => Http::response('', 200),
    ]);

    $this->actingAs($user)
        ->post(route('app.content.verify-remote', $content))
        ->assertRedirect()
        ->assertSessionHas('status', 'Laravel route verified. The page exists and is reachable on the destination site.');
});
