<?php

use App\Models\ClientSite;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use App\Services\DraftComparison\DraftComparisonFeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('resolves draft compare capabilities from workspace entitlements', function () {
    $organization = Organization::query()->create([
        'name' => 'Draft Compare Gate Org',
        'slug' => 'draft-compare-gate-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Draft Compare Gate Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Draft Compare Gate Site',
        'site_url' => 'https://draft-compare-gate.example.com',
        'allowed_domains' => ['draft-compare-gate.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'slug' => 'draft-compare-gate-plan',
        'key' => 'draft-compare-gate-plan',
        'name' => 'Draft Compare Gate Plan',
        'interval' => 'month',
        'monthly_price_cents' => 7900,
        'price_cents' => 7900,
        'currency' => 'EUR',
        'vat_included' => true,
        'included_credits' => 300,
        'included_credits_per_interval' => 300,
        'seat_limit' => 3,
        'limits' => ['sites' => 3, 'users' => 3],
        'is_active' => true,
    ]);

    $subscription = Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 7900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 300,
        'seat_limit' => 3,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    foreach ([
        ['draft_compare_enabled', 'bool', false],
        ['draft_compare_max_models', 'int', 1],
        ['draft_compare_hybrid_enabled', 'bool', false],
        ['draft_compare_scoring_enabled', 'bool', false],
        ['draft_compare_premium_models_enabled', 'bool', false],
    ] as [$key, $type, $value]) {
        WorkspaceEntitlement::query()->create([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'organization_id' => $organization->id,
            'subscription_id' => $subscription->id,
            'plan_id' => $plan->id,
            'feature_key' => $key,
            'value_type' => $type,
            'value_bool' => $type === 'bool' ? (bool) $value : null,
            'value_int' => $type === 'int' ? (int) $value : null,
            'source' => 'test',
            'effective_at' => now()->subMinute(),
            'expires_at' => now()->addDay(),
            'refreshed_at' => now(),
        ]);
    }

    $capabilities = app(DraftComparisonFeatureGate::class)->capabilitiesForWorkspace($workspace);

    expect($capabilities['enabled'])->toBeFalse()
        ->and($capabilities['max_models'])->toBe(1)
        ->and($capabilities['hybrid_enabled'])->toBeFalse()
        ->and($capabilities['scoring_enabled'])->toBeFalse()
        ->and($capabilities['premium_models_enabled'])->toBeFalse()
        ->and($capabilities['allowed_modes'])->toBe([])
        ->and((string) $capabilities['blocked_reason'])->not->toBe('');
});

it('filters premium model options when premium entitlement is disabled', function () {
    config()->set('credits.draft_compare.premium_model_patterns', ['gpt-5*']);

    $featureGate = app(DraftComparisonFeatureGate::class);
    $options = [
        [
            'key' => 'openai:gpt-5-mini',
            'provider' => 'openai',
            'provider_label' => 'OpenAI',
            'model' => 'gpt-5-mini',
            'label' => 'OpenAI - gpt-5-mini',
        ],
        [
            'key' => 'openai:gpt-4.1-mini',
            'provider' => 'openai',
            'provider_label' => 'OpenAI',
            'model' => 'gpt-4.1-mini',
            'label' => 'OpenAI - gpt-4.1-mini',
        ],
    ];

    $filtered = $featureGate->filterModelOptionsForCapabilities($options, [
        'enabled' => true,
        'max_models' => 2,
        'hybrid_enabled' => true,
        'scoring_enabled' => true,
        'premium_models_enabled' => false,
        'allowed_modes' => ['single', 'compare_two'],
        'compare_mode_enabled' => true,
        'blocked_reason' => null,
    ]);

    expect($filtered)->toHaveCount(1)
        ->and((string) data_get($filtered, '0.key'))->toBe('openai:gpt-4.1-mini')
        ->and($featureGate->containsPremiumModelSelection(['openai:gpt-5-mini']))->toBeTrue();
});
