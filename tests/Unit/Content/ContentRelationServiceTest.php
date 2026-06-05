<?php

require_once __DIR__ . '/ContentIntelligenceTestHelpers.php';

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentSeries;
use App\Models\ContentSeriesArticle;
use App\Models\ContentVersion;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Content\ContentRelationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('selects related content from the same site and locale only', function () {
    [$workspace, $site] = makeContentIntelligenceContext('related-selection');
    [, $otherSite] = makeContentIntelligenceContext('related-selection-alt');

    $source = makeContentVariant($workspace, $site, 'Source article', 'en', [
        'status' => 'published',
        'publish_status' => 'published',
    ]);
    $sameSiteSameLocale = makeContentVariant($workspace, $site, 'Same site same locale', 'en', [
        'status' => 'published',
        'publish_status' => 'published',
    ]);
    makeContentVariant($workspace, $site, 'Same site other locale', 'nl', [
        'status' => 'published',
        'publish_status' => 'published',
    ]);
    makeContentVariant($workspace, $otherSite, 'Other site candidate', 'en', [
        'status' => 'published',
        'publish_status' => 'published',
    ]);
    makeContentVariant($workspace, $site, 'Unpublished candidate', 'en', [
        'status' => 'draft',
        'publish_status' => 'draft',
    ]);

    $results = app(ContentRelationService::class)->relatedContents(
        $source,
        locale: 'en',
        sameSite: true,
        publishedOnly: true,
    );

    expect($results->pluck('id')->all())->toBe([(string) $sameSiteSameLocale->id]);
});

it('respects chain relationships and order', function () {
    [$workspace, $site] = makeContentIntelligenceContext('chain-relations');

    $series = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $workspace->organization_id,
        'site_id' => $site->id,
        'name' => 'AI Governance Chain',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance',
        'supporting_keywords' => ['workflow checklist'],
        'articles_count' => 3,
        'status' => 'ready',
    ]);

    $pillar = makeContentVariant($workspace, $site, 'Governance foundations', 'en', [
        'series_id' => $series->id,
        'status' => 'published',
        'publish_status' => 'published',
    ]);
    $source = makeContentVariant($workspace, $site, 'Workflow checklist', 'en', [
        'series_id' => $series->id,
        'status' => 'published',
        'publish_status' => 'published',
    ]);
    $supporting = makeContentVariant($workspace, $site, 'Governance FAQ', 'en', [
        'series_id' => $series->id,
        'status' => 'published',
        'publish_status' => 'published',
    ]);

    makeSeriesArticle($series, $pillar, 1, true);
    makeSeriesArticle($series, $source, 2, false);
    makeSeriesArticle($series, $supporting, 3, false);

    $source = makeCurrentVersion($source, '<p>Source body</p>', now()->subDays(10));
    $pillar = makeCurrentVersion($pillar, '<p>Pillar body</p>', now()->subDays(20));
    $supporting = makeCurrentVersion($supporting, '<p>Support body</p>', now()->subDays(2));

    $service = app(ContentRelationService::class);

    expect($service->relationship($source, $pillar))->toBe('same_chain_pillar')
        ->and($service->relationship($pillar, $supporting))->toBe('same_chain_supporting')
        ->and($service->chainOrder($source))->toBe(2)
        ->and((string) $service->pillarContent($source)?->id)->toBe((string) $pillar->id)
        ->and($service->newerChainArticleCount($source))->toBe(1);
});
