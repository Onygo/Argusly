<?php

use App\Models\ClientSite;
use App\Models\ContentSeries;
use App\Models\CreditAction;
use App\Models\CreditLedgerEntry;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('deducts credits once up front with a series_generation ledger entry', function () {
    config(['llm.providers.openai.api_key' => 'test-api-key']);
    config(['llm.providers.openai.default_model' => 'gpt-4.1-mini']);

    Http::fake([
        '*/v1/responses' => Http::sequence()
            ->push([
                'output_text' => json_encode([
                    'title' => 'Article A',
                    'meta' => ['description' => 'desc', 'keywords' => ['a']],
                    'sections' => [
                        ['heading' => 'Intro', 'html' => '<p>' . str_repeat('A governance paragraph. ', 30) . '</p>'],
                    ],
                    'links' => [],
                ]),
            ])
            ->push([
                'output_text' => json_encode([
                    'title' => 'Article B',
                    'meta' => ['description' => 'desc', 'keywords' => ['b']],
                    'sections' => [
                        ['heading' => 'Intro', 'html' => '<p>' . str_repeat('Another governance paragraph. ', 30) . '</p>'],
                    ],
                    'links' => [],
                ]),
            ]),
    ]);

    $organization = Organization::query()->create([
        'name' => 'Series Credits Org',
        'slug' => 'series-credits-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Series Credits Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series Credits Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Series Credits Site',
        'site_url' => 'https://series-credits.example.com',
        'base_url' => 'https://series-credits.example.com',
        'allowed_domains' => ['series-credits.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'series-credits-plan-' . Str::random(6),
        'name' => 'Series Credits Plan',
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
        'name' => 'Series Credits Owner',
        'email' => 'series-credits-owner+' . Str::random(6) . '@example.com',
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
        'credits_cost' => 5,
        'label_nl' => 'Article',
        'label_en' => 'Article',
        'is_active' => true,
        'meta' => [],
    ]);

    $walletService = app(CreditWalletService::class);
    $walletService->addCredits(
        clientSiteId: (string) $site->id,
        amount: 20,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'test_funding']
    );

    $series = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'Series Credits',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance workflow',
        'supporting_keywords' => ['governance policy', 'workflow controls'],
        'articles_count' => 2,
        'status' => 'strategy_generated',
        'strategy_json' => [
            'angle' => 'Connected governance chain.',
            'articles' => [
                ['article_number' => 1, 'title' => 'Article A', 'primary_keyword' => 'kw a', 'secondary_keywords' => [], 'internal_links_to' => [2]],
                ['article_number' => 2, 'title' => 'Article B', 'primary_keyword' => 'kw b', 'secondary_keywords' => [], 'internal_links_to' => [1]],
            ],
        ],
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->post(route('app.content.series.generate-articles', $series))
        ->assertRedirect();

    $entry = CreditLedgerEntry::query()
        ->where('source_type', ContentSeries::class)
        ->where('source_id', (string) $series->id)
        ->where('type', 'series_generation')
        ->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->amount)->toBe(-10);

    $summary = $walletService->getSummary((string) $site->id);
    expect((int) $summary['available'])->toBe(8);
});
