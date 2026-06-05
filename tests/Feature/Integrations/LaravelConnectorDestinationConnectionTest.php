<?php

use App\Models\ClientSite;
use App\Models\ContentDestination;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeLaravelDestinationConnectionContext(): array
{
    $organization = Organization::query()->create([
        'name' => 'Laravel Health Org',
        'slug' => 'laravel-health-org-'.Str::random(5),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Laravel Health Org BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Laravel Health Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Health Billing Site',
        'site_url' => 'https://health.example.com',
        'base_url' => 'https://health.example.com',
        'allowed_domains' => ['health.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'laravel-health-plan'],
        [
            'name' => 'Laravel Health Plan',
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

    $destination = ContentDestination::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'name' => 'Health Destination',
        'type' => 'laravel',
        'status' => 'active',
        'environment' => 'production',
        'default_language' => 'en',
        'tracking_enabled' => true,
        'seo_audit_enabled' => true,
        'config' => [
            'billing_client_site_id' => (string) $site->id,
            'laravel_connector' => [
                'base_url' => 'https://health.example.com',
                'site_id' => 'health-site-1',
                'sync_endpoint' => '/api/publishlayer/sync',
                'api_key_encrypted' => Crypt::encryptString('health-secret-123'),
                'enabled' => true,
                'mode' => 'hosted_views',
            ],
        ],
    ]);

    return compact('organization', 'user', 'workspace', 'site', 'destination');
}

it('tests laravel connector destination connectivity from the developer ui', function () {
    $ctx = makeLaravelDestinationConnectionContext();

    Http::fake([
        'https://health.example.com/api/publishlayer/health' => Http::response([
            'ok' => true,
            'checks' => [],
        ], 200),
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('app.developer.destinations.test-connection', $ctx['destination']))
        ->assertRedirect()
        ->assertSessionHas('status', 'Laravel connector health check succeeded. (HTTP 200)');
});

it('surfaces laravel connector connection test failures to operators', function () {
    $ctx = makeLaravelDestinationConnectionContext();

    Http::fake([
        'https://health.example.com/api/publishlayer/health' => Http::response([
            'ok' => false,
            'message' => 'The provided site identifier does not match this connector.',
        ], 422),
    ]);

    $this->actingAs($ctx['user'])
        ->post(route('app.developer.destinations.test-connection', $ctx['destination']))
        ->assertRedirect()
        ->assertSessionHasErrors([
            'destinations' => 'The provided site identifier does not match this connector. (HTTP 422)',
        ]);
});
