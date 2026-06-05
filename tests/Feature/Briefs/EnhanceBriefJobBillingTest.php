<?php

use App\Actions\Briefs\EnhanceBriefAction;
use App\Jobs\Briefs\EnhanceBriefJob;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\CreditReservation;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\CreditReservationService;
use App\Services\CreditWalletService;
use App\Services\Entitlements\FeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('captures reserved credits when enhance brief job succeeds with billing enabled', function () {
    [, $workspace, $site, $user, $brief] = makeEnhanceJobContext('brief-intel-job-success');

    setEnhanceJobEntitlement($workspace, 'brief_intelligence_enabled', 'bool', true);
    setEnhanceJobEntitlement($workspace, 'brief_intelligence_billing_enabled', 'bool', true);
    setEnhanceJobEntitlement($workspace, 'brief_intelligence_credits_per_run', 'int', 3);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 20,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['event' => 'brief-intelligence-job-success'],
    );

    $enhanceAction = \Mockery::mock(EnhanceBriefAction::class);
    $enhanceAction->shouldReceive('execute')
        ->once()
        ->andReturn([
            'suggestions_created' => 4,
            'analysis' => ['score' => 78],
            'input_hash' => 'hash-job-success',
            'intelligence_summary' => 'Generated summary',
            'linked_research' => null,
            'llm' => ['provider' => 'openai', 'model' => 'gpt-4.1-mini', 'request_id' => 'req-job-success'],
        ]);

    $job = new EnhanceBriefJob((string) $brief->id, 'run-success', false, (int) $user->id);
    $job->handle(
        $enhanceAction,
        app(FeatureGate::class),
        app(CreditReservationService::class),
        app(CreditWalletService::class),
    );

    $reservation = CreditReservation::query()
        ->where('context_type', Brief::class)
        ->where('context_id', $brief->id)
        ->where('purpose', 'brief_intelligence_enhance')
        ->first();

    expect($reservation)->not->toBeNull()
        ->and((string) $reservation->status)->toBe(CreditReservation::STATUS_CAPTURED)
        ->and((string) data_get($brief->fresh()->client_refs, 'brief_intelligence.runtime.status'))->toBe('succeeded');
});

it('releases reserved credits and marks runtime failed when enhance brief job throws', function () {
    [, $workspace, $site, $user, $brief] = makeEnhanceJobContext('brief-intel-job-fail');

    setEnhanceJobEntitlement($workspace, 'brief_intelligence_enabled', 'bool', true);
    setEnhanceJobEntitlement($workspace, 'brief_intelligence_billing_enabled', 'bool', true);
    setEnhanceJobEntitlement($workspace, 'brief_intelligence_credits_per_run', 'int', 3);

    app(CreditWalletService::class)->addCredits(
        clientSiteId: (string) $site->id,
        amount: 20,
        type: CreditWalletService::TYPE_ALLOWANCE,
        meta: ['event' => 'brief-intelligence-job-fail'],
    );

    $enhanceAction = \Mockery::mock(EnhanceBriefAction::class);
    $enhanceAction->shouldReceive('execute')
        ->once()
        ->andThrow(new RuntimeException('Synthetic enhancement failure for testing.'));

    $job = new EnhanceBriefJob((string) $brief->id, 'run-fail', false, (int) $user->id);

    expect(fn () => $job->handle(
        $enhanceAction,
        app(FeatureGate::class),
        app(CreditReservationService::class),
        app(CreditWalletService::class),
    ))->toThrow(RuntimeException::class, 'Synthetic enhancement failure');

    $reservation = CreditReservation::query()
        ->where('context_type', Brief::class)
        ->where('context_id', $brief->id)
        ->where('purpose', 'brief_intelligence_enhance')
        ->first();

    $brief->refresh();

    expect($reservation)->not->toBeNull()
        ->and((string) $reservation->status)->toBe(CreditReservation::STATUS_RELEASED)
        ->and((string) data_get($brief->client_refs, 'brief_intelligence.runtime.status'))->toBe('failed')
        ->and((string) data_get($brief->client_refs, 'brief_intelligence.runtime.failure_reason'))->toContain('Synthetic enhancement failure');
});

function makeEnhanceJobContext(string $prefix = 'brief-intel-job'): array
{
    $organization = Organization::query()->create([
        'name' => 'Brief Intelligence Job Org',
        'slug' => $prefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Brief Job BV',
        'billing_address_line1' => 'Queue Street 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Brief Intelligence Job Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Brief Intelligence Job Site',
        'site_url' => 'https://brief-intel-job.example.com',
        'allowed_domains' => ['brief-intel-job.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => $prefix . '-plan'],
        [
            'name' => 'Brief Job Plan',
            'slug' => $prefix . '-plan',
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
        'name' => 'Brief Job User',
        'email' => $prefix . '+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $brief = Brief::query()->create([
        'client_site_id' => $site->id,
        'created_by_user_id' => $user->id,
        'status' => 'draft',
        'source' => 'client_ui',
        'progress' => 0,
        'title' => 'Enhance me',
        'language' => 'en',
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'primary_keyword' => 'ai governance',
    ]);

    return [$organization, $workspace, $site, $user, $brief];
}

function setEnhanceJobEntitlement(Workspace $workspace, string $featureKey, string $valueType, mixed $value): void
{
    WorkspaceEntitlement::query()->updateOrCreate(
        [
            'workspace_id' => $workspace->id,
            'feature_key' => $featureKey,
        ],
        [
            'id' => (string) Str::uuid(),
            'organization_id' => $workspace->organization_id,
            'value_type' => $valueType,
            'value_bool' => $valueType === 'bool' ? (bool) $value : null,
            'value_int' => $valueType === 'int' ? (int) $value : null,
            'value_string' => $valueType === 'string' ? (string) $value : null,
            'value_json' => $valueType === 'json' ? (array) $value : null,
            'source' => 'manual',
            'effective_at' => now()->subMinute(),
            'expires_at' => null,
            'refreshed_at' => now(),
        ]
    );
}
