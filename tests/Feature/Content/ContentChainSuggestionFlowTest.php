<?php

use App\Jobs\RebuildContentMarkdownArtifactJob;
use App\Models\AnalyticsSite;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentChainGuidance;
use App\Models\ContentChainSuggestion;
use App\Models\ContentRevision;
use App\Models\ContentSeries;
use App\Models\ContentVersion;
use App\Models\OnboardingState;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('features.content_network_analysis', true);
    config()->set('content_chain.suggestions.source_min_score', 30);
    config()->set('content_chain.suggestions.confidence_threshold', 0.55);
    config()->set('content_chain.inline_links.default_max_links', 2);
});

it('refreshes chained suggestions and renders the admin panel in the content overview', function () {
    [$user, $workspace, $site, $analyticsSite] = makeContentChainContext('content-chain-refresh');

    $series = makeContentSeries($workspace, $site, $user, 'AI governance');
    $source = makeChainedContent(
        workspace: $workspace,
        site: $site,
        title: 'AI governance overview',
        keyword: 'ai governance',
        path: '/blog/ai-governance-overview',
        series: $series,
        html: '<p>An editorial workflow checklist improves AI governance maturity and keeps the review loop predictable.</p><p>Teams also need a practical policy template to align ownership.</p>',
    );
    $inlineTarget = makeChainedContent(
        workspace: $workspace,
        site: $site,
        title: 'Editorial workflow checklist',
        keyword: 'editorial workflow checklist',
        path: '/blog/editorial-workflow-checklist',
        series: $series,
    );
    $footerTarget = makeChainedContent(
        workspace: $workspace,
        site: $site,
        title: 'AI policy template',
        keyword: 'ai policy template',
        path: '/blog/ai-policy-template',
        series: $series,
    );

    seedChainRollup($analyticsSite, $source, 220, 90);

    $this->actingAs($user)
        ->post(route('app.content.chain-guidance.update', $source), [
            'is_source_enabled' => '1',
            'preferred_angle' => 'Operational rollout',
            'goal_type' => 'comparison',
            'priority' => 'high',
            'target_keyword' => 'ai governance workflow',
            'target_audience' => 'Editorial leads',
            'target_intent' => 'commercial',
            'explicit_topic' => 'AI governance workflows for scaleups',
            'inline_link_mode' => 'review',
            'max_inline_links' => 2,
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('app.content.chain-suggestions.refresh', $source))
        ->assertRedirect();

    $source->refresh();

    expect(ContentChainGuidance::query()->where('content_id', $source->id)->exists())->toBeTrue()
        ->and(ContentChainSuggestion::query()->where('source_content_id', $source->id)->where('suggestion_kind', ContentChainSuggestion::KIND_GROWTH)->count())->toBeGreaterThan(0)
        ->and(ContentChainSuggestion::query()->where('source_content_id', $source->id)->where('suggestion_kind', ContentChainSuggestion::KIND_INLINE_LINK)->count())->toBe(1)
        ->and(ContentChainSuggestion::query()->where('source_content_id', $source->id)->where('suggestion_kind', ContentChainSuggestion::KIND_FOOTER_LINK)->count())->toBe(0);

    $growth = ContentChainSuggestion::query()
        ->where('source_content_id', $source->id)
        ->where('suggestion_kind', ContentChainSuggestion::KIND_GROWTH)
        ->firstOrFail();

    expect(Str::lower((string) $growth->title))->toContain('ai governance workflow')
        ->and((string) data_get($growth->meta, 'target_audience'))->toBe('Editorial leads');

    $this->actingAs($user)
        ->get(route('app.content.show', ['content' => $source, 'tab' => 'overview']))
        ->assertOk()
        ->assertSee('Chained Content Guidance')
        ->assertSee('Chained Opportunities')
        ->assertSee('Contextual Inline Links')
        ->assertSee('Footer Fallback Links');

    expect($inlineTarget->id)->not->toBe($footerTarget->id);
});

it('prevents duplicate suggestions on refresh and preserves manual rejection', function () {
    [$user, $workspace, $site, $analyticsSite] = makeContentChainContext('content-chain-rerun');

    $series = makeContentSeries($workspace, $site, $user, 'Editorial governance');
    $source = makeChainedContent(
        workspace: $workspace,
        site: $site,
        title: 'Editorial governance basics',
        keyword: 'editorial governance',
        path: '/blog/editorial-governance-basics',
        series: $series,
        html: '<p>An editorial workflow checklist helps governance teams standardize publishing operations.</p>',
    );
    makeChainedContent(
        workspace: $workspace,
        site: $site,
        title: 'Editorial workflow checklist',
        keyword: 'editorial workflow checklist',
        path: '/blog/editorial-workflow-checklist',
        series: $series,
    );

    seedChainRollup($analyticsSite, $source, 180, 70);

    $this->actingAs($user)
        ->post(route('app.content.chain-guidance.update', $source), [
            'priority' => 'medium',
            'inline_link_mode' => 'review',
            'max_inline_links' => 2,
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('app.content.chain-suggestions.refresh', $source))
        ->assertRedirect();

    $inlineSuggestion = ContentChainSuggestion::query()
        ->where('source_content_id', $source->id)
        ->where('suggestion_kind', ContentChainSuggestion::KIND_INLINE_LINK)
        ->firstOrFail();

    $initialCount = ContentChainSuggestion::query()
        ->where('source_content_id', $source->id)
        ->count();

    $this->actingAs($user)
        ->post(route('app.content.chain-suggestions.reject', [$source, $inlineSuggestion]))
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('app.content.chain-suggestions.refresh', $source))
        ->assertRedirect();

    expect(ContentChainSuggestion::query()->where('source_content_id', $source->id)->count())->toBe($initialCount)
        ->and(ContentChainSuggestion::query()->findOrFail($inlineSuggestion->id)->status)->toBe(ContentChainSuggestion::STATUS_REJECTED);
});

it('applies approved chained links inline without appending footer fallbacks', function () {
    Queue::fake();

    [$user, $workspace, $site, $analyticsSite] = makeContentChainContext('content-chain-apply');

    $series = makeContentSeries($workspace, $site, $user, 'Search ops');
    $source = makeChainedContent(
        workspace: $workspace,
        site: $site,
        title: 'Search operations guide',
        keyword: 'search operations',
        path: '/blog/search-operations-guide',
        series: $series,
        html: '<p>A search intent framework helps teams align content with the right audience signals.</p><p>Operational reporting still matters for follow-up analysis.</p>',
    );
    $inlineTarget = makeChainedContent(
        workspace: $workspace,
        site: $site,
        title: 'Search intent framework',
        keyword: 'search intent framework',
        path: '/blog/search-intent-framework',
        series: $series,
    );
    $footerTarget = makeChainedContent(
        workspace: $workspace,
        site: $site,
        title: 'Editorial reporting templates',
        keyword: 'editorial reporting templates',
        path: '/blog/editorial-reporting-templates',
        series: $series,
    );

    seedChainRollup($analyticsSite, $source, 210, 88);

    $this->actingAs($user)
        ->post(route('app.content.chain-guidance.update', $source), [
            'priority' => 'high',
            'inline_link_mode' => 'review',
            'max_inline_links' => 1,
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('app.content.chain-suggestions.refresh', $source))
        ->assertRedirect();

    $inlineSuggestion = ContentChainSuggestion::query()
        ->where('source_content_id', $source->id)
        ->where('suggestion_kind', ContentChainSuggestion::KIND_INLINE_LINK)
        ->firstOrFail();

    $footerSuggestion = ContentChainSuggestion::query()->create([
        'workspace_id' => $workspace->id,
        'source_content_id' => $source->id,
        'target_content_id' => $footerTarget->id,
        'fingerprint' => sha1((string) Str::uuid()),
        'suggestion_kind' => ContentChainSuggestion::KIND_FOOTER_LINK,
        'suggestion_type' => 'supplementary_footer',
        'title' => $footerTarget->title,
        'anchor_text' => $footerTarget->primary_keyword,
        'placement_type' => 'footer',
        'placement_label' => 'Additional reading',
        'rationale' => 'Legacy footer fallback that should no longer be applied.',
        'score' => 55,
        'confidence_score' => 0.55,
        'meta' => ['target_url' => $footerTarget->published_url],
        'status' => ContentChainSuggestion::STATUS_APPROVED,
    ]);

    $this->actingAs($user)->post(route('app.content.chain-suggestions.approve', [$source, $inlineSuggestion]))->assertRedirect();

    $this->actingAs($user)
        ->post(route('app.content.chain-suggestions.apply-approved-links', $source))
        ->assertRedirect();

    $source->refresh()->load('currentRevision', 'currentVersion');
    $html = (string) $source->currentRevision?->content_html;

    expect($html)->toContain('<a href="' . $inlineTarget->published_url . '">search intent framework</a>')
        ->and($html)->not->toContain('data-content-chain-links="1"')
        ->and($html)->not->toContain((string) $footerTarget->published_url)
        ->and(substr_count($html, (string) $inlineTarget->published_url))->toBe(1)
        ->and(ContentChainSuggestion::query()->findOrFail($inlineSuggestion->id)->applied_at)->not->toBeNull()
        ->and(ContentChainSuggestion::query()->findOrFail($footerSuggestion->id)->applied_at)->toBeNull();

    Queue::assertPushed(RebuildContentMarkdownArtifactJob::class);
});

it('does not suggest chained links to another locale variant of the same article', function () {
    [$user, $workspace, $site, $analyticsSite] = makeContentChainContext('content-chain-locale-family');

    $series = makeContentSeries($workspace, $site, $user, 'Localization workflow');
    $source = makeChainedContent(
        workspace: $workspace,
        site: $site,
        title: 'Localization workflow overview',
        keyword: 'localization workflow',
        path: '/nl/blog/localization-workflow-overview',
        series: $series,
        html: '<p>Een localization workflow checklist voorkomt dat vertaalde artikelen naar zichzelf verwijzen.</p>',
        language: 'nl',
    );
    $sameFamilyTarget = makeChainedContent(
        workspace: $workspace,
        site: $site,
        title: 'Localization workflow checklist',
        keyword: 'localization workflow checklist',
        path: '/en/blog/localization-workflow-checklist',
        series: $series,
        language: 'en',
        attributes: [
            'family_id' => $source->id,
            'translation_source_content_id' => $source->id,
            'is_source_locale' => false,
        ],
    );
    $validTarget = makeChainedContent(
        workspace: $workspace,
        site: $site,
        title: 'Localization workflow checklist',
        keyword: 'localization workflow checklist',
        path: '/nl/blog/localization-workflow-checklist',
        series: $series,
        language: 'nl',
    );

    seedChainRollup($analyticsSite, $source, 180, 95);

    $this->actingAs($user)
        ->post(route('app.content.chain-guidance.update', $source), [
            'priority' => 'high',
            'inline_link_mode' => 'review',
            'max_inline_links' => 2,
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('app.content.chain-suggestions.refresh', $source))
        ->assertRedirect();

    $targetIds = ContentChainSuggestion::query()
        ->where('source_content_id', $source->id)
        ->where('suggestion_kind', ContentChainSuggestion::KIND_INLINE_LINK)
        ->pluck('target_content_id')
        ->map(fn ($id): string => (string) $id)
        ->all();

    expect($targetIds)->toContain((string) $validTarget->id)
        ->and($targetIds)->not->toContain((string) $sameFamilyTarget->id);
});

it('creates new chained content from an approved growth suggestion', function () {
    [$user, $workspace, $site, $analyticsSite] = makeContentChainContext('content-chain-create');

    $series = makeContentSeries($workspace, $site, $user, 'Automation strategy');
    $source = makeChainedContent(
        workspace: $workspace,
        site: $site,
        title: 'Automation strategy guide',
        keyword: 'automation strategy',
        path: '/blog/automation-strategy-guide',
        series: $series,
        html: '<p>Automation strategy needs a clear decision model for the first workflow.</p>',
    );

    seedChainRollup($analyticsSite, $source, 320, 140);

    $this->actingAs($user)
        ->post(route('app.content.chain-guidance.update', $source), [
            'is_source_enabled' => '1',
            'preferred_angle' => 'Buyer enablement',
            'goal_type' => 'comparison',
            'priority' => 'critical',
            'target_keyword' => 'automation prioritization matrix',
            'target_audience' => 'Operations leaders',
            'target_intent' => 'informational',
            'explicit_topic' => 'Automation prioritization matrix',
            'inline_link_mode' => 'off',
            'max_inline_links' => 2,
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('app.content.chain-suggestions.refresh', $source))
        ->assertRedirect();

    $growthSuggestion = ContentChainSuggestion::query()
        ->where('source_content_id', $source->id)
        ->where('suggestion_kind', ContentChainSuggestion::KIND_GROWTH)
        ->firstOrFail();

    $response = $this->actingAs($user)
        ->post(route('app.content.chain-suggestions.create', [$source, $growthSuggestion]));

    $response->assertRedirect();

    $growthSuggestion->refresh();
    $created = Content::query()->findOrFail($growthSuggestion->generated_content_id);

    expect($growthSuggestion->status)->toBe(ContentChainSuggestion::STATUS_CONVERTED)
        ->and((string) $created->series_id)->toBe((string) $source->series_id)
        ->and((string) $created->getRawOriginal('source'))->toBe('automation')
        ->and(Str::lower((string) $created->title))->toContain('automation prioritization matrix')
        ->and($created->brief)->not->toBeNull()
        ->and(data_get($created->brief->client_refs, 'chain_source_content_id'))->toBe((string) $source->id);
});

function makeContentChainContext(string $prefix): array
{
    $organization = Organization::query()->create([
        'name' => 'Content Chain Org',
        'slug' => $prefix . '-' . Str::lower(Str::random(6)),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Content Chain BV',
        'billing_address_line1' => 'Ketenstraat 7',
        'billing_country_code' => 'NL',
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Content Chain Workspace',
        'organization_id' => $organization->id,
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Content Chain Site',
        'site_url' => 'https://content-chain.example.com',
        'base_url' => 'https://content-chain.example.com',
        'allowed_domains' => ['content-chain.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ]);

    $analyticsSite = AnalyticsSite::query()->create([
        'client_site_id' => $site->id,
        'allowed_domains' => ['content-chain.example.com'],
        'is_enabled' => true,
        'verified_at' => now(),
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => $prefix . '-plan'],
        [
            'name' => 'Content Chain Plan',
            'slug' => $prefix . '-plan',
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

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
    ]);

    OnboardingState::query()->create([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'phase' => OnboardingState::PHASE_ACTIVATED,
        'registered_at' => now()->subDays(2),
        'completed_steps_json' => ['intent', 'company_profile', 'connect_site'],
    ]);

    WorkspaceEntitlement::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'organization_id' => $organization->id,
        'feature_key' => 'content_network_analysis_enabled',
        'value_type' => 'bool',
        'value_bool' => true,
        'source' => 'manual',
        'effective_at' => now()->subMinute(),
        'refreshed_at' => now(),
    ]);

    return [$user, $workspace, $site, $analyticsSite];
}

function makeContentSeries(Workspace $workspace, ClientSite $site, User $user, string $topic): ContentSeries
{
    return ContentSeries::query()->create([
        'organization_id' => $workspace->organization_id,
        'site_id' => $site->id,
        'name' => $topic . ' Series',
        'main_topic' => $topic,
        'primary_keyword' => Str::lower($topic),
        'status' => ContentSeries::STATUS_READY,
        'articles_count' => 0,
        'created_by' => $user->id,
    ]);
}

function makeChainedContent(
    Workspace $workspace,
    ClientSite $site,
    string $title,
    string $keyword,
    string $path,
    ?ContentSeries $series = null,
    string $html = '<p>Default chained content body.</p>',
    string $language = 'en',
    array $attributes = [],
): Content {
    $content = Content::query()->create(array_merge([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'series_id' => $series?->id,
        'title' => $title,
        'primary_keyword' => $keyword,
        'language' => $language,
        'is_source_locale' => true,
        'type' => 'article',
        'status' => 'published',
        'source' => 'manual',
        'publish_status' => 'published',
        'published_url' => rtrim((string) $site->base_url, '/') . $path,
        'external_key' => (string) Str::uuid(),
        'generation_mode' => 'balanced',
        'preferred_length' => 'medium',
    ], $attributes));

    $version = ContentVersion::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'type' => ContentVersion::TYPE_DRAFT,
        'body' => $html,
        'source' => ContentVersion::SOURCE_ARGUSLY,
    ]);

    $revision = ContentRevision::query()->create([
        'id' => (string) Str::uuid(),
        'content_id' => $content->id,
        'revision_number' => 1,
        'label' => 'R1',
        'content_html' => $html,
        'is_active' => true,
    ]);

    $content->update([
        'current_version_id' => $version->id,
        'current_revision_id' => $revision->id,
    ]);

    return $content->fresh(['currentVersion', 'currentRevision', 'brief']);
}

function seedChainRollup(AnalyticsSite $analyticsSite, Content $content, int $pageViews, int $engagedViews): void
{
    $path = parse_url((string) $content->published_url, PHP_URL_PATH) ?: '/';

    DB::table('analytics_rollups_daily')->insert([
        'analytics_site_id' => $analyticsSite->id,
        'date' => now()->toDateString(),
        'path' => $path,
        'path_hash' => hash('sha256', $path),
        'article_id' => $content->id,
        'title' => $content->title,
        'page_views' => $pageViews,
        'unique_visitors' => max(1, $pageViews - 20),
        'scroll_50' => max(1, intdiv($pageViews, 2)),
        'scroll_100' => max(1, intdiv($engagedViews, 2)),
        'heartbeats' => $engagedViews * 2,
        'engaged_views' => $engagedViews,
        'total_time_seconds' => $engagedViews * 45,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
