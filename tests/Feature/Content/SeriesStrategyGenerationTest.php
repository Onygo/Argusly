<?php

use App\Models\ClientSite;
use App\Models\ContentSeries;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('generates and stores series strategy json', function () {
    config(['llm.providers.openai.api_key' => 'test-api-key']);
    config(['llm.providers.openai.default_model' => 'gpt-4.1-mini']);

    Http::fake([
        '*/v1/responses' => Http::response([
            'output_text' => json_encode([
                'angle' => 'Build authority then conversion through linked practical guides.',
                'articles' => [
                    [
                        'title' => 'AI governance fundamentals',
                        'primary_keyword' => 'ai governance fundamentals',
                        'secondary_keywords' => ['ai policy', 'risk controls'],
                        'internal_links_to' => [2],
                    ],
                    [
                        'title' => 'Operational governance checklist',
                        'primary_keyword' => 'governance checklist',
                        'secondary_keywords' => ['workflow checklist'],
                        'internal_links_to' => [1, 3],
                    ],
                    [
                        'title' => 'Executive reporting for governance',
                        'primary_keyword' => 'governance reporting',
                        'secondary_keywords' => ['kpi dashboard'],
                        'internal_links_to' => [1],
                    ],
                ],
            ]),
        ]),
    ]);

    $organization = Organization::query()->create([
        'name' => 'Series Strategy Org',
        'slug' => 'series-strategy-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Series Strategy Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series Strategy Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Series Strategy Site',
        'site_url' => 'https://series-strategy.example.com',
        'base_url' => 'https://series-strategy.example.com',
        'allowed_domains' => ['series-strategy.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'series-strategy-plan-' . Str::random(6),
        'name' => 'Series Strategy Plan',
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
        'name' => 'Strategy Owner',
        'email' => 'series-strategy-owner+' . Str::random(6) . '@example.com',
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
        'name' => 'Strategy Series',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance workflow',
        'supporting_keywords' => ['ai policy', 'governance checklist'],
        'intent_keys' => ['educate', 'commercial'],
        'articles_count' => 3,
        'status' => 'draft',
        'strategy_json' => [
            'meta' => [
                'source_url' => 'https://example.com/reference-article',
                'source_references' => [
                    'https://example.com/reference-article',
                    'OpenAI blog',
                ],
                'strategic_positioning' => 'Position Argusly as the marketing operating system for autonomous workflows.',
            ],
        ],
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->post(route('app.content.series.generate-strategy', $series))
        ->assertRedirect(route('app.content.series.structure', $series))
        ->assertSessionHas('status');

    $series->refresh();

    expect((string) $series->status)->toBe('strategy_generated')
        ->and((string) data_get($series->strategy_json, 'angle'))->toContain('Build authority')
        ->and(count((array) data_get($series->strategy_json, 'articles')))->toBe(3)
        ->and((bool) data_get($series->strategy_json, 'articles.0.is_pillar'))->toBeTrue();

    Http::assertSent(function ($request): bool {
        return str_contains($request->body(), 'Content intent: educate, commercial.')
            && str_contains($request->body(), 'reference-article')
            && str_contains($request->body(), 'OpenAI blog')
            && str_contains($request->body(), 'marketing operating system');
    });
});
