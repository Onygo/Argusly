<?php

use App\Enums\PublicationDeliveryStatus;
use App\Enums\RemoteExistenceStatus;
use App\Enums\SupportedLanguage;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentDestination;
use App\Models\ContentDeliveryEvent;
use App\Models\ContentPublication;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\View\Presenters\ContentStatusPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ============================================================================
// Presenter Tests: deliveryActions()
// ============================================================================

it('returns republish action when content can be delivered', function () {
    [$content, $publication] = createDeliveryUITestContext('pending');
    // Status must be 'ready_to_deliver' or 'scheduled' for isDeliverable() to return true
    $content->update(['status' => 'ready_to_deliver']);
    $presenter = ContentStatusPresenter::for($content->fresh());

    $actions = $presenter->deliveryActions();

    expect($actions)->toHaveKey('republish')
        ->and($actions['republish']['label'])->toContain('Republish');
});

it('returns recreate action when remote is missing', function () {
    [$content, $publication] = createDeliveryUITestContext('missing_remote');
    $publication->markMissingRemote('12345');
    // Status must be 'ready_to_deliver' or 'scheduled' for isDeliverable() to return true
    $content->update(['status' => 'ready_to_deliver', 'delivery_status' => 'missing_remote']);
    $presenter = ContentStatusPresenter::for($content->fresh());

    $actions = $presenter->deliveryActions();

    expect($actions)->toHaveKey('republish')
        ->and($actions['republish']['label'])->toContain('Recreate')
        ->and($actions['republish']['confirm'])->toBeTrue();
});

it('returns verify action when remote ID exists', function () {
    [$content, $publication] = createDeliveryUITestContext('delivered');
    $publication->markDelivered('12345', 'https://example.com/post/12345');
    $presenter = ContentStatusPresenter::for($content->fresh());

    $actions = $presenter->deliveryActions();

    expect($actions)->toHaveKey('verify')
        ->and($actions['verify']['icon'])->toBe('search');
});

it('returns retry action when delivery failed', function () {
    [$content, $publication] = createDeliveryUITestContext('failed');
    $publication->markFailed('500', 'Server error');
    $content->update(['status' => 'draft', 'delivery_status' => 'failed']);
    $presenter = ContentStatusPresenter::for($content->fresh());

    $actions = $presenter->deliveryActions();

    expect($actions)->toHaveKey('retry')
        ->and($actions['retry']['icon'])->toBe('rotate-ccw');
});

it('returns open remote action when published URL exists', function () {
    [$content, $publication] = createDeliveryUITestContext('delivered');
    $publication->markDelivered('12345', 'https://example.com/post/12345');
    $presenter = ContentStatusPresenter::for($content->fresh());

    $actions = $presenter->deliveryActions();

    expect($actions)->toHaveKey('open_remote')
        ->and($actions['open_remote']['external'])->toBeTrue()
        ->and($actions['open_remote']['route'])->toBe('https://example.com/post/12345');
});

it('returns laravel-specific actions for laravel destinations', function () {
    [$content, $publication] = createDeliveryUITestContext(
        deliveryStatus: 'delivered',
        siteType: ClientSite::TYPE_LARAVEL
    );
    $publication->markDelivered('article-123', 'https://example.com/knowledge/article-123');
    $content->update(['status' => 'ready_to_deliver', 'publish_status' => 'published']);

    $presenter = ContentStatusPresenter::for($content->fresh());
    $actions = $presenter->deliveryActions();

    expect($actions['republish']['label'])->toBe('Republish to Laravel')
        ->and($actions)->not->toHaveKey('verify')
        ->and($actions['open_remote']['label'])->toBe('Open on site');
});

it('maps laravel draft publishing published and failed states for the UI', function () {
    [$draftContent, $draftPublication] = createDeliveryUITestContext(
        deliveryStatus: 'pending',
        siteType: ClientSite::TYPE_LARAVEL
    );
    $draftPresenter = ContentStatusPresenter::for($draftContent->fresh());

    expect($draftPresenter->fullStatus()['delivery']['value'])->toBe('Pending')
        ->and($draftPresenter->fullStatus()['remote']['value'])->toBe('Draft');

    [$publishingContent, $publishingPublication] = createDeliveryUITestContext(
        deliveryStatus: 'processing',
        siteType: ClientSite::TYPE_LARAVEL
    );
    $publishingContent->update(['publish_status' => 'publishing']);
    $publishingPresenter = ContentStatusPresenter::for($publishingContent->fresh());

    expect($publishingPresenter->fullStatus()['delivery']['value'])->toBe('Delivering');

    [$publishedContent, $publishedPublication] = createDeliveryUITestContext(
        deliveryStatus: 'delivered',
        siteType: ClientSite::TYPE_LARAVEL
    );
    $publishedPublication->markDelivered('article-456', 'https://example.com/knowledge/article-456');
    $publishedContent->update(['status' => 'published', 'publish_status' => 'published']);
    $publishedPresenter = ContentStatusPresenter::for($publishedContent->fresh());

    expect($publishedPresenter->fullStatus()['delivery']['value'])->toBe('Delivered')
        ->and($publishedPresenter->fullStatus()['remote']['value'])->toBe('Published');

    [$failedContent, $failedPublication] = createDeliveryUITestContext(
        deliveryStatus: 'failed',
        siteType: ClientSite::TYPE_LARAVEL
    );
    $failedPublication->markFailed('SYNC_FAILED', 'Laravel sync failed.');
    $failedContent->update(['publish_status' => 'failed']);
    $failedPresenter = ContentStatusPresenter::for($failedContent->fresh());

    expect($failedPresenter->fullStatus()['delivery']['value'])->toBe('Failed');
});

// ============================================================================
// Presenter Tests: errorCategory()
// ============================================================================

it('categorizes auth errors correctly', function () {
    [$content, $publication] = createDeliveryUITestContext('failed');
    $publication->markFailed('401', 'WordPress rejected the request as unauthorized.');
    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->errorCategory())->toBe('auth');
});

it('categorizes 403 forbidden as auth error', function () {
    [$content, $publication] = createDeliveryUITestContext('failed');
    $publication->markFailed('403', 'Access forbidden.');
    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->errorCategory())->toBe('auth');
});

it('categorizes validation errors correctly', function () {
    [$content, $publication] = createDeliveryUITestContext('failed');
    $publication->markFailed('422', 'WordPress rejected the payload as invalid.');
    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->errorCategory())->toBe('validation');
});

it('categorizes transport errors correctly', function () {
    [$content, $publication] = createDeliveryUITestContext('failed');
    $publication->markFailed('504', 'Connection timeout after 30 seconds');
    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->errorCategory())->toBe('transport');
});

it('categorizes missing remote errors correctly', function () {
    [$content, $publication] = createDeliveryUITestContext('missing_remote');
    $publication->markMissingRemote('12345');
    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->errorCategory())->toBe('missing');
});

it('returns null error category when no error', function () {
    [$content, $publication] = createDeliveryUITestContext('delivered');
    $publication->markDelivered('12345', 'https://example.com');
    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->errorCategory())->toBeNull();
});

// ============================================================================
// Presenter Tests: recoveryMessage()
// ============================================================================

it('returns appropriate recovery message for missing remote', function () {
    [$content, $publication] = createDeliveryUITestContext('missing_remote');
    $publication->markMissingRemote('12345');
    $presenter = ContentStatusPresenter::for($content->fresh());

    $message = $presenter->recoveryMessage();

    expect($message)->toContain('no longer exists')
        ->and($message)->toContain('Republishing will recreate');
});

it('returns appropriate recovery message for auth errors', function () {
    [$content, $publication] = createDeliveryUITestContext('failed');
    $publication->markFailed('401', 'WordPress rejected the request as unauthorized.');
    $presenter = ContentStatusPresenter::for($content->fresh());

    $message = $presenter->recoveryMessage();

    expect($message)->toContain('rejected the connection');
});

it('returns appropriate recovery message for transport errors', function () {
    [$content, $publication] = createDeliveryUITestContext('failed');
    $publication->markFailed('503', 'Connection timeout');
    $presenter = ContentStatusPresenter::for($content->fresh());

    $message = $presenter->recoveryMessage();

    expect($message)->toContain('Could not reach')
        ->and($message)->toContain('retry');
});

it('returns null recovery message when no error', function () {
    [$content, $publication] = createDeliveryUITestContext('delivered');
    $publication->markDelivered('12345', 'https://example.com');
    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->recoveryMessage())->toBeNull();
});

// ============================================================================
// Timeline Tests
// ============================================================================

it('returns recent delivery events in order', function () {
    [$content, $publication] = createDeliveryUITestContext('delivered');

    ContentDeliveryEvent::recordCreate($publication, [], [], 201);
    ContentDeliveryEvent::recordUpdate($publication, [], [], 200);
    ContentDeliveryEvent::recordVerify($publication, true, [], 200);

    $presenter = ContentStatusPresenter::for($content->fresh());
    $events = $presenter->recentDeliveryEvents(10);

    expect($events)->toHaveCount(3)
        ->and($events->first()->event_type)->toBe('verify_remote'); // Most recent first
});

it('limits delivery events returned', function () {
    [$content, $publication] = createDeliveryUITestContext('delivered');

    for ($i = 0; $i < 15; $i++) {
        ContentDeliveryEvent::recordUpdate($publication, [], [], 200);
    }

    $presenter = ContentStatusPresenter::for($content->fresh());
    $events = $presenter->recentDeliveryEvents(5);

    expect($events)->toHaveCount(5);
});

it('returns empty collection when no publication', function () {
    [$content] = createDeliveryUITestContext('pending', createPublication: false);
    $presenter = ContentStatusPresenter::for($content);

    $events = $presenter->recentDeliveryEvents();

    expect($events)->toBeEmpty();
});

// ============================================================================
// Verify Remote Helpers
// ============================================================================

it('canVerifyRemote returns true when remote ID exists', function () {
    [$content, $publication] = createDeliveryUITestContext('delivered');
    $publication->markDelivered('12345', 'https://example.com');
    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->canVerifyRemote())->toBeTrue();
});

it('canVerifyRemote returns true for legacy content with wp_post_id', function () {
    [$content] = createDeliveryUITestContext('pending', createPublication: false);
    $content->update(['wp_post_id' => '99999']);
    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->canVerifyRemote())->toBeTrue();
});

it('canOpenInWordPress returns true when URL exists', function () {
    [$content, $publication] = createDeliveryUITestContext('delivered');
    $publication->markDelivered('12345', 'https://example.com/post/12345');
    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->canOpenInWordPress())->toBeTrue();
});

it('canOpenInWordPress returns false when no URL', function () {
    [$content, $publication] = createDeliveryUITestContext('pending');
    $presenter = ContentStatusPresenter::for($content);

    expect($presenter->canOpenInWordPress())->toBeFalse();
});

// ============================================================================
// Integration: View Rendering
// ============================================================================

it('renders attention indicator on content index for failed delivery', function () {
    $user = createDeliveryUITestUser();
    [$content, $publication] = createDeliveryUITestContext('failed', user: $user);
    $publication->markFailed('500', 'Server error');

    $response = actingAs($user)->get(route('app.content.index'));

    $response->assertOk();
    $response->assertSee('Needs attention');
});

it('renders lifecycle and publish icons on content index for delivered content', function () {
    $user = createDeliveryUITestUser();
    [$content, $publication] = createDeliveryUITestContext('delivered', user: $user);

    $content->update([
        'status' => 'published',
        'delivery_status' => 'delivered',
        'publish_status' => 'published',
    ]);

    $publication->markDelivered('12345', 'https://example.com/post/12345');

    $response = actingAs($user)->get(route('app.content.index'));

    $response->assertOk();
    $response->assertSee('data-lucide="send"', false);
    $response->assertSee('data-lucide="check-circle"', false);
    $response->assertSee('Delivered');
});

it('renders a single source locale badge on the content index when no translations exist', function () {
    $user = createDeliveryUITestUser();
    [$content] = createDeliveryUITestContext('pending', user: $user);

    $content->workspace->update([
        'default_content_language' => SupportedLanguage::NL->value,
        'enabled_content_languages' => [SupportedLanguage::NL->value],
    ]);

    $content->update([
        'language' => SupportedLanguage::NL->value,
        'is_source_locale' => true,
    ]);

    $response = actingAs($user)->get(route('app.content.index'));

    $response->assertOk();
    $response->assertSee('Locales');
    $response->assertSee('0/1 published');
    $response->assertSee('>NL<', false);
    $response->assertDontSee('SRC EN');
});

it('renders the content index in the wide workspace layout', function () {
    $user = createDeliveryUITestUser();
    createDeliveryUITestContext('pending', user: $user);

    $response = actingAs($user)->get(route('app.content.index'));

    $response->assertOk();
    $response->assertSee('pl-page pl-page--wide', false);
    $response->assertSee('min-w-[1120px]', false);
    $response->assertDontSee('xl:min-w-[1280px]', false);
});

it('renders locale badges with per-locale status and direct links on the content index', function () {
    $user = createDeliveryUITestUser();
    [$source, $sourcePublication] = createDeliveryUITestContext('delivered', user: $user);

    $source->workspace->update([
        'default_content_language' => SupportedLanguage::NL->value,
        'enabled_content_languages' => [SupportedLanguage::NL->value, SupportedLanguage::EN->value],
    ]);

    $source->update([
        'language' => SupportedLanguage::NL->value,
        'is_source_locale' => true,
        'status' => 'published',
        'publish_status' => 'published',
    ]);
    $sourcePublication?->markDelivered('111', 'https://example.test/nl/article');

    $enVariant = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $source->workspace_id,
        'client_site_id' => (string) $source->client_site_id,
        'content_destination_id' => (string) $source->content_destination_id,
        'title' => 'Test Content EN Variant',
        'language' => SupportedLanguage::EN->value,
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => false,
        'primary_keyword' => 'test-en',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'translation',
        'external_key' => (string) Str::uuid(),
        'publish_status' => 'draft',
        'delivery_status' => 'pending',
    ]);

    ContentPublication::query()->create([
        'content_id' => (string) $enVariant->id,
        'destination_id' => (string) $source->content_destination_id,
        'client_site_id' => (string) $source->client_site_id,
        'provider' => ContentPublication::PROVIDER_WORDPRESS,
        'delivery_status' => 'pending',
    ]);

    $response = actingAs($user)->get(route('app.content.index'));

    $response->assertOk();
    $response->assertSee('SRC NL');
    $response->assertSee('href="' . route('app.content.show', $source) . '"', false);
    $response->assertSee('href="' . route('app.content.show', $enVariant) . '"', false);
});

it('groups translation variants under one expandable content row on the content index', function () {
    $user = createDeliveryUITestUser();
    [$source, $sourcePublication] = createDeliveryUITestContext('delivered', user: $user);

    $source->workspace->update([
        'default_content_language' => SupportedLanguage::NL->value,
        'enabled_content_languages' => [SupportedLanguage::NL->value, SupportedLanguage::EN->value],
    ]);

    $source->update([
        'title' => 'AI Cybersecurity Architecture',
        'language' => SupportedLanguage::NL->value,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => true,
        'status' => 'published',
        'publish_status' => 'published',
    ]);
    $sourcePublication?->markDelivered('111', 'https://example.test/nl/ai-cybersecurity-architecture');

    Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $source->workspace_id,
        'client_site_id' => (string) $source->client_site_id,
        'content_destination_id' => (string) $source->content_destination_id,
        'title' => 'AI Cybersecurity Architecture',
        'language' => SupportedLanguage::EN->value,
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'translation',
        'publish_status' => 'draft',
        'delivery_status' => 'pending',
    ]);

    $response = actingAs($user)->get(route('app.content.index'));

    $response->assertOk();
    $response->assertSee('data-content-tree-toggle', false);
    $response->assertSee('aria-expanded="false"', false);
    $response->assertSee('data-content-tree-children="article:'.$source->id.'"', false);
    $response->assertSee('aria-hidden="true"', false);
    $response->assertSee('SRC NL');
});

it('shows the actual locale and source locale on the content draft tab', function () {
    $user = createDeliveryUITestUser();
    [$source] = createDeliveryUITestContext('delivered', user: $user);

    $source->update([
        'language' => SupportedLanguage::NL->value,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => true,
    ]);

    $variant = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $source->workspace_id,
        'client_site_id' => (string) $source->client_site_id,
        'content_destination_id' => (string) $source->content_destination_id,
        'title' => 'Localized EN Variant',
        'language' => SupportedLanguage::EN->value,
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'translation',
        'publish_status' => 'draft',
        'delivery_status' => 'pending',
    ]);

    $response = actingAs($user)->get(route('app.content.show', ['content' => $variant, 'tab' => 'draft']));

    $response->assertOk();
    $response->assertSee('Language: EN');
    $response->assertSee('(Source: NL)');
});

it('shows the actual locale and source locale on the draft detail page', function () {
    $user = createDeliveryUITestUser();
    [$source] = createDeliveryUITestContext('pending', user: $user);

    $source->update([
        'language' => SupportedLanguage::NL->value,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => true,
    ]);

    $sourceBrief = Brief::query()->create([
        'client_site_id' => (string) $source->client_site_id,
        'content_id' => (string) $source->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Nederlandse bronbrief',
        'language' => SupportedLanguage::NL->value,
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $sourceDraft = Draft::query()->create([
        'brief_id' => (string) $sourceBrief->id,
        'content_id' => (string) $source->id,
        'client_site_id' => (string) $source->client_site_id,
        'status' => 'ready',
        'title' => 'Nederlandse brondraft',
        'language' => SupportedLanguage::NL->value,
        'draft_type' => 'original',
        'output_type' => 'kb_article',
        'content_html' => '<p>Broncontent.</p>',
    ]);

    $translatedContent = Content::query()->create([
        'workspace_id' => (string) $source->workspace_id,
        'client_site_id' => (string) $source->client_site_id,
        'content_destination_id' => (string) $source->content_destination_id,
        'title' => 'English draft content',
        'language' => SupportedLanguage::EN->value,
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => false,
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);

    $translatedBrief = Brief::query()->create([
        'client_site_id' => (string) $source->client_site_id,
        'content_id' => (string) $translatedContent->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'English translation brief',
        'language' => SupportedLanguage::EN->value,
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $translatedDraft = Draft::query()->create([
        'brief_id' => (string) $translatedBrief->id,
        'content_id' => (string) $translatedContent->id,
        'client_site_id' => (string) $source->client_site_id,
        'status' => 'ready',
        'title' => 'English translation draft',
        'language' => SupportedLanguage::EN->value,
        'draft_type' => 'translation',
        'source_draft_id' => (string) $sourceDraft->id,
        'translation_source_language' => SupportedLanguage::NL->value,
        'output_type' => 'kb_article',
        'content_html' => '<p>Translated content.</p>',
    ]);

    Draft::query()->create([
        'brief_id' => (string) $translatedBrief->id,
        'content_id' => (string) $translatedContent->id,
        'client_site_id' => (string) $source->client_site_id,
        'status' => 'queued',
        'title' => 'English translation pending A',
        'language' => SupportedLanguage::EN->value,
        'draft_type' => 'translation',
        'source_draft_id' => (string) $sourceDraft->id,
        'translation_source_language' => SupportedLanguage::NL->value,
        'output_type' => 'kb_article',
        'content_html' => '<p>Pending content.</p>',
    ]);

    Draft::query()->create([
        'brief_id' => (string) $translatedBrief->id,
        'content_id' => (string) $translatedContent->id,
        'client_site_id' => (string) $source->client_site_id,
        'status' => 'pending',
        'title' => 'English translation pending B',
        'language' => SupportedLanguage::EN->value,
        'draft_type' => 'translation',
        'source_draft_id' => (string) $sourceDraft->id,
        'translation_source_language' => SupportedLanguage::NL->value,
        'output_type' => 'kb_article',
        'content_html' => '<p>Pending content.</p>',
    ]);

    $response = actingAs($user)->get(route('app.drafts.show', $translatedDraft));

    $response->assertOk();
    $response->assertSee('Language: EN (Source: NL)');
    $response->assertSee('Publishing');
    $response->assertSee('Publish article');
    $response->assertSee(route('app.content.publish-now', $translatedContent), false);
    $response->assertSee('name="locale" value="en"', false);
    $response->assertSee('Languages');
    $response->assertSee('2 pending');
    expect(substr_count($response->getContent(), 'data-language-locale="en"'))->toBe(1)
        ->and(substr_count($response->getContent(), 'data-language-locale="nl"'))->toBe(1);
});

it('renders status panel with recovery message for missing remote', function () {
    $user = createDeliveryUITestUser();
    [$content, $publication] = createDeliveryUITestContext('missing_remote', user: $user);
    $publication->markMissingRemote('12345');
    $content->update(['status' => 'published', 'delivery_status' => 'missing_remote']);

    $response = actingAs($user)->get(route('app.content.show', $content));

    $response->assertOk();
    $response->assertSee('Attention Required');
    $response->assertSee('no longer exists');
});

it('renders delivery actions for delivered content', function () {
    $user = createDeliveryUITestUser();
    [$content, $publication] = createDeliveryUITestContext('delivered', user: $user);
    $publication->markDelivered('12345', 'https://example.com/post/12345');

    $response = actingAs($user)->get(route('app.content.show', $content));

    $response->assertOk();
    $response->assertSee('Republish to WordPress');
    $response->assertSee('Verify Remote Exists');
    $response->assertSee('Open in WordPress');
});

it('renders laravel delivery actions without wordpress ctas', function () {
    $user = createDeliveryUITestUser();
    [$content, $publication] = createDeliveryUITestContext(
        deliveryStatus: 'delivered',
        user: $user,
        siteType: ClientSite::TYPE_LARAVEL
    );
    $publication->markDelivered('article-123', 'https://example.com/knowledge/article-123');
    $content->update(['status' => 'published', 'publish_status' => 'published']);

    $response = actingAs($user)->get(route('app.content.show', $content));

    $response->assertOk();
    $response->assertSee('Republish to Laravel');
    $response->assertSee('Verify route exists');
    $response->assertSee('Open on site');
    $response->assertDontSee('Republish to WordPress');
    $response->assertDontSee('Open in WordPress');
});

it('renders laravel failures without stale wordpress error messaging', function () {
    $user = createDeliveryUITestUser();
    [$content, $publication] = createDeliveryUITestContext(
        deliveryStatus: 'failed',
        user: $user,
        siteType: ClientSite::TYPE_LARAVEL
    );

    $publication->markFailed('SYNC_FAILED', 'Laravel connector sync failed: Route rejected the payload.');
    $content->update([
        'publish_status' => 'failed',
        'publish_error' => 'Webhook failed, http 405, WordPress connector create endpoint was not found.',
        'delivery_status' => 'failed',
    ]);

    $response = actingAs($user)->get(route('app.content.show', $content));

    $response->assertOk();
    $response->assertSee('Laravel connector sync failed: Route rejected the payload.');
    $response->assertDontSee('WordPress connector create endpoint was not found.');
    $response->assertDontSee('Webhook failed, http 405');
});

it('prefers the laravel canonical publication over stale wordpress publication rows', function () {
    [$content, $publication] = createDeliveryUITestContext(
        deliveryStatus: 'failed',
        siteType: ClientSite::TYPE_LARAVEL
    );

    $publication->markFailed('SYNC_FAILED', 'Laravel connector sync failed.');

    ContentPublication::query()->create([
        'content_id' => $content->id,
        'client_site_id' => $content->client_site_id,
        'provider' => ContentPublication::PROVIDER_WORDPRESS,
        'locale' => SupportedLanguage::EN->value,
        'delivery_status' => 'failed',
        'last_error_code' => '405',
        'last_error_message' => 'WordPress connector create endpoint was not found.',
        'last_error_at' => now(),
    ]);

    $presenter = ContentStatusPresenter::for($content->fresh());

    expect($presenter->destinationType())->toBe(ClientSite::TYPE_LARAVEL)
        ->and($presenter->getPublication())->not->toBeNull()
        ->and((string) $presenter->getPublication()?->provider)->toBe(ContentPublication::PROVIDER_LARAVEL)
        ->and($presenter->lastErrorMessage())->toBe('Laravel connector sync failed.');
});

it('renders the draft tab without undefined variable errors for wordpress content and shows auto repush option', function () {
    $user = createDeliveryUITestUser();
    [$content] = createDeliveryUITestContext('pending', user: $user, siteType: ClientSite::TYPE_WORDPRESS);
    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $content->client_site_id,
        'content_id' => (string) $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Brief for WordPress draft',
        'language' => SupportedLanguage::EN->value,
        'output_type' => 'kb_article',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $content->client_site_id,
        'status' => 'ready',
        'title' => 'Draft for WordPress content',
        'language' => SupportedLanguage::EN->value,
        'content_html' => '<p>WordPress draft body</p>',
        'delivery_status' => 'pending',
    ]);

    $response = actingAs($user)->get(route('app.content.show', ['content' => $content, 'tab' => 'draft']));

    $response->assertOk();
    $response->assertSee('Auto repush to WordPress after regenerate');
    $response->assertDontSee('Undefined variable');
});

it('renders the draft tab without undefined variable errors for non-wordpress content and hides auto repush option', function () {
    $user = createDeliveryUITestUser();
    [$content] = createDeliveryUITestContext('pending', user: $user, siteType: ClientSite::TYPE_LARAVEL);
    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $content->client_site_id,
        'content_id' => (string) $content->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'Brief for Laravel draft',
        'language' => SupportedLanguage::EN->value,
        'output_type' => 'kb_article',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $content->client_site_id,
        'status' => 'ready',
        'title' => 'Draft for Laravel content',
        'language' => SupportedLanguage::EN->value,
        'content_html' => '<p>Laravel draft body</p>',
        'delivery_status' => 'pending',
    ]);

    $response = actingAs($user)->get(route('app.content.show', ['content' => $content, 'tab' => 'draft']));

    $response->assertOk();
    $response->assertDontSee('Auto repush to WordPress after regenerate');
    $response->assertDontSee('Undefined variable');
});

it('renders per-variant laravel publish actions in the language variants panel', function () {
    $user = createDeliveryUITestUser();
    [$source, $sourcePublication] = createDeliveryUITestContext('delivered', user: $user, siteType: ClientSite::TYPE_LARAVEL);

    $source->update([
        'language' => SupportedLanguage::NL->value,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => true,
        'status' => 'published',
        'publish_status' => 'published',
    ]);
    $sourcePublication?->markDelivered('nl-article', 'https://example.com/nl/article');

    $english = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $source->workspace_id,
        'client_site_id' => (string) $source->client_site_id,
        'content_destination_id' => (string) $source->content_destination_id,
        'title' => 'Localized EN Variant',
        'language' => SupportedLanguage::EN->value,
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'translation',
        'publish_status' => 'draft',
        'delivery_status' => 'pending',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $english->client_site_id,
        'content_id' => (string) $english->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'English publish brief',
        'language' => SupportedLanguage::EN->value,
        'output_type' => 'kb_article',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $english->id,
        'client_site_id' => (string) $english->client_site_id,
        'content_destination_id' => (string) $english->content_destination_id,
        'status' => 'ready',
        'title' => 'English variant draft',
        'language' => SupportedLanguage::EN->value,
        'content_html' => '<p>English variant body.</p>',
        'delivery_status' => 'pending',
    ]);

    $response = actingAs($user)->get(route('app.content.show', $source));

    $response->assertOk();
    $response->assertSee('Language Variants');
    $response->assertSee('SRC NL');
    $response->assertSee('Publish now');
    $response->assertSee('Schedule');
    $response->assertSee(route('app.content.publish-now', $source), false);
    $response->assertSee('name="locale" value="en"', false);
    $response->assertSee(route('app.content.schedule', $english), false);
});

it('renders publishing and update-live states per laravel language variant', function () {
    $user = createDeliveryUITestUser();
    [$source, $sourcePublication] = createDeliveryUITestContext('processing', user: $user, siteType: ClientSite::TYPE_LARAVEL);

    $source->update([
        'language' => SupportedLanguage::NL->value,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => true,
        'status' => 'draft',
        'publish_status' => 'publishing',
    ]);

    $english = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $source->workspace_id,
        'client_site_id' => (string) $source->client_site_id,
        'content_destination_id' => (string) $source->content_destination_id,
        'title' => 'Localized EN Variant',
        'language' => SupportedLanguage::EN->value,
        'translation_source_content_id' => (string) $source->id,
        'translation_source_locale' => SupportedLanguage::NL->value,
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'published',
        'source' => 'translation',
        'publish_status' => 'published',
        'delivery_status' => 'delivered',
    ]);

    ContentPublication::query()->create([
        'content_id' => (string) $english->id,
        'destination_id' => (string) $source->content_destination_id,
        'client_site_id' => (string) $source->client_site_id,
        'provider' => ContentPublication::PROVIDER_LARAVEL,
        'delivery_status' => 'delivered',
        'last_delivered_at' => now()->subHour(),
        'remote_id' => 'en-article',
        'remote_url' => 'https://example.com/en/article',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $english->client_site_id,
        'content_id' => (string) $english->id,
        'status' => 'done',
        'progress' => 1,
        'title' => 'English update brief',
        'language' => SupportedLanguage::EN->value,
        'output_type' => 'kb_article',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $english->id,
        'client_site_id' => (string) $english->client_site_id,
        'content_destination_id' => (string) $english->content_destination_id,
        'status' => 'ready',
        'title' => 'English updated draft',
        'language' => SupportedLanguage::EN->value,
        'content_html' => '<p>English variant updated body.</p>',
        'delivery_status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = actingAs($user)->get(route('app.content.show', $source));

    $response->assertOk();
    $response->assertSee('Publishing...');
    $response->assertSee('Update publish');
    $response->assertSee('Draft newer than live version');
});

// ============================================================================
// Integration: Verify Remote Route
// ============================================================================

it('verify remote route returns success for existing post', function () {
    $user = createDeliveryUITestUser();
    [$content, $publication] = createDeliveryUITestContext('delivered', user: $user);
    $publication->markDelivered('12345', 'https://example.com/post/12345');

    // This test would need mocking the WordPressConnector - skipping actual HTTP call
})->skip('Requires WordPressConnector mock for HTTP verification');

// ============================================================================
// Integration: Republish Route Consistency
// ============================================================================

it('republish action uses the correct route name', function () {
    [$content, $publication] = createDeliveryUITestContext('pending');
    $content->update(['status' => 'ready_to_deliver']);
    $presenter = ContentStatusPresenter::for($content->fresh());

    $actions = $presenter->deliveryActions();

    expect($actions)->toHaveKey('republish')
        ->and($actions['republish']['route'])->toBe('app.content.republish');
});

it('content detail page renders republish button with correct route for wordpress', function () {
    $user = createDeliveryUITestUser();
    [$content, $publication] = createDeliveryUITestContext('delivered', user: $user, siteType: ClientSite::TYPE_WORDPRESS);
    $publication->markDelivered('12345', 'https://example.com/post/12345');
    $content->update(['status' => 'published']);

    $response = test()->actingAs($user)->get(route('app.content.show', $content));

    $response->assertOk();
    $response->assertSee('/content/' . $content->id . '/republish');
    $response->assertDontSee('/content/' . $content->id . '/repush');
});

it('content detail page renders republish button with correct route for laravel', function () {
    $user = createDeliveryUITestUser();
    [$content, $publication] = createDeliveryUITestContext('delivered', user: $user, siteType: ClientSite::TYPE_LARAVEL);
    $publication->markDelivered('article-123', 'https://example.com/knowledge/article-123');
    $content->update(['status' => 'published']);

    $response = test()->actingAs($user)->get(route('app.content.show', $content));

    $response->assertOk();
    $response->assertSee('/content/' . $content->id . '/republish');
    $response->assertDontSee('/content/' . $content->id . '/repush');
});

it('republish route works for localized content variants', function () {
    $user = createDeliveryUITestUser();
    [$content, $publication] = createDeliveryUITestContext('delivered', user: $user, siteType: ClientSite::TYPE_LARAVEL);
    $publication->markDelivered('article-123', 'https://example.com/knowledge/article-123');
    $content->update([
        'status' => 'published',
        'language' => 'nl',
        'translation_source_locale' => 'en',
    ]);

    $response = test()->actingAs($user)->get(route('app.content.show', $content));

    $response->assertOk();
    $response->assertSee('/content/' . $content->id . '/republish');
});

// ============================================================================
// Helper Functions
// ============================================================================

function createDeliveryUITestContext(
    string $deliveryStatus = 'pending',
    ?User $user = null,
    bool $createPublication = true,
    string $siteType = ClientSite::TYPE_WORDPRESS,
): array {
    $user = $user ?? createDeliveryUITestUser();
    $organization = $user->organization;

    // Always use the organization's first workspace (created with the user)
    $workspace = Workspace::query()->where('organization_id', $organization->id)->first();
    if (! $workspace) {
        $workspace = Workspace::create([
            'name' => 'Test Workspace',
            'organization_id' => $organization->id,
        ]);
    }

    $suffix = Str::lower(Str::random(6));
    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => $siteType,
        'name' => 'Test Site ' . $suffix,
        'site_url' => 'https://test-' . $suffix . '.example.com',
        'base_url' => 'https://test-' . $suffix . '.example.com',
        'allowed_domains' => ['test-' . $suffix . '.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $provider = $siteType === ClientSite::TYPE_LARAVEL
        ? ContentPublication::PROVIDER_LARAVEL
        : ContentPublication::PROVIDER_WORDPRESS;

    $destination = ContentDestination::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => ucfirst($siteType) . ' Destination ' . $suffix,
        'type' => $siteType,
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
        'config' => [
            'billing_client_site_id' => (string) $site->id,
            'laravel_connector' => $siteType === ClientSite::TYPE_LARAVEL ? [
                'base_url' => 'https://test-' . $suffix . '.example.com',
                'site_id' => 'site-' . $suffix,
                'enabled' => true,
            ] : null,
        ],
    ]);

    $content = Content::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'content_destination_id' => $destination->id,
        'title' => 'Test Content ' . $suffix,
        'language' => SupportedLanguage::EN->value,
        'translation_source_locale' => SupportedLanguage::EN->value,
        'is_source_locale' => true,
        'primary_keyword' => 'test',
        'type' => 'article',
        'status' => 'draft',
        'source' => $siteType,
        'delivery_status' => $deliveryStatus,
    ]);

    $publication = null;
    if ($createPublication) {
        $publication = ContentPublication::create([
            'content_id' => $content->id,
            'destination_id' => $destination->id,
            'client_site_id' => $site->id,
            'provider' => $provider,
            'delivery_status' => $deliveryStatus,
        ]);
    }

    return [$content, $publication];
}

function createDeliveryUITestUser(): User
{
    $suffix = Str::lower(Str::random(6));

    $organization = Organization::create([
        'name' => 'UI Test Org ' . $suffix,
        'slug' => 'ui-test-org-' . $suffix,
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'UI Test Company',
        'billing_address_line1' => 'Test Street 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::create([
        'name' => 'UI Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'ui-test-plan'],
        [
            'name' => 'UI Test Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    return User::create([
        'name' => 'Test User',
        'email' => 'test' . $suffix . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'email_verified_at' => now(),
        'approved_at' => now(),
        'is_admin' => false,
    ]);
}

function actingAs(User $user)
{
    return test()->actingAs($user);
}
