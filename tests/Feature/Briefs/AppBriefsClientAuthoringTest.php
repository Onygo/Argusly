<?php

use App\Jobs\GenerateDraftJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeBriefAuthContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Brief Org',
        'slug' => 'brief-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Brief Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Brief Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Brief Site',
        'site_url' => 'https://briefs.example.com',
        'allowed_domains' => ['briefs.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = \App\Models\Plan::query()->firstOrCreate(
        ['key' => 'test-plan'],
        [
            'name' => 'Test Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ]
    );

    \App\Models\Subscription::query()->create([
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
        'name' => 'Brief User',
        'email' => 'brief+' . Str::random(5) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$organization, $workspace, $site, $user];
}

it('allows creating a brief from the client dashboard', function () {
    [, $workspace, $site, $user] = makeBriefAuthContext();

    $response = $this->actingAs($user)->post(route('app.briefs.store'), [
        'site_id' => $site->id,
        'title' => 'Client UI brief',
        'content_type' => 'blog',
        'language' => 'nl',
        'primary_keyword' => 'ai content governance',
        'secondary_keywords' => "governance\ncompliance",
        'target_audience' => 'Marketing managers',
        'desired_length_min' => 900,
        'desired_length_max' => 1200,
    ]);

    $response->assertRedirect();

    $brief = Brief::query()->where('title', 'Client UI brief')->first();
    expect($brief)->not->toBeNull();
    expect((string) $brief->client_site_id)->toBe((string) $site->id);
    expect((string) $brief->source)->toBe('client_ui');
    expect((string) $brief->status)->toBe('draft');
    expect($brief->secondary_keywords)->toBe(['governance', 'compliance']);
    expect((string) $brief->content_type)->toBe('blog');
    expect((string) $brief->output_type)->toBe('kb_article');
    expect((string) $brief->clientSite?->workspace_id)->toBe((string) $workspace->id);
});

it('blocks creating a brief for a site outside the users organization', function () {
    [, , , $user] = makeBriefAuthContext();

    $foreignOrg = Organization::query()->create([
        'name' => 'Foreign Org',
        'slug' => 'foreign-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);
    $foreignWorkspace = Workspace::query()->create([
        'name' => 'Foreign Workspace',
        'organization_id' => $foreignOrg->id,
    ]);
    $foreignSite = ClientSite::query()->create([
        'workspace_id' => $foreignWorkspace->id,
        'type' => 'wordpress',
        'name' => 'Foreign Site',
        'site_url' => 'https://foreign.example.com',
        'allowed_domains' => ['foreign.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $response = $this->from(route('app.briefs.create'))
        ->actingAs($user)
        ->post(route('app.briefs.store'), [
            'site_id' => $foreignSite->id,
            'title' => 'Should fail',
            'content_type' => 'blog',
            'language' => 'nl',
        ]);

    $response->assertRedirect(route('app.briefs.create'));
    $response->assertSessionHasErrors(['site_id']);
    expect(Brief::query()->where('title', 'Should fail')->exists())->toBeFalse();
});

it('generates a draft from a brief and queues generation job', function () {
    Queue::fake();
    [, $workspace, $site, $user] = makeBriefAuthContext();
    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'progress' => 0,
        'title' => 'Generate me',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'desired_length_min' => 900,
        'desired_length_max' => 1200,
    ]);

    $response = $this->actingAs($user)->post(route('app.briefs.generate-draft', $brief));
    $response->assertRedirect();

    $brief->refresh();
    $draft = $brief->drafts()->latest('created_at')->first();

    expect($draft)->not->toBeNull();
    expect((string) $brief->status)->toBe('done');
    expect((string) $draft->status)->toBe('queued');
    expect((string) $draft->content_id)->not->toBe('');
    expect((string) $draft->client_site_id)->toBe((string) $site->id);
    expect((string) $draft->content?->workspace_id)->toBe((string) $workspace->id);
    expect((int) $draft->credit_cost)->toBe(10);
    expect((int) data_get($draft->meta, 'requested_max_output_tokens'))->toBe(8000);
    expect((int) data_get($draft->meta, 'required_credits'))->toBe(10);

    Queue::assertPushed(GenerateDraftJob::class, 1);
});

it('calculates higher required credits for long article output', function () {
    Queue::fake();
    [, , $site, $user] = makeBriefAuthContext();
    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 100,
        type: CreditWalletService::TYPE_ALLOWANCE
    );

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'progress' => 0,
        'title' => 'Long output article',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
    ]);

    $response = $this->actingAs($user)->post(route('app.briefs.generate-draft', $brief), [
        'requested_max_output_tokens' => 10000,
    ]);
    $response->assertRedirect();

    $brief->refresh();
    $draft = $brief->drafts()->latest('created_at')->first();

    expect($draft)->not->toBeNull();
    expect((int) $draft->credit_cost)->toBe(12);
    expect((int) data_get($draft->meta, 'requested_max_output_tokens'))->toBe(10000);
    expect((int) data_get($draft->meta, 'required_credits'))->toBe(12);
});

it('blocks generate draft from client dashboard when credits are insufficient', function () {
    Queue::fake();
    [, , $site, $user] = makeBriefAuthContext();

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'progress' => 0,
        'title' => 'Insufficient credits brief',
        'language' => 'nl',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'desired_length_min' => 900,
        'desired_length_max' => 1200,
    ]);

    $response = $this->from(route('app.briefs.show', $brief))
        ->actingAs($user)
        ->post(route('app.briefs.generate-draft', $brief));

    $response->assertRedirect(route('app.briefs.show', $brief));
    $response->assertSessionHasErrors(['brief']);
    expect(session('errors')->first('brief'))->toContain('Insufficient credits. Required:');

    Queue::assertNotPushed(GenerateDraftJob::class);
});
