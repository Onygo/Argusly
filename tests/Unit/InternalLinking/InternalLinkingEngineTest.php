<?php

use App\Agents\Data\AgentContext;
use App\Agents\InternalLinking\InternalLinkingAgent;
use App\Data\InternalLinkSuggestion;
use App\Models\ClientSite;
use App\Models\Brief;
use App\Models\Content;
use App\Models\ContentPublication;
use App\Models\ContentSeries;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\Content\ContentSeriesArticleSyncService;
use App\Services\InternalLinking\InternalLinkCandidateService;
use App\Services\InternalLinking\InternalLinkSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('internal_linking.enabled', true);
    config()->set('internal_linking.max_links_per_article', 4);
    config()->set('internal_linking.max_links_per_paragraph', 1);
    config()->set('internal_linking.candidate_limit', 12);
    config()->set('internal_linking.min_similarity_score', 0.0);
    config()->set('internal_linking.prefer_same_chain', true);
    config()->set('internal_linking.inject_into_html', true);
});

function makeInternalLinkingContext(string $prefix = 'Internal Linking'): array
{
    $organization = Organization::query()->create([
        'name' => $prefix . ' Org',
        'slug' => Str::slug($prefix) . '-org-' . Str::random(6),
        'status' => 'active',
    ]);

    $workspace = Workspace::query()->create([
        'name' => $prefix . ' Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => $prefix . ' Site',
        'site_url' => 'https://' . Str::slug($prefix) . '.example.com',
        'base_url' => 'https://' . Str::slug($prefix) . '.example.com',
        'allowed_domains' => [Str::slug($prefix) . '.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    return [$workspace, $site];
}

function makePublishedContentForLinking(
    Workspace $workspace,
    ClientSite $site,
    string $title,
    string $keyword,
    string $path,
    ?ContentSeries $series = null,
    ?string $externalKey = null
): Content {
    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'series_id' => $series?->id,
        'external_key' => $externalKey ?: (string) Str::uuid(),
        'title' => $title,
        'primary_keyword' => $keyword,
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
        'published_url' => rtrim((string) $site->site_url, '/') . $path,
    ]);

    ContentPublication::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'locale' => 'en',
        'provider' => $site->isLaravel() ? ContentPublication::PROVIDER_LARAVEL : ContentPublication::PROVIDER_WORDPRESS,
        'remote_id' => (string) Str::random(8),
        'remote_type' => 'post',
        'remote_url' => $content->published_url,
        'remote_status' => ContentPublication::REMOTE_PUBLISHED,
        'delivery_status' => ContentPublication::STATUS_DELIVERED,
        'last_verified_at' => now(),
        'last_delivered_at' => now(),
        'meta' => [],
    ]);

    return $content;
}

function attachSourceDraft(Content $content, ClientSite $site, string $html): Draft
{
    $brief = Brief::query()->create([
        'id' => (string) Str::uuid(),
        'client_site_id' => $site->id,
        'content_id' => $content->id,
        'status' => 'ready',
        'title' => $content->title,
        'language' => 'en',
        'primary_keyword' => $content->primary_keyword,
        'output_type' => 'kb_article',
    ]);

    return Draft::query()->create([
        'id' => (string) Str::uuid(),
        'brief_id' => $brief->id,
        'content_id' => $content->id,
        'client_site_id' => $site->id,
        'status' => 'generated',
        'delivery_status' => 'pending',
        'title' => $content->title,
        'output_type' => 'kb_article',
        'content_html' => $html,
        'links' => [],
    ]);
}

it('does not return the source article as an internal linking candidate', function () {
    [$workspace, $site] = makeInternalLinkingContext('Internal Linking Self');

    $source = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'AI governance workflow',
        keyword: 'ai governance workflow',
        path: '/blog/ai-governance-workflow',
    );

    makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Governance checklist',
        keyword: 'governance checklist',
        path: '/blog/governance-checklist',
    );

    $candidates = app(InternalLinkCandidateService::class)->candidatesFor(
        $source,
        '<p>This article mentions governance checklist and workflow design.</p>',
    );

    expect($candidates->pluck('content.id')->all())->not->toContain((string) $source->id);
});

it('prioritizes same-chain pillar candidates ahead of topic-related content', function () {
    [$workspace, $site] = makeInternalLinkingContext('Internal Linking Chain');

    $series = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $workspace->organization_id,
        'site_id' => $site->id,
        'name' => 'Governance Chain',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance',
        'supporting_keywords' => ['workflow checklist'],
        'articles_count' => 3,
        'status' => 'ready',
        'strategy_json' => [
            'articles' => [
                ['article_number' => 1, 'title' => 'AI governance foundations', 'primary_keyword' => 'ai governance foundations', 'secondary_keywords' => [], 'internal_links_to' => [2]],
                ['article_number' => 2, 'title' => 'Workflow checklist', 'primary_keyword' => 'workflow checklist', 'secondary_keywords' => [], 'internal_links_to' => [1]],
                ['article_number' => 3, 'title' => 'Governance FAQ', 'primary_keyword' => 'governance faq', 'secondary_keywords' => [], 'internal_links_to' => [1]],
            ],
        ],
    ]);

    $pillar = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'AI governance foundations',
        keyword: 'ai governance foundations',
        path: '/blog/ai-governance-foundations',
        series: $series,
        externalKey: 'series-' . $series->id . '-article-1',
    );
    $source = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Workflow checklist',
        keyword: 'workflow checklist',
        path: '/blog/workflow-checklist',
        series: $series,
        externalKey: 'series-' . $series->id . '-article-2',
    );
    makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Governance FAQ',
        keyword: 'governance faq',
        path: '/blog/governance-faq',
        series: $series,
        externalKey: 'series-' . $series->id . '-article-3',
    );
    $topicRelated = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'AI governance reporting',
        keyword: 'ai governance reporting',
        path: '/blog/ai-governance-reporting',
    );

    app(ContentSeriesArticleSyncService::class)->sync($series);
    app(ContentSeriesArticleSyncService::class)->setPillar($series, 1);

    $candidates = app(InternalLinkCandidateService::class)->candidatesFor(
        $source,
        '<p>This workflow checklist should point back to ai governance foundations and also mention ai governance reporting when useful.</p>',
    );

    expect((string) data_get($candidates->first(), 'content.id'))->toBe((string) $pillar->id)
        ->and($candidates->pluck('content.id')->all())->toContain((string) $topicRelated->id);
});

it('does not generate duplicate anchor suggestions', function () {
    [$workspace, $site] = makeInternalLinkingContext('Internal Linking Duplicate Anchors');

    $source = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Automation strategy',
        keyword: 'automation strategy',
        path: '/blog/automation-strategy',
    );
    attachSourceDraft($source, $site, '<p>An automation checklist helps teams define the first rollout. Another automation checklist can support change management.</p>');

    makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Operations rollout guide',
        keyword: 'automation checklist',
        path: '/blog/operations-rollout-guide',
    );
    makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Change management plan',
        keyword: 'automation checklist',
        path: '/blog/change-management-plan',
    );

    $suggestions = app(InternalLinkSuggestionService::class)->suggestFor($source);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions->first()?->anchorText)->toBe('automation checklist');
});

it('respects the maximum number of links per article', function () {
    [$workspace, $site] = makeInternalLinkingContext('Internal Linking Max Links');

    $source = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Cluster overview',
        keyword: 'cluster overview',
        path: '/blog/cluster-overview',
    );
    attachSourceDraft(
        $source,
        $site,
        '<p>This article references topic alpha, topic beta, topic gamma, topic delta, topic epsilon, and topic zeta in one connected overview.</p>'
    );

    foreach (['topic alpha', 'topic beta', 'topic gamma', 'topic delta', 'topic epsilon', 'topic zeta'] as $topic) {
        makePublishedContentForLinking(
            workspace: $workspace,
            site: $site,
            title: Str::title($topic),
            keyword: $topic,
            path: '/blog/' . Str::slug($topic),
        );
    }

    $suggestions = app(InternalLinkSuggestionService::class)->suggestFor($source);

    expect($suggestions)->toHaveCount(4)
        ->and($suggestions->map(fn ($suggestion) => $suggestion->anchorText)->all())->toBe([
            'topic alpha',
            'topic beta',
            'topic gamma',
            'topic delta',
        ]);
});

it('filters internal linking suggestions to the same locale', function () {
    [$workspace, $site] = makeInternalLinkingContext('Agent Locale Filter');

    $source = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Workflow planning guide',
        keyword: 'workflow planning guide',
        path: '/blog/workflow-planning-guide',
    );
    $draft = attachSourceDraft(
        $source,
        $site,
        '<p>This guide references editorial workflow checklist and dutch governance checklist for comparison.</p>'
    );

    $englishTarget = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Editorial workflow checklist',
        keyword: 'editorial workflow checklist',
        path: '/blog/editorial-workflow-checklist',
    );

    Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Dutch governance checklist',
        'primary_keyword' => 'dutch governance checklist',
        'language' => 'nl',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
        'published_url' => rtrim((string) $site->site_url, '/') . '/blog/dutch-governance-checklist',
    ]);

    $result = app(InternalLinkingAgent::class)->run(AgentContext::forDraft($draft));

    expect(collect($result->suggestions)->pluck('target_content_id')->all())->toBe([(string) $englishTarget->id]);
});

it('excludes inactive published-url targets from internal linking candidates', function () {
    [$workspace, $site] = makeInternalLinkingContext('Internal Linking Active Targets');

    $source = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'AI visibility campaign clusters',
        keyword: 'ai visibility campaign clusters',
        path: '/knowledge-base/ai-visibility-campaign-clusters',
    );

    $activeTarget = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Published AI visibility framework',
        keyword: 'published ai visibility framework',
        path: '/knowledge-base/published-ai-visibility-framework',
    );

    $inactiveTarget = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Inactive AI visibility framework',
        keyword: 'inactive ai visibility framework',
        path: '/knowledge-base/inactive-ai-visibility-framework',
    );
    $inactiveTarget->publications()->update([
        'delivery_status' => ContentPublication::STATUS_FAILED,
        'remote_status' => ContentPublication::REMOTE_DRAFT,
    ]);

    Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'title' => 'Canonical Only AI visibility framework',
        'primary_keyword' => 'canonical only ai visibility framework',
        'language' => 'en',
        'type' => 'article',
        'status' => 'published',
        'publish_status' => 'published',
        'source' => 'manual',
        'seo_canonical' => rtrim((string) $site->site_url, '/') . '/knowledge-base/canonical-only-ai-visibility-framework',
    ]);

    $candidates = app(InternalLinkCandidateService::class)->candidatesFor(
        $source,
        '<p>This article mentions published ai visibility framework, inactive ai visibility framework, and canonical only ai visibility framework.</p>',
    );

    expect($candidates->pluck('content.id')->all())->toBe([(string) $activeTarget->id])
        ->and($candidates->pluck('target_url')->all())->toBe([$activeTarget->published_url]);
});

it('replaces model-mutated internal link urls with canonical candidate urls', function () {
    [$workspace, $site] = makeInternalLinkingContext('Internal Linking Url Authority');

    $source = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'AI visibility clusters',
        keyword: 'ai visibility clusters',
        path: '/knowledge-base/ai-visibility-clusters',
    );

    $target = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Van SEO naar GEO naar AI visibility',
        keyword: 'van seo naar geo naar ai visibility',
        path: '/knowledge-base/van-seo-naar-geo-naar-ai-visibility-een-strategisch-raamwerk-voor-b2b-marketeers-en-developers',
    );

    $candidates = app(InternalLinkCandidateService::class)->candidatesFor(
        $source,
        '<p>This article mentions van seo naar geo naar ai visibility.</p>',
    );

    $service = app(InternalLinkSuggestionService::class);
    $method = new ReflectionMethod($service, 'normalizeSuggestions');
    $method->setAccessible(true);

    $suggestions = $method->invoke($service, collect([
        new InternalLinkSuggestion(
            targetContentId: (string) $target->id,
            targetUrl: rtrim((string) $site->site_url, '/') . '/knowledge-base/van-seo-naar-geo-naar-ai-visibility-een-strategisch-raamwerk-voor-b2b-marketeers-en%20-developers/',
            anchorText: 'van seo naar geo naar ai visibility',
            reason: 'Relevant context',
        ),
    ]), $candidates);

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions->first()?->targetUrl)->toBe($target->published_url);
});

it('filters internal linking suggestions to the same site', function () {
    [$workspace, $site] = makeInternalLinkingContext('Agent Site Filter');

    $otherSite = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Agent Site Filter Secondary',
        'site_url' => 'https://secondary-agent-site-filter.example.com',
        'base_url' => 'https://secondary-agent-site-filter.example.com',
        'allowed_domains' => ['secondary-agent-site-filter.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $source = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Localization workflow guide',
        keyword: 'localization workflow guide',
        path: '/blog/localization-workflow-guide',
    );
    $draft = attachSourceDraft(
        $source,
        $site,
        '<p>This guide references multilingual launch process and editorial governance checklist.</p>'
    );

    $sameSiteTarget = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Editorial governance checklist',
        keyword: 'editorial governance checklist',
        path: '/blog/editorial-governance-checklist',
    );

    makePublishedContentForLinking(
        workspace: $workspace,
        site: $otherSite,
        title: 'Multilingual launch process',
        keyword: 'multilingual launch process',
        path: '/blog/multilingual-launch-process',
    );

    $result = app(InternalLinkingAgent::class)->run(AgentContext::forDraft($draft));

    expect(collect($result->suggestions)->pluck('target_content_id')->all())->toBe([(string) $sameSiteTarget->id]);
});

it('prevents duplicate target suggestions in the internal linking agent', function () {
    [$workspace, $site] = makeInternalLinkingContext('Agent Duplicate Targets');

    $source = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Automation strategy',
        keyword: 'automation strategy',
        path: '/blog/automation-strategy',
    );
    $draft = attachSourceDraft(
        $source,
        $site,
        '<p>An automation checklist helps teams define the first rollout and another automation checklist can support change management.</p>'
    );

    makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Operations rollout guide',
        keyword: 'automation checklist',
        path: '/blog/operations-rollout-guide',
    );
    makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Change management plan',
        keyword: 'automation checklist',
        path: '/blog/change-management-plan',
    );

    $result = app(InternalLinkingAgent::class)->run(AgentContext::forDraft($draft));

    expect($result->suggestions)->toHaveCount(1)
        ->and(data_get($result->suggestions, '0.anchor_text'))->toBe('automation checklist');
});

it('prevents self linking in the internal linking agent', function () {
    [$workspace, $site] = makeInternalLinkingContext('Agent Self Link');

    $source = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Internal search template',
        keyword: 'internal search template',
        path: '/blog/internal-search-template',
    );
    $draft = attachSourceDraft(
        $source,
        $site,
        '<p>This internal search template compares internal search template patterns with editorial QA workflow steps.</p>'
    );

    $target = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Editorial QA workflow',
        keyword: 'editorial qa workflow',
        path: '/blog/editorial-qa-workflow',
    );

    $result = app(InternalLinkingAgent::class)->run(AgentContext::forDraft($draft));

    expect(collect($result->suggestions)->pluck('target_content_id')->all())
        ->toContain((string) $target->id)
        ->not->toContain((string) $source->id);
});

it('excludes already linked targets when detecting internal link suggestions', function () {
    [$workspace, $site] = makeInternalLinkingContext('Agent Existing Link');

    $source = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Search operations guide',
        keyword: 'search operations guide',
        path: '/blog/search-operations-guide',
    );
    $existingTarget = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Search intent framework',
        keyword: 'search intent framework',
        path: '/blog/search-intent-framework',
    );
    $availableTarget = makePublishedContentForLinking(
        workspace: $workspace,
        site: $site,
        title: 'Editorial reporting template',
        keyword: 'editorial reporting template',
        path: '/blog/editorial-reporting-template',
    );
    $draft = attachSourceDraft(
        $source,
        $site,
        '<p>A <a href="' . $existingTarget->published_url . '">search intent framework</a> helps teams align content.</p><p>Later the article also references editorial reporting template for follow-up analysis.</p>'
    );

    $result = app(InternalLinkingAgent::class)->run(AgentContext::forDraft($draft));

    expect(collect($result->suggestions)->pluck('target_content_id')->all())->toBe([(string) $availableTarget->id]);
});
