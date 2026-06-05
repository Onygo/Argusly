<?php

use App\Jobs\GenerateSeriesRunArticleJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentSeries;
use App\Models\ContentSeriesArticle;
use App\Models\ContentSeriesGenerationRun;
use App\Models\ContentSeriesGenerationRunArticle;
use App\Models\CreditAction;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Content\SeriesArticleGenerationService;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('queues series generation from controller and completes through queued jobs', function () {
    config(['features.draft_link_suggestions' => false]);
    config(['llm.providers.openai.api_key' => 'test-api-key']);
    config(['llm.providers.openai.default_model' => 'gpt-4.1-mini']);

    $generatedPayload = function (string $title): array {
        return [
            'output_text' => json_encode([
                'title' => $title,
                'meta' => [
                    'description' => 'Practical governance guide for B2B teams.',
                    'keywords' => ['governance', 'workflow'],
                ],
                'sections' => [
                    [
                        'heading' => 'Introduction',
                        'html' => '<p>' . str_repeat('This paragraph explains governance controls and workflow outcomes. ', 24) . '</p>',
                    ],
                    [
                        'heading' => 'Execution',
                        'html' => '<p>' . str_repeat('Operational detail with measurable checkpoints for delivery teams. ', 24) . '</p>',
                    ],
                ],
                'links' => [],
            ]),
        ];
    };

    Http::fake([
        '*/v1/responses' => Http::sequence()
            ->push($generatedPayload('Series article 1'))
            ->push($generatedPayload('Series article 2')),
    ]);

    $organization = Organization::query()->create([
        'name' => 'Series Gen Org',
        'slug' => 'series-gen-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Series Gen Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series Gen Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Series Gen Site',
        'site_url' => 'https://series-gen.example.com',
        'base_url' => 'https://series-gen.example.com',
        'allowed_domains' => ['series-gen.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'series-gen-plan-' . Str::random(6),
        'name' => 'Series Gen Plan',
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
        'name' => 'Series Gen Owner',
        'email' => 'series-gen-owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'email_code_verified_at' => now(),
        'active' => true,
    ]);

    CreditAction::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'content.article',
        'category' => 'content',
        'credits_cost' => 4,
        'label_nl' => 'Article',
        'label_en' => 'Article',
        'is_active' => true,
        'meta' => [],
    ]);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 40,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'test_funding']
    );

    $series = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'Series Generation',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance workflow',
        'supporting_keywords' => ['governance policy', 'workflow controls'],
        'articles_count' => 2,
        'status' => 'strategy_generated',
        'strategy_json' => [
            'angle' => 'Connected governance content chain.',
            'articles' => [
                [
                    'article_number' => 1,
                    'title' => 'Governance foundations',
                    'primary_keyword' => 'governance foundations',
                    'secondary_keywords' => ['policy controls'],
                    'internal_links_to' => [2],
                ],
                [
                    'article_number' => 2,
                    'title' => 'Workflow execution playbook',
                    'primary_keyword' => 'workflow execution',
                    'secondary_keywords' => ['governance process'],
                    'internal_links_to' => [1],
                ],
            ],
        ],
        'created_by' => $user->id,
    ]);

    Queue::fake();

    $this->actingAs($user)
        ->post(route('app.content.series.generate-articles', $series))
        ->assertRedirect()
        ->assertSessionHas('status');

    Queue::assertPushed(GenerateSeriesRunArticleJob::class, 2);

    $run = ContentSeriesGenerationRun::query()
        ->where('series_id', (string) $series->id)
        ->latest('created_at')
        ->first();

    expect($run)->not->toBeNull()
        ->and((string) $run->status)->toBe('pending');

    $runArticles = ContentSeriesGenerationRunArticle::query()
        ->where('run_id', (string) $run->id)
        ->orderBy('article_number')
        ->get();

    foreach ($runArticles as $runArticle) {
        app(SeriesArticleGenerationService::class)->generateRunArticle($runArticle, 1, 3);
    }

    $series->refresh();

    expect((string) $series->status)->toBe('ready');

    $contents = Content::query()->where('series_id', $series->id)->orderBy('created_at')->get();
    expect($contents->count())->toBe(2);

    $drafts = Draft::query()->whereIn('content_id', $contents->pluck('id'))->orderBy('created_at')->get();
    expect($drafts->count())->toBe(2);

    foreach ($drafts as $draft) {
        expect((string) $draft->content_html)->not->toContain('[[link:article-')
            ->and((string) $draft->content_html)->toContain('/blog/');
    }

    expect((string) $run->fresh()->status)->toBe('completed')
        ->and((int) $run->fresh()->completed_articles)->toBe(2)
        ->and((int) $run->fresh()->failed_articles)->toBe(0);
});

it('redispatches open articles for an active series generation run', function () {
    $organization = Organization::query()->create([
        'name' => 'Series Resume Org',
        'slug' => 'series-resume-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series Resume Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Series Resume Site',
        'site_url' => 'https://series-resume.example.com',
        'base_url' => 'https://series-resume.example.com',
        'allowed_domains' => ['series-resume.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Series Resume Owner',
        'email' => 'series-resume-owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'is_admin' => true,
        'approved_at' => now(),
        'active' => true,
    ]);

    $series = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'Series Resume',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance workflow',
        'articles_count' => 1,
        'status' => 'generating',
        'strategy_json' => [
            'articles' => [
                [
                    'article_number' => 1,
                    'title' => 'Governance foundations',
                    'primary_keyword' => 'governance foundations',
                    'secondary_keywords' => ['policy controls'],
                    'internal_links_to' => [],
                ],
            ],
        ],
        'created_by' => $user->id,
    ]);

    $run = ContentSeriesGenerationRun::query()->create([
        'id' => (string) Str::uuid(),
        'series_id' => (string) $series->id,
        'organization_id' => (int) $organization->id,
        'requested_by' => (int) $user->id,
        'total_articles' => 1,
        'completed_articles' => 0,
        'failed_articles' => 0,
        'status' => ContentSeriesGenerationRun::STATUS_RUNNING,
        'started_at' => now()->subMinutes(10),
    ]);

    $runArticle = ContentSeriesGenerationRunArticle::query()->create([
        'id' => (string) Str::uuid(),
        'run_id' => (string) $run->id,
        'series_id' => (string) $series->id,
        'article_number' => 1,
        'title' => 'Governance foundations',
        'status' => ContentSeriesGenerationRunArticle::STATUS_PENDING,
        'attempts' => 1,
        'error_message' => 'Meta description exceeds 155 characters.',
    ]);

    Queue::fake();

    $result = app(SeriesArticleGenerationService::class)->dispatchGeneration($series, (int) $user->id);

    expect((bool) $result['already_running'])->toBeTrue()
        ->and((int) $result['queued'])->toBe(1)
        ->and((string) $result['run_id'])->toBe((string) $run->id);

    Queue::assertPushed(GenerateSeriesRunArticleJob::class, function (GenerateSeriesRunArticleJob $job) use ($runArticle): bool {
        return (string) $job->runArticleId === (string) $runArticle->id;
    });
});

it('retries failed series articles through the repair command', function () {
    $organization = Organization::query()->create([
        'name' => 'Series Retry Command Org',
        'slug' => 'series-retry-command-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series Retry Command Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Series Retry Command Site',
        'site_url' => 'https://series-retry-command.example.com',
        'base_url' => 'https://series-retry-command.example.com',
        'allowed_domains' => ['series-retry-command.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $series = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'Series Retry Command',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance workflow',
        'articles_count' => 1,
        'status' => 'strategy_generated',
        'strategy_json' => ['articles' => []],
    ]);

    $run = ContentSeriesGenerationRun::query()->create([
        'id' => (string) Str::uuid(),
        'series_id' => (string) $series->id,
        'organization_id' => (int) $organization->id,
        'total_articles' => 1,
        'completed_articles' => 0,
        'failed_articles' => 1,
        'status' => ContentSeriesGenerationRun::STATUS_FAILED,
        'last_error' => 'Meta description exceeds 155 characters.',
        'started_at' => now()->subMinutes(10),
        'finished_at' => now()->subMinute(),
    ]);

    $runArticle = ContentSeriesGenerationRunArticle::query()->create([
        'id' => (string) Str::uuid(),
        'run_id' => (string) $run->id,
        'series_id' => (string) $series->id,
        'article_number' => 7,
        'title' => 'Questions to Answer Before Investing in Real-Time Campaign Optimization',
        'status' => ContentSeriesGenerationRunArticle::STATUS_FAILED,
        'attempts' => 3,
        'error_message' => 'Meta description exceeds 155 characters.',
        'finished_at' => now()->subMinute(),
    ]);

    Queue::fake();

    $this->artisan('content:series:retry-failed', [
        'series' => (string) $series->id,
        '--article' => [7],
    ])->assertExitCode(0);

    $run->refresh();
    $runArticle->refresh();
    $series->refresh();

    expect((string) $runArticle->status)->toBe(ContentSeriesGenerationRunArticle::STATUS_PENDING)
        ->and($runArticle->error_message)->toBeNull()
        ->and((string) $run->status)->toBe(ContentSeriesGenerationRun::STATUS_RUNNING)
        ->and((int) $run->failed_articles)->toBe(0)
        ->and((string) $series->status)->toBe(ContentSeries::STATUS_GENERATING);

    Queue::assertPushed(GenerateSeriesRunArticleJob::class, function (GenerateSeriesRunArticleJob $job) use ($runArticle): bool {
        return (string) $job->runArticleId === (string) $runArticle->id;
    });
});

it('reuses a series article content link during retry when external key is missing', function () {
    config(['features.draft_link_suggestions' => false]);
    config(['llm.providers.openai.api_key' => 'test-api-key']);
    config(['llm.providers.openai.default_model' => 'gpt-4.1-mini']);

    Http::fake([
        '*/v1/responses' => Http::response([
            'output_text' => json_encode([
                'title' => 'Retry article title',
                'meta' => [
                    'description' => 'A concise retry article description.',
                    'keywords' => ['retry'],
                ],
                'sections' => [
                    [
                        'heading' => 'Introduction',
                        'html' => '<p>' . str_repeat('Retry content explains the existing article path. ', 24) . '</p>',
                    ],
                    [
                        'heading' => 'Next steps',
                        'html' => '<p>' . str_repeat('The retry updates the linked row instead of creating another content item. ', 24) . '</p>',
                    ],
                ],
                'links' => [],
            ]),
        ]),
    ]);

    $organization = Organization::query()->create([
        'name' => 'Series Existing Link Org',
        'slug' => 'series-existing-link-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series Existing Link Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Series Existing Link Site',
        'site_url' => 'https://series-existing-link.example.com',
        'base_url' => 'https://series-existing-link.example.com',
        'allowed_domains' => ['series-existing-link.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Series Existing Link Owner',
        'email' => 'series-existing-link-owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'is_admin' => true,
        'approved_at' => now(),
        'active' => true,
    ]);

    $series = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'Series Existing Link',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance workflow',
        'articles_count' => 1,
        'status' => ContentSeries::STATUS_GENERATING,
        'strategy_json' => [
            'articles' => [
                [
                    'article_number' => 1,
                    'title' => 'Retry article title',
                    'primary_keyword' => 'retry article',
                    'secondary_keywords' => [],
                    'internal_links_to' => [],
                ],
            ],
        ],
        'created_by' => $user->id,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'series_id' => (string) $series->id,
        'title' => 'Retry article title',
        'language' => 'en',
        'type' => 'article',
        'status' => 'brief',
        'source' => 'manual',
        'origin_type' => 'series_generated',
        'external_key' => null,
        'publish_status' => 'draft',
        'delivery_status' => 'pending',
    ]);

    ContentSeriesArticle::query()->create([
        'id' => (string) Str::uuid(),
        'series_id' => (string) $series->id,
        'content_id' => (string) $content->id,
        'article_number' => 1,
        'title' => 'Retry article title',
        'primary_keyword' => 'retry article',
        'secondary_keywords' => [],
        'internal_links_to' => [],
        'is_pillar' => true,
        'meta' => [],
    ]);

    $run = ContentSeriesGenerationRun::query()->create([
        'id' => (string) Str::uuid(),
        'series_id' => (string) $series->id,
        'organization_id' => (int) $organization->id,
        'requested_by' => (int) $user->id,
        'total_articles' => 1,
        'completed_articles' => 0,
        'failed_articles' => 0,
        'status' => ContentSeriesGenerationRun::STATUS_RUNNING,
        'meta' => [
            'pricing' => ['cost' => 4],
        ],
    ]);

    $runArticle = ContentSeriesGenerationRunArticle::query()->create([
        'id' => (string) Str::uuid(),
        'run_id' => (string) $run->id,
        'series_id' => (string) $series->id,
        'article_number' => 1,
        'title' => 'Retry article title',
        'status' => ContentSeriesGenerationRunArticle::STATUS_PENDING,
        'content_id' => null,
        'attempts' => 1,
    ]);

    app(SeriesArticleGenerationService::class)->generateRunArticle($runArticle, 1, 3);

    expect(Content::query()->where('series_id', (string) $series->id)->count())->toBe(1)
        ->and((string) $runArticle->fresh()->content_id)->toBe((string) $content->id)
        ->and((string) $content->fresh()->external_key)->toBe('series-' . $series->id . '-article-1');
});

it('counts existing ready drafts as generated during a partial retry run', function () {
    $organization = Organization::query()->create([
        'name' => 'Series Display Org',
        'slug' => 'series-display-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series Display Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Series Display Site',
        'site_url' => 'https://series-display.example.com',
        'base_url' => 'https://series-display.example.com',
        'allowed_domains' => ['series-display.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'series-display-plan-' . Str::random(6),
        'name' => 'Series Display Plan',
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
        'name' => 'Series Display Owner',
        'email' => 'series-display-owner+' . Str::random(6) . '@example.com',
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
        'name' => 'Series Display',
        'main_topic' => 'Agentic marketing',
        'primary_keyword' => 'agentic marketing',
        'articles_count' => 2,
        'status' => ContentSeries::STATUS_GENERATING,
        'strategy_json' => [
            'articles' => [
                ['article_number' => 1, 'title' => 'First Article', 'primary_keyword' => 'first article'],
                ['article_number' => 2, 'title' => 'Second Article', 'primary_keyword' => 'second article'],
            ],
        ],
        'created_by' => $user->id,
    ]);

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'series_id' => (string) $series->id,
        'title' => 'First Article',
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'external_key' => 'series-' . $series->id . '-article-1',
        'publish_status' => 'draft',
        'delivery_status' => 'pending',
    ]);

    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => (string) $site->id,
        'content_id' => (string) $content->id,
        'created_by_user_id' => (int) $user->id,
        'status' => 'queued',
        'title' => 'First Article',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'intent' => 'educate',
        'primary_keyword' => 'first article',
    ]);

    Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => (string) $brief->id,
        'content_id' => (string) $content->id,
        'client_site_id' => (string) $site->id,
        'status' => 'ready_to_deliver',
        'title' => 'First Article',
        'output_type' => 'kb_article',
        'language' => 'en',
        'content_html' => '<p>Ready article.</p>',
    ]);

    $run = ContentSeriesGenerationRun::query()->create([
        'id' => (string) Str::uuid(),
        'series_id' => (string) $series->id,
        'organization_id' => (int) $organization->id,
        'requested_by' => (int) $user->id,
        'total_articles' => 1,
        'completed_articles' => 0,
        'failed_articles' => 0,
        'status' => ContentSeriesGenerationRun::STATUS_PENDING,
    ]);

    ContentSeriesGenerationRunArticle::query()->create([
        'id' => (string) Str::uuid(),
        'run_id' => (string) $run->id,
        'series_id' => (string) $series->id,
        'article_number' => 2,
        'title' => 'Second Article',
        'status' => ContentSeriesGenerationRunArticle::STATUS_PENDING,
    ]);

    $controller = app(\App\Http\Controllers\App\AppContentSeriesController::class);
    $method = new ReflectionMethod($controller, 'buildSeriesDisplayData');
    $method->setAccessible(true);

    $displayData = $method->invoke($controller, $series->fresh()->load([
        'seriesArticles.content.currentVersion',
        'contents.currentVersion',
    ]));
    $articleRows = collect($displayData['article_rows']);

    expect($articleRows->where('status', ContentSeriesGenerationRunArticle::STATUS_DRAFT)->count())->toBe(1)
        ->and((string) $articleRows->firstWhere('article_number', 1)['status'])->toBe(ContentSeriesGenerationRunArticle::STATUS_DRAFT)
        ->and((string) $articleRows->firstWhere('article_number', 2)['status'])->toBe(ContentSeriesGenerationRunArticle::STATUS_PENDING);
});
