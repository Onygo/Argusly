<?php

use App\Jobs\GenerateSeriesRunArticleJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentSeries;
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

it('completes the full series happy path for 1 pillar and 3 supporting articles with intent payloads', function () {
    config(['features.draft_link_suggestions' => false]);
    config(['llm.providers.openai.api_key' => 'test-api-key']);
    config(['llm.providers.openai.default_model' => 'gpt-4.1-mini']);

    $generatedPayload = fn (string $title): array => [
        'output_text' => json_encode([
            'title' => $title,
            'meta' => [
                'description' => 'Series article description.',
            ],
            'sections' => [
                [
                    'heading' => 'Why automation strategy matters to the buying team',
                    'html' => '<p>' . str_repeat('This article explains the topic with practical implementation detail. ', 22) . '</p>',
                ],
                [
                    'heading' => 'How implementation choices reduce automation risk',
                    'html' => '<p>' . str_repeat('It also includes execution detail and next steps for the reader. ', 22) . '</p>',
                ],
            ],
            'links' => [],
        ]),
    ];

    Http::fake([
        '*/v1/responses' => Http::sequence()
            ->push($generatedPayload('Governance comparison guide'))
            ->push($generatedPayload('Process automation playbook'))
            ->push($generatedPayload('Strategy rollout framework'))
            ->push($generatedPayload('Solution architecture landing page')),
    ]);

    $organization = Organization::query()->create([
        'name' => 'Series Intent Org',
        'slug' => 'series-intent-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Series Intent Org BV',
        'billing_address_line1' => 'Intentstraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series Intent Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Series Intent Site',
        'site_url' => 'https://series-intent.example.com',
        'base_url' => 'https://series-intent.example.com',
        'allowed_domains' => ['series-intent.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'series-intent-plan-' . Str::random(6),
        'name' => 'Series Intent Plan',
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
        'name' => 'Series Intent Owner',
        'email' => 'series-intent-owner+' . Str::random(6) . '@example.com',
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
        amount: 80,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'series_intent_test']
    );

    $series = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'Series Intent Flow',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance workflow',
        'supporting_keywords' => ['process automation', 'strategy rollout', 'solution architecture'],
        'intent_keys' => ['commercial'],
        'audience' => 'operations',
        'articles_count' => 4,
        'status' => 'strategy_generated',
        'strategy_json' => [
            'angle' => 'Authority through linked pillar and supporting articles.',
            'articles' => [
                [
                    'article_number' => 1,
                    'title' => 'Governance comparison guide',
                    'primary_keyword' => 'governance comparison',
                    'secondary_keywords' => ['decision framework'],
                    'internal_links_to' => [2, 3, 4],
                ],
                [
                    'article_number' => 2,
                    'title' => 'Process automation playbook',
                    'primary_keyword' => 'process automation',
                    'secondary_keywords' => ['workflow process'],
                    'output_type' => 'article',
                    'internal_links_to' => [1],
                ],
                [
                    'article_number' => 3,
                    'title' => 'Strategy rollout framework',
                    'primary_keyword' => 'strategy rollout',
                    'secondary_keywords' => ['implementation roadmap'],
                    'output_type' => 'article',
                    'internal_links_to' => [1],
                ],
                [
                    'article_number' => 4,
                    'title' => 'Solution architecture landing page',
                    'primary_keyword' => 'solution architecture',
                    'secondary_keywords' => ['platform solution'],
                    'output_type' => 'seo_page',
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

    Queue::assertPushed(GenerateSeriesRunArticleJob::class, 4);

    $run = ContentSeriesGenerationRun::query()
        ->where('series_id', (string) $series->id)
        ->latest('created_at')
        ->firstOrFail();

    $runArticles = ContentSeriesGenerationRunArticle::query()
        ->where('run_id', (string) $run->id)
        ->orderBy('article_number')
        ->get();

    foreach ($runArticles as $runArticle) {
        app(SeriesArticleGenerationService::class)->generateRunArticle($runArticle, 1, 3);
    }

    $series->refresh();

    expect((string) $series->status)->toBe('ready')
        ->and((string) $run->fresh()->status)->toBe('completed')
        ->and((int) $run->fresh()->completed_articles)->toBe(4)
        ->and((int) $run->fresh()->failed_articles)->toBe(0);

    $briefs = Brief::query()
        ->whereHas('content', fn ($query) => $query->where('series_id', (string) $series->id))
        ->orderBy('created_at')
        ->get();

    expect($briefs)->toHaveCount(4)
        ->and(Draft::query()->whereIn('brief_id', $briefs->pluck('id'))->count())->toBe(4);

    expect(data_get($briefs[0]->client_refs, 'request_payload.brief.intent.keys'))->toBe(['commercial', 'compare']);
    expect(data_get($briefs[1]->client_refs, 'request_payload.brief.intent.keys'))->toBe(['commercial', 'process']);
    expect(data_get($briefs[2]->client_refs, 'request_payload.brief.intent.keys'))->toBe(['commercial', 'strategic']);
    expect(data_get($briefs[3]->client_refs, 'request_payload.brief.intent.keys'))->toBe(['commercial', 'solution']);

    expect(data_get($briefs[0]->client_refs, 'taxonomy.intent_keys'))->toBe(['commercial', 'compare'])
        ->and(data_get($briefs[1]->client_refs, 'taxonomy.intent_keys'))->toBe(['commercial', 'process'])
        ->and(data_get($briefs[2]->client_refs, 'taxonomy.intent_keys'))->toBe(['commercial', 'strategic'])
        ->and(data_get($briefs[3]->client_refs, 'taxonomy.intent_keys'))->toBe(['commercial', 'solution']);

    $contents = Content::query()
        ->where('series_id', (string) $series->id)
        ->orderBy('created_at')
        ->get();

    expect((array) ($contents[0]->intent_keys ?? []))->toBe(['commercial', 'compare'])
        ->and((array) ($contents[1]->intent_keys ?? []))->toBe(['commercial', 'process'])
        ->and((array) ($contents[2]->intent_keys ?? []))->toBe(['commercial', 'strategic'])
        ->and((array) ($contents[3]->intent_keys ?? []))->toBe(['commercial', 'solution']);

    Http::assertSent(function ($request): bool {
        return str_contains($request->body(), 'Content intent: commercial, compare.');
    });
});

it('handles series with no user configured intent keys by using output type defaults', function () {
    config(['features.draft_link_suggestions' => false]);
    config(['llm.providers.openai.api_key' => 'test-api-key']);
    config(['llm.providers.openai.default_model' => 'gpt-4.1-mini']);

    $generatedPayload = fn (string $title): array => [
        'output_text' => json_encode([
            'title' => $title,
            'meta' => ['description' => 'Comprehensive KB article covering essential topics.'],
            'sections' => [
                [
                    'heading' => 'What content context clarifies before choosing an approach',
                    'html' => '<p>' . str_repeat('This knowledge base article explains the fundamentals and best practices. ', 25) . '</p>',
                ],
                [
                    'heading' => 'How automation concepts shape the operating model',
                    'html' => '<p>' . str_repeat('Understanding these core concepts is essential for implementation success. ', 25) . '</p>',
                ],
            ],
            'links' => [],
        ]),
    ];

    Http::fake([
        '*/v1/responses' => Http::sequence()
            ->push($generatedPayload('Pillar KB article'))
            ->push($generatedPayload('Supporting KB article 1'))
            ->push($generatedPayload('Supporting KB article 2'))
            ->push($generatedPayload('Supporting KB article 3')),
    ]);

    $organization = Organization::query()->create([
        'name' => 'Series No Intent Org',
        'slug' => 'series-no-intent-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Series No Intent Org BV',
        'billing_address_line1' => 'Nostraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series No Intent Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Series No Intent Site',
        'site_url' => 'https://series-no-intent.example.com',
        'base_url' => 'https://series-no-intent.example.com',
        'allowed_domains' => ['series-no-intent.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'series-no-intent-plan-' . Str::random(6),
        'name' => 'Series No Intent Plan',
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
        'name' => 'Series No Intent Owner',
        'email' => 'series-no-intent-owner+' . Str::random(6) . '@example.com',
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
        amount: 80,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'series_no_intent_test']
    );

    // Deliberately create series WITHOUT intent_keys - should use defaults
    $series = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'Knowledge Base Chain Without Intent',
        'main_topic' => 'Knowledge management',
        'primary_keyword' => 'knowledge base',
        'supporting_keywords' => ['documentation', 'help center'],
        'intent_keys' => null, // Explicitly null - must use defaults
        'audience' => 'operations',
        'articles_count' => 4,
        'status' => 'strategy_generated',
        'strategy_json' => [
            'angle' => 'Knowledge base pillar with supporting articles.',
            'articles' => [
                [
                    'article_number' => 1,
                    'title' => 'Pillar KB article',
                    'primary_keyword' => 'knowledge base pillar',
                    'output_type' => 'kb_article', // Knowledge Base
                    'internal_links_to' => [2, 3, 4],
                ],
                [
                    'article_number' => 2,
                    'title' => 'Supporting KB article 1',
                    'primary_keyword' => 'documentation system',
                    'output_type' => 'kb_article',
                    'internal_links_to' => [1],
                ],
                [
                    'article_number' => 3,
                    'title' => 'Supporting KB article 2',
                    'primary_keyword' => 'help center setup',
                    'output_type' => 'kb_article',
                    'internal_links_to' => [1],
                ],
                [
                    'article_number' => 4,
                    'title' => 'Supporting KB article 3',
                    'primary_keyword' => 'support portal',
                    'output_type' => 'kb_article',
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

    Queue::assertPushed(GenerateSeriesRunArticleJob::class, 4);

    $run = ContentSeriesGenerationRun::query()
        ->where('series_id', (string) $series->id)
        ->latest('created_at')
        ->firstOrFail();

    $runArticles = ContentSeriesGenerationRunArticle::query()
        ->where('run_id', (string) $run->id)
        ->orderBy('article_number')
        ->get();

    // Process all 4 articles - should NOT throw 422 validation error
    foreach ($runArticles as $runArticle) {
        app(SeriesArticleGenerationService::class)->generateRunArticle($runArticle, 1, 3);
    }

    $series->refresh();
    $run->refresh();

    // All 4 briefs should be created successfully
    expect((string) $series->status)->toBe('ready')
        ->and((string) $run->status)->toBe('completed')
        ->and((int) $run->completed_articles)->toBe(4)
        ->and((int) $run->failed_articles)->toBe(0);

    $briefs = Brief::query()
        ->whereHas('content', fn ($query) => $query->where('series_id', (string) $series->id))
        ->orderBy('created_at')
        ->get();

    expect($briefs)->toHaveCount(4);

    // Knowledge Base output type should get default intent keys: ['educate', 'explain', 'guide']
    // Plus article-specific extensions like 'compare' for pillar
    foreach ($briefs as $brief) {
        $intentKeys = (array) data_get($brief->client_refs, 'taxonomy.intent_keys', []);
        expect($intentKeys)->not->toBeEmpty()
            ->and($intentKeys)->toContain('educate');
    }
});
