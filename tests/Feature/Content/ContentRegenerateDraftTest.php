<?php

use App\Jobs\DeliverDraftJob;
use App\Jobs\RegenerateContentDraftJob;
use App\Models\ClientSite;
use App\Models\Brief;
use App\Models\Content;
use App\Models\ContentRevision;
use App\Models\CreditAction;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('regenerates draft from content tab, consumes credits, and stores a new revision', function () {
    config(['llm.providers.openai.api_key' => 'test-api-key']);
    config(['llm.providers.openai.default_model' => 'gpt-4.1-mini']);

    Http::fake([
        '*/v1/responses' => Http::response([
            'output_text' => json_encode([
                'title' => 'Regenerated title',
                'meta' => [
                    'description' => 'A concise SEO meta description.',
                    'keywords' => ['ai', 'content'],
                ],
                'sections' => [
                    [
                        'heading' => 'Intro',
                        'html' => '<p>' . str_repeat('Regenerated content paragraph. ', 35) . '</p>',
                    ],
                ],
                'links' => [],
            ]),
        ]),
    ]);

    $organization = Organization::create([
        'name' => 'Regenerate Org',
        'slug' => 'regen-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Regenerate Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::create([
        'name' => 'Regenerate Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Regenerate Site',
        'site_url' => 'https://regen.example.com',
        'allowed_domains' => ['regen.example.com'],
        'is_active' => true,
    ]);
    attachActiveSubscription($organization, $site);

    $content = Content::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Original title',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
    ]);

    $brief = Brief::create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'queued',
        'progress' => 0,
        'title' => 'Brief title',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);

    $draft = Draft::create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Original draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Old content</p>',
        'meta' => [
            'language' => 'en',
            'preferred_length' => 'medium',
        ],
        'links' => [],
        'credit_cost' => 6,
    ]);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 20,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'test_funding']
    );

    $user = User::create([
        'name' => 'Owner',
        'email' => 'owner+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('app.content.regenerate', $content), ['run_sync' => 1])
        ->assertRedirect();

    $draft->refresh();
    $content->refresh();

    expect($draft->status)->toBe('generated');
    expect($draft->title)->toBe('Regenerated title');
    expect(ContentRevision::query()->where('content_id', $content->id)->count())->toBe(1);
    expect($content->current_revision_id)->not->toBeNull();

    $summary = app(CreditWalletService::class)->getSummary((string) $site->id);
    expect((int) $summary['available'])->toBe(13);
});

it('queues wp repush when auto repush toggle is enabled', function () {
    config(['llm.providers.openai.api_key' => 'test-api-key']);
    config(['llm.providers.openai.default_model' => 'gpt-4.1-mini']);

    Queue::fake();
    Http::fake([
        '*/v1/responses' => Http::response([
            'output_text' => json_encode([
                'title' => 'Repush regenerated title',
                'meta' => ['description' => 'SEO desc', 'keywords' => ['wp']],
                'sections' => [
                    ['heading' => 'Intro', 'html' => '<p>' . str_repeat('Repush content. ', 40) . '</p>'],
                ],
                'links' => [],
            ]),
        ]),
    ]);

    $organization = Organization::create([
        'name' => 'Repush Org',
        'slug' => 'repush-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Repush Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);
    $workspace = Workspace::create(['name' => 'Ws', 'organization_id' => $organization->id]);
    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Site',
        'site_url' => 'https://repush.example.com',
        'allowed_domains' => ['repush.example.com'],
        'is_active' => true,
    ]);
    attachActiveSubscription($organization, $site);
    $content = Content::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Original title',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
    ]);
    $brief = Brief::create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'queued',
        'progress' => 0,
        'title' => 'Brief title',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);
    $draft = Draft::create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Original draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Old content</p>',
        'meta' => ['language' => 'en', 'preferred_length' => 'medium'],
        'links' => [],
        'credit_cost' => 4,
    ]);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 20,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'test_funding']
    );

    $user = User::create([
        'name' => 'Owner',
        'email' => 'owner-repush+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('app.content.regenerate', $content), ['run_sync' => 1, 'auto_repush_to_wp' => 1])
        ->assertRedirect();

    $draft->refresh();
    expect($draft->status)->toBe('ready_to_deliver');
    expect($draft->delivery_status)->toBe('pending');

    Queue::assertPushed(DeliverDraftJob::class, function (DeliverDraftJob $job) use ($draft) {
        return (string) $job->draftId === (string) $draft->id;
    });
});

it('auto resolves missing draft credit cost from credit action before regeneration', function () {
    config(['llm.providers.openai.api_key' => 'test-api-key']);
    config(['llm.providers.openai.default_model' => 'gpt-4.1-mini']);

    Http::fake([
        '*/v1/responses' => Http::response([
            'output_text' => json_encode([
                'title' => 'Recovered cost title',
                'meta' => ['description' => 'SEO desc', 'keywords' => ['ai']],
                'sections' => [
                    ['heading' => 'Intro', 'html' => '<p>' . str_repeat('Recovered cost content. ', 40) . '</p>'],
                ],
                'links' => [],
            ]),
        ]),
    ]);

    $action = CreditAction::create([
        'id' => (string) Str::uuid(),
        'key' => 'content.article',
        'category' => 'content',
        'credits_cost' => 10,
        'label_nl' => 'Article',
        'label_en' => 'Article',
        'is_active' => true,
        'meta' => [],
    ]);

    $organization = Organization::create([
        'name' => 'Cost Org',
        'slug' => 'cost-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Cost Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);
    $workspace = Workspace::create(['name' => 'Ws', 'organization_id' => $organization->id]);
    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Site',
        'site_url' => 'https://cost.example.com',
        'allowed_domains' => ['cost.example.com'],
        'is_active' => true,
    ]);
    attachActiveSubscription($organization, $site);
    $content = Content::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Original title',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
    ]);
    $brief = Brief::create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'queued',
        'progress' => 0,
        'title' => 'Brief title',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);
    $draft = Draft::create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Original draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Old content</p>',
        'meta' => ['language' => 'en'],
        'links' => [],
        'credit_cost' => null,
    ]);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 30,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['reason' => 'test_funding']
    );

    $user = User::create([
        'name' => 'Owner',
        'email' => 'owner-cost+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('app.content.regenerate', $content), ['run_sync' => 1])
        ->assertRedirect();

    $draft->refresh();
    expect((int) $draft->credit_cost)->toBe(10);
    expect((string) $draft->credit_action_id)->toBe((string) $action->id);
    expect($draft->status)->toBe('generated');
});

it('queues regeneration by default to avoid request timeout', function () {
    Queue::fake();

    $organization = Organization::create([
        'name' => 'Async Org',
        'slug' => 'async-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Async Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);
    $workspace = Workspace::create(['name' => 'Ws', 'organization_id' => $organization->id]);
    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Site',
        'site_url' => 'https://async.example.com',
        'allowed_domains' => ['async.example.com'],
        'is_active' => true,
    ]);
    attachActiveSubscription($organization, $site);
    $content = Content::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Original title',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'wp',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
    ]);
    $brief = Brief::create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'queued',
        'progress' => 0,
        'title' => 'Brief title',
        'language' => 'en',
        'output_type' => 'kb_article',
    ]);
    $draft = Draft::create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'ready',
        'title' => 'Original draft',
        'output_type' => 'kb_article',
        'content_html' => '<p>Old content</p>',
        'meta' => ['language' => 'en'],
        'links' => [],
        'credit_cost' => 5,
    ]);

    $user = User::create([
        'name' => 'Owner',
        'email' => 'owner-async+' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $this->actingAs($user)
        ->post(route('app.content.regenerate', $content))
        ->assertRedirect();

    Queue::assertPushed(RegenerateContentDraftJob::class, function (RegenerateContentDraftJob $job) use ($draft, $user) {
        return (string) $job->draftId === (string) $draft->id
            && (int) $job->userId === (int) $user->id
            && $job->autoRepushToWp === false;
    });
});

function attachActiveSubscription(Organization $organization, ClientSite $site): Subscription
{
    $plan = Plan::create([
        'id' => (string) Str::uuid(),
        'key' => 'content-test-plan-' . Str::random(6),
        'name' => 'Content Test Plan',
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

    $subscription = Subscription::create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
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

    $organization->active_subscription_id = $subscription->id;
    $organization->save();

    return $subscription;
}
