<?php

use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentSeries;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Content\ContentSeriesArticleSyncService;
use App\Services\Content\SeriesStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function makeSeriesPillarContext(string $prefix = 'Series Pillar'): array
{
    $organization = Organization::query()->create([
        'name' => $prefix . ' Org',
        'slug' => Str::slug($prefix) . '-org-' . Str::random(6),
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => $prefix . ' BV',
        'billing_address_line1' => 'Teststraat 123',
        'billing_country_code' => 'NL',
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

    $plan = Plan::query()->create([
        'id' => (string) Str::uuid(),
        'key' => Str::slug($prefix) . '-plan-' . Str::random(6),
        'name' => $prefix . ' Plan',
        'interval' => 'month',
        'monthly_price_cents' => 4900,
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits' => 100,
        'included_credits_per_interval' => 100,
        'seat_limit' => 5,
        'limits' => ['users' => 5],
        'is_active' => true,
    ]);

    $subscription = Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'plan_id' => $plan->id,
        'interval' => 'month',
        'price_cents' => 4900,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 5,
        'status' => 'active',
        'current_period_start' => now()->subDay(),
        'current_period_end' => now()->addMonth(),
    ]);

    $organization->update(['active_subscription_id' => $subscription->id]);

    $owner = User::query()->create([
        'name' => $prefix . ' Owner',
        'email' => Str::slug($prefix) . '-owner-' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    $viewer = User::query()->create([
        'name' => $prefix . ' Viewer',
        'email' => Str::slug($prefix) . '-viewer-' . Str::random(6) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'viewer',
        'approved_at' => now(),
        'active' => true,
    ]);

    $series = ContentSeries::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'site_id' => $site->id,
        'name' => $prefix . ' Series',
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance workflow',
        'supporting_keywords' => ['policy controls', 'workflow checklist'],
        'articles_count' => 3,
        'status' => ContentSeries::STATUS_STRATEGY_GENERATED,
        'strategy_json' => [
            'angle' => 'Connected governance cluster.',
            'articles' => [
                ['article_number' => 1, 'title' => 'Foundations', 'primary_keyword' => 'ai governance foundations', 'secondary_keywords' => ['policy controls'], 'internal_links_to' => [2, 3]],
                ['article_number' => 2, 'title' => 'Checklist', 'primary_keyword' => 'governance checklist', 'secondary_keywords' => ['workflow checklist'], 'internal_links_to' => [1]],
                ['article_number' => 3, 'title' => 'FAQ', 'primary_keyword' => 'governance faq', 'secondary_keywords' => ['governance questions'], 'internal_links_to' => [1]],
            ],
        ],
        'created_by' => $owner->id,
    ]);

    app(ContentSeriesArticleSyncService::class)->sync($series);

    return [$owner, $viewer, $workspace, $site, $series];
}

it('marks and switches the pillar article while keeping only one pillar', function () {
    [$owner, , , , $series] = makeSeriesPillarContext();

    $this->actingAs($owner)
        ->post(route('app.content.series.pillar.set', $series), ['article_number' => 2])
        ->assertRedirect();

    $series->refresh()->load('seriesArticles');

    expect($series->hasPillarArticle())->toBeTrue()
        ->and((int) $series->getPillarArticle()?->article_number)->toBe(2)
        ->and($series->getSupportingArticles()->pluck('article_number')->all())->toBe([1, 3])
        ->and($series->seriesArticles->where('is_pillar', true)->count())->toBe(1)
        ->and((bool) data_get($series->strategy_json, 'articles.1.is_pillar'))->toBeTrue();

    $this->actingAs($owner)
        ->post(route('app.content.series.pillar.set', $series), ['article_number' => 3])
        ->assertRedirect();

    $series->refresh()->load('seriesArticles');

    expect((int) $series->getPillarArticle()?->article_number)->toBe(3)
        ->and($series->seriesArticles->where('is_pillar', true)->count())->toBe(1)
        ->and((bool) data_get($series->strategy_json, 'articles.1.is_pillar'))->toBeFalse()
        ->and((bool) data_get($series->strategy_json, 'articles.2.is_pillar'))->toBeTrue();
});

it('removes pillar designation when the current pillar content is deleted', function () {
    [$owner, , $workspace, $site, $series] = makeSeriesPillarContext('Series Pillar Delete');

    $content = Content::query()->create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'series_id' => $series->id,
        'title' => 'Foundations',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'external_key' => 'series-' . $series->id . '-article-1',
        'primary_keyword' => 'ai governance foundations',
    ]);

    $this->actingAs($owner)
        ->post(route('app.content.series.pillar.set', $series), ['article_number' => 1])
        ->assertRedirect();

    expect($series->fresh()->hasPillarArticle())->toBeTrue();

    $content->delete();

    $series->refresh()->load('seriesArticles');
    $row = $series->seriesArticles->firstWhere('article_number', 1);

    expect($series->hasPillarArticle())->toBeFalse()
        ->and($row)->not->toBeNull()
        ->and($row?->content_id)->toBeNull()
        ->and($row?->is_pillar)->toBeFalse()
        ->and((bool) data_get($series->strategy_json, 'articles.0.is_pillar'))->toBeFalse();
});

it('forbids pillar actions for users without update permission', function () {
    [, $viewer, , , $series] = makeSeriesPillarContext('Series Pillar Auth');

    $this->actingAs($viewer)
        ->post(route('app.content.series.pillar.set', $series), ['article_number' => 2])
        ->assertForbidden();
});

it('suggests the broadest article as pillar and exposes it on the structure screen', function () {
    [$owner, , , , $series] = makeSeriesPillarContext('Series Structure');

    $series->update([
        'main_topic' => 'AI governance',
        'primary_keyword' => 'ai governance',
        'strategy_json' => [
            'angle' => 'Connected governance cluster.',
            'articles' => [
                ['article_number' => 1, 'title' => 'AI governance fundamentals', 'primary_keyword' => 'ai governance fundamentals', 'secondary_keywords' => [], 'internal_links_to' => [2, 3]],
                ['article_number' => 2, 'title' => 'AI governance checklist for marketing teams', 'primary_keyword' => 'ai governance checklist', 'secondary_keywords' => [], 'internal_links_to' => [1]],
                ['article_number' => 3, 'title' => 'AI governance FAQ', 'primary_keyword' => 'ai governance faq', 'secondary_keywords' => [], 'internal_links_to' => [1]],
            ],
        ],
    ]);

    app(ContentSeriesArticleSyncService::class)->sync($series->fresh());

    expect(app(SeriesStructureService::class)->suggestPillarArticleNumber($series->fresh()))->toBe(1);

    $response = $this->actingAs($owner)
        ->get(route('app.content.series.structure', $series));

    $response
        ->assertOk()
        ->assertSee('Step 3: Structure')
        ->assertSee('Suggested pillar')
        ->assertSee('AI governance fundamentals')
        ->assertSee('Supporting articles');
});
