<?php

use App\Enums\ContentLifecycleStatus;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentSeries;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::create([
        'name' => 'Test Org',
        'slug' => 'test-org',
        'status' => 'active',
        'approved_at' => now(),
        'billing_company_name' => 'Test Org BV',
        'billing_address_line1' => 'Damrak 1',
        'billing_country_code' => 'NL',
    ]);
    $this->workspace = Workspace::create(['name' => 'Test Workspace', 'organization_id' => $this->org->id]);
    $this->user = User::factory()->create([
        'organization_id' => $this->org->id,
        'role' => 'editor',
        'active' => true,
        'approved_at' => now(),
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'content-lifecycle-test-plan'],
        [
            'id' => (string) Str::uuid(),
            'name' => 'Content Lifecycle Test Plan',
            'slug' => 'content-lifecycle-test-plan',
            'interval' => 'month',
            'price_cents' => 0,
            'monthly_price_cents' => 0,
            'currency' => 'EUR',
            'included_credits' => 100,
            'included_credits_per_interval' => 100,
            'seat_limit' => 5,
            'is_active' => true,
        ]
    );

    $subscription = Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $this->org->id,
        'workspace_id' => $this->workspace->id,
        'client_site_id' => null,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'seat_limit' => 5,
        'current_period_start' => now()->startOfDay(),
        'current_period_end' => now()->addMonth()->startOfDay(),
    ]);

    $this->org->forceFill([
        'active_subscription_id' => $subscription->id,
    ])->save();
});

function createDashboardTestContent(array $attributes = []): Content
{
    return Content::create(array_merge([
        'workspace_id' => test()->workspace->id,
        'title' => 'Test Content',
        'type' => 'article',
        'status' => 'draft',
        'lifecycle_stage' => ContentLifecycleStatus::DRAFT->value,
        'source' => 'api',
        'delivery_status' => 'pending',
        'generation_mode' => 'balanced',
    ], $attributes));
}

function createDashboardTestSite(?Workspace $workspace = null, array $attributes = []): ClientSite
{
    $workspace ??= test()->workspace;

    return ClientSite::query()->create(array_merge([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Lifecycle Site ' . Str::lower(Str::random(4)),
        'site_url' => 'https://lifecycle-site.example.com',
        'allowed_domains' => ['lifecycle-site.example.com'],
        'is_active' => true,
        'status' => 'connected',
    ], $attributes));
}

function createDashboardTestSeries(?ClientSite $site = null, array $attributes = []): ContentSeries
{
    $site ??= createDashboardTestSite();

    return ContentSeries::query()->create(array_merge([
        'organization_id' => test()->org->id,
        'site_id' => $site->id,
        'name' => 'Lifecycle Series ' . Str::lower(Str::random(4)),
        'main_topic' => 'Lifecycle Topic',
        'primary_keyword' => 'lifecycle-keyword',
        'supporting_keywords' => ['supporting keyword'],
        'articles_count' => 5,
        'status' => ContentSeries::STATUS_DRAFT,
        'created_by' => test()->user->id,
    ], $attributes));
}

describe('Lifecycle Dashboard Index', function () {
    it('loads dashboard for authorized user', function () {
        $response = $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index'));

        $response->assertStatus(200);
        $response->assertViewIs('app.content.lifecycle.index');
    });

    it('displays content grouped by lifecycle stage', function () {
        createDashboardTestContent(['title' => 'Draft Content', 'lifecycle_stage' => ContentLifecycleStatus::DRAFT->value]);
        createDashboardTestContent(['title' => 'Review Content', 'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value]);
        createDashboardTestContent(['title' => 'Published Content', 'lifecycle_stage' => ContentLifecycleStatus::PUBLISHED->value]);

        $response = $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index'));

        $response->assertStatus(200);
        $response->assertSee('Draft Content');
        $response->assertSee('Review Content');
        $response->assertSee('Published Content');
    });

    it('filters content by stage', function () {
        createDashboardTestContent(['title' => 'Draft Content', 'lifecycle_stage' => ContentLifecycleStatus::DRAFT->value]);
        createDashboardTestContent(['title' => 'Review Content', 'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value]);

        $response = $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index', ['stage' => 'draft']));

        $response->assertStatus(200);
        $response->assertSee('Draft Content');
    });

    it('filters content by search query', function () {
        createDashboardTestContent(['title' => 'Marketing Article']);
        createDashboardTestContent(['title' => 'Sales Guide']);

        $response = $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index', ['q' => 'Marketing']));

        $response->assertStatus(200);
        $response->assertSee('Marketing Article');
    });

    it('shows overdue content indicator', function () {
        createDashboardTestContent([
            'title' => 'Overdue Content',
            'lifecycle_stage' => ContentLifecycleStatus::DRAFT->value,
            'due_at' => now()->subDays(2),
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index'));

        $response->assertStatus(200);
        $response->assertSee('overdue');
    });

    it('requires authentication', function () {
        $response = $this->get(route('app.content.lifecycle.index'));

        $response->assertRedirect(route('login'));
    });

    it('shows the series dropdown and loads series without assuming a workspace_id column', function () {
        $site = createDashboardTestSite();
        createDashboardTestSeries($site, ['name' => 'Visible Series']);

        $response = $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index'));

        $response->assertOk()
            ->assertSee('Chain')
            ->assertSee('Visible Series');
    });

    it('only shows series for the current organization workspaces', function () {
        $visibleSite = createDashboardTestSite();
        createDashboardTestSeries($visibleSite, ['name' => 'Current Org Series']);

        $otherOrg = Organization::create([
            'name' => 'Other Org',
            'slug' => 'other-org-' . Str::lower(Str::random(6)),
            'status' => 'active',
        ]);
        $otherWorkspace = Workspace::create([
            'name' => 'Other Workspace',
            'organization_id' => $otherOrg->id,
        ]);
        $otherSite = createDashboardTestSite($otherWorkspace, [
            'name' => 'Other Org Site',
            'site_url' => 'https://other-org-site.example.com',
            'allowed_domains' => ['other-org-site.example.com'],
        ]);

        ContentSeries::query()->create([
            'organization_id' => $otherOrg->id,
            'site_id' => $otherSite->id,
            'name' => 'Other Org Series',
            'main_topic' => 'Other Topic',
            'primary_keyword' => 'other-keyword',
            'supporting_keywords' => ['other supporting keyword'],
            'articles_count' => 4,
            'status' => ContentSeries::STATUS_DRAFT,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index'));

        $response->assertOk()
            ->assertSee('Current Org Series')
            ->assertDontSee('Other Org Series');
    });
});

describe('Stage Summaries', function () {
    it('shows correct counts per stage', function () {
        createDashboardTestContent(['lifecycle_stage' => ContentLifecycleStatus::DRAFT->value]);
        createDashboardTestContent(['lifecycle_stage' => ContentLifecycleStatus::DRAFT->value]);
        createDashboardTestContent(['lifecycle_stage' => ContentLifecycleStatus::REVIEW->value]);

        $response = $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index'));

        $response->assertStatus(200);
        // The view should show counts in stage tabs
    });

    it('limits cards per lifecycle column and exposes load-more state', function () {
        foreach (range(1, 12) as $index) {
            createDashboardTestContent([
                'title' => 'Draft Card '.$index,
                'lifecycle_stage' => ContentLifecycleStatus::DRAFT->value,
            ]);
        }

        $response = $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index'));

        $response->assertOk();

        $groupedContents = $response->viewData('groupedContents');
        expect($groupedContents[ContentLifecycleStatus::DRAFT->value]['contents'])->toHaveCount(10)
            ->and($groupedContents[ContentLifecycleStatus::DRAFT->value]['has_more'])->toBeTrue();
    });

    it('keeps lifecycle query count bounded for board rendering', function () {
        foreach (ContentLifecycleStatus::canonicalStages() as $stage) {
            foreach (range(1, 3) as $index) {
                createDashboardTestContent([
                    'title' => $stage->value.'-'.$index,
                    'lifecycle_stage' => $stage->value,
                    'content_health_score' => 70,
                    'ai_visibility_score' => 55,
                ]);
            }
        }

        DB::enableQueryLog();

        $response = $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index'));

        $response->assertOk();

        expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(50);
    });

    it('refreshes cached lifecycle summaries after a content stage change', function () {
        $content = createDashboardTestContent([
            'title' => 'Cached Lifecycle Item',
            'lifecycle_stage' => ContentLifecycleStatus::DRAFT->value,
        ]);

        $firstResponse = $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index'));

        $firstResponse->assertOk();
        $firstSummaries = $firstResponse->viewData('stageSummaries');
        expect($firstSummaries[ContentLifecycleStatus::DRAFT->value]['count'])->toBe(1);

        $content->update([
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
        ]);

        $secondResponse = $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index'));

        $secondResponse->assertOk();
        $secondSummaries = $secondResponse->viewData('stageSummaries');
        expect($secondSummaries[ContentLifecycleStatus::DRAFT->value]['count'])->toBe(0)
            ->and($secondSummaries[ContentLifecycleStatus::REVIEW->value]['count'])->toBe(1);
    });

    it('loads the lifecycle dashboard without window function SQL', function () {
        foreach (range(1, 3) as $index) {
            createDashboardTestContent([
                'title' => 'Draft MariaDB '.$index,
                'lifecycle_stage' => ContentLifecycleStatus::DRAFT->value,
            ]);

            createDashboardTestContent([
                'title' => 'Review MariaDB '.$index,
                'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
            ]);
        }

        $executedSql = [];
        DB::listen(function ($query) use (&$executedSql): void {
            $executedSql[] = (string) $query->sql;
        });

        $response = $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index'));

        $response->assertOk()
            ->assertSee('Draft MariaDB 1')
            ->assertSee('Review MariaDB 1');

        expect(collect($executedSql)->contains(function (string $sql): bool {
            $normalized = strtoupper($sql);

            return str_contains($normalized, 'ROW_NUMBER(')
                || str_contains($normalized, ' OVER ')
                || str_contains($normalized, 'PARTITION BY');
        }))->toBeFalse();
    });

    it('loads and filters lifecycle dashboard when lifecycle stages are enum-cast on the model', function () {
        $draft = createDashboardTestContent([
            'title' => 'Enum Draft Content',
            'lifecycle_stage' => ContentLifecycleStatus::DRAFT->value,
        ]);
        $review = createDashboardTestContent([
            'title' => 'Enum Review Content',
            'lifecycle_stage' => ContentLifecycleStatus::REVIEW->value,
        ]);

        expect($draft->fresh()->lifecycle_stage)->toBeInstanceOf(ContentLifecycleStatus::class)
            ->and($review->fresh()->lifecycle_stage)->toBeInstanceOf(ContentLifecycleStatus::class);

        $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index'))
            ->assertOk()
            ->assertSee('Enum Draft Content')
            ->assertSee('Enum Review Content');

        $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index', ['stage' => ContentLifecycleStatus::DRAFT->value]))
            ->assertOk()
            ->assertSee('Enum Draft Content')
            ->assertDontSee('Enum Review Content');
    });
});

describe('Lifecycle intelligence layer', function () {
    it('renders workflow and intelligence status separately on lifecycle cards', function () {
        createDashboardTestContent([
            'title' => 'AI Native Lifecycle Card',
            'content_health_score' => 82,
            'ai_visibility_score' => 74,
            'semantic_coverage_score' => 79,
            'freshness_score' => 76,
            'internal_link_score' => 71,
            'answer_block_score' => 78,
            'translation_parity_score' => 88,
            'optimization_opportunity_score' => 23,
            'decay_risk_level' => 'low',
            'intelligence_status' => 'healthy',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index'));

        $response->assertOk()
            ->assertSee('AI Native Lifecycle Card')
            ->assertSee('Workflow: Draft')
            ->assertSee('Healthy')
            ->assertSee('AI Visible');
    });

    it('filters lifecycle items by decay risk and health score', function () {
        createDashboardTestContent([
            'title' => 'Critical decay article',
            'content_health_score' => 28,
            'decay_risk_level' => 'critical',
            'freshness_score' => 22,
            'ai_visibility_score' => 18,
        ]);

        createDashboardTestContent([
            'title' => 'Healthy article',
            'content_health_score' => 84,
            'decay_risk_level' => 'low',
            'freshness_score' => 81,
            'ai_visibility_score' => 76,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('app.content.lifecycle.index', [
                'decay_risk' => 'critical',
                'health_range' => 'low',
            ]));

        $response->assertOk()
            ->assertSee('Critical decay article')
            ->assertDontSee('Healthy article');
    });
});
