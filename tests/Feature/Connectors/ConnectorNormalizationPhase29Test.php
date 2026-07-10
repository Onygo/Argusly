<?php

use App\Contracts\Connectors\Normalization\NormalizedRecordMapper;
use App\Events\Connectors\ConnectorRawRecordsWritten;
use App\Events\Connectors\ConnectorSyncCompletedForTransformation;
use App\Events\Connectors\Normalization\NormalizedCampaignDataUpdated;
use App\Events\Connectors\Normalization\NormalizedCrmDataUpdated;
use App\Events\Connectors\Normalization\NormalizedLeadDataUpdated;
use App\Events\Connectors\Normalization\NormalizedMarketingPerformanceUpdated;
use App\Events\Connectors\Normalization\NormalizedSalesDataUpdated;
use App\Jobs\Connectors\TransformConnectorRawRecordsJob;
use App\Models\ClientSite;
use App\Models\Connectors\ConnectorAccount;
use App\Models\Connectors\ConnectorBackfillRange;
use App\Models\Connectors\ConnectorDataset;
use App\Models\Connectors\ConnectorHealthEvent;
use App\Models\Connectors\ConnectorProvider;
use App\Models\Connectors\ConnectorRawRecord;
use App\Models\Connectors\ConnectorSyncRun;
use App\Models\Connectors\NormalizationRun;
use App\Models\Connectors\NormalizationRunItem;
use App\Models\Connectors\Normalized\NormalizedCampaign;
use App\Models\Connectors\Normalized\NormalizedCrmActivity;
use App\Models\Connectors\Normalized\NormalizedCrmCompany;
use App\Models\Connectors\Normalized\NormalizedCrmContact;
use App\Models\Connectors\Normalized\NormalizedCrmDeal;
use App\Models\Connectors\Normalized\NormalizedDailyPerformance;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DataConnectors\ConnectorSyncContext;
use App\Services\DataConnectors\ConnectorSyncPlan;
use App\Services\DataConnectors\Normalization\ConnectorNormalizationService;
use App\Services\DataConnectors\Normalization\NormalizedRecordMapperResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('queues normalization from raw record and sync completed events', function () {
    Bus::fake([TransformConnectorRawRecordsJob::class]);

    $context = phase29ConnectorContext('phase29-triggers', 'google_ads', 'ads_daily_performance');
    $syncContext = phase29SyncContext($context);

    event(new ConnectorRawRecordsWritten($syncContext, 3));
    event(new ConnectorSyncCompletedForTransformation($syncContext, ['raw_records_written' => 3]));

    expect(NormalizationRun::query()->count())->toBe(1)
        ->and(NormalizationRun::query()->firstOrFail()->metadata_json['collapsed_request_count'] ?? 0)->toBe(1);

    Bus::assertDispatched(TransformConnectorRawRecordsJob::class, 1);
});

it('normalizes ads campaigns and daily performance idempotently and emits feed events', function () {
    Event::fake([
        NormalizedCampaignDataUpdated::class,
        NormalizedMarketingPerformanceUpdated::class,
    ]);

    $context = phase29ConnectorContext('phase29-ads', 'google_ads', 'ads_daily_performance');
    phase29RawRecord($context, [
        'segments' => ['date' => '2026-07-08'],
        'campaign' => ['id' => 'cmp-1', 'name' => 'Search Campaign', 'status' => 'ENABLED', 'advertising_channel_type' => 'SEARCH'],
        'ad_group' => ['id' => 'ag-1', 'name' => 'Brand Terms', 'status' => 'ENABLED'],
        'metrics' => [
            'impressions' => 1000,
            'clicks' => 125,
            'cost_micros' => 2500000,
            'conversions' => 8,
            'ctr' => 0.125,
            'average_cpc' => 20000,
            'average_cpm' => 2500000,
        ],
    ], externalId: 'google-row-1');

    $run = phase29NormalizationRun($context);
    app(ConnectorNormalizationService::class)->normalize($run);

    $rerun = phase29NormalizationRun($context, trigger: 'manual_reprocess');
    app(ConnectorNormalizationService::class)->normalize($rerun);

    expect(NormalizedCampaign::query()->count())->toBe(1)
        ->and(NormalizedDailyPerformance::query()->count())->toBe(1)
        ->and((float) NormalizedDailyPerformance::query()->firstOrFail()->cost)->toBe(2.5)
        ->and(NormalizationRun::query()->where('status', NormalizationRun::STATUS_COMPLETED)->count())->toBe(2);

    Event::assertDispatched(NormalizedCampaignDataUpdated::class);
    Event::assertDispatched(NormalizedMarketingPerformanceUpdated::class);
});

it('normalizes CRM companies contacts deals and activities without storing raw email addresses', function () {
    Event::fake([
        NormalizedCrmDataUpdated::class,
        NormalizedLeadDataUpdated::class,
        NormalizedSalesDataUpdated::class,
    ]);

    $context = phase29ConnectorContext('phase29-crm', 'hubspot', 'contacts');
    $companies = phase29Dataset($context, 'companies', ['object' => 'companies']);
    $contacts = phase29Dataset($context, 'contacts', ['object' => 'contacts']);
    $deals = phase29Dataset($context, 'deals', ['object' => 'deals']);
    $activities = phase29Dataset($context, 'activities', ['object' => 'activities']);

    phase29RawRecord($context, [
        'id' => 'company-1',
        'properties' => ['name' => 'Acme', 'domain' => 'acme.example', 'industry' => 'SaaS'],
    ], dataset: $companies, recordType: 'companies', externalId: 'company-1');
    phase29RawRecord($context, [
        'id' => 'contact-1',
        'properties' => [
            'email' => 'Ada@example.test',
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'jobtitle' => 'CMO',
            'associatedcompanyid' => 'company-1',
        ],
    ], dataset: $contacts, recordType: 'contacts', externalId: 'contact-1');
    phase29RawRecord($context, [
        'id' => 'deal-1',
        'properties' => [
            'dealstage' => 'qualified',
            'amount' => '12500',
            'currency' => 'EUR',
            'closedate' => '2026-08-01',
            'associatedcompanyid' => 'company-1',
            'contact_id' => 'contact-1',
        ],
    ], dataset: $deals, recordType: 'deals', externalId: 'deal-1');
    phase29RawRecord($context, [
        'id' => 'activity-1',
        'properties' => [
            'type' => 'call',
            'subject' => 'Intro call',
            'occurred_at' => '2026-07-08T10:00:00Z',
            'associatedcompanyid' => 'company-1',
            'contact_id' => 'contact-1',
            'deal_id' => 'deal-1',
        ],
    ], dataset: $activities, recordType: 'activities', externalId: 'activity-1');

    app(ConnectorNormalizationService::class)->normalize(phase29NormalizationRun($context, dataset: null));

    $contact = NormalizedCrmContact::query()->firstOrFail();

    expect(NormalizedCrmCompany::query()->count())->toBe(1)
        ->and(NormalizedCrmContact::query()->count())->toBe(1)
        ->and(NormalizedCrmDeal::query()->count())->toBe(1)
        ->and(NormalizedCrmActivity::query()->count())->toBe(1)
        ->and($contact->email_hash)->toHaveLength(64)
        ->and(DB::table('connector_normalized_crm_contacts')->where('id', $contact->id)->first())->not->toHaveProperty('email')
        ->and(json_encode($contact->raw_reference))->not->toContain('Ada@example.test');

    Event::assertDispatched(NormalizedCrmDataUpdated::class);
    Event::assertDispatched(NormalizedLeadDataUpdated::class);
    Event::assertDispatched(NormalizedSalesDataUpdated::class);
});

it('logs failed mapper items without stopping workspace isolation', function () {
    $context = phase29ConnectorContext('phase29-failed-item', 'google_ads', 'ads_daily_performance');
    phase29RawRecord($context, ['id' => 'bad-row'], externalId: 'bad-row');

    $resolver = Mockery::mock(NormalizedRecordMapperResolver::class);
    $resolver->shouldReceive('has')->andReturnTrue();
    $resolver->shouldReceive('resolve')->andReturn(new class implements NormalizedRecordMapper
    {
        public function provider(): string
        {
            return 'google_ads';
        }

        public function map(ConnectorRawRecord $rawRecord): array
        {
            unset($rawRecord);

            throw new RuntimeException('Mapper exploded.');
        }
    });
    app()->instance(NormalizedRecordMapperResolver::class, $resolver);

    $run = app(ConnectorNormalizationService::class)->normalize(phase29NormalizationRun($context));

    expect($run->status)->toBe(NormalizationRun::STATUS_FAILED)
        ->and(NormalizationRunItem::query()->where('status', NormalizationRunItem::STATUS_FAILED)->count())->toBe(1)
        ->and(NormalizationRunItem::query()->firstOrFail()->error_message)->toBe('Mapper exploded.');
});

it('keeps duplicate provider ids isolated by workspace', function () {
    $first = phase29ConnectorContext('phase29-isolation-a', 'meta_ads', 'ads_daily_performance');
    $second = phase29ConnectorContext('phase29-isolation-b', 'meta_ads', 'ads_daily_performance');

    foreach ([$first, $second] as $context) {
        phase29RawRecord($context, [
            'date_start' => '2026-07-08',
            'campaign_id' => 'shared-campaign',
            'campaign_name' => 'Shared Campaign',
            'impressions' => 10,
            'clicks' => 1,
            'spend' => 2,
        ], externalId: 'shared-row-'.$context['workspace']->id);

        app(ConnectorNormalizationService::class)->normalize(phase29NormalizationRun($context));
    }

    expect(NormalizedCampaign::query()->where('provider_campaign_id', 'shared-campaign')->count())->toBe(2)
        ->and(NormalizedCampaign::query()->where('workspace_id', $first['workspace']->id)->count())->toBe(1)
        ->and(NormalizedCampaign::query()->where('workspace_id', $second['workspace']->id)->count())->toBe(1);
});

it('enforces manual normalize and retry permissions while members can view status', function () {
    Bus::fake([TransformConnectorRawRecordsJob::class]);

    $context = phase29ConnectorContext('phase29-permissions', 'google_ads', 'ads_daily_performance');
    $member = User::query()->create([
        'name' => 'Phase 29 Member',
        'email' => 'phase29-member+'.Str::lower(Str::random(6)).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $context['organization']->id,
        'role' => 'member',
        'active' => true,
        'approved_at' => now(),
        'email_verified_at' => now(),
    ]);

    $failedRun = phase29NormalizationRun($context);
    $failedRun->forceFill(['status' => NormalizationRun::STATUS_FAILED, 'latest_error' => 'Previous failure'])->save();

    $this->actingAs($context['user'])
        ->post(route('app.connectors.normalize', $context['account']))
        ->assertRedirect();

    $this->actingAs($member)
        ->get(route('app.connectors.show', $context['account']))
        ->assertOk()
        ->assertSee('Normalization health');

    $this->actingAs($member)
        ->post(route('app.connectors.normalize', $context['account']))
        ->assertForbidden();

    $this->actingAs($context['user'])
        ->post(route('app.connectors.normalization-runs.retry', $failedRun))
        ->assertRedirect();

    expect($failedRun->fresh()->status)->toBe(NormalizationRun::STATUS_PENDING);
    Bus::assertDispatched(TransformConnectorRawRecordsJob::class);
});

it('queues normalization only for completed backfill ranges', function () {
    Bus::fake([TransformConnectorRawRecordsJob::class]);

    $context = phase29ConnectorContext('phase29-backfill', 'google_ads', 'ads_daily_performance');
    $succeeded = phase29BackfillRange($context, ConnectorBackfillRange::STATUS_SUCCEEDED);
    $failed = phase29BackfillRange($context, ConnectorBackfillRange::STATUS_FAILED);

    $service = app(ConnectorNormalizationService::class);
    $run = $service->enqueueForBackfillRange($succeeded);
    $skipped = $service->enqueueForBackfillRange($failed);

    expect($run)->toBeInstanceOf(NormalizationRun::class)
        ->and($run->trigger)->toBe('backfill_completed')
        ->and($skipped)->toBeNull()
        ->and(NormalizationRun::query()->count())->toBe(1);

    Bus::assertDispatched(TransformConnectorRawRecordsJob::class, 1);
});

function phase29ConnectorContext(string $slug, string $providerKey, string $datasetType): array
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
        ['key' => 'phase29-plan'],
        [
            'name' => 'Phase 29 Plan',
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

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => 'Phase 29 Site',
        'site_url' => 'https://'.$unique.'.example.test',
        'base_url' => 'https://'.$unique.'.example.test',
        'allowed_domains' => [$unique.'.example.test'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::query()->create([
        'name' => 'Phase 29 Owner',
        'email' => 'phase29-owner+'.Str::lower(Str::random(6)).'@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_verified_at' => now(),
    ]);

    $provider = ConnectorProvider::query()->firstOrCreate(
        ['provider_key' => $providerKey],
        [
            'name' => Str::headline($providerKey),
            'category' => str_contains($providerKey, 'ads') ? 'ads' : 'crm',
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
        'provider_key' => $providerKey,
        'account_name' => Str::headline($providerKey).' Account',
        'external_account_id' => 'acct-'.$unique,
        'status' => ConnectorAccount::STATUS_CONNECTED,
        'connected_at' => now(),
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'health_score' => 100,
        'metadata_json' => [],
    ]);

    $dataset = phase29Dataset(['account' => $account, 'provider' => $provider, 'workspace' => $workspace, 'site' => $site], $datasetType, [
        'ad_account_id' => 'acct-'.$unique,
        'account_id' => 'acct-'.$unique,
        'object' => $datasetType,
        'provider_object' => $datasetType,
    ]);

    $syncRun = ConnectorSyncRun::query()->create([
        'connector_account_id' => $account->id,
        'connector_dataset_id' => $dataset->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'provider_key' => $providerKey,
        'dataset_key' => $dataset->dataset_key,
        'status' => ConnectorSyncRun::STATUS_SUCCEEDED,
        'run_type' => ConnectorSyncRun::TYPE_MANUAL,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
        'duration_ms' => 100,
        'records_processed' => 0,
        'metrics_json' => [],
        'rate_limit_json' => [],
        'retry_json' => [],
        'idempotency_key' => (string) Str::uuid(),
    ]);

    return compact('organization', 'workspace', 'site', 'user', 'provider', 'account', 'dataset', 'syncRun');
}

function phase29Dataset(array $context, string $datasetType, array $config = []): ConnectorDataset
{
    /** @var ConnectorAccount $account */
    $account = $context['account'];

    return ConnectorDataset::query()->create([
        'connector_account_id' => $account->id,
        'workspace_id' => $account->workspace_id,
        'client_site_id' => $account->client_site_id,
        'provider_key' => $account->provider_key,
        'dataset_key' => $account->provider_key.':'.$datasetType.':'.Str::lower(Str::random(6)),
        'dataset_type' => $datasetType,
        'external_dataset_id' => (string) ($config['ad_account_id'] ?? $account->provider_key.':'.$datasetType),
        'display_name' => Str::headline($datasetType),
        'status' => ConnectorDataset::STATUS_ACTIVE,
        'sync_frequency' => 'daily',
        'discovered_at' => now(),
        'last_seen_at' => now(),
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'cursor_json' => [],
        'capabilities_json' => ['keys' => ['normalization']],
        'sync_config_json' => [],
        'config_json' => $config,
        'metadata_json' => [],
    ]);
}

function phase29RawRecord(
    array $context,
    array $payload,
    ?ConnectorDataset $dataset = null,
    ?string $recordType = null,
    ?string $externalId = null,
): ConnectorRawRecord {
    $dataset ??= $context['dataset'];
    $syncRun = $context['syncRun'];

    return ConnectorRawRecord::query()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'connector_provider_id' => $context['provider']->id,
        'connector_account_id' => $context['account']->id,
        'connector_dataset_id' => $dataset->id,
        'connector_sync_run_id' => $syncRun->id,
        'provider_key' => $context['account']->provider_key,
        'dataset_key' => $dataset->dataset_key,
        'record_type' => $recordType ?? $dataset->dataset_type,
        'external_record_id' => $externalId ?? (string) Str::uuid(),
        'fingerprint' => hash('sha256', $context['workspace']->id.'|'.($externalId ?? Str::uuid()).'|'.json_encode($payload)),
        'period_start' => '2026-07-08 00:00:00',
        'period_end' => '2026-07-08 23:59:59',
        'observed_at' => now(),
        'payload_json' => $payload,
        'metadata_json' => [],
    ]);
}

function phase29NormalizationRun(array $context, ?ConnectorDataset $dataset = null, string $trigger = 'manual'): NormalizationRun
{
    return NormalizationRun::query()->create([
        'workspace_id' => $context['workspace']->id,
        'connector_account_id' => $context['account']->id,
        'connector_dataset_id' => $dataset?->id,
        'provider' => $context['account']->provider_key,
        'dataset_key' => $dataset?->dataset_key,
        'trigger' => $trigger,
        'status' => NormalizationRun::STATUS_PENDING,
        'metadata_json' => [],
    ]);
}

function phase29SyncContext(array $context): ConnectorSyncContext
{
    return new ConnectorSyncContext(
        ConnectorSyncPlan::forDataset($context['dataset'], ConnectorSyncRun::TYPE_MANUAL),
        $context['syncRun'],
    );
}

function phase29BackfillRange(array $context, string $status): ConnectorBackfillRange
{
    return ConnectorBackfillRange::query()->create([
        'workspace_id' => $context['workspace']->id,
        'connector_account_id' => $context['account']->id,
        'connector_dataset_id' => $context['dataset']->id,
        'provider_key' => $context['account']->provider_key,
        'dataset_key' => $context['dataset']->dataset_key,
        'status' => $status,
        'range_start' => '2026-07-01',
        'range_end' => '2026-07-08',
        'attempts' => 1,
        'connector_sync_run_id' => $context['syncRun']->id,
        'idempotency_key' => hash('sha256', $context['dataset']->id.'|'.$status.'|'.Str::uuid()),
        'metadata_json' => [],
    ]);
}
