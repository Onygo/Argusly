<?php

use App\Jobs\GenerateSeriesRunArticleJob;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentSeries;
use App\Models\ContentSeriesGenerationRun;
use App\Models\ContentSeriesGenerationRunArticle;
use App\Models\CreditAction;
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

$generatedPayload = function (string $title): array {
    return [
        'output_text' => json_encode([
            'title' => $title,
            'meta' => [
                'description' => 'Series description.',
                'keywords' => ['series', 'seo'],
            ],
            'sections' => [
                [
                    'heading' => 'How content series context guides action',
                    'html' => '<p>' . str_repeat('Intro paragraph for testing. ', 26) . '</p>',
                ],
                [
                    'heading' => 'What implementation decisions improve performance',
                    'html' => '<p>' . str_repeat('Body paragraph for testing. ', 26) . '</p>',
                ],
            ],
            'links' => [],
        ]),
    ];
};

$makeSeriesContext = function (int $articlesCount = 3) {
    config(['llm.providers.openai.api_key' => 'test-api-key']);
    config(['llm.providers.openai.default_model' => 'gpt-4.1-mini']);

    $organization = Organization::query()->create([
        'name' => 'Series Queue Org',
        'slug' => 'series-queue-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Series Queue BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series Queue Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Series Queue Site',
        'site_url' => 'https://series-queue.example.com',
        'base_url' => 'https://series-queue.example.com',
        'allowed_domains' => ['series-queue.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'series-queue-plan-' . Str::random(6),
        'name' => 'Series Queue Plan',
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
        'name' => 'Series Queue Owner',
        'email' => 'series-queue-owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
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
        amount: 200,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'series_queue_test']
    );

    $articles = [];
    for ($i = 1; $i <= $articlesCount; $i++) {
        $articles[] = [
            'article_number' => $i,
            'title' => 'Series article ' . $i,
            'primary_keyword' => 'keyword ' . $i,
            'secondary_keywords' => ['secondary ' . $i],
            'internal_links_to' => $articlesCount > 1 ? [$i === $articlesCount ? 1 : $i + 1] : [],
        ];
    }

    $series = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'Queued Series',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance workflow',
        'supporting_keywords' => ['governance policy', 'workflow controls'],
        'articles_count' => $articlesCount,
        'status' => ContentSeries::STATUS_STRATEGY_GENERATED,
        'strategy_json' => [
            'angle' => 'Queue-first strategy.',
            'articles' => $articles,
        ],
        'created_by' => $user->id,
    ]);

    return [$user, $series, $site];
};

it('dispatches generation jobs from the controller request', function () use ($makeSeriesContext) {
    [$user, $series] = $makeSeriesContext(3);

    Queue::fake();

    $this->actingAs($user)
        ->post(route('app.content.series.generate-articles', $series))
        ->assertRedirect()
        ->assertSessionHas('status');

    Queue::assertPushed(GenerateSeriesRunArticleJob::class, 3);

    $run = ContentSeriesGenerationRun::query()->where('series_id', (string) $series->id)->latest('created_at')->first();

    expect($run)->not->toBeNull()
        ->and((int) $run->total_articles)->toBe(3)
        ->and((string) $run->status)->toBe('pending');

    expect(Content::query()->where('series_id', (string) $series->id)->count())->toBe(0);
});

it('keeps the HTTP request timeout-safe by not running generation inline', function () use ($makeSeriesContext) {
    [$user, $series] = $makeSeriesContext(2);

    Http::fake([
        '*/v1/responses' => Http::response(['error' => ['message' => 'should not be called in HTTP request']], 500),
    ]);

    Queue::fake();

    $this->actingAs($user)
        ->post(route('app.content.series.generate-articles', $series))
        ->assertRedirect();

    Http::assertNothingSent();
});

it('supports partial completion and resume for remaining articles', function () use ($makeSeriesContext, $generatedPayload) {
    [$user, $series] = $makeSeriesContext(3);

    Http::fake([
        '*/v1/responses' => Http::sequence()
            ->push($generatedPayload('Series article 1 done'))
            ->push($generatedPayload('Series article 2 done'))
            ->push($generatedPayload('Series article 3 done')),
    ]);

    Queue::fake();

    $this->actingAs($user)
        ->post(route('app.content.series.generate-articles', $series))
        ->assertRedirect();

    $firstRun = ContentSeriesGenerationRun::query()->where('series_id', (string) $series->id)->latest('created_at')->firstOrFail();

    $firstRunArticles = ContentSeriesGenerationRunArticle::query()
        ->where('run_id', (string) $firstRun->id)
        ->orderBy('article_number')
        ->get();

    $service = app(SeriesArticleGenerationService::class);

    $service->generateRunArticle($firstRunArticles[0], 1, 3);
    $service->generateRunArticle($firstRunArticles[1], 1, 3);
    $service->markRunArticleFailed($firstRunArticles[2], 'simulated failure', true);

    expect(Content::query()->where('series_id', (string) $series->id)->count())->toBe(2);

    Queue::fake();

    $this->actingAs($user)
        ->post(route('app.content.series.generate-articles', $series))
        ->assertRedirect();

    Queue::assertPushed(GenerateSeriesRunArticleJob::class, 1);

    $secondRun = ContentSeriesGenerationRun::query()->where('series_id', (string) $series->id)->latest('created_at')->firstOrFail();

    expect((string) $secondRun->id)->not->toBe((string) $firstRun->id)
        ->and((int) $secondRun->total_articles)->toBe(1)
        ->and((int) $secondRun->completed_articles)->toBe(0);
});

it('retries only the missing article without duplicating existing generated content', function () use ($makeSeriesContext, $generatedPayload) {
    [$user, $series] = $makeSeriesContext(3);

    Http::fake([
        '*/v1/responses' => Http::sequence()
            ->push($generatedPayload('Series article 1 done'))
            ->push($generatedPayload('Series article 2 done'))
            ->push($generatedPayload('Series article 3 done')),
    ]);

    Queue::fake();

    $this->actingAs($user)
        ->post(route('app.content.series.generate-articles', $series))
        ->assertRedirect();

    $firstRun = ContentSeriesGenerationRun::query()->where('series_id', (string) $series->id)->latest('created_at')->firstOrFail();

    $service = app(SeriesArticleGenerationService::class);

    $firstRunArticles = ContentSeriesGenerationRunArticle::query()
        ->where('run_id', (string) $firstRun->id)
        ->orderBy('article_number')
        ->get();

    $service->generateRunArticle($firstRunArticles[0], 1, 3);
    $service->generateRunArticle($firstRunArticles[1], 1, 3);
    $service->markRunArticleFailed($firstRunArticles[2], 'simulated failure', true);

    $existingByExternalKey = Content::query()
        ->where('series_id', (string) $series->id)
        ->pluck('id', 'external_key')
        ->all();

    Queue::fake();

    $this->actingAs($user)
        ->post(route('app.content.series.generate-articles', $series))
        ->assertRedirect();

    Queue::assertPushed(GenerateSeriesRunArticleJob::class, 1);

    $secondRun = ContentSeriesGenerationRun::query()->where('series_id', (string) $series->id)->latest('created_at')->firstOrFail();

    $remainingRunArticle = ContentSeriesGenerationRunArticle::query()
        ->where('run_id', (string) $secondRun->id)
        ->firstOrFail();

    $service->generateRunArticle($remainingRunArticle, 1, 3);

    $allContents = Content::query()->where('series_id', (string) $series->id)->get();

    expect($allContents->count())->toBe(3)
        ->and((int) Content::query()->where('series_id', (string) $series->id)->distinct('external_key')->count('external_key'))->toBe(3);

    foreach ($existingByExternalKey as $externalKey => $contentId) {
        expect((string) Content::query()->where('series_id', (string) $series->id)->where('external_key', $externalKey)->value('id'))
            ->toBe((string) $contentId);
    }
});

it('marks failed article and run state when generation fails permanently', function () use ($makeSeriesContext) {
    [$user, $series] = $makeSeriesContext(1);

    Queue::fake();

    $this->actingAs($user)
        ->post(route('app.content.series.generate-articles', $series))
        ->assertRedirect();

    $run = ContentSeriesGenerationRun::query()->where('series_id', (string) $series->id)->latest('created_at')->firstOrFail();
    $runArticle = ContentSeriesGenerationRunArticle::query()->where('run_id', (string) $run->id)->firstOrFail();

    Http::fake([
        '*/v1/responses' => Http::response([
            'error' => ['message' => 'provider unavailable'],
        ], 500),
    ]);

    $thrown = false;
    try {
        app(SeriesArticleGenerationService::class)->generateRunArticle($runArticle, 3, 3);
    } catch (\Throwable) {
        $thrown = true;
    }

    expect($thrown)->toBeTrue();

    $runArticle->refresh();
    $run->refresh();
    $series->refresh();

    expect((string) $runArticle->status)->toBe('failed')
        ->and((string) $run->status)->toBe('failed')
        ->and((int) $run->failed_articles)->toBe(1)
        ->and((string) $series->status)->toBe('strategy_generated');
});
