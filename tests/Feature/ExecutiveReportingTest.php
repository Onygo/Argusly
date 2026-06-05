<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Brand;
use App\Models\ContentAsset;
use App\Models\Ga4MetricSnapshot;
use App\Models\Ga4Property;
use App\Models\Integration;
use App\Models\IntegrationConnection;
use App\Models\Recommendation;
use App\Models\Report;
use App\Models\Role;
use App\Models\SearchConsoleQuerySnapshot;
use App\Models\SearchConsoleSite;
use App\Models\SocialPost;
use App\Models\SocialProfile;
use App\Models\User;
use App\Models\VisibilitySnapshot;
use App\Services\ExecutiveReportService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExecutiveReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_service_generates_static_sections_and_snapshot(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        $this->seedMetrics($account, $brand, $user);

        $report = app(ExecutiveReportService::class)->generate($account, $brand, $user, 'executive');

        $this->assertSame('executive', $report->type);
        $this->assertSame(12, $report->sections()->count());
        $this->assertDatabaseHas('report_sections', [
            'report_id' => $report->id,
            'section_type' => 'executive_summary',
            'title' => 'Executive summary',
        ]);
        $this->assertDatabaseHas('report_sections', [
            'report_id' => $report->id,
            'section_type' => 'kpi_tracking',
            'title' => 'KPI tracking',
        ]);
        $this->assertDatabaseHas('report_sections', [
            'report_id' => $report->id,
            'section_type' => 'ai_visibility',
            'title' => 'AI visibility',
        ]);
        $this->assertDatabaseHas('report_sections', [
            'report_id' => $report->id,
            'section_type' => 'next_actions',
        ]);
        $this->assertDatabaseHas('report_sections', [
            'report_id' => $report->id,
            'section_type' => 'board_summary',
        ]);
        $this->assertDatabaseHas('report_sections', [
            'report_id' => $report->id,
            'section_type' => 'trend_report',
        ]);

        $snapshot = $report->snapshots()->firstOrFail();
        $this->assertStringContainsString('Executive report', $snapshot->html);
        $this->assertCount(12, $snapshot->payload['sections']);
    }

    public function test_reports_index_detail_and_generate_button_are_tenant_safe(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner');
        [$otherUser, $otherAccount, $otherBrand] = $this->tenantWithRole('owner', 'other-report');
        $this->seedMetrics($account, $brand, $user);
        $visibleReport = app(ExecutiveReportService::class)->generate($account, $brand, $user, 'weekly');
        $hiddenReport = app(ExecutiveReportService::class)->generate($otherAccount, $otherBrand, $otherUser, 'weekly');

        $this->actingAs($user)
            ->get(route('app.reports'))
            ->assertOk()
            ->assertSee('Reports')
            ->assertSee('Generate report')
            ->assertSee($visibleReport->title)
            ->assertDontSee($hiddenReport->title);

        $this->actingAs($user)
            ->get(route('app.reports.show', $visibleReport))
            ->assertOk()
            ->assertSee('Snapshot export')
            ->assertSee('KPI tracking')
            ->assertSee('AI visibility')
            ->assertSee('Content performance')
            ->assertSee('Search performance')
            ->assertSee('Social distribution')
            ->assertSee('Board summary')
            ->assertSee('Trend report');

        $this->actingAs($user)
            ->get(route('app.reports.show', $hiddenReport))
            ->assertNotFound();

        $this->actingAs($user)
            ->post(route('app.reports.store'), ['type' => 'monthly'])
            ->assertRedirect();

        $this->assertDatabaseHas('reports', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'type' => 'monthly',
        ]);
    }

    public function test_executive_dashboard_pdf_powerpoint_and_scheduled_reports_are_operational(): void
    {
        [$user, $account, $brand] = $this->tenantWithRole('owner', 'executive-dashboard');
        $this->seedMetrics($account, $brand, $user);
        $report = app(ExecutiveReportService::class)->generate($account, $brand, $user, 'executive');

        $this->actingAs($user)
            ->get(route('app.reporting.executive'))
            ->assertOk()
            ->assertSee('Executive dashboard')
            ->assertSee('KPI tracking')
            ->assertSee('Board summary')
            ->assertSee('Trend report')
            ->assertSee('PDF')
            ->assertSee('PPTX');

        $pdf = $this->actingAs($user)
            ->get(route('app.reports.export.pdf', $report))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF', $pdf->baseResponse->getContent());

        $pptx = $this->actingAs($user)
            ->get(route('app.reports.export.powerpoint', $report))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.presentationml.presentation');

        $this->assertStringStartsWith('PK', $pptx->baseResponse->getContent());

        $this->artisan('reports:generate-scheduled', ['--type' => 'weekly', '--limit' => 5, '--sync' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('reports', [
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'type' => 'weekly',
            'generated_by' => null,
        ]);
    }

    private function seedMetrics(Account $account, Brand $brand, User $user): void
    {
        $asset = ContentAsset::factory()->forBrand($brand)->published()->create(['title' => 'Reporting asset']);
        $property = Ga4Property::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'display_name' => 'Reporting GA4',
            'status' => 'connected',
        ]);
        Ga4MetricSnapshot::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'ga4_property_id' => $property->id,
            'content_asset_id' => $asset->id,
            'date' => now()->toDateString(),
            'sessions' => 150,
            'users' => 100,
            'pageviews' => 300,
            'conversions' => 5,
        ]);
        $site = SearchConsoleSite::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'site_url' => 'https://reporting.example/',
            'status' => 'connected',
        ]);
        SearchConsoleQuerySnapshot::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'search_console_site_id' => $site->id,
            'content_asset_id' => $asset->id,
            'date' => now()->toDateString(),
            'query' => 'executive reporting',
            'clicks' => 24,
            'impressions' => 480,
            'ctr' => 0.05,
            'position' => 4.2,
        ]);
        VisibilitySnapshot::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'provider' => 'ChatGPT',
            'score' => 72,
            'mention_found' => true,
            'results_count' => 1,
            'captured_at' => now(),
        ]);
        $integration = Integration::query()->firstOrCreate(
            ['key' => 'linkedin'],
            ['name' => 'LinkedIn', 'auth_type' => 'oauth2'],
        );
        $connection = IntegrationConnection::query()->create([
            'integration_id' => $integration->id,
            'owner_user_id' => $user->id,
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'name' => 'Reporting LinkedIn',
            'status' => 'active',
        ]);
        $profile = SocialProfile::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'integration_connection_id' => $connection->id,
            'owner_user_id' => $user->id,
            'provider' => 'linkedin',
            'provider_profile_id' => 'report-profile',
            'display_name' => 'Report Profile',
            'type' => 'page',
            'status' => 'connected',
        ]);
        SocialPost::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'content_asset_id' => $asset->id,
            'social_profile_id' => $profile->id,
            'provider' => 'linkedin',
            'status' => 'published',
            'post_text' => 'Reporting update.',
            'language' => 'en',
            'published_at' => now(),
        ]);
        Recommendation::query()->create([
            'account_id' => $account->id,
            'brand_id' => $brand->id,
            'title' => 'Refresh reporting page',
            'summary' => 'Refresh the highest-impact reporting content.',
            'recommended_action' => 'Refresh the page and rerun visibility checks.',
            'impact_score' => 80,
            'confidence_score' => 90,
            'status' => 'new',
        ]);
    }

    private function tenantWithRole(string $roleName, string $slug = 'reporting'): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(SubscriptionCatalogSeeder::class);

        $user = User::factory()->create();
        $account = Account::query()->create(['name' => str($slug)->headline()->toString(), 'slug' => fake()->unique()->slug()]);
        $brand = Brand::query()->create([
            'account_id' => $account->id,
            'name' => str($slug)->headline().' Brand',
            'slug' => fake()->unique()->slug(),
            'enabled_content_languages' => ['en'],
            'default_content_language' => 'en',
        ]);
        $role = Role::query()->where('name', $roleName)->firstOrFail();

        $user->accounts()->attach($account, ['status' => 'active']);
        $user->brands()->attach($brand, ['account_id' => $account->id, 'status' => 'active']);
        $user->roles()->attach($role, ['account_id' => $account->id]);
        app(SubscriptionService::class)->activatePlan($account, 'scale_monthly');

        return [$user, $account, $brand];
    }
}
