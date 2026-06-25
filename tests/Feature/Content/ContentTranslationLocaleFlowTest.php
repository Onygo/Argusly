<?php

use App\Jobs\TranslateDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentTranslation;
use App\Models\ContentVersion;
use App\Models\MarketingBlogRedirect;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Draft;
use App\Models\TranslationDebugEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Translation\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeContentTranslationLocaleContext(bool $withCredits = true): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Translation Org',
        'slug' => 'content-translation-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Content Translation BV',
        'billing_address_line1' => 'Straat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Content Translation Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'nl',
        'enabled_content_languages' => ['nl', 'en'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Content Translation Site',
        'site_url' => 'https://content-translation.example.com',
        'allowed_domains' => ['content-translation.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'content-translation-plan'],
        [
            'name' => 'Content Translation Plan',
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

    $user = User::query()->create([
        'name' => 'Content Translation User',
        'email' => 'content-translation-' . Str::random(6) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    if ($withCredits) {
        \App\Models\CreditWallet::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => (string) $site->id,
            'workspace_id' => (string) $workspace->id,
            'balance_cached' => 25,
            'reserved_cached' => 0,
            'used_cached' => 0,
        ]);
    }

    return [$workspace, $site, $user];
}

function makeMigratedDutchSourceContent(Workspace $workspace, ClientSite $site, User $user): array
{
    $slug = 'legacy-nederlandse-bron';

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Nederlandse bron voor vertaling',
        'language' => 'nl',
        'translation_source_locale' => 'nl',
        'is_source_locale' => true,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'external_key' => (string) Str::uuid(),
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
        'publish_url_key' => $slug,
        'created_by' => (int) $user->id,
        'updated_by' => (int) $user->id,
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'revision',
        'body' => '<p>Dit is de Nederlandse broncontent die vertaald moet worden.</p>',
        'meta' => [
            'excerpt' => 'Nederlandse broncontent.',
            'slug' => $slug,
        ],
        'source' => 'pl',
        'created_by' => (int) $user->id,
    ]);

    $content->forceFill([
        'current_version_id' => (string) $version->id,
    ])->save();

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'created_by_user_id' => (int) $user->id,
        'content_id' => (string) $content->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Legacy translated brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $staleDraft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'status' => 'ready',
        'title' => 'Legacy draft with stale locale',
        'language' => 'en',
        'draft_type' => 'original',
        'output_type' => 'kb_article',
        'content_html' => '<p>This legacy draft incorrectly claims English.</p>',
    ]);

    MarketingBlogRedirect::query()->create([
        'source_path' => '/en/blog/' . $slug,
        'source_locale' => 'en',
        'source_slug' => $slug,
        'target_path' => '/nl/blog/' . $slug,
        'target_locale' => 'nl',
        'target_slug' => $slug,
        'target_content_id' => (string) $content->id,
        'redirect_kind' => 'legacy_locale_mismatch',
        'is_active' => true,
    ]);

    return [$content->fresh(['currentVersion', 'brief']), $staleDraft->fresh(), $brief->fresh()];
}

function makePublishedDutchSourceContentWithStaleDraft(Workspace $workspace, ClientSite $site, User $user, bool $withRenderableVersion = true): array
{
    $slug = 'gepubliceerde-nederlandse-bron';

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Gepubliceerde Nederlandse bron',
        'language' => 'nl',
        'translation_source_locale' => 'nl',
        'is_source_locale' => true,
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'external_key' => (string) Str::uuid(),
        'delivery_status' => 'delivered',
        'publish_status' => 'published',
        'publish_url_key' => $slug,
        'created_by' => (int) $user->id,
        'updated_by' => (int) $user->id,
    ]);

    if ($withRenderableVersion) {
        $version = ContentVersion::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => (string) $content->id,
            'type' => ContentVersion::TYPE_PUBLISHED_SNAPSHOT,
            'body' => '<p>Gepubliceerde Nederlandse broncontent voor vertaling.</p>',
            'meta' => [
                'excerpt' => 'Gepubliceerde Nederlandse broncontent.',
                'slug' => $slug,
            ],
            'source' => 'pl',
            'created_by' => (int) $user->id,
        ]);

        $content->forceFill([
            'current_version_id' => (string) $version->id,
        ])->save();
    }

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'created_by_user_id' => (int) $user->id,
        'content_id' => (string) $content->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Published source brief',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $staleDraft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'status' => 'processing',
        'title' => 'Stale processing source draft',
        'language' => 'nl',
        'draft_type' => 'original',
        'output_type' => 'kb_article',
        'content_html' => '<p>Oude draftinhoud die niet leidend mag zijn.</p>',
    ]);

    return [$content->fresh(['currentVersion', 'brief']), $staleDraft->fresh(), $brief->fresh()];
}

function makeEnglishMarkedButDutchSourceContent(Workspace $workspace, ClientSite $site, User $user): array
{
    $dutchBody = '<p>Dit is een uitgebreide Nederlandse brontekst voor de automatische locale-correctie. '
        .'We hebben deze tekst nodig omdat de inhoud duidelijk in het Nederlands is geschreven en veel '
        .'woorden bevat zoals de, het, een, deze, onze, omdat, met, voor, naar en zonder. '
        .'De inhoud legt uit hoe teams vandaag betere content maken en waarom deze bron eerst naar NL moet worden gezet.</p>';

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'Nederlandse bron met verkeerde locale',
        'language' => 'en',
        'translation_source_locale' => null,
        'is_source_locale' => true,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'external_key' => (string) Str::uuid(),
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
        'created_by' => (int) $user->id,
        'updated_by' => (int) $user->id,
    ]);

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'type' => 'revision',
        'body' => $dutchBody,
        'meta' => [
            'excerpt' => 'Nederlandse brontekst.',
        ],
        'source' => 'pl',
        'created_by' => (int) $user->id,
    ]);

    $content->forceFill([
        'current_version_id' => (string) $version->id,
    ])->save();

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'created_by_user_id' => (int) $user->id,
        'content_id' => (string) $content->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => 'Nederlandse brief met verkeerde locale',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'status' => 'ready',
        'title' => 'Nederlandse draft met verkeerde locale',
        'language' => 'en',
        'draft_type' => 'original',
        'output_type' => 'kb_article',
        'content_html' => $dutchBody,
        'meta' => [
            'language' => 'en',
        ],
    ]);

    return [$content->fresh(['currentVersion', 'brief', 'drafts']), $brief->fresh(), $draft->fresh()];
}

it('renders an explicit translate en target on the content detail view independent of ui locale', function () {
    [$workspace, $site, $user] = makeContentTranslationLocaleContext();
    [$content] = makeMigratedDutchSourceContent($workspace, $site, $user);

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $content, 'lang' => 'nl']))
        ->assertOk()
        ->assertSee('Language: NL')
        ->assertSee('Legacy redirect')
        ->assertSee('Historical locale route redirects to the canonical NL URL.')
        ->assertSee('/en/blog/legacy-nederlandse-bron → /nl/blog/legacy-nederlandse-bron')
        ->assertDontSee('Legacy locale migration active.')
        ->assertSee('Translate')
        ->assertSee('name="target_locale" value="en"', false)
        ->assertDontSee('name="target_locale" value="nl"', false);
});

it('queues nl to en translation from migrated content using normalized explicit target locale', function () {
    Bus::fake();
    config()->set('translation.queue.name', 'translations-debug');
    config()->set('translation.queue.connection', 'database');

    [$workspace, $site, $user] = makeContentTranslationLocaleContext();
    [$content, $staleDraft, $brief] = makeMigratedDutchSourceContent($workspace, $site, $user);

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $content, 'lang' => 'nl']))
        ->post(route('app.content.translate', $content), [
            'target_locale' => 'en-US',
        ])
        ->assertRedirect(route('app.content.show', ['content' => $content, 'lang' => 'nl']))
        ->assertSessionHas('status', 'Translation queued for English.');

    $translationService = app(TranslationService::class);

    Bus::assertDispatched(TranslateDraftJob::class, function (TranslateDraftJob $job) use ($translationService): bool {
        $queuedSourceDraft = Draft::query()->find($job->sourceDraftId);

        return $queuedSourceDraft instanceof Draft
            && $job->targetLanguage === 'en'
            && $job->queue === 'translations-debug'
            && $job->connection === 'database'
            && $translationService->resolveSourceLanguage($queuedSourceDraft)->value === 'nl';
    });
});

it('does not present legacy redirect state as a language variant status on a real translated variant', function () {
    [$workspace, $site, $user] = makeContentTranslationLocaleContext();
    [$source] = makeMigratedDutchSourceContent($workspace, $site, $user);

    $variant = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'English translation',
        'language' => 'en',
        'translation_source_content_id' => (string) $source->id,
        'translation_source_version_id' => (string) $source->current_version_id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'translation',
        'external_key' => (string) Str::uuid(),
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
        'created_by' => (int) $user->id,
        'updated_by' => (int) $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $variant]))
        ->assertOk()
        ->assertSee('Language: EN')
        ->assertSee('(Source: NL)')
        ->assertDontSee('Legacy redirect')
        ->assertDontSee('/en/blog/legacy-nederlandse-bron → /nl/blog/legacy-nederlandse-bron');
});

it('blocks true same-language translations after locale normalization on migrated content', function () {
    Bus::fake();

    [$workspace, $site, $user] = makeContentTranslationLocaleContext();
    [$content] = makeMigratedDutchSourceContent($workspace, $site, $user);

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $content, 'lang' => 'nl']))
        ->post(route('app.content.translate', $content), [
            'target_locale' => 'nl-NL',
        ])
        ->assertRedirect(route('app.content.show', ['content' => $content, 'lang' => 'nl']))
        ->assertSessionHasErrors(['translation']);

    expect(session('errors')->first('translation'))->toBe('Cannot translate draft to the same language.');

    Bus::assertNotDispatched(TranslateDraftJob::class);
});

it('queues translation for published content by bootstrapping from the published content version when the latest draft is stale', function () {
    Bus::fake();
    config()->set('translation.queue.name', 'translations-debug');
    config()->set('translation.queue.connection', 'database');

    [$workspace, $site, $user] = makeContentTranslationLocaleContext();
    [$content] = makePublishedDutchSourceContentWithStaleDraft($workspace, $site, $user);

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $content, 'lang' => 'nl']))
        ->post(route('app.content.translate', $content), [
            'target_locale' => 'en',
        ])
        ->assertRedirect(route('app.content.show', ['content' => $content, 'lang' => 'nl']))
        ->assertSessionHas('status', 'Translation queued for English.');

    Bus::assertDispatched(TranslateDraftJob::class, function (TranslateDraftJob $job) use ($content): bool {
        $queuedSourceDraft = Draft::query()->find($job->sourceDraftId);

        return $queuedSourceDraft instanceof Draft
            && (string) $queuedSourceDraft->content_id === (string) $content->id
            && (string) $queuedSourceDraft->status === 'ready'
            && (string) data_get($queuedSourceDraft->meta, 'translation_source_type') === 'published'
            && (string) data_get($queuedSourceDraft->meta, 'bootstrap_reason') === 'translation_source_from_published_content'
            && str_contains((string) $queuedSourceDraft->content_html, 'Gepubliceerde Nederlandse broncontent');
    });
});

it('blocks translation when published content has no usable draft or renderable content version', function () {
    Bus::fake();

    [$workspace, $site, $user] = makeContentTranslationLocaleContext(withCredits: false);
    [$content] = makePublishedDutchSourceContentWithStaleDraft($workspace, $site, $user, withRenderableVersion: false);

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $content, 'lang' => 'nl']))
        ->post(route('app.content.translate', $content), [
            'target_locale' => 'en',
        ])
        ->assertRedirect(route('app.content.show', ['content' => $content, 'lang' => 'nl']))
        ->assertSessionHasErrors(['translation']);

    expect(session('errors')->first('translation'))
        ->toBe('No usable translation source is available. Argusly could not find a current delivered/published content version with body content to translate.');

    Bus::assertNotDispatched(TranslateDraftJob::class);
});

it('does not queue a duplicate root-locale translation when translating from a child locale variant', function () {
    Bus::fake();

    [$workspace, $site, $user] = makeContentTranslationLocaleContext(withCredits: false);
    [$source] = makeMigratedDutchSourceContent($workspace, $site, $user);

    $variant = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'English translation child',
        'language' => 'en',
        'translation_source_content_id' => (string) $source->id,
        'translation_source_version_id' => (string) $source->current_version_id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'translation',
        'external_key' => (string) Str::uuid(),
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
        'created_by' => (int) $user->id,
        'updated_by' => (int) $user->id,
    ]);

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $variant, 'lang' => 'en']))
        ->post(route('app.content.translate', $variant), [
            'target_locale' => 'nl',
        ])
        ->assertRedirect(route('app.content.show', ['content' => $variant, 'lang' => 'en']))
        ->assertSessionHasErrors(['translation']);

    expect(session('errors')->first('translation'))->toBe('Cannot translate draft to the same language.');

    Bus::assertNotDispatched(TranslateDraftJob::class);
});

it('auto-corrects an english source to dutch before queueing english translation', function () {
    Bus::fake();

    [$workspace, $site, $user] = makeContentTranslationLocaleContext();
    [$content, $brief, $draft] = makeEnglishMarkedButDutchSourceContent($workspace, $site, $user);

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $content]))
        ->post(route('app.content.translate', $content), [
            'target_locale' => 'en',
        ])
        ->assertRedirect(route('app.content.show', ['content' => $content]))
        ->assertSessionHas('status', 'Translation queued for English.');

    expect($content->fresh()->localeCode())->toBe('nl')
        ->and((string) $brief->fresh()->language)->toBe('nl')
        ->and($draft->fresh()->language->value)->toBe('nl')
        ->and((string) data_get($draft->fresh()->meta, 'language'))->toBe('nl');

    Bus::assertDispatched(TranslateDraftJob::class, function (TranslateDraftJob $job): bool {
        $queuedSourceDraft = Draft::query()->find($job->sourceDraftId);

        return $job->targetLanguage === 'en'
            && $queuedSourceDraft instanceof Draft
            && $queuedSourceDraft->language->value === 'nl';
    });
});

it('fixes locale to nl and queues an english regeneration from the insights action', function () {
    Bus::fake();

    [$workspace, $site, $user] = makeContentTranslationLocaleContext();
    [$content, $brief, $draft] = makeEnglishMarkedButDutchSourceContent($workspace, $site, $user);

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $content]))
        ->post(route('app.content.convert-to-nl-and-regenerate-en', $content))
        ->assertRedirect(route('app.content.show', ['content' => $content]))
        ->assertSessionHas('status');

    expect($content->fresh()->localeCode())->toBe('nl')
        ->and((bool) $content->fresh()->is_source_locale)->toBeTrue()
        ->and((string) $brief->fresh()->language)->toBe('nl')
        ->and($draft->fresh()->language->value)->toBe('nl');

    Bus::assertDispatched(TranslateDraftJob::class, function (TranslateDraftJob $job): bool {
        return $job->targetLanguage === 'en';
    });
});

it('shows failed and stale translation states with retry actions on the content detail page', function () {
    [$workspace, $site, $user] = makeContentTranslationLocaleContext();
    [$content] = makeMigratedDutchSourceContent($workspace, $site, $user);

    ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'en',
        'status' => ContentTranslation::STATUS_FAILED,
        'requested_by_user_id' => $user->id,
        'error_message' => 'Provider timeout from translation backend',
    ]);

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $content]))
        ->assertOk()
        ->assertSee('Translation Monitor')
        ->assertSee('English translation failed. Last error: Provider timeout from translation backend')
        ->assertSee('Retry translation');

    config()->set('translation.processing_lock_ttl_seconds', 60);

    ContentTranslation::query()
        ->where('content_id', (string) $content->id)
        ->where('target_locale', 'en')
        ->update([
            'status' => ContentTranslation::STATUS_PROCESSING,
            'error_message' => null,
            'processing_started_at' => now()->subHours(2),
            'processing_last_heartbeat_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
            'created_at' => now()->subHours(2),
        ]);

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $content]))
        ->assertOk()
        ->assertSee('English translation lock looks stale. You can clear it and retry.')
        ->assertSee('Retry translation')
        ->assertDontSee('Translation debug')
        ->assertDontSee('A translation to Dutch is already processing');
});

it('does not show a stale lock warning for completed translations with an old heartbeat', function () {
    [$workspace, $site, $user] = makeContentTranslationLocaleContext();
    [$content] = makeMigratedDutchSourceContent($workspace, $site, $user);

    $translated = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'English translation draft',
        'language' => 'en',
        'translation_source_content_id' => (string) $content->id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'translation',
        'external_key' => (string) Str::uuid(),
        'delivery_status' => 'pending',
    ]);

    ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_content_id' => (string) $translated->id,
        'target_locale' => 'en',
        'status' => ContentTranslation::STATUS_COMPLETED,
        'requested_by_user_id' => $user->id,
        'processing_job_uuid' => (string) Str::uuid(),
        'processing_started_at' => now()->subHour(),
        'processing_last_heartbeat_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
        'created_at' => now()->subHour(),
    ]);

    config()->set('translation.processing_lock_ttl_seconds', 60);

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $content]))
        ->assertOk()
        ->assertSee('English translation completed.')
        ->assertDontSee('Stale lock warning')
        ->assertDontSee('translation lock looks stale')
        ->assertDontSee('Retry translation');
});

it('renders translation monitor technical details and recent events in a compact view', function () {
    [$workspace, $site, $user] = makeContentTranslationLocaleContext();
    [$content] = makeMigratedDutchSourceContent($workspace, $site, $user);

    $translated = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => 'English translation draft',
        'language' => 'en',
        'translation_source_content_id' => (string) $content->id,
        'translation_source_locale' => 'nl',
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'translation',
        'external_key' => (string) Str::uuid(),
        'delivery_status' => 'pending',
    ]);

    ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_content_id' => (string) $translated->id,
        'target_locale' => 'en',
        'status' => ContentTranslation::STATUS_QUEUED,
        'requested_by_user_id' => $user->id,
        'processing_job_uuid' => (string) Str::uuid(),
    ]);

    TranslationDebugEvent::query()->create([
        'trace_id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'locale' => 'en',
        'event_type' => 'STATE_SNAPSHOT',
        'message' => 'Translation state queued before dispatch.',
        'payload' => ['queue_state' => 'queued'],
    ]);

    TranslationDebugEvent::query()->create([
        'trace_id' => (string) Str::uuid(),
        'content_id' => (string) $content->id,
        'locale' => 'en',
        'event_type' => 'DISPATCHED',
        'message' => 'Translation job dispatched.',
        'payload' => ['queue_state' => 'queued'],
    ]);

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $content]))
        ->assertOk()
        ->assertSee('Translation Monitor')
        ->assertSee('Current queue, lock and recent lifecycle activity')
        ->assertSee('English translation is queued and waiting for a worker.')
        ->assertSee('Technical details')
        ->assertSee('Recent events')
        ->assertSee('STATE_SNAPSHOT')
        ->assertSee('Translation job dispatched.')
        ->assertSee('Open translated draft')
        ->assertSee('Refresh status')
        ->assertSee('View raw debug')
        ->assertDontSee('Current lock state')
        ->assertDontSee('Last 20 translation events');
});

it('renders the content detail page as an operational workspace shell', function () {
    [$workspace, $site, $user] = makeContentTranslationLocaleContext();
    [$content] = makeMigratedDutchSourceContent($workspace, $site, $user);

    $response = $this->actingAs($user)->get(route('app.content.show', ['content' => $content]));

    $response->assertOk()
        ->assertSee('Editorial Header')
        ->assertSee('Content Health Trend')
        ->assertSee('Performance Snapshot')
        ->assertSee('Content Workflow Timeline')
        ->assertSee('Localization Operations')
        ->assertSee('AI Assistant Panel')
        ->assertSee('Recommended Actions')
        ->assertSee('Run AI checks')
        ->assertSee('Developer diagnostics')
        ->assertSee('Open Draft');
});

it('shows insufficient credits as a billing state instead of a stale translation state', function () {
    [$workspace, $site, $user] = makeContentTranslationLocaleContext();
    [$content] = makeMigratedDutchSourceContent($workspace, $site, $user);

    ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'en',
        'status' => ContentTranslation::STATUS_FAILED,
        'failure_reason' => ContentTranslation::FAILURE_REASON_INSUFFICIENT_CREDITS,
        'required_credits' => 6,
        'available_credits' => 0,
        'requested_by_user_id' => $user->id,
        'error_message' => 'Not enough credits to translate this article. Required: 6, available: 0.',
    ]);

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $content]))
        ->assertOk()
        ->assertSee('Not enough credits')
        ->assertSee('Required: 6 credits')
        ->assertSee('Available: 0 credits')
        ->assertSee('Buy credits')
        ->assertSee('Upgrade plan')
        ->assertSee('Retry after adding credits')
        ->assertDontSee('Stale recovered')
        ->assertDontSee('already processing');
});

it('blocks translation before dispatch when credits are unavailable and allows retry after credits are added', function () {
    Bus::fake();

    [$workspace, $site, $user] = makeContentTranslationLocaleContext(withCredits: false);
    [$content] = makeMigratedDutchSourceContent($workspace, $site, $user);

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $content, 'lang' => 'nl']))
        ->post(route('app.content.translate', $content), [
            'target_locale' => 'en',
        ])
        ->assertRedirect(route('app.content.show', ['content' => $content, 'lang' => 'nl']))
        ->assertSessionHasErrors(['translation']);

    expect(session('errors')->first('translation'))
        ->toBe('Not enough credits to translate this article. Required: 6, available: 0.');

    Bus::assertNotDispatched(TranslateDraftJob::class);

    $translationRequest = ContentTranslation::query()
        ->where('content_id', (string) $content->id)
        ->where('target_locale', 'en')
        ->firstOrFail();

    expect($translationRequest->displayStatus())->toBe(ContentTranslation::STATUS_INSUFFICIENT_CREDITS)
        ->and($translationRequest->processing_job_uuid)->toBeNull();

    app(\App\Services\CreditWalletService::class)->addCredits(
        (string) $content->client_site_id,
        25,
        \App\Services\CreditWalletService::TYPE_ALLOWANCE
    );

    $this->actingAs($user)
        ->from(route('app.content.show', ['content' => $content, 'lang' => 'nl']))
        ->post(route('app.content.translate', $content), [
            'target_locale' => 'en',
        ])
        ->assertRedirect(route('app.content.show', ['content' => $content, 'lang' => 'nl']))
        ->assertSessionHas('status', 'Translation queued for English.');

    Bus::assertDispatched(TranslateDraftJob::class, 1);
});
