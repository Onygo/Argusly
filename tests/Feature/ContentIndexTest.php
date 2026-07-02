<?php

use App\Enums\ContentOriginType;
use App\Enums\SupportedLanguage;
use App\Models\AgentRun;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentAutomation;
use App\Models\ContentImprovementRun;
use App\Models\ContentPublication;
use App\Models\ContentSeries;
use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
});

function createTestUser(): User
{
    $organization = Organization::create([
        'name' => 'Test Org',
        'slug' => 'test-org-' . uniqid(),
        'status' => 'active',
    ]);

    $user = User::create([
        'name' => 'Test User',
        'email' => 'test-' . uniqid() . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'email_verified_at' => now(),
        'approved_at' => now(),
    ]);

    return $user;
}

function createTestWorkspaceAndSite(Organization $organization): array
{
    $workspace = Workspace::create([
        'name' => 'Test Workspace',
        'organization_id' => $organization->id,
    ]);

    $siteUrl = 'https://test-' . uniqid() . '.example.com';

    $site = ClientSite::create([
        'workspace_id' => $workspace->id,
        'type' => 'wordpress',
        'name' => 'Test Site',
        'site_url' => $siteUrl,
        'allowed_domains' => [parse_url($siteUrl, PHP_URL_HOST)],
        'is_active' => true,
    ]);

    return [$workspace, $site];
}

function createTestContent(ClientSite $site, array $overrides = []): Content
{
    $timestamps = array_intersect_key($overrides, array_flip(['created_at', 'updated_at']));
    $overrides = array_diff_key($overrides, $timestamps);

    $content = Content::create(array_merge([
        'workspace_id' => $site->workspace_id,
        'client_site_id' => $site->id,
        'title' => 'Test Content ' . uniqid(),
        'language' => 'en',
        'type' => 'article',
        'status' => 'draft',
        'source' => 'manual',
        'origin_type' => ContentOriginType::MANUAL->value,
    ], $overrides));

    if ($timestamps !== []) {
        $content->forceFill($timestamps)->saveQuietly();
    }

    return $content;
}

describe('Content index sorting', function () {
    it('renders a paginated overview with many content records without excessive queries', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        foreach (range(1, 75) as $index) {
            createTestContent($site, [
                'title' => 'Bulk Content ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($user)->get(route('app.content.index'));

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertStatus(200)
            ->assertSee('Bulk Content 001')
            ->assertDontSee('Bulk Content 075');

        expect($queryCount)->toBeLessThan(80);
    });

    it('sorts by newest created by default', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        $oldContent = createTestContent($site, ['title' => 'Old Content', 'created_at' => now()->subDays(5)]);
        $newContent = createTestContent($site, ['title' => 'New Content', 'created_at' => now()]);

        $response = $this->actingAs($user)->get(route('app.content.index'));

        $response->assertStatus(200);
        $response->assertSeeInOrder(['New Content', 'Old Content']);
    });

    it('sorts by oldest created when requested', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        $oldContent = createTestContent($site, ['title' => 'Old Content', 'created_at' => now()->subDays(5)]);
        $newContent = createTestContent($site, ['title' => 'New Content', 'created_at' => now()]);

        $response = $this->actingAs($user)->get(route('app.content.index', ['sort' => 'oldest_created']));

        $response->assertStatus(200);
        $response->assertSeeInOrder(['Old Content', 'New Content']);
    });

    it('sorts by newest published when requested', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        $oldPublished = createTestContent($site, [
            'title' => 'Old Published',
            'first_published_at' => now()->subDays(10),
        ]);
        $newPublished = createTestContent($site, [
            'title' => 'New Published',
            'first_published_at' => now()->subDays(1),
        ]);
        $unpublished = createTestContent($site, [
            'title' => 'Unpublished',
            'first_published_at' => null,
        ]);

        $response = $this->actingAs($user)->get(route('app.content.index', ['sort' => 'newest_published']));

        $response->assertStatus(200);
        $response->assertSeeInOrder(['New Published', 'Old Published']);
    });

    it('sorts by title ascending when requested', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        createTestContent($site, ['title' => 'Zebra Article']);
        createTestContent($site, ['title' => 'Apple Article']);
        createTestContent($site, ['title' => 'Mango Article']);

        $response = $this->actingAs($user)->get(route('app.content.index', ['sort' => 'title_asc']));

        $response->assertStatus(200);
        $response->assertSeeInOrder(['Apple Article', 'Mango Article', 'Zebra Article']);
    });
});

describe('Content index filtering by lifecycle status', function () {
    it('draft chip excludes content that is already published', function () {
        $user = createTestUser();
        [, $site] = createTestWorkspaceAndSite($user->organization);

        createTestContent($site, [
            'title' => 'Visible draft article',
            'status' => 'draft',
            'publish_status' => 'draft',
        ]);

        createTestContent($site, [
            'title' => 'Published article with stale draft status',
            'status' => 'draft',
            'publish_status' => 'published',
            'first_published_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('app.content.index', ['status' => 'draft']));

        $response->assertOk()
            ->assertSee('Visible draft article')
            ->assertDontSee('Published article with stale draft status');
    });
});

describe('Content index filtering by origin', function () {
    it('filters by manual origin type', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        $manual = createTestContent($site, ['title' => 'Manual Content', 'origin_type' => ContentOriginType::MANUAL->value]);
        $auto = createTestContent($site, ['title' => 'Automation Content', 'origin_type' => ContentOriginType::AUTOMATION->value]);

        $response = $this->actingAs($user)->get(route('app.content.index', ['origin' => 'manual']));

        $response->assertStatus(200);
        $response->assertSee('Manual Content');
        $response->assertDontSee('Automation Content');
    });

    it('filters by automation origin type', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        $manual = createTestContent($site, ['title' => 'Manual Content', 'origin_type' => ContentOriginType::MANUAL->value]);
        $auto = createTestContent($site, ['title' => 'Automation Content', 'origin_type' => ContentOriginType::AUTOMATION->value]);

        $response = $this->actingAs($user)->get(route('app.content.index', ['origin' => 'automation']));

        $response->assertStatus(200);
        $response->assertSee('Automation Content');
        $response->assertDontSee('Manual Content');
    });
});

describe('Content index filtering by series', function () {
    it('filters by content series', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        $series = ContentSeries::create([
            'organization_id' => $user->organization_id,
            'site_id' => $site->id,
            'name' => 'Test Series',
            'main_topic' => 'Testing',
            'primary_keyword' => 'testing',
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        $seriesContent = createTestContent($site, [
            'title' => 'Series Content',
            'series_id' => $series->id,
            'origin_type' => ContentOriginType::SERIES_GENERATED->value,
        ]);
        $standaloneContent = createTestContent($site, [
            'title' => 'Standalone Content',
            'series_id' => null,
        ]);

        $response = $this->actingAs($user)->get(route('app.content.index', ['series' => $series->id]));

        $response->assertStatus(200);
        $response->assertSee('Series Content');
        $response->assertDontSee('Standalone Content');
    });
});

describe('Content index filtering by automation', function () {
    it('filters by automation', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        $automation = ContentAutomation::create([
            'organization_id' => $user->organization_id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'name' => 'Test Automation',
            'topic_scope' => 'Testing topics',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $autoContent = createTestContent($site, [
            'title' => 'Automated Content',
            'automation_id' => $automation->id,
            'origin_type' => ContentOriginType::AUTOMATION->value,
        ]);
        $manualContent = createTestContent($site, [
            'title' => 'Manual Content',
            'automation_id' => null,
            'origin_type' => ContentOriginType::MANUAL->value,
        ]);

        $response = $this->actingAs($user)->get(route('app.content.index', ['automation' => $automation->id]));

        $response->assertStatus(200);
        $response->assertSee('Automated Content');
        $response->assertDontSee('Manual Content');
    });
});

describe('Content index date range filtering', function () {
    it('filters by created date range', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        $recentContent = createTestContent($site, [
            'title' => 'Recent Content',
            'created_at' => now()->subDays(2),
        ]);
        $oldContent = createTestContent($site, [
            'title' => 'Old Content',
            'created_at' => now()->subDays(30),
        ]);

        $response = $this->actingAs($user)->get(route('app.content.index', [
            'created_from' => now()->subDays(7)->format('Y-m-d'),
            'created_to' => now()->format('Y-m-d'),
        ]));

        $response->assertStatus(200);
        $response->assertSee('Recent Content');
        $response->assertDontSee('Old Content');
    });

    it('filters by published date range', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        $recentlyPublished = createTestContent($site, [
            'title' => 'Recently Published',
            'first_published_at' => now()->subDays(2),
        ]);
        $oldPublished = createTestContent($site, [
            'title' => 'Old Published',
            'first_published_at' => now()->subDays(30),
        ]);
        $unpublished = createTestContent($site, [
            'title' => 'Unpublished',
            'first_published_at' => null,
        ]);

        $response = $this->actingAs($user)->get(route('app.content.index', [
            'published_from' => now()->subDays(7)->format('Y-m-d'),
            'published_to' => now()->format('Y-m-d'),
        ]));

        $response->assertStatus(200);
        $response->assertSee('Recently Published');
        $response->assertDontSee('Old Published');
    });
});

describe('Content index combined filters', function () {
    it('combines multiple filters correctly', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        $automation = ContentAutomation::create([
            'organization_id' => $user->organization_id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'name' => 'Test Automation',
            'topic_scope' => 'Testing topics',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $matchingContent = createTestContent($site, [
            'title' => 'Matching Content',
            'automation_id' => $automation->id,
            'origin_type' => ContentOriginType::AUTOMATION->value,
            'created_at' => now()->subDays(2),
        ]);
        $wrongOrigin = createTestContent($site, [
            'title' => 'Wrong Origin',
            'origin_type' => ContentOriginType::MANUAL->value,
            'created_at' => now()->subDays(2),
        ]);
        $wrongDate = createTestContent($site, [
            'title' => 'Wrong Date',
            'automation_id' => $automation->id,
            'origin_type' => ContentOriginType::AUTOMATION->value,
            'created_at' => now()->subDays(30),
        ]);

        $response = $this->actingAs($user)->get(route('app.content.index', [
            'automation' => $automation->id,
            'origin' => 'automation',
            'created_from' => now()->subDays(7)->format('Y-m-d'),
        ]));

        $response->assertStatus(200);
        $response->assertSee('Matching Content');
        $response->assertDontSee('Wrong Origin');
        $response->assertDontSee('Wrong Date');
    });

    it('preserves filters in pagination links', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        // Create enough content for pagination
        for ($i = 0; $i < 25; $i++) {
            createTestContent($site, [
                'title' => "Content $i",
                'origin_type' => ContentOriginType::MANUAL->value,
            ]);
        }

        $response = $this->actingAs($user)->get(route('app.content.index', [
            'origin' => 'manual',
            'sort' => 'title_asc',
        ]));

        $response->assertStatus(200);
        // The page should contain the filter parameters in the pagination links
        $response->assertSee('origin=manual');
        $response->assertSee('sort=title_asc');
    });
});

describe('Content index empty states', function () {
    it('renders the default empty state when no content exists', function () {
        $user = createTestUser();
        [, $site] = createTestWorkspaceAndSite($user->organization);

        $response = $this->actingAs($user)->get(route('app.content.index'));

        $response->assertOk()
            ->assertSee('No content yet')
            ->assertSee('Create your first content')
            ->assertSee('Connect a website');
    });

    it('renders the filtered empty state when filters are active and no rows match', function () {
        $user = createTestUser();
        [, $site] = createTestWorkspaceAndSite($user->organization);

        createTestContent($site, ['title' => 'Existing article']);

        $response = $this->actingAs($user)->get(route('app.content.index', [
            'q' => 'no-match-term',
            'status' => 'draft',
        ]));

        $response->assertOk()
            ->assertSee('No content matches your filters')
            ->assertSee('Try adjusting your search or filter criteria.')
            ->assertSee('Clear filters');
    });

    it('renders the filtered view with localization and automation metadata without error', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        $automation = ContentAutomation::create([
            'organization_id' => $user->organization_id,
            'workspace_id' => $workspace->id,
            'client_site_id' => $site->id,
            'name' => 'Regression Automation',
            'topic_scope' => 'Regression topics',
            'is_active' => true,
            'created_by' => $user->id,
        ]);

        $series = ContentSeries::create([
            'organization_id' => $user->organization_id,
            'site_id' => $site->id,
            'name' => 'Regression Series',
            'main_topic' => 'Regression',
            'primary_keyword' => 'regression',
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        $source = createTestContent($site, [
            'title' => 'Source article',
            'language' => 'en',
            'status' => 'published',
            'publish_status' => 'published',
            'automation_id' => $automation->id,
            'series_id' => $series->id,
        ]);

        createTestContent($site, [
            'title' => 'Dutch translation',
            'language' => 'nl',
            'family_id' => $source->id,
            'translation_source_content_id' => $source->id,
            'translation_source_locale' => 'en',
            'is_source_locale' => false,
            'status' => 'draft',
            'publish_status' => 'draft',
            'automation_id' => $automation->id,
            'series_id' => $series->id,
        ]);

        $response = $this->actingAs($user)->get(route('app.content.index', [
            'automation' => $automation->id,
            'locale' => 'nl',
        ]));

        $response->assertOk()
            ->assertSee('Source article')
            ->assertSee('Dutch translation')
            ->assertSee('Regression Automation')
            ->assertSee('Regression Series');
    });

    it('filters partially published content and combines locale coverage filters', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        $workspace->update([
            'default_content_language' => SupportedLanguage::EN->value,
            'enabled_content_languages' => [SupportedLanguage::EN->value, SupportedLanguage::NL->value],
        ]);

        $partial = createTestContent($site, [
            'title' => 'Partial rollout article',
            'language' => SupportedLanguage::EN->value,
            'status' => 'published',
            'publish_status' => 'published',
        ]);

        ContentPublication::query()->create([
            'content_id' => $partial->id,
            'client_site_id' => $site->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
            'remote_id' => 'partial-1',
            'remote_url' => 'https://example.test/partial',
        ]);

        $full = createTestContent($site, [
            'title' => 'Fully published article',
            'language' => SupportedLanguage::EN->value,
            'status' => 'published',
            'publish_status' => 'published',
        ]);

        ContentPublication::query()->create([
            'content_id' => $full->id,
            'client_site_id' => $site->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
            'remote_id' => 'full-en',
            'remote_url' => 'https://example.test/full-en',
        ]);

        $fullNl = createTestContent($site, [
            'title' => 'Fully published article',
            'language' => SupportedLanguage::NL->value,
            'family_id' => $full->id,
            'translation_source_content_id' => $full->id,
            'translation_source_locale' => SupportedLanguage::EN->value,
            'is_source_locale' => false,
            'status' => 'published',
            'publish_status' => 'published',
        ]);

        ContentPublication::query()->create([
            'content_id' => $fullNl->id,
            'client_site_id' => $site->id,
            'provider' => ContentPublication::PROVIDER_WORDPRESS,
            'delivery_status' => ContentPublication::STATUS_DELIVERED,
            'remote_id' => 'full-nl',
            'remote_url' => 'https://example.test/full-nl',
        ]);

        $response = $this->actingAs($user)->get(route('app.content.index', [
            'publication_state' => 'partially_published',
            'locale_scope' => 'missing_nl',
        ]));

        $response->assertOk()
            ->assertSee('Partial rollout article')
            ->assertDontSee('Fully published article')
            ->assertSee('Partially published')
            ->assertSee('Missing locale');
    });

    it('filters content with generated ai improvements', function () {
        $user = createTestUser();
        [, $site] = createTestWorkspaceAndSite($user->organization);

        $generated = createTestContent($site, ['title' => 'Generated improvements article']);
        $pending = createTestContent($site, ['title' => 'Pending improvements article']);

        ContentImprovementRun::query()->create([
            'content_id' => $generated->id,
            'organization_id' => $user->organization_id,
            'type' => 'improve_scanability',
            'status' => ContentImprovementRun::STATUS_COMPLETED,
            'recommendation_label' => 'Improve scanability',
            'completed_at' => now(),
            'result_payload' => ['content_html' => '<p>Updated</p>'],
        ]);

        ContentImprovementRun::query()->create([
            'content_id' => $pending->id,
            'organization_id' => $user->organization_id,
            'type' => 'improve_scanability',
            'status' => ContentImprovementRun::STATUS_RUNNING,
            'recommendation_label' => 'Improve scanability',
        ]);

        $response = $this->actingAs($user)->get(route('app.content.index', [
            'workflow_state' => 'ai_improvements_generated',
        ]));

        $response->assertOk()
            ->assertSee('Generated improvements article')
            ->assertDontSee('Pending improvements article');
    });

    it('preserves advanced filters in pagination links', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        $workspace->update([
            'default_content_language' => SupportedLanguage::EN->value,
            'enabled_content_languages' => [SupportedLanguage::EN->value, SupportedLanguage::NL->value],
        ]);

        foreach (range(1, 21) as $index) {
            $content = createTestContent($site, [
                'title' => 'Partial article ' . $index,
                'language' => SupportedLanguage::EN->value,
                'status' => 'published',
                'publish_status' => 'published',
            ]);

            ContentPublication::query()->create([
                'content_id' => $content->id,
                'client_site_id' => $site->id,
                'provider' => ContentPublication::PROVIDER_WORDPRESS,
                'delivery_status' => ContentPublication::STATUS_DELIVERED,
                'remote_id' => 'partial-' . $index,
                'remote_url' => 'https://example.test/partial-' . $index,
            ]);
        }

        $response = $this->actingAs($user)->get(route('app.content.index', [
            'publication_state' => 'partially_published',
            'locale_scope' => 'missing_nl',
        ]));

        $response->assertOk()
            ->assertSee('publication_state=partially_published', false)
            ->assertSee('locale_scope=missing_nl', false);
    });

    it('keeps overview listing queries free of heavy content payload columns', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        foreach (range(1, 25) as $index) {
            createTestContent($site, [
                'title' => 'Overview item '.$index,
                'publish_status' => $index % 2 === 0 ? 'published' : 'draft',
                'status' => $index % 2 === 0 ? 'published' : 'draft',
            ]);
        }

        DB::enableQueryLog();

        $response = $this->actingAs($user)->get(route('app.content.index'));

        $response->assertOk();

        $contentQueries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'from "contents"') || str_contains(strtolower($sql), 'from `contents`'));

        expect($contentQueries->isNotEmpty())->toBeTrue();

        foreach (['content_html', 'body_html', 'markdown', 'raw_ai_response'] as $forbiddenColumn) {
            expect($contentQueries->contains(fn (string $sql): bool => str_contains(strtolower($sql), strtolower($forbiddenColumn))))->toBeFalse();
        }
    });

    it('does not load every card in a family graph just to render one overview page', function () {
        $user = createTestUser();
        [$workspace, $site] = createTestWorkspaceAndSite($user->organization);

        foreach (range(1, 25) as $index) {
            createTestContent($site, [
                'title' => 'Paginated item '.$index,
                'status' => 'draft',
            ]);
        }

        $response = $this->actingAs($user)->get(route('app.content.index'));

        $response->assertOk();
        $contents = $response->viewData('contents');
        expect($contents->count())->toBe(20)
            ->and($contents->hasMorePages())->toBeTrue();
    });
});
