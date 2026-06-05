<?php

use App\Models\ClientSite;
use App\Models\ContentSeries;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Content\SeriesBriefPayloadFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeSeriesBriefPayloadFactoryContext(array $seriesOverrides = []): array
{
    $organization = Organization::query()->create([
        'name' => 'Series Payload Org',
        'slug' => 'series-payload-' . Str::random(6),
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Series Payload Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Series Payload Site',
        'site_url' => 'https://series-payload.example.com',
        'base_url' => 'https://series-payload.example.com',
        'allowed_domains' => ['series-payload.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $series = ContentSeries::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => 'Series Payload',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance',
        'supporting_keywords' => ['workflow automation'],
        'intent_keys' => null,
        'audience' => 'operations',
        'tone' => 'clear',
        'articles_count' => 4,
        'status' => 'strategy_generated',
        'created_by' => null,
    ], $seriesOverrides));

    return [$series, $site];
}

it('builds the pillar brief payload with required nested intent keys', function () {
    [$series, $site] = makeSeriesBriefPayloadFactoryContext();

    $payload = app(SeriesBriefPayloadFactory::class)->build(
        series: $series,
        site: $site,
        article: [
            'title' => 'AI governance foundation guide',
        ],
        articleNumber: 1,
        title: 'AI governance foundation guide',
        primaryKeyword: 'ai governance foundation',
        secondaryKeywords: ['policy controls'],
        slug: 'ai-governance-foundation-guide',
        plannedUrl: 'https://series-payload.example.com/blog/ai-governance-foundation-guide',
        internalLinksTo: [2, 3],
    );

    expect(data_get($payload, 'brief.intent.keys'))->toBe(['educate', 'explain', 'guide', 'compare'])
        ->and(data_get($payload, 'brief.audience_keys'))->toBe(['operations'])
        ->and(data_get($payload, 'brief.preferred_length'))->toBe('pillar');
});

it('builds the supporting brief payload with article specific intent extensions', function () {
    [$series, $site] = makeSeriesBriefPayloadFactoryContext();

    $payload = app(SeriesBriefPayloadFactory::class)->build(
        series: $series,
        site: $site,
        article: [
            'title' => 'Process automation playbook',
            'output_type' => 'article',
        ],
        articleNumber: 2,
        title: 'Process automation playbook',
        primaryKeyword: 'process automation',
        secondaryKeywords: ['workflow playbook'],
        slug: 'process-automation-playbook',
        plannedUrl: 'https://series-payload.example.com/blog/process-automation-playbook',
        internalLinksTo: [1],
    );

    expect(data_get($payload, 'brief.intent.keys'))->toBe(['educate', 'inform', 'engage', 'process'])
        ->and(data_get($payload, 'brief.output_type'))->toBe('article')
        ->and(data_get($payload, 'brief.content_type'))->toBe('blog');
});

it('uses default intent keys when the user did not configure series content intent', function () {
    [$series, $site] = makeSeriesBriefPayloadFactoryContext(['intent_keys' => []]);

    $payload = app(SeriesBriefPayloadFactory::class)->build(
        series: $series,
        site: $site,
        article: [
            'title' => 'Landing page explainer',
            'output_type' => 'seo_page',
        ],
        articleNumber: 3,
        title: 'Landing page explainer',
        primaryKeyword: 'landing page explainer',
        secondaryKeywords: [],
        slug: 'landing-page-explainer',
        plannedUrl: 'https://series-payload.example.com/landing-page-explainer',
        internalLinksTo: [],
    );

    expect(data_get($payload, 'brief.intent.keys'))->toBe(['convert', 'persuade', 'explain'])
        ->and(data_get($payload, 'brief.audience_keys'))->toBe(['operations']);
});
