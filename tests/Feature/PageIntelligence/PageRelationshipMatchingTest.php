<?php

use App\Models\Campaign;
use App\Models\ClientSite;
use App\Models\CompanyIntelligenceProfile;
use App\Models\MonitoredPage;
use App\Models\PageContentExtraction;
use App\Models\PageSnapshot;
use App\Models\SiteCompetitor;
use App\Services\PageIntelligence\Matching\PageBrandMatcher;
use App\Services\PageIntelligence\Matching\PageCampaignMatcher;
use App\Services\PageIntelligence\Matching\PageCompetitorMatcher;
use App\Services\PageIntelligence\Matching\PageMarketPackMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a campaign match from a campaign URL', function (): void {
    [$page] = relationshipMatchingPage([
        'canonical_url' => 'https://example.com/campaign-launch',
        'first_seen_url' => 'https://example.com/campaign-launch',
        'domain' => 'example.com',
    ]);
    $campaign = Campaign::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'name' => 'Launch Campaign',
        'metadata' => [
            'campaign_urls' => ['https://example.com/campaign-launch'],
        ],
    ]);

    $matches = app(PageCampaignMatcher::class)->match($page);

    expect($matches->pluck('match_type')->all())->toContain('campaign_url')
        ->and($page->campaignMatches()->where('campaign_id', $campaign->id)->where('match_type', 'campaign_url')->exists())->toBeTrue();
});

it('creates a campaign match from UTM parameters', function (): void {
    [$page] = relationshipMatchingPage([
        'canonical_url' => 'https://publisher.test/article',
        'first_seen_url' => 'https://publisher.test/article?utm_campaign=summer_launch&utm_source=newsletter',
        'domain' => 'publisher.test',
    ]);
    $campaign = Campaign::factory()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'name' => 'Summer Launch',
        'metadata' => [
            'tracking_parameters' => [
                'utm_campaign' => 'summer_launch',
                'utm_source' => 'newsletter',
            ],
        ],
    ]);

    app(PageCampaignMatcher::class)->match($page);

    $match = $page->campaignMatches()->where('campaign_id', $campaign->id)->where('match_type', 'utm')->first();

    expect($match)->not->toBeNull()
        ->and($match->evidence_json['matched_parameters'])->toHaveKey('utm_campaign');
});

it('creates a competitor match from competitor domain', function (): void {
    [$page] = relationshipMatchingPage([
        'canonical_url' => 'https://competitor.example/news',
        'first_seen_url' => 'https://competitor.example/news',
        'domain' => 'competitor.example',
    ]);
    $site = ClientSite::query()->create([
        'workspace_id' => $page->workspace_id,
        'name' => 'Argusly Site',
        'site_url' => 'https://argusly.test',
        'base_url' => 'https://argusly.test',
        'allowed_domains' => ['argusly.test'],
        'type' => ClientSite::TYPE_WORDPRESS,
        'is_active' => true,
    ]);
    $competitor = SiteCompetitor::query()->create([
        'workspace_id' => $page->workspace_id,
        'client_site_id' => $site->id,
        'name' => 'Competitor Example',
        'domain' => 'competitor.example',
        'is_active' => true,
    ]);

    app(PageCompetitorMatcher::class)->match($page);

    expect($page->competitorMatches()->where('site_competitor_id', $competitor->id)->where('match_type', 'competitor_domain')->exists())->toBeTrue();
});

it('creates a brand match from brand mention context', function (): void {
    [$page] = relationshipMatchingPage([], 'Argusly was named as a leading answer engine visibility platform.');
    $profile = CompanyIntelligenceProfile::query()->create([
        'organization_id' => $page->organization_id,
        'workspace_id' => $page->workspace_id,
        'brand_key' => 'argusly',
        'company_name' => 'Argusly',
        'target_entities' => ['Argusly'],
        'status' => CompanyIntelligenceProfile::STATUS_ACTIVE,
        'is_default' => true,
        'source_type' => 'test',
    ]);

    app(PageBrandMatcher::class)->match($page);

    $match = $page->brandMatches()->where('brand_ref_id', $profile->id)->where('match_type', 'brand_mention')->first();

    expect($match)->not->toBeNull()
        ->and($match->brand_name)->toBe('Argusly')
        ->and($match->evidence_json['term'])->toBe('Argusly');
});

it('creates a market pack match from configured theme keywords', function (): void {
    config()->set('page_intelligence.market_packs', [
        'ai_visibility' => [
            'name' => 'AI Visibility',
            'keywords' => ['answer engine visibility', 'GEO citations'],
        ],
    ]);
    [$page] = relationshipMatchingPage([], 'This report covers answer engine visibility and GEO citations for B2B brands.');

    app(PageMarketPackMatcher::class)->match($page);

    $match = $page->marketPackMatches()->where('market_pack_key', 'ai_visibility')->where('match_type', 'market_theme')->first();

    expect($match)->not->toBeNull()
        ->and($match->market_pack_name)->toBe('AI Visibility')
        ->and($match->evidence_json['matched_keywords'])->toContain('answer engine visibility');
});

function relationshipMatchingPage(array $pageAttributes = [], string $mainText = 'Page relationship matching evidence.'): array
{
    $firstSeenUrl = (string) ($pageAttributes['first_seen_url'] ?? $pageAttributes['canonical_url'] ?? 'https://publisher.test/article');
    $canonicalUrl = (string) ($pageAttributes['canonical_url'] ?? preg_replace('/\\?.*$/', '', $firstSeenUrl));

    $page = MonitoredPage::factory()->create(array_merge([
        'canonical_url' => $canonicalUrl,
        'canonical_url_hash' => hash('sha256', $canonicalUrl),
        'first_seen_url' => $firstSeenUrl,
        'first_seen_url_hash' => hash('sha256', $firstSeenUrl),
        'final_url' => $firstSeenUrl,
        'final_url_hash' => hash('sha256', $firstSeenUrl),
        'domain' => parse_url($canonicalUrl, PHP_URL_HOST) ?: 'publisher.test',
        'published_at_current' => now(),
    ], $pageAttributes));

    $snapshot = PageSnapshot::factory()->forPage($page)->create();
    $extraction = PageContentExtraction::factory()->forSnapshot($snapshot)->create([
        'title' => $page->title_current ?: 'Relationship matching article',
        'summary' => $mainText,
        'main_text' => $mainText,
        'outbound_links_json' => [],
        'internal_links_json' => [],
    ]);

    return [$page->refresh(), $snapshot, $extraction];
}
