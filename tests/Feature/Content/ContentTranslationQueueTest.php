<?php

use App\Jobs\TranslateDraftJob;
use App\Services\Content\ContentTranslationCoordinator;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentSeries;
use App\Models\ContentSeriesArticle;
use App\Models\ContentTranslation;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('queues content translation once, shows queued state, and reuses duplicate submit', function () {
    [$user, $content] = makeContentTranslationContext();

    Queue::fake();

    $result = app(ContentTranslationCoordinator::class)->queue($content, 'nl', (string) $user->id);

    $this->assertDatabaseHas('content_translations', [
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_QUEUED,
    ]);

    Queue::assertPushed(TranslateDraftJob::class, function (TranslateDraftJob $job) use ($content): bool {
        return $job->sourceDraftId !== ''
            && $job->targetLanguage === 'nl'
            && $job->translationRequestId !== null;
    });

    $target = app(ContentTranslationCoordinator::class)
        ->targetLocales($content)
        ->firstWhere('value', 'nl');

    expect($result['translation_request']->status)->toBe(ContentTranslation::STATUS_QUEUED)
        ->and($target['state'] ?? null)->toBe(ContentTranslation::STATUS_QUEUED)
        ->and($target['state_label'] ?? null)->toBe('Queued');

    $duplicate = app(ContentTranslationCoordinator::class)->queue($content, 'nl', (string) $user->id);

    expect((string) $duplicate['translation_request']->id)->toBe((string) $result['translation_request']->id)
        ->and($duplicate['translation_request']->status)->toBe(ContentTranslation::STATUS_QUEUED);

    Queue::assertPushed(TranslateDraftJob::class, 1);
});

it('retries a failed translation using the same row and existing locale content', function () {
    [$user, $content, $translatedContent] = makeContentTranslationContext(withTranslation: true);

    $translationRequest = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'target_content_id' => (string) $translatedContent->id,
        'status' => ContentTranslation::STATUS_FAILED,
        'requested_by_user_id' => $user->id,
        'error_message' => 'Previous translation failed',
    ]);

    Queue::fake();

    $beforeTarget = app(ContentTranslationCoordinator::class)
        ->targetLocales($content)
        ->firstWhere('value', 'nl');

    expect($beforeTarget['state'] ?? null)->toBe(ContentTranslation::STATUS_FAILED)
        ->and($beforeTarget['state_label'] ?? null)->toBe('Failed')
        ->and($beforeTarget['action'] ?? null)->toBe('retry');

    app(ContentTranslationCoordinator::class)->queue($content, 'nl', (string) $user->id);

    $translationRequest->refresh();

    expect($translationRequest->status)->toBe(ContentTranslation::STATUS_QUEUED)
        ->and($translationRequest->error_message)->toBeNull()
        ->and($translationRequest->target_content_id)->toBe((string) $translatedContent->id);

    Queue::assertPushed(TranslateDraftJob::class, function (TranslateDraftJob $job) use ($translatedContent): bool {
        return $job->targetContentId === (string) $translatedContent->id
            && $job->translationRequestId !== null;
    });

    $this->assertDatabaseCount('contents', 2);
});

it('queues a refresh when translated locale content already exists', function () {
    [$user, $content, $translatedContent] = makeContentTranslationContext(withTranslation: true);

    Queue::fake();

    $result = app(ContentTranslationCoordinator::class)->queue($content, 'nl', (string) $user->id);

    expect($result['mode'])->toBe('refresh')
        ->and((string) $result['existing_variant']->id)->toBe((string) $translatedContent->id)
        ->and($result['translation_request']->target_content_id)->toBe((string) $translatedContent->id);

    Queue::assertPushed(TranslateDraftJob::class, function (TranslateDraftJob $job) use ($translatedContent): bool {
        return $job->targetContentId === (string) $translatedContent->id
            && $job->translationRequestId !== null;
    });
});

it('marks the translation request as failed when the job throws on last retry', function () {
    [$user, $content] = makeContentTranslationContext(withCredits: false);

    $translationRequest = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_QUEUED,
        'requested_by_user_id' => $user->id,
    ]);

    $translationService = \Mockery::mock(\App\Services\Translation\TranslationService::class);
    $translationService->shouldReceive('resolveTargetVariantContent')->once()->andReturn(null);
    $translationService->shouldReceive('validateSourceDraft')->once();
    $translationService->shouldReceive('validateTargetLanguageAvailabilityForJob')->once();
    $translationService->shouldReceive('translate')->once()->andThrow(new RuntimeException('Translation provider failed'));

    $wallet = app(\App\Services\CreditWalletService::class);
    $wallet->addCredits((string) $content->client_site_id, 25, \App\Services\CreditWalletService::TYPE_ALLOWANCE);

    $asyncOperationService = \Mockery::mock(\App\Services\Integrations\AsyncOperationService::class);
    $webhookPublisher = \Mockery::mock(\App\Services\Integrations\ApiWebhookPublisher::class);
    $webhookPublisher->shouldNotReceive('publish');
    $contentLifecycleService = app(\App\Services\Content\ContentLifecycleService::class);
    $automationItemState = app(\App\Services\ContentAutomation\AutomationRunItemStateService::class);

    $job = new TranslateDraftJob(
        sourceDraftId: (string) $content->drafts()->latest('created_at')->firstOrFail()->id,
        targetLanguage: 'nl',
        userId: (string) $user->id,
        translationRequestId: (string) $translationRequest->id,
    );

    // Simulate this being the last retry attempt by setting tries to 1
    $job->tries = 1;

    try {
        $job->handle(
            $translationService,
            $wallet,
            $asyncOperationService,
            $webhookPublisher,
            $contentLifecycleService,
            $automationItemState,
            app(\App\Services\Content\TranslationLockService::class)
        );
        $this->fail('Expected translation job to throw.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Translation provider failed');
    }

    $this->assertDatabaseHas('content_translations', [
        'id' => (string) $translationRequest->id,
        'status' => ContentTranslation::STATUS_FAILED,
        'error_message' => 'Translation provider failed',
    ]);
});

it('does not dispatch a translation job when credits are insufficient before queueing', function () {
    [$user, $content] = makeContentTranslationContext(withCredits: false);

    Queue::fake();

    try {
        app(ContentTranslationCoordinator::class)->queue($content, 'nl', (string) $user->id);
        $this->fail('Expected insufficient credits to block translation dispatch.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Not enough credits to translate this article. Required: 6, available: 0.');
    }

    Queue::assertNothingPushed();

    $translationRequest = ContentTranslation::query()
        ->where('content_id', (string) $content->id)
        ->where('target_locale', 'nl')
        ->firstOrFail();

    expect($translationRequest->status)->toBe(ContentTranslation::STATUS_FAILED)
        ->and($translationRequest->displayStatus())->toBe(ContentTranslation::STATUS_INSUFFICIENT_CREDITS)
        ->and($translationRequest->failure_reason)->toBe(ContentTranslation::FAILURE_REASON_INSUFFICIENT_CREDITS)
        ->and($translationRequest->processing_job_uuid)->toBeNull()
        ->and($translationRequest->required_credits)->toBe(6)
        ->and($translationRequest->available_credits)->toBe(0);
});

it('allows retry after credits are added following an insufficient credits failure', function () {
    [$user, $content] = makeContentTranslationContext(withCredits: false);

    Queue::fake();

    try {
        app(ContentTranslationCoordinator::class)->queue($content, 'nl', (string) $user->id);
        $this->fail('Expected insufficient credits to block the first translation attempt.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Not enough credits to translate this article. Required: 6, available: 0.');
    }

    app(\App\Services\CreditWalletService::class)->addCredits(
        (string) $content->client_site_id,
        25,
        \App\Services\CreditWalletService::TYPE_ALLOWANCE
    );

    app(ContentTranslationCoordinator::class)->queue($content, 'nl', (string) $user->id);

    Queue::assertPushed(TranslateDraftJob::class, 1);

    $translationRequest = ContentTranslation::query()
        ->where('content_id', (string) $content->id)
        ->where('target_locale', 'nl')
        ->firstOrFail();

    expect($translationRequest->status)->toBe(ContentTranslation::STATUS_QUEUED)
        ->and($translationRequest->failure_reason)->toBeNull()
        ->and($translationRequest->required_credits)->toBeNull()
        ->and($translationRequest->available_credits)->toBeNull();
});

it('allows retry when a processing translation lock is stale', function () {
    [$user, $content] = makeContentTranslationContext();

    config()->set('translation.processing_lock_ttl_seconds', 60);

    $translationRequest = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_PROCESSING,
        'requested_by_user_id' => $user->id,
    ]);

    $translationRequest->forceFill([
        'updated_at' => now()->subMinutes(10),
        'created_at' => now()->subMinutes(10),
    ])->saveQuietly();

    Queue::fake();

    $beforeTarget = app(ContentTranslationCoordinator::class)
        ->targetLocales($content)
        ->firstWhere('value', 'nl');

    expect($beforeTarget['state'] ?? null)->toBe('stale_recovered')
        ->and($beforeTarget['state_label'] ?? null)->toBe('Stale recovered')
        ->and($beforeTarget['action'] ?? null)->toBe('retry')
        ->and($beforeTarget['verb'] ?? null)->toBe('Retry translation');

    app(ContentTranslationCoordinator::class)->queue($content, 'nl', (string) $user->id);

    $translationRequest->refresh();

    expect($translationRequest->status)->toBe(ContentTranslation::STATUS_QUEUED)
        ->and($translationRequest->error_message)->toBeNull();

    Queue::assertPushed(TranslateDraftJob::class, function (TranslateDraftJob $job) use ($translationRequest): bool {
        return $job->translationRequestId === (string) $translationRequest->id
            && $job->targetLanguage === 'nl';
    });
});

it('allows retry when a failed translation still reports already processing with no job reference', function () {
    [$user, $content] = makeContentTranslationContext();

    config()->set('translation.processing_lock_ttl_seconds', 60);

    $translationRequest = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_FAILED,
        'requested_by_user_id' => $user->id,
        'job_id' => null,
        'error_message' => "A translation to 'Dutch' is already processing.",
    ]);

    $translationRequest->forceFill([
        'updated_at' => now()->subMinutes(20),
        'created_at' => now()->subMinutes(20),
    ])->saveQuietly();

    Queue::fake();

    app(ContentTranslationCoordinator::class)->queue($content, 'nl', (string) $user->id);

    $translationRequest->refresh();

    expect($translationRequest->status)->toBe(ContentTranslation::STATUS_QUEUED)
        ->and($translationRequest->job_id)->toBeNull()
        ->and($translationRequest->error_message)->toBeNull();

    Queue::assertPushed(TranslateDraftJob::class, 1);
});

it('removes duplicate queued jobs before dispatching a fresh translation job', function () {
    [$user, $content] = makeContentTranslationContext();

    $translationRequest = ContentTranslation::query()->create([
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_FAILED,
        'requested_by_user_id' => $user->id,
        'error_message' => 'Previous failure',
    ]);

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => encodeQueuedTranslationJobPayload(new TranslateDraftJob(
            sourceDraftId: (string) $content->drafts()->latest('created_at')->firstOrFail()->id,
            targetLanguage: 'nl',
            userId: (string) $user->id,
            translationRequestId: (string) $translationRequest->id,
            sourceContentId: (string) $content->id,
        )),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    Queue::fake();

    app(ContentTranslationCoordinator::class)->queue($content, 'nl', (string) $user->id);

    Queue::assertPushed(TranslateDraftJob::class, 1);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and($translationRequest->fresh()->status)->toBe(ContentTranslation::STATUS_QUEUED);
});

it('queues translations for all generated source articles in a series', function () {
    [$user, $content] = makeContentTranslationContext();
    $this->withoutMiddleware(\App\Http\Middleware\EnsureBillingOnboardingCompleted::class);
    $user->forceFill([
        'is_admin' => false,
        'approved_at' => now(),
        'active' => true,
    ])->save();

    $series = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $user->organization_id,
        'site_id' => (string) $content->client_site_id,
        'name' => 'Translation series',
        'main_topic' => 'Agentic marketing',
        'primary_keyword' => 'agentic marketing',
        'supporting_keywords' => [],
        'intent_keys' => [],
        'articles_count' => 2,
        'content_type' => 'post',
        'status' => ContentSeries::STATUS_PUBLISHED,
        'is_locked' => true,
        'created_by' => $user->id,
    ]);

    attachContentToSeriesForTranslation($content, $series, 1);
    $secondContent = makeSeriesTranslationArticle($content, 'Second source content');
    attachContentToSeriesForTranslation($secondContent, $series, 2);

    Queue::fake();

    $response = $this
        ->actingAs($user)
        ->post(route('app.content.series.translate', $series), [
            'target_locale' => 'nl',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('status', 'Queued 2/2 article translation(s) to Dutch.');

    $this->assertDatabaseHas('content_translations', [
        'content_id' => (string) $content->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_QUEUED,
    ]);
    $this->assertDatabaseHas('content_translations', [
        'content_id' => (string) $secondContent->id,
        'target_locale' => 'nl',
        'status' => ContentTranslation::STATUS_QUEUED,
    ]);

    Queue::assertPushed(TranslateDraftJob::class, 2);
});

function makeContentTranslationContext(bool $withTranslation = false, bool $withCredits = true): array
{
    $user = User::query()->create([
        'name' => 'Translation Queue User',
        'email' => 'translation-queue-' . Str::random(8) . '@example.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $organization = Organization::query()->create([
        'name' => 'Translation Queue Org',
        'slug' => 'translation-queue-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'primary_user_id' => $user->id,
    ]);

    $user->organization_id = $organization->id;
    $user->role = 'owner';
    $user->save();

    $workspace = Workspace::query()->create([
        'name' => 'Translation Queue Workspace',
        'organization_id' => $organization->id,
        'default_content_language' => 'en',
        'enabled_content_languages' => ['en', 'nl'],
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Translation Queue Site',
        'site_url' => 'https://translation-queue.example.com',
        'allowed_domains' => ['translation-queue.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'family_id' => null,
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

    $sourceDraft = Draft::query()->create([
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

    if (! $withTranslation) {
        return [$user, $content];
    }

    $translatedContent = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'family_id' => $content->id,
        'title' => 'Translated content',
        'language' => 'nl',
        'translation_source_content_id' => $content->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'origin_type' => 'manual',
        'delivery_status' => 'pending',
        'publish_status' => 'draft',
    ]);

    return [$user, $content, $translatedContent];
}

function makeSeriesTranslationArticle(Content $templateContent, string $title): Content
{
    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $templateContent->workspace_id,
        'client_site_id' => $templateContent->client_site_id,
        'family_id' => null,
        'title' => $title,
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
        'client_site_id' => $templateContent->client_site_id,
        'content_id' => $content->id,
        'status' => 'done',
        'source' => 'client_ui',
        'progress' => 1,
        'title' => $title . ' brief',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'source keyword',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $templateContent->client_site_id,
        'status' => 'ready',
        'title' => $title,
        'language' => 'en',
        'draft_type' => 'original',
        'output_type' => 'kb_article',
        'content_html' => '<h1>' . e($title) . '</h1><p>Original text.</p>',
        'seo_title' => $title,
        'seo_meta_description' => 'Source description',
    ]);

    return $content;
}

function attachContentToSeriesForTranslation(Content $content, ContentSeries $series, int $articleNumber): void
{
    $content->forceFill([
        'series_id' => (string) $series->id,
    ])->save();

    ContentSeriesArticle::query()->create([
        'id' => (string) Str::uuid(),
        'series_id' => (string) $series->id,
        'content_id' => (string) $content->id,
        'article_number' => $articleNumber,
        'title' => $content->title,
        'primary_keyword' => 'agentic marketing',
        'secondary_keywords' => [],
        'internal_links_to' => [],
        'is_pillar' => $articleNumber === 1,
        'meta' => [],
    ]);
}

function encodeQueuedTranslationJobPayload(TranslateDraftJob $job): string
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
