<?php

use App\Models\ClientSite;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Connectors\ConnectorSyncRun;
use App\Models\MarketingDimensionDefinition;
use App\Models\MarketingMetricDefinition;
use App\Models\MarketingObservation;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\DataConnectors\ConnectorDatasetDiscoveryService;
use App\Services\DataConnectors\ConnectorOAuthAuthorizationUrlGenerator;
use App\Services\DataConnectors\ConnectorProviderConfigValidator;
use App\Services\DataConnectors\ConnectorSyncEngine;
use App\Services\DataConnectors\ConnectorSyncPlan;
use App\Services\DataConnectors\ConnectorTokenVault;
use App\Services\DataConnectors\DataConnectorRegistry;
use App\Services\DataConnectors\LinkedIn\LinkedInAnalyticsSyncAdapter;
use App\Services\DataConnectors\LinkedIn\LinkedInDatasetDiscoveryAdapter;
use App\Services\Social\LinkedIn\LinkedInClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('validates the LinkedIn analytics provider config and adapters', function () {
    $definition = config('data_connectors.providers.linkedin');

    app(ConnectorProviderConfigValidator::class)
        ->validateProviderDefinition('linkedin', $definition);

    $registry = app(DataConnectorRegistry::class);

    expect($registry->provider('linkedin')['config_json']['required_scopes'])
        ->toContain('r_organization_social', 'rw_organization_admin')
        ->not->toContain('w_member_social')
        ->and($registry->datasetDiscoveryAdapter('linkedin'))
        ->toBeInstanceOf(LinkedInDatasetDiscoveryAdapter::class)
        ->and($registry->syncAdapter('linkedin'))
        ->toBeInstanceOf(LinkedInAnalyticsSyncAdapter::class);
});

it('generates a LinkedIn OAuth URL with analytics scopes', function () {
    $context = phase34LinkedInContext();

    $authorization = app(ConnectorOAuthAuthorizationUrlGenerator::class)
        ->generate('linkedin', [
            'workspace_id' => $context['workspace']->id,
            'connector_provider_id' => $context['provider']->id,
            'connector_account_id' => $context['account']->id,
        ]);

    parse_str((string) parse_url($authorization->url, PHP_URL_QUERY), $query);

    expect($authorization->url)->toStartWith('https://www.linkedin.com/oauth/v2/authorization?')
        ->and($query['scope'])->toContain('openid')
        ->and($query['scope'])->toContain('profile')
        ->and($query['scope'])->toContain('r_organization_social')
        ->and($query['scope'])->toContain('rw_organization_admin')
        ->and($query['scope'])->not->toContain('w_member_social');
});

it('discovers LinkedIn organization pages into connector datasets idempotently', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.linkedin.com/v2/organizationalEntityAcls*' => Http::response([
            'elements' => [
                [
                    'role' => 'ADMINISTRATOR',
                    'state' => 'APPROVED',
                    'organizationalTarget' => 'urn:li:organization:12345',
                    'organizationalTarget~' => [
                        'id' => 12345,
                        'localizedName' => 'Argusly',
                        'vanityName' => 'argusly',
                        'access_token' => 'plain-secret',
                    ],
                ],
            ],
            'paging' => ['count' => 100, 'start' => 0, 'total' => 1],
        ]),
    ]);

    $context = phase34LinkedInContext(withDataset: false);
    $first = app(ConnectorDatasetDiscoveryService::class)->discover($context['account']);
    $second = app(ConnectorDatasetDiscoveryService::class)->discover($context['account']);

    $dataset = ConnectorDataset::query()->firstOrFail();

    expect($first['created'])->toBe(1)
        ->and($second['created'])->toBe(0)
        ->and($second['updated'])->toBe(1)
        ->and(ConnectorDataset::query()->count())->toBe(1)
        ->and($dataset->provider_key)->toBe('linkedin')
        ->and($dataset->dataset_type)->toBe('organization_page')
        ->and($dataset->external_dataset_id)->toBe('urn:li:organization:12345')
        ->and($dataset->display_name)->toBe('Argusly')
        ->and($dataset->config_json['organization_urn'])->toBe('urn:li:organization:12345')
        ->and($dataset->sync_config_json['metrics'])->toContain('impressions', 'followers')
        ->and($dataset->sync_config_json['dimensions'])->toContain('organization', 'post', 'mediaType')
        ->and($dataset->metadata_json['raw_organization']['access_token'])->toBe('[redacted]')
        ->and($dataset->hasCapability('social.organic_analytics'))->toBeTrue()
        ->and($first['sync_run']->status)->toBe(ConnectorSyncRun::STATUS_SUCCEEDED);

    Http::assertSentCount(2);
});

it('syncs fake LinkedIn analytics rows into canonical observations with dimensions and checkpoint advancement', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.linkedin.com/v2/organizationalEntityShareStatistics*' => Http::response([
            'elements' => [
                phase34LinkedInShareRow('2026-07-01', [
                    'impressionCount' => 120,
                    'clickCount' => 12,
                    'likeCount' => 8,
                    'commentCount' => 3,
                    'shareCount' => 2,
                    'engagement' => 0.2083,
                ]),
            ],
            'paging' => ['count' => 100, 'start' => 0, 'total' => 1],
        ], 200, ['X-RestLi-RateLimit-Remaining' => '42']),
        'https://api.linkedin.com/v2/organizationalEntityFollowerStatistics*' => Http::response([
            'elements' => [
                phase34LinkedInFollowerRow('2026-07-01', 432),
            ],
            'paging' => ['count' => 100, 'start' => 0, 'total' => 1],
        ]),
    ]);

    $context = phase34LinkedInContext();
    phase34CreateCanonicalDefinitions();

    $result = app(ConnectorSyncEngine::class)->sync(new ConnectorSyncPlan(
        workspace: $context['workspace'],
        clientSite: $context['site'],
        provider: 'linkedin',
        account: $context['account'],
        dataset: $context['dataset'],
        dateRangeStart: Carbon::parse('2026-07-01'),
        dateRangeEnd: Carbon::parse('2026-07-01'),
        pageSize: 100,
        runType: ConnectorSyncRun::TYPE_MANUAL,
    ));

    $observations = MarketingObservation::query()->with('dimensions')->get();
    $impressions = $observations
        ->where('metric_key', 'impressions')
        ->first();
    $followers = $observations
        ->where('metric_key', 'followers')
        ->first();

    expect($result->succeeded())->toBeTrue()
        ->and($result->metrics['pages'])->toBe(2)
        ->and($result->metrics['observations_written'])->toBe(7)
        ->and($observations)->toHaveCount(7)
        ->and($observations->pluck('metric_key')->unique()->sort()->values()->all())->toBe([
            'clicks',
            'comments',
            'engagementRate',
            'followers',
            'impressions',
            'reactions',
            'shares',
        ])
        ->and($impressions)->not->toBeNull()
        ->and($impressions->metric_value)->toBe('120.0000000000')
        ->and($impressions->unit)->toBe('count')
        ->and($impressions->period_start->toDateString())->toBe('2026-07-01')
        ->and($impressions->source_metadata_json['source_metric'])->toBe('totalShareStatistics.impressionCount')
        ->and($impressions->raw_metadata_json['row']['authorization'])->toBe('[redacted]')
        ->and($impressions->dimensions->pluck('dimension_value', 'dimension_key')->all())->toMatchArray([
            'date' => '2026-07-01',
            'organization' => 'urn:li:organization:12345',
            'post' => 'urn:li:share:phase34',
            'mediaType' => 'ARTICLE',
            'campaign' => 'phase-34-launch',
            'content' => 'phase-34-guide',
        ])
        ->and($followers)->not->toBeNull()
        ->and($followers->metric_value)->toBe('432.0000000000')
        ->and($followers->dimensions->pluck('dimension_value', 'dimension_key')->all())->toMatchArray([
            'date' => '2026-07-01',
            'organization' => 'urn:li:organization:12345',
        ])
        ->and($context['dataset']->fresh()->cursor_json)->toMatchArray([
            'resource_index' => 0,
            'resource' => 'share_statistics',
            'start' => 0,
            'last_synced_date' => '2026-07-01',
        ])
        ->and($result->run->fresh()->rate_limit_json['remaining'])->toBe('42')
        ->and($context['account']->fresh()->health_status)->toBe(ConnectorHealthEvent::STATUS_HEALTHY);

    Http::assertSentCount(2);
    Http::assertSent(function ($request): bool {
        $url = $request->url();

        return str_contains($url, 'organizationalEntity=urn%3Ali%3Aorganization%3A12345')
            && str_contains($url, 'timeIntervals.timeGranularityType=DAY')
            && str_contains($url, 'start=0')
            && str_contains($url, 'count=100');
    });
});

it('routes failed LinkedIn API responses through generic sync run and health failure paths', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.linkedin.com/v2/organizationalEntityShareStatistics*' => Http::response([
            'message' => 'rate limit exceeded',
        ], 429),
    ]);

    $context = phase34LinkedInContext();
    $result = app(ConnectorSyncEngine::class)->sync(new ConnectorSyncPlan(
        workspace: $context['workspace'],
        clientSite: $context['site'],
        provider: 'linkedin',
        account: $context['account'],
        dataset: $context['dataset'],
        dateRangeStart: Carbon::parse('2026-07-01'),
        dateRangeEnd: Carbon::parse('2026-07-01'),
    ));

    $run = $result->run->fresh();
    $event = ConnectorHealthEvent::query()->where('event_type', 'sync.recoverable_failed')->firstOrFail();

    expect($run->status)->toBe(ConnectorSyncRun::STATUS_FAILED)
        ->and($run->error_message)->toBe('LinkedIn share statistics request failed with status 429.')
        ->and($run->retry_json['recoverable'])->toBeTrue()
        ->and($event->event_type)->toBe('sync.recoverable_failed')
        ->and(ConnectorHealthEvent::query()->where('event_type', ConnectorHealthEvent::EVENT_RATE_LIMITED)->exists())->toBeTrue()
        ->and($context['dataset']->fresh()->health_status)->toBe(ConnectorHealthEvent::STATUS_WARNING);
});

it('keeps the existing LinkedIn publishing connector behavior unaffected', function () {
    config([
        'services.linkedin.client_id' => 'publishing-client-id',
        'services.linkedin.redirect_uri' => 'https://example.test/social/linkedin/callback',
        'services.linkedin.scopes' => ['openid', 'profile', 'w_member_social'],
    ]);

    $url = app(LinkedInClient::class)->authorizationUrl('publish-state');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect((array) config('services.linkedin.scopes'))->toBe(['openid', 'profile', 'w_member_social'])
        ->and($query['client_id'])->toBe('publishing-client-id')
        ->and($query['redirect_uri'])->toBe('https://example.test/social/linkedin/callback')
        ->and($query['scope'])->toContain('w_member_social')
        ->and($query['scope'])->not->toContain('r_organization_social')
        ->and(config('data_connectors.providers.linkedin.config_json.oauth.scopes'))->toContain('r_organization_social')
        ->and(config('data_connectors.providers.linkedin.config_json.oauth.scopes'))->not->toContain('w_member_social');
});

it('does not introduce LinkedIn specific tables or models', function () {
    expect(Schema::hasTable('linkedin_analytics'))->toBeFalse()
        ->and(Schema::hasTable('linkedin_organization_pages'))->toBeFalse()
        ->and(Schema::hasTable('linkedin_observations'))->toBeFalse()
        ->and(Schema::hasTable('linkedin_connector_datasets'))->toBeFalse()
        ->and(glob(app_path('Models/*LinkedIn*')))->toBe([]);
});

function phase34LinkedInContext(bool $withDataset = true): array
{
    $organization = Organization::query()->create([
        'name' => 'Phase 34 Organization',
        'slug' => 'phase-34-'.Str::lower(Str::random(8)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Phase 34 Workspace',
        'display_name' => 'Phase 34 Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Phase 34 Site',
        'site_url' => 'https://example.com',
        'base_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $provider = ConnectorProvider::factory()->create([
        'provider_key' => 'linkedin',
        'name' => 'LinkedIn',
        'category' => ConnectorProvider::CATEGORY_SOCIAL,
    ]);

    $account = ConnectorAccount::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'connector_provider_id' => $provider->id,
        'provider_key' => $provider->provider_key,
        'account_name' => 'Argusly LinkedIn',
        'external_account_id' => 'urn:li:organization:12345',
        'status' => ConnectorAccount::STATUS_CONNECTED,
        'connected_at' => now(),
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'metadata_json' => [],
    ]);

    app(ConnectorTokenVault::class)->store($account, 'fake-linkedin-access-token', null);

    $dataset = $withDataset
        ? ConnectorDataset::query()->create([
            'connector_account_id' => $account->id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'provider_key' => $provider->provider_key,
            'dataset_key' => 'organization_page:phase34',
            'dataset_type' => 'organization_page',
            'external_dataset_id' => 'urn:li:organization:12345',
            'display_name' => 'Argusly',
            'status' => ConnectorDataset::STATUS_ACTIVE,
            'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
            'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
            'cursor_json' => [],
            'capabilities_json' => [
                'keys' => ['social.organization', 'social.analytics', 'social.organic_analytics'],
                'definitions' => [
                    'social.organization' => ['enabled' => true],
                    'social.analytics' => ['enabled' => true],
                    'social.organic_analytics' => ['enabled' => true],
                ],
            ],
            'sync_config_json' => [
                'resources' => ['share_statistics', 'follower_statistics'],
                'metrics' => ['impressions', 'clicks', 'reactions', 'comments', 'shares', 'followers', 'engagementRate'],
                'dimensions' => ['date', 'organization', 'post', 'mediaType', 'campaign', 'content'],
            ],
            'config_json' => [
                'organization_urn' => 'urn:li:organization:12345',
                'organization_id' => '12345',
            ],
            'metadata_json' => [],
        ])
        : null;

    return compact('organization', 'workspace', 'site', 'provider', 'account', 'dataset');
}

function phase34CreateCanonicalDefinitions(): void
{
    foreach ([
        'impressions' => 'count',
        'clicks' => 'count',
        'reactions' => 'count',
        'comments' => 'count',
        'shares' => 'count',
        'followers' => 'count',
        'engagementRate' => 'ratio',
    ] as $metricKey => $unit) {
        MarketingMetricDefinition::factory()->create([
            'metric_key' => $metricKey,
            'default_unit' => $unit,
        ]);
    }

    foreach (['date', 'organization', 'post', 'mediaType', 'campaign', 'content'] as $dimensionKey) {
        MarketingDimensionDefinition::factory()->create(['dimension_key' => $dimensionKey]);
    }
}

function phase34LinkedInShareRow(string $date, array $statistics): array
{
    return [
        'timeRange' => [
            'start' => Carbon::parse($date)->startOfDay()->getTimestamp() * 1000,
            'end' => Carbon::parse($date)->endOfDay()->getTimestamp() * 1000,
        ],
        'share' => 'urn:li:share:phase34',
        'mediaType' => 'ARTICLE',
        'campaign' => 'phase-34-launch',
        'content' => 'phase-34-guide',
        'totalShareStatistics' => $statistics,
        'authorization' => 'Bearer plain-secret',
    ];
}

function phase34LinkedInFollowerRow(string $date, int $followers): array
{
    return [
        'timeRange' => [
            'start' => Carbon::parse($date)->startOfDay()->getTimestamp() * 1000,
            'end' => Carbon::parse($date)->endOfDay()->getTimestamp() * 1000,
        ],
        'totalFollowerCounts' => [
            'organicFollowerCount' => $followers,
            'paidFollowerCount' => 7,
        ],
    ];
}
