<?php

use App\Models\ClientSite;
use App\Models\ContentSeries;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates a content series in the user organization scope', function () {
    $organization = Organization::query()->create([
        'name' => 'Series Org',
        'slug' => 'series-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Series Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Series Site',
        'site_url' => 'https://series.example.com',
        'base_url' => 'https://series.example.com',
        'allowed_domains' => ['series.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'series-plan-' . Str::random(6),
        'name' => 'Series Plan',
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
        'name' => 'Series Owner',
        'email' => 'series-owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('app.content.series.store'), [
            'site_id' => $site->id,
            'name' => 'Q2 Chained Engine',
            'main_topic' => 'AI governance',
            'primary_keyword' => 'ai governance workflow',
            'supporting_keywords' => "ai policy\ncontent workflow\nbrand controls",
            'intents' => ['educate', 'commercial'],
            'audience' => 'B2B SaaS marketers',
            'tone' => 'clear and practical',
            'funnel_stage' => 'consideration',
            'articles_count' => 4,
        ])
        ->assertRedirect();

    $series = ContentSeries::query()->first();

    expect($series)->not->toBeNull()
        ->and((int) $series->organization_id)->toBe($organization->id)
        ->and((string) $series->site_id)->toBe((string) $site->id)
        ->and((string) $series->status)->toBe('draft')
        ->and((int) $series->articles_count)->toBe(4)
        ->and((array) $series->supporting_keywords)->toContain('ai policy')
        ->and((array) $series->intent_keys)->toBe(['educate', 'commercial']);

    $briefing = <<<'BRIEF'
Content Briefing
Working title

The Biggest AI Bottleneck Isn't Talent. It's Your Marketing Operating System.
Primary keyword

AI marketing operating system
Secondary keywords
agentic marketing
AI content operations
AI governance marketing
Target audience
CMOs
Marketing Directors
Core message

Build institutional AI capability through an AI Native Marketing Operating System.
Angle

Explain why AI capability should live inside the marketing operating system instead of a few specialists.
BRIEF;

    $this->actingAs($user)
        ->post(route('app.content.series.store'), [
            'site_id' => $site->id,
            'content_type' => 'post',
            'complete_briefing' => $briefing,
            'articles_count' => 5,
        ])
        ->assertRedirect();

    $briefedSeries = ContentSeries::query()
        ->where('name', "The Biggest AI Bottleneck Isn't Talent. It's Your Marketing Operating System.")
        ->firstOrFail();

    expect((string) $briefedSeries->main_topic)->toBe('AI marketing operating system')
        ->and((string) $briefedSeries->primary_keyword)->toBe('AI marketing operating system')
        ->and((array) $briefedSeries->supporting_keywords)->toContain('agentic marketing')
        ->and((string) $briefedSeries->audience)->toContain('CMOs')
        ->and(data_get($briefedSeries->strategy_json, 'meta.complete_briefing.raw'))->toContain('Content Briefing')
        ->and(data_get($briefedSeries->strategy_json, 'meta.strategic_positioning'))->toContain('AI Native Marketing Operating System');
});

it('stores supplied article titles as an initial editorial series plan', function () {
    $organization = Organization::query()->create([
        'name' => 'Series Plan Org',
        'slug' => 'series-plan-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Series Plan BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series Plan Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Series Plan Site',
        'site_url' => 'https://series-plan.example.com',
        'base_url' => 'https://series-plan.example.com',
        'allowed_domains' => ['series-plan.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'series-editorial-plan-' . Str::random(6),
        'name' => 'Series Editorial Plan',
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
        'name' => 'Series Plan Owner',
        'email' => 'series-plan-owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('app.content.series.store'), [
            'site_id' => $site->id,
            'name' => 'Google marketing lessons chain',
            'main_topic' => 'Autonomous marketing',
            'primary_keyword' => 'autonomous marketing',
            'supporting_keywords' => "ai marketing\nmarketing operating system",
            'article_plan' => implode("\n", [
                'What Google\'s Media Lab gets right. And what is still missing. - Use Google as the market signal, then contrast it with the Argusly view.',
                'Why AI tools are not enough anymore. - Explain why context and governance matter.',
                'From AI content to autonomous marketing. - Show the shift from production to operating system.',
            ]),
            'source_references' => implode("\n", [
                'https://business.google.com/en-all/think/ai-excellence/media-lab-marketing-lessons-2026/',
                'OpenAI blog',
                'Microsoft AI marketing',
            ]),
            'strategic_positioning' => 'Do not summarize Google. Use sources as evidence that marketing is moving toward autonomous marketing.',
            'audience' => 'B2B marketing leaders',
            'tone' => 'strategic and direct',
            'funnel_stage' => 'consideration',
            'articles_count' => 2,
        ])
        ->assertRedirect();

    $series = ContentSeries::query()->firstOrFail();

    expect((int) $series->articles_count)->toBe(3)
        ->and(data_get($series->strategy_json, 'meta.source'))->toBe('editorial_article_plan')
        ->and(data_get($series->strategy_json, 'meta.source_url'))->toBe('https://business.google.com/en-all/think/ai-excellence/media-lab-marketing-lessons-2026/')
        ->and(data_get($series->strategy_json, 'meta.source_references'))->toBe([
            'https://business.google.com/en-all/think/ai-excellence/media-lab-marketing-lessons-2026/',
            'OpenAI blog',
            'Microsoft AI marketing',
        ])
        ->and(data_get($series->strategy_json, 'meta.strategic_positioning'))->toContain('autonomous marketing')
        ->and(data_get($series->strategy_json, 'articles.0.title'))->toBe('What Google\'s Media Lab gets right. And what is still missing.')
        ->and(data_get($series->strategy_json, 'articles.0.editorial_angle'))->toContain('Argusly view')
        ->and(data_get($series->strategy_json, 'articles.0.is_pillar'))->toBeTrue()
        ->and(data_get($series->strategy_json, 'articles.1.internal_links_to'))->toBe([1]);
});

it('renders the series setup form with tag-based content intent selection', function () {
    $organization = Organization::query()->create([
        'name' => 'Series Setup Org',
        'slug' => 'series-setup-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Series Setup BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series Setup Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Series Setup Site',
        'site_url' => 'https://series-setup.example.com',
        'base_url' => 'https://series-setup.example.com',
        'allowed_domains' => ['series-setup.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => 'series-setup-plan-' . Str::random(6),
        'name' => 'Series Setup Plan',
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
        'name' => 'Series Setup Owner',
        'email' => 'series-setup-owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($user)
        ->get(route('app.content.series.create'))
        ->assertOk()
        ->assertSee('Step 1: Series setup')
        ->assertSee('Complete briefing')
        ->assertSee('Select one or more intents')
        ->assertSee('Commercial')
        ->assertDontSee('Make pillar');
});
