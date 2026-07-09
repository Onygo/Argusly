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
use App\Models\PageIntelligenceReport;
use App\Models\RecommendedAction;
use App\Models\ScheduledPageIntelligenceBriefing;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-09 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('renders the workspace route', function (): void {
    $context = marketingIntelligenceContext('Route Render');

    $this->withoutMiddleware(marketingIntelligenceDisabledMiddleware())
        ->actingAs($context['user'])
        ->get(route('app.marketing-intelligence.index'))
        ->assertOk()
        ->assertSee('Unified Marketing Intelligence Workspace')
        ->assertSee('What changed?')
        ->assertSee('What should we do next?');
});

it('renders the empty state when read models have no data', function (): void {
    $context = marketingIntelligenceContext('Empty State');

    $this->withoutMiddleware(marketingIntelligenceDisabledMiddleware())
        ->actingAs($context['user'])
        ->get(route('app.marketing-intelligence.index'))
        ->assertOk()
        ->assertSee('No intelligence yet')
        ->assertSee('No trend evidence in this timeframe');
});

it('renders trends from canonical read models', function (): void {
    $context = marketingIntelligenceContext('Trend Data');

    marketingIntelligenceObservation($context, 'sessions', 40, '2026-07-01', [
        'defaultChannelGroup' => 'Organic Search',
    ]);
    marketingIntelligenceObservation($context, 'sessions', 100, '2026-07-02', [
        'defaultChannelGroup' => 'Organic Search',
    ]);

    $this->withoutMiddleware(marketingIntelligenceDisabledMiddleware())
        ->actingAs($context['user'])
        ->get(route('app.marketing-intelligence.index', [
            'time_window' => 'custom_range',
            'from' => '2026-07-02',
            'to' => '2026-07-02',
        ]))
        ->assertOk()
        ->assertSee('Organic Search Sessions')
        ->assertSee('Trend')
        ->assertSee('Confidence')
        ->assertSee('Marketing observations');
});

it('exposes evidence drawer metadata', function (): void {
    $context = marketingIntelligenceContext('Evidence Drawer');

    marketingIntelligenceObservation($context, 'sessions', 20, '2026-07-01', [
        'defaultChannelGroup' => 'Organic Search',
    ]);
    marketingIntelligenceObservation($context, 'sessions', 50, '2026-07-02', [
        'defaultChannelGroup' => 'Organic Search',
    ]);

    $this->withoutMiddleware(marketingIntelligenceDisabledMiddleware())
        ->actingAs($context['user'])
        ->get(route('app.marketing-intelligence.index', [
            'time_window' => 'custom_range',
            'from' => '2026-07-02',
            'to' => '2026-07-02',
        ]))
        ->assertOk()
        ->assertSee('data-drawer-target="marketing-intelligence.evidence"', false)
        ->assertSee('data-marketing-intelligence-evidence-panel', false)
        ->assertSee('Loading evidence')
        ->assertSee('Unable to show evidence')
        ->assertSee('evidence_reference_count', false)
        ->assertSee('Source summary');
});

it('renders recommendation links', function (): void {
    $context = marketingIntelligenceContext('Recommendation Links');

    RecommendedAction::query()->create([
        'workspace_id' => $context['workspace']->id,
        'organization_id' => $context['organization']->id,
        'source_signature' => 'recommendation-link-'.Str::random(8),
        'source_group' => RecommendedAction::SOURCE_CONTENT_INTELLIGENCE,
        'action_type' => 'review',
        'status' => RecommendedAction::STATUS_OPEN,
        'title' => 'Review pricing page momentum',
        'summary' => 'Traffic changed enough to review the pricing page.',
        'why_this_matters' => 'The pricing page is tied to pipeline impact.',
        'what_argusly_will_do' => 'Prepare the review notes.',
        'estimated_effort' => RecommendedAction::EFFORT_LOW,
        'priority_score' => 88,
        'confidence_score' => 82,
        'expected_impact_score' => 84,
        'priority_label' => 'high',
        'confidence_label' => 'high',
        'expected_impact_label' => 'high',
        'primary_cta_label' => 'Open recommendation',
        'primary_cta_url' => route('app.recommended-actions.index'),
        'visible_at' => now()->subMinute(),
    ]);

    $this->withoutMiddleware(marketingIntelligenceDisabledMiddleware())
        ->actingAs($context['user'])
        ->get(route('app.marketing-intelligence.index'))
        ->assertOk()
        ->assertSee('Review pricing page momentum')
        ->assertSee(route('app.recommended-actions.index'), false);
});

it('renders report and briefing links', function (): void {
    $context = marketingIntelligenceContext('Report Briefing Links');

    $report = PageIntelligenceReport::query()->create([
        'organization_id' => $context['organization']->id,
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'report_type' => 'weekly',
        'identity_hash' => hash('sha256', 'report-briefing-links'),
        'idempotency_key' => hash('sha256', 'report-briefing-links-key'),
        'title' => 'Weekly Intelligence Briefing',
        'status' => PageIntelligenceReport::STATUS_GENERATED,
        'snapshot_version' => 1,
        'template_version' => 'test',
        'period_start' => Carbon::parse('2026-07-01')->startOfDay(),
        'period_end' => Carbon::parse('2026-07-07')->endOfDay(),
        'summary' => 'Weekly intelligence summary.',
        'payload_json' => ['label' => 'Weekly Intelligence Briefing'],
        'provenance_json' => ['test' => true],
        'generated_by' => $context['user']->id,
        'generated_at' => now(),
        'artifact_status' => PageIntelligenceReport::ARTIFACT_STATUS_PENDING,
    ]);
    $briefing = ScheduledPageIntelligenceBriefing::query()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'report_type' => 'weekly',
        'market_pack_key' => null,
        'frequency' => ScheduledPageIntelligenceBriefing::FREQUENCY_WEEKLY,
        'day_of_week' => 1,
        'day_of_month' => null,
        'timezone' => 'UTC',
        'recipients_json' => [],
        'delivery_channels_json' => [],
        'delivery_state_json' => ['status' => 'not_delivered'],
        'is_active' => true,
        'last_generated_at' => null,
        'next_run_at' => now()->addDay(),
        'created_by' => $context['user']->id,
    ]);

    $this->withoutMiddleware(marketingIntelligenceDisabledMiddleware())
        ->actingAs($context['user'])
        ->get(route('app.marketing-intelligence.index', [
            'time_window' => 'custom_range',
            'from' => '2026-07-02',
            'to' => '2026-07-07',
        ]))
        ->assertOk()
        ->assertSee('Weekly Intelligence Briefing')
        ->assertSee(route('app.page-intelligence.reports.show', $report), false)
        ->assertSee(route('app.page-intelligence.scheduled-briefings.edit', $briefing), false);
});

it('guards workspaces outside the user tenancy', function (): void {
    $context = marketingIntelligenceContext('Tenant Owner');
    $foreign = marketingIntelligenceContext('Tenant Foreign');

    $this->withoutMiddleware(marketingIntelligenceDisabledMiddleware())
        ->actingAs($context['user'])
        ->get(route('app.marketing-intelligence.index', ['workspace' => $foreign['workspace']->id]))
        ->assertNotFound();
});

it('guards source filters outside the selected workspace', function (): void {
    $context = marketingIntelligenceContext('Source Owner');
    $foreign = marketingIntelligenceContext('Source Foreign');

    $this->withoutMiddleware(marketingIntelligenceDisabledMiddleware())
        ->actingAs($context['user'])
        ->get(route('app.marketing-intelligence.index', [
            'workspace' => $context['workspace']->id,
            'site' => $foreign['site']->id,
        ]))
        ->assertNotFound();
});

it('allows viewer roles to inspect the read-only workspace', function (): void {
    $context = marketingIntelligenceContext('Viewer Read Only', role: 'viewer');

    $this->withoutMiddleware(marketingIntelligenceDisabledMiddleware())
        ->actingAs($context['user'])
        ->get(route('app.marketing-intelligence.index'))
        ->assertOk()
        ->assertSee('Read-only source metadata')
        ->assertDontSee('Run Intelligence');
});

it('adjusts invalid custom timeframe filters without crashing', function (): void {
    $context = marketingIntelligenceContext('Invalid Timeframe');

    $this->withoutMiddleware(marketingIntelligenceDisabledMiddleware())
        ->actingAs($context['user'])
        ->get(route('app.marketing-intelligence.index', [
            'time_window' => 'custom_range',
            'from' => '2026-07-08',
            'to' => '2026-07-02',
        ]))
        ->assertOk()
        ->assertSee('The timeframe was adjusted so From is before To.')
        ->assertSee('2026-07-02 to 2026-07-08');
});

it('does not render provider-specific dashboard wording', function (): void {
    $context = marketingIntelligenceContext('Provider Wording');

    marketingIntelligenceObservation($context, 'sessions', 10, '2026-07-01', [
        'defaultChannelGroup' => 'Organic Search',
    ]);
    marketingIntelligenceObservation($context, 'sessions', 20, '2026-07-02', [
        'defaultChannelGroup' => 'Organic Search',
    ]);

    $this->withoutMiddleware(marketingIntelligenceDisabledMiddleware())
        ->actingAs($context['user'])
        ->get(route('app.marketing-intelligence.index', [
            'time_window' => 'custom_range',
            'from' => '2026-07-02',
            'to' => '2026-07-02',
        ]))
        ->assertOk()
        ->assertDontSee('GSC dashboard')
        ->assertDontSee('GA4 dashboard')
        ->assertDontSee('LinkedIn dashboard');
});

function marketingIntelligenceContext(string $name, string $role = 'owner', bool $isAdmin = false): array
{
    $organization = Organization::query()->create([
        'name' => $name.' Organization',
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => $name.' Workspace',
        'display_name' => $name.' Workspace',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_LARAVEL,
        'name' => $name.' Site',
        'site_url' => 'https://example.com',
        'base_url' => 'https://example.com',
        'allowed_domains' => ['example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => $role,
        'is_admin' => $isAdmin,
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);
    $connector = marketingIntelligenceConnector($workspace, $site, 'canonical_analytics_'.Str::lower(Str::random(6)));

    return compact('organization', 'workspace', 'site', 'user', 'connector');
}

function marketingIntelligenceConnector(Workspace $workspace, ClientSite $site, string $providerKey): array
{
    $provider = ConnectorProvider::factory()->create([
        'provider_key' => $providerKey,
        'name' => Str::headline($providerKey),
        'category' => ConnectorProvider::CATEGORY_OTHER,
    ]);
    $account = ConnectorAccount::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'connector_provider_id' => $provider->id,
        'provider_key' => $provider->provider_key,
        'account_name' => $provider->name.' Account',
        'external_account_id' => $providerKey.'-account',
        'status' => ConnectorAccount::STATUS_CONNECTED,
        'connected_at' => now(),
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'metadata_json' => [],
    ]);
    $dataset = ConnectorDataset::query()->create([
        'connector_account_id' => $account->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'provider_key' => $provider->provider_key,
        'dataset_key' => $providerKey.':performance',
        'dataset_type' => 'performance',
        'external_dataset_id' => $providerKey.'-performance',
        'display_name' => $provider->name.' Performance',
        'status' => ConnectorDataset::STATUS_ACTIVE,
        'health_status' => ConnectorHealthEvent::STATUS_HEALTHY,
        'health_severity' => ConnectorHealthEvent::SEVERITY_INFO,
        'capabilities_json' => ['keys' => ['performance.observations'], 'definitions' => []],
        'sync_config_json' => [],
        'config_json' => [],
        'metadata_json' => [],
    ]);
    $syncRun = ConnectorSyncRun::factory()->create([
        'connector_account_id' => $account->id,
        'connector_dataset_id' => $dataset->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'provider_key' => $provider->provider_key,
        'dataset_key' => $dataset->dataset_key,
        'status' => ConnectorSyncRun::STATUS_SUCCEEDED,
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    return compact('provider', 'account', 'dataset', 'syncRun');
}

function marketingIntelligenceObservation(array $context, string $metricKey, float|int $value, string $date, array $dimensions = []): MarketingObservation
{
    $metric = MarketingMetricDefinition::query()->firstOrCreate(
        ['metric_key' => $metricKey],
        [
            'display_name' => Str::headline($metricKey),
            'description' => 'Marketing intelligence test metric.',
            'value_type' => MarketingMetricDefinition::VALUE_TYPE_DECIMAL,
            'default_unit' => 'count',
            'aggregation' => MarketingMetricDefinition::AGGREGATION_SUM,
            'direction' => 'up',
            'is_active' => true,
            'metadata_json' => [],
        ],
    );

    foreach (array_keys($dimensions) as $dimensionKey) {
        MarketingDimensionDefinition::query()->firstOrCreate(
            ['dimension_key' => $dimensionKey],
            [
                'display_name' => Str::headline($dimensionKey),
                'description' => 'Marketing intelligence test dimension.',
                'value_type' => MarketingDimensionDefinition::VALUE_TYPE_STRING,
                'is_active' => true,
                'metadata_json' => [],
            ],
        );
    }

    $connector = $context['connector'];
    $periodStart = Carbon::parse($date)->startOfDay();

    return MarketingObservation::upsertByFingerprint([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'connector_provider_id' => $connector['provider']->id,
        'connector_account_id' => $connector['account']->id,
        'connector_dataset_id' => $connector['dataset']->id,
        'connector_sync_run_id' => $connector['syncRun']->id,
        'marketing_metric_definition_id' => $metric->id,
        'metric_key' => $metric->metric_key,
        'metric_value' => $value,
        'unit' => 'count',
        'period_start' => $periodStart,
        'period_end' => $periodStart->copy()->endOfDay(),
        'granularity' => MarketingObservation::GRANULARITY_DAILY,
        'observed_at' => $periodStart->copy()->addDay(),
        'confidence_score' => 1,
        'quality_score' => 1,
        'external_id' => (string) Str::uuid(),
        'source_metadata_json' => ['test' => 'marketing_intelligence'],
        'quality_metadata_json' => [],
        'raw_metadata_json' => [],
    ], $dimensions);
}

function marketingIntelligenceDisabledMiddleware(): array
{
    return [
        \App\Http\Middleware\SetAppLocale::class,
        \App\Http\Middleware\EnsureSupportModeContext::class,
        \App\Http\Middleware\DenyWriteActionsInSupportMode::class,
        \App\Http\Middleware\EnsureEmailCodeVerified::class,
        \App\Http\Middleware\EnsureUserApproved::class,
        \App\Http\Middleware\EnsureUserHasOrganization::class,
        \App\Http\Middleware\EnsureBillingOnboardingCompleted::class,
    ];
}
