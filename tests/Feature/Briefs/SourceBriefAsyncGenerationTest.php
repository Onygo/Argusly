<?php

use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Jobs\GenerateSourceBriefJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentSource;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
    Queue::fake();
});

function makeAsyncTestContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Async Test Org',
        'slug' => 'async-test-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Async Test Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Async Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Async Test Site',
        'site_url' => 'https://async-test.example.com',
        'allowed_domains' => ['async-test.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'async-test-plan'],
        [
            'name' => 'Async Test Plan',
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
        'name' => 'Async Test User',
        'email' => 'async-test+' . Str::random(5) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$organization, $workspace, $site, $user];
}

function sampleAsyncArticleHtml(string $title = 'What is Answer Engine Optimization?'): string
{
    return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <title>{$title}</title>
    <meta name="description" content="A practical explanation of answer engine optimization for modern search and AI systems.">
</head>
<body>
    <article>
        <h1>{$title}</h1>
        <p>Answer engine optimization helps brands become the answer in AI systems, search experiences, and assistant results.</p>
        <p>This article explains how structured content, entity coverage, direct answers, and content design shape AI visibility.</p>
        <h2>Why answer-first content matters</h2>
        <p>Answer-first content improves scanability for readers and helps machine systems identify direct responses.</p>
        <h2>How to structure content for AI systems</h2>
        <p>Use direct answers, question-led sections, concise summaries, and clear entities.</p>
        <p>Strong content should connect the topic to audience pains, internal expertise, and brand proof points.</p>
    </article>
</body>
</html>
HTML;
}

function dispatchFirstSourceGenerationJob(): void
{
    $jobs = Queue::pushed(GenerateSourceBriefJob::class);
    expect($jobs)->not->toBeEmpty();

    /** @var GenerateSourceBriefJob $job */
    $job = $jobs[0];
    app()->call([$job, 'handle']);
}

function createAsyncTestSource(Workspace $workspace, User $user): ContentSource
{
    return ContentSource::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'type' => 'url',
        'source_url' => 'https://example.com/aeo',
        'final_url' => 'https://example.com/aeo',
        'source_domain' => 'example.com',
        'source_title' => 'Async Test Source',
        'source_language' => 'en',
        'extraction_status' => 'extracted',
        'generation_status' => ContentSource::GENERATION_STATUS_PENDING,
        'generation_output_mode' => 'brief_only',
        'extracted_text' => 'This article has enough content to pass validation and trigger generation without a fresh fetch. It explains answer engine optimization, structured answers, and practical publishing guidance for modern content teams.',
        'metadata_json' => [
            'extraction' => [
                'word_count' => 180,
            ],
        ],
        'created_by_user_id' => (int) $user->id,
    ]);
}

describe('Async source brief generation', function () {
    it('returns immediately with a lightweight processing response', function () {
        [, $workspace, , $user] = makeAsyncTestContext();

        Http::fake([
            'https://example.com/aeo' => Http::response(sampleAsyncArticleHtml(), 200, ['Content-Type' => 'text/html']),
        ]);

        $startTime = microtime(true);

        $response = $this->actingAs($user)
            ->postJson(route('app.content.create.from-url.generate'), [
                'source_url' => 'https://example.com/aeo',
                'output_mode' => 'brief_only',
            ]);

        $duration = microtime(true) - $startTime;

        $response->assertOk();
        $response->assertJsonPath('status', 'processing');
        expect($duration)->toBeLessThan(2.0);

        $source = ContentSource::query()->firstOrFail();

        expect((string) $source->workspace_id)->toBe((string) $workspace->id)
            ->and((string) $source->generation_status)->toBe(ContentSource::GENERATION_STATUS_QUEUED)
            ->and((string) $response->json('job_id'))->toBe((string) $source->id)
            ->and((string) $response->json('redirect_url'))->toContain('source=' . $source->id);

        Queue::assertPushed(GenerateSourceBriefJob::class, 1);
    });

    it('reuses the active job for duplicate submissions with the same idempotency key', function () {
        [, $workspace, , $user] = makeAsyncTestContext();

        $source = ContentSource::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'type' => 'url',
            'source_url' => 'https://example.com/aeo',
            'generation_status' => ContentSource::GENERATION_STATUS_RUNNING,
            'generation_progress_step' => 'analyzing_source',
            'generation_output_mode' => 'brief_only',
            'generation_locale' => 'en',
            'generation_intent' => 'brief_only',
            'generation_idempotency_key' => 'source-generation:' . sha1(implode('|', [
                (string) $workspace->id,
                (string) $user->id,
                'https://example.com/aeo',
                'en',
                'brief_only',
            ])),
            'created_by_user_id' => (int) $user->id,
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('app.content.create.from-url.generate'), [
                'source_url' => 'https://example.com/aeo',
                'output_mode' => 'brief_only',
            ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'processing',
            'job_id' => (string) $source->id,
        ]);

        Queue::assertNothingPushed();
        expect(ContentSource::query()->count())->toBe(1);
    });

    it('retries a failed duplicate submission instead of reusing the failed source response', function () {
        [, $workspace, , $user] = makeAsyncTestContext();

        $source = ContentSource::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'type' => 'url',
            'source_url' => 'https://example.com/aeo',
            'generation_status' => ContentSource::GENERATION_STATUS_FAILED,
            'generation_progress_step' => 'failed',
            'generation_output_mode' => 'brief_only',
            'generation_locale' => 'en',
            'generation_intent' => 'brief_only',
            'generation_idempotency_key' => 'source-generation:' . sha1(implode('|', [
                (string) $workspace->id,
                (string) $user->id,
                'https://example.com/aeo',
                'en',
                'brief_only',
            ])),
            'generation_failure_code' => 'GENERATION_DISPATCH_FAILED',
            'generation_failure_message' => 'SQLSTATE[42S02]: Base table or view not found',
            'created_by_user_id' => (int) $user->id,
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('app.content.create.from-url.generate'), [
                'source_url' => 'https://example.com/aeo',
                'output_mode' => 'brief_only',
            ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'processing',
            'job_id' => (string) $source->id,
        ]);

        Queue::assertPushed(GenerateSourceBriefJob::class, 1);

        $source->refresh();

        expect((string) $source->generation_status)->toBe(ContentSource::GENERATION_STATUS_QUEUED)
            ->and($source->generation_failure_message)->toBeNull();
    });

    it('returns the existing completed result for duplicate submissions', function () {
        [, $workspace, , $user] = makeAsyncTestContext();

        $source = ContentSource::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'type' => 'url',
            'source_url' => 'https://example.com/aeo',
            'generation_status' => ContentSource::GENERATION_STATUS_COMPLETED,
            'generation_progress_step' => 'completed',
            'generation_output_mode' => 'brief_only',
            'generation_locale' => 'en',
            'generation_intent' => 'brief_only',
            'generation_idempotency_key' => 'source-generation:' . sha1(implode('|', [
                (string) $workspace->id,
                (string) $user->id,
                'https://example.com/aeo',
                'en',
                'brief_only',
            ])),
            'created_by_user_id' => (int) $user->id,
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('app.content.create.from-url.generate'), [
                'source_url' => 'https://example.com/aeo',
                'output_mode' => 'brief_only',
            ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'completed',
            'job_id' => (string) $source->id,
        ]);

        Queue::assertNothingPushed();
    });

    it('returns the real dispatcher error when queue dispatch fails', function () {
        [, , , $user] = makeAsyncTestContext();

        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
        ]);

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('Queue connection refused'));
        $dispatcher->shouldNotReceive('dispatchSync');
        $this->app->instance(Dispatcher::class, $dispatcher);

        $response = $this->actingAs($user)
            ->postJson(route('app.content.create.from-url.generate'), [
                'source_url' => 'https://example.com/aeo',
                'output_mode' => 'brief_only',
            ]);

        $response->assertStatus(500);
        $response->assertJson([
            'message' => 'We could not start the brief generation job. Please try again in a moment.',
            'error_code' => 'GENERATION_DISPATCH_FAILED',
        ]);
    });

    it('returns a clear validation error for an invalid URL', function () {
        [, , , $user] = makeAsyncTestContext();

        $response = $this->actingAs($user)
            ->postJson(route('app.content.create.from-url.generate'), [
                'source_url' => 'https://exa mple.com',
                'output_mode' => 'brief_only',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['source_url']);
    });

    it('keeps local database queue submissions asynchronous', function () {
        [, $workspace, , $user] = makeAsyncTestContext();
        $source = createAsyncTestSource($workspace, $user);

        config([
            'app.env' => 'local',
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
        ]);

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andReturnNull();
        $dispatcher->shouldNotReceive('dispatchSync');
        $this->app->instance(Dispatcher::class, $dispatcher);

        $response = $this->actingAs($user)
            ->postJson(route('app.content.create.from-url.generate'), [
                'content_source_id' => (string) $source->id,
                'output_mode' => 'brief_only',
            ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'processing',
            'job_id' => (string) $source->id,
        ]);
    });

    it('surfaces the real job exception immediately when the queue driver is sync', function () {
        [, $workspace, , $user] = makeAsyncTestContext();
        $source = createAsyncTestSource($workspace, $user);

        config([
            'app.env' => 'local',
            'queue.default' => 'sync',
            'queue.connections.sync.driver' => 'sync',
        ]);

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatchSync')
            ->once()
            ->andThrow(new RuntimeException('Test sync failure'));
        $dispatcher->shouldNotReceive('dispatch');
        $this->app->instance(Dispatcher::class, $dispatcher);

        $response = $this->actingAs($user)
            ->postJson(route('app.content.create.from-url.generate'), [
                'content_source_id' => (string) $source->id,
                'output_mode' => 'brief_only',
            ]);

        $response->assertStatus(500);
        $response->assertJson([
            'message' => 'We could not start the brief generation job. Please try again in a moment.',
            'error_code' => 'GENERATION_DISPATCH_FAILED',
        ]);

        $source->refresh();

        expect((string) $source->generation_status)->toBe(ContentSource::GENERATION_STATUS_FAILED)
            ->and((string) $source->generation_failure_code)->toBe('GENERATION_DISPATCH_FAILED');
    });
});

describe('Source generation status endpoint', function () {
    it('returns progress metadata for a queued source job', function () {
        [, $workspace, , $user] = makeAsyncTestContext();
        $source = ContentSource::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'type' => 'url',
            'source_url' => 'https://example.com/aeo',
            'generation_status' => ContentSource::GENERATION_STATUS_QUEUED,
            'generation_progress_step' => 'queued',
            'created_by_user_id' => (int) $user->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('app.content.create.from-url.jobs.status', $source->id));

        $response->assertOk();
        $response->assertJson([
            'job_id' => (string) $source->id,
            'status' => ContentSource::GENERATION_STATUS_QUEUED,
            'progress_step' => 'queued',
            'is_pending' => true,
            'is_completed' => false,
            'is_failed' => false,
        ]);
    });

    it('returns safe failure metadata for a failed source job', function () {
        [, $workspace, , $user] = makeAsyncTestContext();
        $source = ContentSource::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'type' => 'url',
            'source_url' => 'https://example.com/aeo',
            'generation_status' => ContentSource::GENERATION_STATUS_FAILED,
            'generation_progress_step' => 'failed',
            'generation_failure_code' => 'GENERATION_FAILED',
            'generation_failure_message' => 'An error occurred during brief generation. Please try again.',
            'created_by_user_id' => (int) $user->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('app.content.create.from-url.jobs.status', $source->id));

        $response->assertOk();
        $response->assertJson([
            'job_id' => (string) $source->id,
            'status' => ContentSource::GENERATION_STATUS_FAILED,
            'is_failed' => true,
            'error_code' => 'GENERATION_FAILED',
        ]);
    });

    it('does not expose raw infrastructure exceptions from failed source jobs', function () {
        [, $workspace, , $user] = makeAsyncTestContext();
        $source = ContentSource::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'type' => 'url',
            'source_url' => 'https://example.com/aeo',
            'generation_status' => ContentSource::GENERATION_STATUS_FAILED,
            'generation_progress_step' => 'failed',
            'generation_failure_code' => 'dispatch_failed',
            'generation_failure_message' => "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'argusly.source_extractions' doesn't exist",
            'created_by_user_id' => (int) $user->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('app.content.create.from-url.jobs.status', $source->id));

        $response->assertOk();
        $response->assertJson([
            'job_id' => (string) $source->id,
            'status' => ContentSource::GENERATION_STATUS_FAILED,
            'is_failed' => true,
            'error_code' => 'GENERATION_DISPATCH_FAILED',
            'failure_message' => 'We could not start the brief generation job. Please try again in a moment.',
        ]);

        expect((string) $response->json('failure_message'))->not->toContain('SQLSTATE')
            ->and((string) $response->json('failure_message'))->not->toContain('source_extractions');
    });

    it('keeps recoverable source fetch failures in a fallback pending state', function () {
        [, $workspace, , $user] = makeAsyncTestContext();
        $source = ContentSource::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'type' => 'url',
            'source_url' => 'https://www.mckinsey.com/example/slow-article',
            'generation_status' => ContentSource::GENERATION_STATUS_FAILED,
            'generation_progress_step' => 'failed',
            'generation_failure_code' => 'SOURCE_FETCH_TIMEOUT',
            'generation_failure_message' => 'We could not fetch this URL within the request window.',
            'created_by_user_id' => (int) $user->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson(route('app.content.create.from-url.jobs.status', $source->id));

        $response->assertOk();
        $response->assertJson([
            'job_id' => (string) $source->id,
            'status' => ContentSource::GENERATION_STATUS_QUEUED,
            'progress_step' => 'fallback_extraction',
            'progress_label' => 'Trying fallback extraction methods',
            'is_pending' => true,
            'is_extraction_pending' => true,
            'is_completed' => false,
            'is_failed' => false,
            'failure_message' => null,
            'error_code' => null,
        ]);
    });
});

describe('URL generation results and saving', function () {
    it('completes a brief_chain generation from a URL', function () {
        [, , , $user] = makeAsyncTestContext();

        Http::fake([
            'https://example.com/aeo' => Http::response(sampleAsyncArticleHtml(), 200, ['Content-Type' => 'text/html']),
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('app.content.create.from-url.generate'), [
                'source_url' => 'https://example.com/aeo',
                'output_mode' => 'brief_chain',
            ]);

        $response->assertOk();

        dispatchFirstSourceGenerationJob();

        $source = ContentSource::query()->firstOrFail()->fresh();

        expect((string) $source->generation_status)->toBe(ContentSource::GENERATION_STATUS_COMPLETED)
            ->and((string) data_get($source->generated_payload_json, 'brief.working_title'))->not->toBe('')
            ->and((string) data_get($source->generated_payload_json, 'chain_proposal.pillar_topic'))->not->toBe('');
    });

    it('stores a failed status when generation errors inside the job', function () {
        [, $workspace, , $user] = makeAsyncTestContext();
        config(['source_extraction.jina_enabled' => false]);

        Http::fake([
            'https://example.com/broken' => Http::response('<html><body><article><h1>Thin</h1><p>Short.</p></article></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->actingAs($user)
            ->postJson(route('app.content.create.from-url.generate'), [
                'source_url' => 'https://example.com/broken',
                'output_mode' => 'brief_only',
            ])
            ->assertOk();

        $jobs = Queue::pushed(GenerateSourceBriefJob::class);
        /** @var GenerateSourceBriefJob $job */
        $job = $jobs[0];

        try {
            app()->call([$job, 'handle']);
        } catch (Throwable) {
            // The job should bubble the exception, but it must also persist failed state.
        }

        $source = ContentSource::query()
            ->where('workspace_id', (string) $workspace->id)
            ->firstOrFail()
            ->fresh();

        expect((string) $source->generation_status)->toBe(ContentSource::GENERATION_STATUS_FAILED)
            ->and((string) $source->generation_failure_message)->not->toBe('');
    });

    it('does not create duplicate content records when the save action is submitted twice', function () {
        [, $workspace, $site, $user] = makeAsyncTestContext();

        $source = ContentSource::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'type' => 'url',
            'source_url' => 'https://example.com/aeo',
            'final_url' => 'https://example.com/aeo',
            'source_domain' => 'example.com',
            'source_title' => 'Generated Source Title',
            'source_language' => 'en',
            'extraction_status' => 'generated',
            'generation_status' => ContentSource::GENERATION_STATUS_COMPLETED,
            'generation_progress_step' => 'completed',
            'generated_payload_json' => [
                'brief' => [
                    'working_title' => str_repeat('A very long generated title ', 20),
                    'summary' => 'Generated summary',
                    'primary_keyword' => 'answer engine optimization',
                    'target_audience' => 'Content teams',
                    'search_intent' => str_repeat('consideration ', 10),
                    'key_talking_points' => ['Point 1', 'Point 2'],
                ],
            ],
            'analysis_json' => [
                'funnel_stage' => 'consideration',
                'source_tone' => 'practical',
            ],
            'created_by_user_id' => (int) $user->id,
        ]);

        $payload = [
            'content_source_id' => (string) $source->id,
            'destination_mode' => 'connected',
            'site_id' => (string) $site->id,
            'next_action' => 'save',
        ];

        $firstResponse = $this->actingAs($user)
            ->post(route('app.content.create.from-url.save'), $payload);

        $firstResponse->assertRedirect();

        $secondResponse = $this->actingAs($user)
            ->post(route('app.content.create.from-url.save'), $payload);

        $secondResponse->assertRedirect();

        expect(Content::query()->count())->toBe(1)
            ->and(Brief::query()->count())->toBe(1);

        $content = Content::query()->firstOrFail();
        $brief = Brief::query()->firstOrFail();

        expect(mb_strlen((string) $content->title))->toBeLessThanOrEqual(255)
            ->and(mb_strlen((string) $brief->title))->toBeLessThanOrEqual(255)
            ->and((string) $source->fresh()->result_brief_id)->toBe((string) $brief->id);
    });
});
