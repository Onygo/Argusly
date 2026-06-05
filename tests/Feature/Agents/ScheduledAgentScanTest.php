<?php

use App\Agents\ContentRefresh\ContentRefreshAgent;
use App\Agents\Localization\LocalizationAgent;
use App\Agents\ScheduledAgentRunner;
use App\Enums\DraftType;
use App\Jobs\Agents\ScanSiteForLocalizationIssues;
use App\Jobs\Agents\ScanSiteForRefreshOpportunities;
use App\Models\AgentRun;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agents\AgentAutomationSettingsResolver;
use App\Services\Agents\SiteContentScanScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('scheduled refresh scans respect site, locale, and status boundaries', function () {
    [$owner, $workspace, $siteA, $siteB] = makeScheduledAgentScanContext('scheduled-refresh-boundaries');

    $includedContent = makeScheduledScanContent($workspace, $siteA, $owner, 'Included refresh content', [
        'language' => 'en',
        'status' => 'published',
        'publish_status' => 'published',
        'seo_title' => null,
        'seo_meta_description' => null,
        'seo_h1' => null,
    ]);
    attachScheduledScanDraft($includedContent, $siteA, '<p>Short body with no links.</p>');

    $excludedDraftStatus = makeScheduledScanContent($workspace, $siteA, $owner, 'Excluded draft content', [
        'language' => 'en',
        'status' => 'draft',
        'publish_status' => 'draft',
        'seo_title' => null,
        'seo_meta_description' => null,
        'seo_h1' => null,
    ]);
    attachScheduledScanDraft($excludedDraftStatus, $siteA, '<p>Short body with no links.</p>');

    $excludedLocale = makeScheduledScanContent($workspace, $siteA, $owner, 'Excluded Dutch content', [
        'language' => 'nl',
        'status' => 'published',
        'publish_status' => 'published',
        'seo_title' => null,
        'seo_meta_description' => null,
        'seo_h1' => null,
    ]);
    attachScheduledScanDraft($excludedLocale, $siteA, '<p>Korte tekst zonder links.</p>');

    $excludedSite = makeScheduledScanContent($workspace, $siteB, $owner, 'Other site content', [
        'language' => 'en',
        'status' => 'published',
        'publish_status' => 'published',
        'seo_title' => null,
        'seo_meta_description' => null,
        'seo_h1' => null,
    ]);
    attachScheduledScanDraft($excludedSite, $siteB, '<p>Short body with no links.</p>');

    $job = new ScanSiteForRefreshOpportunities(
        siteId: (string) $siteA->id,
        organizationId: $owner->organization_id,
        workspaceId: (string) $workspace->id,
        locale: 'en',
        statuses: ['published'],
        recentDays: null,
        limit: 10,
    );

    $job->handle(
        app(SiteContentScanScope::class),
        app(ScheduledAgentRunner::class),
        app(ContentRefreshAgent::class),
        app(AgentAutomationSettingsResolver::class),
    );

    $runs = AgentRun::query()
        ->where('agent_key', ContentRefreshAgent::KEY)
        ->where('trigger_type', 'scheduled')
        ->get();

    expect($runs)->toHaveCount(1)
        ->and((string) $runs->first()->content_id)->toBe((string) $includedContent->id);
});

it('scheduled localization scans only include expected content for the selected site', function () {
    [$owner, $workspace, $siteA, $siteB] = makeScheduledAgentScanContext('scheduled-localization-boundaries');

    $source = makeScheduledScanContent($workspace, $siteA, $owner, 'English source article', [
        'language' => 'en',
        'status' => 'published',
        'publish_status' => 'published',
        'seo_title' => 'English source article',
        'seo_meta_description' => 'English source article description',
        'seo_h1' => 'English source article',
        'publish_url_key' => 'english-source-article',
    ]);
    attachScheduledContentVersion($source, '<p>English source body.</p>');

    makeScheduledScanContent($workspace, $siteA, $owner, 'Dutch translation', [
        'language' => 'nl',
        'status' => 'published',
        'publish_status' => 'published',
        'translation_source_content_id' => $source->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
    ]);

    makeScheduledScanContent($workspace, $siteB, $owner, 'Other site source', [
        'language' => 'en',
        'status' => 'published',
        'publish_status' => 'published',
    ]);

    $job = new ScanSiteForLocalizationIssues(
        siteId: (string) $siteA->id,
        organizationId: $owner->organization_id,
        workspaceId: (string) $workspace->id,
        locale: 'en',
        statuses: ['published'],
        recentDays: null,
        limit: 10,
    );

    $job->handle(
        app(SiteContentScanScope::class),
        app(ScheduledAgentRunner::class),
        app(LocalizationAgent::class),
        app(AgentAutomationSettingsResolver::class),
    );

    $runs = AgentRun::query()
        ->where('agent_key', LocalizationAgent::KEY)
        ->where('trigger_type', 'scheduled')
        ->get();

    expect($runs)->toHaveCount(1)
        ->and((string) $runs->first()->content_id)->toBe((string) $source->id);
});

it('repeated scheduled refresh scans produce deterministic latest summaries for the same content set', function () {
    [$owner, $workspace, $site] = makeScheduledAgentScanSingleSiteContext('scheduled-refresh-deterministic');

    $first = makeScheduledScanContent($workspace, $site, $owner, 'First refresh candidate', [
        'status' => 'published',
        'publish_status' => 'published',
        'seo_title' => null,
        'seo_meta_description' => null,
        'seo_h1' => null,
    ]);
    attachScheduledScanDraft($first, $site, '<p>Short body with no links.</p>');

    $second = makeScheduledScanContent($workspace, $site, $owner, 'Second refresh candidate', [
        'status' => 'published',
        'publish_status' => 'published',
        'seo_title' => null,
        'seo_meta_description' => null,
        'seo_h1' => null,
    ]);
    attachScheduledScanDraft($second, $site, '<p>Short body with no links.</p>');

    $job = new ScanSiteForRefreshOpportunities(
        siteId: (string) $site->id,
        organizationId: $owner->organization_id,
        workspaceId: (string) $workspace->id,
        locale: 'en',
        statuses: ['published'],
        recentDays: null,
        limit: 10,
    );

    $job->handle(
        app(SiteContentScanScope::class),
        app(ScheduledAgentRunner::class),
        app(ContentRefreshAgent::class),
        app(AgentAutomationSettingsResolver::class),
    );

    $firstSnapshot = latestScheduledRefreshSnapshot($site);

    $job->handle(
        app(SiteContentScanScope::class),
        app(ScheduledAgentRunner::class),
        app(ContentRefreshAgent::class),
        app(AgentAutomationSettingsResolver::class),
    );

    $secondSnapshot = latestScheduledRefreshSnapshot($site);

    expect($secondSnapshot)->toBe($firstSnapshot);
});

it('shows scheduled optimization recommendations on the site detail page', function () {
    [$owner, $workspace, $site] = makeScheduledAgentScanSingleSiteContext('scheduled-site-visibility');

    $refreshContent = makeScheduledScanContent($workspace, $site, $owner, 'Refresh candidate content', [
        'status' => 'published',
        'publish_status' => 'published',
        'seo_title' => null,
        'seo_meta_description' => null,
        'seo_h1' => null,
    ]);
    attachScheduledScanDraft($refreshContent, $site, '<p>Short body with no links.</p>');

    $localizedSource = makeScheduledScanContent($workspace, $site, $owner, 'Localized source content', [
        'language' => 'en',
        'status' => 'published',
        'publish_status' => 'published',
        'seo_title' => 'Localized source content',
        'seo_meta_description' => 'Localized source content description',
        'seo_h1' => 'Localized source content',
        'publish_url_key' => 'localized-source-content',
    ]);
    attachScheduledContentVersion($localizedSource, '<p>Source body.</p>');

    makeScheduledScanContent($workspace, $site, $owner, 'Nederlandse variant', [
        'language' => 'nl',
        'status' => 'published',
        'publish_status' => 'published',
        'translation_source_content_id' => $localizedSource->id,
        'translation_source_locale' => 'en',
        'is_source_locale' => false,
        'seo_meta_description' => null,
        'publish_url_key' => null,
    ]);

    (new ScanSiteForRefreshOpportunities(
        siteId: (string) $site->id,
        organizationId: $owner->organization_id,
        workspaceId: (string) $workspace->id,
        locale: 'en',
        statuses: ['published'],
        recentDays: null,
        limit: 10,
    ))->handle(
        app(SiteContentScanScope::class),
        app(ScheduledAgentRunner::class),
        app(ContentRefreshAgent::class),
        app(AgentAutomationSettingsResolver::class),
    );

    (new ScanSiteForLocalizationIssues(
        siteId: (string) $site->id,
        organizationId: $owner->organization_id,
        workspaceId: (string) $workspace->id,
        locale: null,
        statuses: ['published'],
        recentDays: null,
        limit: 10,
    ))->handle(
        app(SiteContentScanScope::class),
        app(ScheduledAgentRunner::class),
        app(LocalizationAgent::class),
        app(AgentAutomationSettingsResolver::class),
    );

    $this->actingAs($owner)
        ->get(route('app.sites.show', $site))
        ->assertOk()
        ->assertSee('Continuous optimization')
        ->assertSee('Refresh opportunities')
        ->assertSee('Localization issues')
        ->assertSee('Refresh candidate content')
        ->assertSee('Localized source content');
});

function makeScheduledAgentScanContext(string $prefix): array
{
    $organization = Organization::query()->create([
        'name' => 'Scheduled Scan Org',
        'slug' => $prefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Scheduled Scan BV',
        'billing_address_line1' => 'Teststraat 1',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Scheduled Scan Workspace',
        'organization_id' => $organization->id,
        'enabled_content_languages' => ['en', 'nl', 'de'],
    ]);

    $siteA = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Scheduled Scan Site A',
        'site_url' => 'https://' . $prefix . '-a.example.com',
        'base_url' => 'https://' . $prefix . '-a.example.com',
        'allowed_domains' => [$prefix . '-a.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $siteB = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Scheduled Scan Site B',
        'site_url' => 'https://' . $prefix . '-b.example.com',
        'base_url' => 'https://' . $prefix . '-b.example.com',
        'allowed_domains' => [$prefix . '-b.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => $prefix . '-plan'],
        [
            'name' => 'Scheduled Scan Plan',
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
        'client_site_id' => $siteA->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $owner = User::query()->create([
        'name' => 'Scheduled Scan Owner',
        'email' => $prefix . '+owner@example.com',
        'password' => bcrypt('secret'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    return [$owner, $workspace, $siteA, $siteB];
}

function makeScheduledAgentScanSingleSiteContext(string $prefix): array
{
    [$owner, $workspace, $site] = makeScheduledAgentScanContext($prefix);

    return [$owner, $workspace, $site];
}

function makeScheduledScanContent(Workspace $workspace, ClientSite $site, User $owner, string $title, array $overrides = []): Content
{
    return Content::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'workspace_id' => (string) $workspace->id,
        'client_site_id' => (string) $site->id,
        'title' => $title,
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'publish_status' => 'draft',
        'source' => 'manual',
        'primary_keyword' => Str::slug($title),
        'created_by' => $owner->id,
        'updated_by' => $owner->id,
    ], $overrides));
}

function attachScheduledScanDraft(Content $content, ClientSite $site, string $html): Draft
{
    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'created_by_user_id' => $content->created_by,
        'status' => 'draft',
        'source' => 'client_ui',
        'title' => $content->title,
        'language' => $content->localeCode(),
        'content_type' => 'blog',
        'output_type' => 'kb_article',
        'progress' => 0,
    ]);

    return Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'title' => $content->title,
        'output_type' => 'kb_article',
        'draft_type' => DraftType::ORIGINAL->value,
        'language' => $content->localeCode(),
        'content_html' => $html,
    ]);
}

function attachScheduledContentVersion(Content $content, string $body): ContentVersion
{
    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => ContentVersion::TYPE_REVISION,
        'body' => $body,
        'source' => ContentVersion::SOURCE_PUBLISHLAYER,
    ]);

    $content->forceFill([
        'current_version_id' => $version->id,
    ])->save();

    return $version;
}

function latestScheduledRefreshSnapshot(ClientSite $site): array
{
    return AgentRun::query()
        ->where('agent_key', ContentRefreshAgent::KEY)
        ->where('trigger_type', 'scheduled')
        ->where('site_id', (string) $site->id)
        ->latest('created_at')
        ->get()
        ->unique('content_id')
        ->mapWithKeys(fn (AgentRun $run): array => [
            (string) $run->content_id => [
                'summary' => (string) ($run->summary ?? ''),
                'score' => (int) data_get($run->output_payload, 'raw_payload.refresh_score', 0),
            ],
        ])
        ->all();
}
