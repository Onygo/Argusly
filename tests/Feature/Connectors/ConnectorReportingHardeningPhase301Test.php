<?php

use App\Contracts\Connectors\Intelligence\MarketingIntelligenceFeed;
use App\Models\AttributionConversion;
use App\Models\AttributionResult;
use App\Models\AttributionRun;
use App\Models\ClientSite;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Connectors\ConnectorRawRecord;
use App\Models\Connectors\ConnectorSyncRun;
use App\Models\Connectors\NormalizationRun;
use App\Models\Connectors\Normalized\NormalizedCrmContact;
use App\Models\Connectors\Normalized\NormalizedDailyPerformance;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DataConnectors\Normalization\ConnectorNormalizationService;
use App\Services\Reporting\ConnectorReportingReadService;
use App\Services\Reporting\MetricDefinitionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('validates workspace reporting timezones and enforces workspace permissions', function (): void {
    $context = phase301Context('timezone-settings', 'owner');

    $this->actingAs($context['user'])
        ->post(route('app.settings.workspace-timezone.update'), [
            'reporting_timezone' => 'Europe/Amsterdam',
        ])
        ->assertRedirect();

    expect($context['workspace']->fresh()->reporting_timezone)->toBe('Europe/Amsterdam');

    $this->actingAs($context['user'])
        ->post(route('app.settings.workspace-timezone.update'), [
            'reporting_timezone' => 'Not/AZone',
        ])
        ->assertSessionHasErrors('reporting_timezone');

    $memberContext = phase301Context('timezone-member', 'member');

    $this->actingAs($memberContext['user'])
        ->post(route('app.settings.workspace-timezone.update'), [
            'reporting_timezone' => 'America/New_York',
        ])
        ->assertForbidden();
});

it('uses Europe Amsterdam local dates for UTC attribution events close to midnight', function (): void {
    $context = phase301Context('amsterdam-midnight');
    $workspace = $context['workspace']->forceFill(['reporting_timezone' => 'Europe/Amsterdam']);
    $workspace->save();

    phase301Attribution($context, '2026-07-08 22:30:00', 100, 'EUR');

    $service = app(ConnectorReportingReadService::class);

    expect($service->summary($workspace, '2026-07-08', '2026-07-08')['metrics']['conversions'])->toBe(0)
        ->and($service->summary($workspace, '2026-07-09', '2026-07-09')['metrics']['conversions'])->toBe(1)
        ->and($service->summary($workspace, '2026-07-09', '2026-07-09')['period']['timezone'])->toBe('Europe/Amsterdam');
});

it('uses America New York local dates for UTC attribution events close to midnight', function (): void {
    $context = phase301Context('new-york-midnight');
    $workspace = $context['workspace']->forceFill(['reporting_timezone' => 'America/New_York']);
    $workspace->save();

    phase301Attribution($context, '2026-07-09 03:30:00', 100, 'USD');

    $service = app(ConnectorReportingReadService::class);

    expect($service->summary($workspace, '2026-07-08', '2026-07-08')['metrics']['conversions'])->toBe(1)
        ->and($service->summary($workspace, '2026-07-09', '2026-07-09')['metrics']['conversions'])->toBe(0);
});

it('handles daylight saving reporting boundaries with timezone-aware date logic', function (): void {
    $context = phase301Context('dst-amsterdam');
    $workspace = $context['workspace']->forceFill(['reporting_timezone' => 'Europe/Amsterdam']);
    $workspace->save();

    phase301Attribution($context, '2026-03-29 21:30:00', 100, 'EUR', 'dst-included');
    phase301Attribution($context, '2026-03-29 22:30:00', 100, 'EUR', 'dst-excluded');

    $summary = app(ConnectorReportingReadService::class)->summary($workspace, '2026-03-29', '2026-03-29');

    expect($summary['metrics']['conversions'])->toBe(1)
        ->and($summary['period']['utc_start'])->toBe('2026-03-28 23:00:00')
        ->and($summary['period']['utc_end'])->toBe('2026-03-29 21:59:59');
});

it('falls back to the app timezone when workspace reporting timezone is unset', function (): void {
    $context = phase301Context('timezone-fallback');

    $summary = app(ConnectorReportingReadService::class)->summary($context['workspace'], '2026-07-08', '2026-07-08');

    expect($context['workspace']->reportingTimezone())->toBe('UTC')
        ->and($summary['period']['timezone'])->toBe('UTC');
});

it('aggregates one-currency spend normally', function (): void {
    $context = phase301Context('one-currency');
    phase301Performance($context, ['date' => '2026-07-08', 'cost' => 100, 'original_currency' => 'EUR']);
    phase301Performance($context, ['date' => '2026-07-08', 'cost' => 50, 'original_currency' => 'EUR', 'entity_id' => 'campaign-b']);

    $summary = app(ConnectorReportingReadService::class)->summary($context['workspace'], '2026-07-08', '2026-07-08');

    expect($summary['metrics']['spend'])->toBe(150.0)
        ->and($summary['monetary']['spend']['status'])->toBe('single_currency')
        ->and($summary['monetary']['spend']['currency'])->toBe('EUR');
});

it('does not silently sum two-currency spend and returns per-currency totals', function (): void {
    $context = phase301Context('mixed-currency');
    phase301Performance($context, ['date' => '2026-07-08', 'cost' => 100, 'original_currency' => 'EUR']);
    phase301Performance($context, ['date' => '2026-07-08', 'cost' => 50, 'original_currency' => 'USD', 'entity_id' => 'campaign-b']);

    $summary = app(ConnectorReportingReadService::class)->summary($context['workspace'], '2026-07-08', '2026-07-08');

    expect($summary['metrics']['spend'])->toBeNull()
        ->and($summary['metrics']['ctr'])->toBe(0.1)
        ->and($summary['monetary']['spend']['status'])->toBe('mixed_currency')
        ->and($summary['monetary']['spend']['totals_by_currency'])->toMatchArray([
            'EUR' => 100.0,
            'USD' => 50.0,
        ])
        ->and($summary['monetary']['cpl']['status'])->toBe('unavailable');
});

it('aggregates complete reporting-currency conversion and flags partial coverage', function (): void {
    $converted = phase301Context('converted-currency');
    phase301Performance($converted, [
        'date' => '2026-07-08',
        'cost' => 100,
        'original_currency' => 'EUR',
        'reporting_currency' => 'EUR',
        'reporting_cost' => 100,
    ]);
    phase301Performance($converted, [
        'date' => '2026-07-08',
        'cost' => 50,
        'original_currency' => 'USD',
        'reporting_currency' => 'EUR',
        'reporting_cost' => 45,
        'entity_id' => 'campaign-b',
    ]);

    $convertedSummary = app(ConnectorReportingReadService::class)->summary($converted['workspace'], '2026-07-08', '2026-07-08');

    expect($convertedSummary['metrics']['spend'])->toBe(145.0)
        ->and($convertedSummary['monetary']['spend']['status'])->toBe('converted')
        ->and($convertedSummary['monetary']['spend']['conversion_coverage']['ratio'])->toBe(1.0);

    $partial = phase301Context('partial-conversion');
    phase301Performance($partial, [
        'date' => '2026-07-08',
        'cost' => 100,
        'original_currency' => 'EUR',
        'reporting_currency' => 'EUR',
        'reporting_cost' => 100,
    ]);
    phase301Performance($partial, ['date' => '2026-07-08', 'cost' => 50, 'original_currency' => 'USD', 'entity_id' => 'campaign-b']);

    $partialSummary = app(ConnectorReportingReadService::class)->summary($partial['workspace'], '2026-07-08', '2026-07-08');

    expect($partialSummary['metrics']['spend'])->toBeNull()
        ->and($partialSummary['monetary']['spend']['conversion_coverage']['ratio'])->toBe(0.5)
        ->and($partialSummary['monetary']['spend']['warnings'])->toContain('Reporting-currency conversion coverage is incomplete.');
});

it('marks ROAS CPA and CPL unavailable when monetary currencies are incomparable', function (): void {
    $context = phase301Context('incomparable-ratios');
    phase301Performance($context, ['date' => '2026-07-08', 'cost' => 100, 'original_currency' => 'EUR']);
    phase301Attribution($context, '2026-07-08 12:00:00', 250, 'USD');
    phase301Lead($context, '2026-07-08 12:00:00');

    $summary = app(ConnectorReportingReadService::class)->summary($context['workspace'], '2026-07-08', '2026-07-08');

    expect($summary['metrics']['roas'])->toBeNull()
        ->and($summary['monetary']['roas']['status'])->toBe('unavailable')
        ->and($summary['metrics']['cpa'])->toBe(100.0)
        ->and($summary['metrics']['cpl'])->toBe(100.0);

    phase301Performance($context, ['date' => '2026-07-08', 'cost' => 25, 'original_currency' => 'USD', 'entity_id' => 'campaign-b']);

    $mixedSummary = app(ConnectorReportingReadService::class)->summary($context['workspace'], '2026-07-08', '2026-07-08');

    expect($mixedSummary['metrics']['cpa'])->toBeNull()
        ->and($mixedSummary['metrics']['cpl'])->toBeNull();
});

it('propagates provider currency into normalized performance rows', function (): void {
    $context = phase301Context('mapper-currency');
    phase301RawRecord($context, [
        'customer' => ['id' => 'account-1', 'currency_code' => 'EUR'],
        'segments' => ['date' => '2026-07-08'],
        'campaign' => ['id' => 'mapper-campaign', 'name' => 'Mapper Campaign'],
        'metrics' => ['impressions' => 100, 'clicks' => 10, 'cost_micros' => 2500000],
    ], 'mapper-row');

    app(ConnectorNormalizationService::class)->normalize(phase301NormalizationRun($context));

    $row = NormalizedDailyPerformance::query()->where('entity_id', 'mapper-campaign')->firstOrFail();

    expect($row->original_currency)->toBe('EUR')
        ->and((float) $row->original_cost)->toBe(2.5);
});

it('exposes timezone and currency metadata in intelligence feeds', function (): void {
    $context = phase301Context('feed-currency');
    $context['workspace']->forceFill(['reporting_timezone' => 'Europe/Amsterdam'])->save();
    phase301Performance($context, ['date' => '2026-07-08', 'cost' => 100, 'original_currency' => 'EUR']);
    phase301Performance($context, ['date' => '2026-07-08', 'cost' => 50, 'original_currency' => 'USD', 'entity_id' => 'campaign-b']);

    $feed = app(MarketingIntelligenceFeed::class)->snapshot($context['workspace'], '2026-07-08', '2026-07-08');

    expect($feed['reporting_timezone'])->toBe('Europe/Amsterdam')
        ->and($feed['monetary_comparability'])->toBe('mixed_currency')
        ->and($feed['currencies_represented'])->toContain('EUR', 'USD')
        ->and($feed['monetary']['spend']['status'])->toBe('mixed_currency')
        ->and($feed['warnings'])->not->toBeEmpty();
});

it('shows mixed-currency warnings in connector UI', function (): void {
    $context = phase301Context('ui-currency');
    phase301Performance($context, ['date' => '2026-07-08', 'cost' => 100, 'original_currency' => 'EUR']);
    phase301Performance($context, ['date' => '2026-07-08', 'cost' => 50, 'original_currency' => 'USD', 'entity_id' => 'campaign-b']);

    $this->actingAs($context['user'])
        ->get(route('app.connectors.show', $context['account']))
        ->assertOk()
        ->assertSee('Monetary totals are not combined because multiple currencies are represented.')
        ->assertSee('EUR 100.00')
        ->assertSee('USD 50.00');
});

it('keeps reporting currency and timezone isolated by workspace', function (): void {
    $first = phase301Context('workspace-isolation-a');
    $second = phase301Context('workspace-isolation-b');
    $first['workspace']->forceFill(['reporting_timezone' => 'Europe/Amsterdam'])->save();
    $second['workspace']->forceFill(['reporting_timezone' => 'America/New_York'])->save();
    phase301Performance($first, ['date' => '2026-07-08', 'cost' => 100, 'original_currency' => 'EUR']);
    phase301Performance($second, ['date' => '2026-07-08', 'cost' => 200, 'original_currency' => 'USD']);

    $service = app(ConnectorReportingReadService::class);
    $firstSummary = $service->summary($first['workspace'], '2026-07-08', '2026-07-08');
    $secondSummary = $service->summary($second['workspace'], '2026-07-08', '2026-07-08');

    expect($firstSummary['metrics']['spend'])->toBe(100.0)
        ->and($firstSummary['period']['timezone'])->toBe('Europe/Amsterdam')
        ->and($secondSummary['metrics']['spend'])->toBe(200.0)
        ->and($secondSummary['period']['timezone'])->toBe('America/New_York');
});

it('keeps monetary formulas centralized in metric definitions', function (): void {
    $definitions = app(MetricDefinitionRegistry::class)->all();

    expect($definitions->get('spend')->toArray())->toMatchArray([
        'currency_dependency' => 'original_currency',
        'mixed_currency_behavior' => 'partition_or_convert',
    ])
        ->and($definitions->get('roas')->toArray())->toMatchArray([
            'currency_dependency' => 'revenue_and_spend_currency',
            'mixed_currency_behavior' => 'unavailable_when_incomparable',
        ]);

    $surfaces = [
        app_path('Http/Controllers/App/AppConnectorController.php'),
        resource_path('views/app/connectors/show.blade.php'),
        resource_path('views/app/connectors/diagnostics.blade.php'),
    ];

    foreach ($surfaces as $surface) {
        $contents = file_get_contents($surface) ?: '';

        expect($contents)->not->toContain('spend / leads')
            ->and($contents)->not->toContain('revenue / spend')
            ->and($contents)->not->toContain('spend / conversions');
    }
});

function phase301Context(string $slug, string $role = 'owner'): array
{
    $unique = $slug.'-'.Str::lower(Str::random(8));

    $organization = Organization::query()->create([
        'name' => Str::headline($slug),
        'slug' => $unique,
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
        'billing_company_name' => Str::headline($slug),
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => Str::headline($slug).' Workspace',
        'display_name' => Str::headline($slug).' Workspace',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'phase301-plan'],
        [
            'name' => 'Phase 30.1 Plan',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 1000,
        ],
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 1000,
        'seat_limit' => 5,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $user = User::query()->create([
        'name' => Str::headline($slug).' User',
        'email' => $unique.'@example.test',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => $role,
        'active' => true,
        'approved_at' => now(),
        'email_verified_at' => now(),
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Phase 30.1 Site',
        'site_url' => 'https://'.$unique.'.example.test',
        'base_url' => 'https://'.$unique.'.example.test',
        'allowed_domains' => [$unique.'.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $provider = ConnectorProvider::query()->firstOrCreate(
        ['provider_key' => 'google_ads'],
        [
            'name' => 'Google Ads',
            'category' => 'ads',
            'status' => ConnectorProvider::STATUS_ACTIVE,
            'config_json' => [],
            'supports_oauth' => true,
            'supports_sync' => true,
            'supports_webhooks' => false,
        ],
    );

    $account = ConnectorAccount::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'connector_provider_id' => $provider->id,
        'provider_key' => 'google_ads',
        'account_name' => 'Google Ads Account',
        'external_account_id' => 'acct-'.$unique,
        'status' => ConnectorAccount::STATUS_CONNECTED,
        'connected_at' => now(),
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'health_score' => 100,
        'metadata_json' => [],
    ]);

    $dataset = ConnectorDataset::query()->create([
        'connector_account_id' => $account->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'provider_key' => 'google_ads',
        'dataset_key' => 'google_ads:ads_daily_performance:'.Str::lower(Str::random(6)),
        'dataset_type' => 'ads_daily_performance',
        'external_dataset_id' => 'google_ads:ads_daily_performance',
        'display_name' => 'Ads daily performance',
        'status' => ConnectorDataset::STATUS_ACTIVE,
        'sync_frequency' => 'daily',
        'discovered_at' => now(),
        'last_seen_at' => now(),
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'cursor_json' => [],
        'capabilities_json' => ['keys' => ['normalization']],
        'sync_config_json' => [],
        'config_json' => ['ad_account_id' => 'acct-'.$unique],
        'metadata_json' => [],
    ]);

    $syncRun = ConnectorSyncRun::query()->create([
        'connector_account_id' => $account->id,
        'connector_dataset_id' => $dataset->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'provider_key' => 'google_ads',
        'dataset_key' => $dataset->dataset_key,
        'status' => ConnectorSyncRun::STATUS_SUCCEEDED,
        'run_type' => ConnectorSyncRun::TYPE_MANUAL,
        'window_start' => '2026-07-08 00:00:00',
        'window_end' => '2026-07-08 23:59:59',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'duration_ms' => 100,
        'records_processed' => 0,
        'metrics_json' => [],
        'rate_limit_json' => [],
        'retry_json' => [],
        'idempotency_key' => (string) Str::uuid(),
    ]);

    return compact('organization', 'workspace', 'user', 'site', 'provider', 'account', 'dataset', 'syncRun');
}

function phase301Performance(array $context, array $overrides = []): NormalizedDailyPerformance
{
    return NormalizedDailyPerformance::query()->create(array_merge([
        'workspace_id' => $context['workspace']->id,
        'connector_account_id' => $context['account']->id,
        'provider' => 'google_ads',
        'entity_type' => 'campaign',
        'entity_id' => 'campaign-a',
        'date' => '2026-07-08',
        'impressions' => 1000,
        'clicks' => 100,
        'cost' => 0,
        'original_cost' => $overrides['cost'] ?? 0,
        'conversions' => 0,
        'raw_reference' => [],
    ], $overrides));
}

function phase301Lead(array $context, string $createdAt): NormalizedCrmContact
{
    $contact = NormalizedCrmContact::query()->create([
        'workspace_id' => $context['workspace']->id,
        'provider' => 'hubspot',
        'provider_contact_id' => 'contact-'.Str::lower(Str::random(8)),
        'email_hash' => hash('sha256', Str::random(16)),
        'raw_reference' => [],
    ]);
    $contact->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ])->save();

    return $contact;
}

function phase301Attribution(array $context, string $occurredAt, float $value, string $currency, ?string $key = null): AttributionResult
{
    $key ??= 'conversion-'.Str::lower(Str::random(8));
    $run = AttributionRun::query()->firstOrCreate(
        [
            'workspace_id' => $context['workspace']->id,
            'model_key' => 'last_touch',
            'period_start' => '2026-01-01 00:00:00',
            'period_end' => '2026-12-31 23:59:59',
        ],
        [
            'status' => AttributionRun::STATUS_COMPLETED,
            'lookback_days' => 90,
            'metadata_json' => [],
        ],
    );

    $conversion = AttributionConversion::query()->create([
        'workspace_id' => $context['workspace']->id,
        'conversion_key' => $key,
        'conversion_type' => 'revenue',
        'occurred_at' => $occurredAt,
        'value' => $value,
        'currency' => $currency,
        'status' => 'won',
        'raw_reference' => [],
    ]);

    return AttributionResult::query()->create([
        'workspace_id' => $context['workspace']->id,
        'attribution_run_id' => $run->id,
        'attribution_conversion_id' => $conversion->id,
        'result_key' => hash('sha256', $key),
        'model_key' => 'last_touch',
        'credit' => 1,
        'value' => $value,
        'currency' => $currency,
        'match_confidence' => 'high',
        'metadata_json' => [],
    ]);
}

function phase301RawRecord(array $context, array $payload, string $externalId): ConnectorRawRecord
{
    return ConnectorRawRecord::query()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'connector_provider_id' => $context['provider']->id,
        'connector_account_id' => $context['account']->id,
        'connector_dataset_id' => $context['dataset']->id,
        'connector_sync_run_id' => $context['syncRun']->id,
        'provider_key' => 'google_ads',
        'dataset_key' => $context['dataset']->dataset_key,
        'record_type' => 'ads_daily_performance',
        'external_record_id' => $externalId,
        'fingerprint' => hash('sha256', $context['workspace']->id.'|'.$externalId.'|'.json_encode($payload)),
        'period_start' => '2026-07-08 00:00:00',
        'period_end' => '2026-07-08 23:59:59',
        'observed_at' => now(),
        'payload_json' => $payload,
        'metadata_json' => [],
    ]);
}

function phase301NormalizationRun(array $context): NormalizationRun
{
    return NormalizationRun::query()->create([
        'workspace_id' => $context['workspace']->id,
        'connector_account_id' => $context['account']->id,
        'connector_dataset_id' => $context['dataset']->id,
        'connector_sync_run_id' => $context['syncRun']->id,
        'provider' => 'google_ads',
        'dataset_key' => $context['dataset']->dataset_key,
        'source_type' => 'connector_account',
        'source_key' => $context['account']->id,
        'trigger' => 'phase301_test',
        'status' => NormalizationRun::STATUS_PENDING,
        'metadata_json' => [],
    ]);
}
