<?php

use App\Http\Middleware\EnsureBillingOnboardingCompleted;
use App\Models\ClientSite;
use App\Models\CompanyIntelligenceProfile;
use App\Models\CompanyProfile;
use App\Models\CreditLedgerEntry;
use App\Models\CreditReservation;
use App\Models\LlmTrackingQuery;
use App\Models\Organization;
use App\Models\SiteCompetitor;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiVisibility\AiVisibilityStarterQueryService;
use App\Services\Onboarding\FirstValueActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutMiddleware(EnsureBillingOnboardingCompleted::class);
});

function aiVisibilityStarterContext(string $slug = 'starter'): array
{
    $organization = Organization::query()->create([
        'name' => 'Starter Org '.$slug,
        'slug' => 'starter-org-'.$slug,
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'organization_id' => $organization->id,
        'name' => 'Starter Workspace '.$slug,
        'display_name' => 'Acme Visibility',
    ]);

    $site = ClientSite::query()->create([
        'workspace_id' => $workspace->id,
        'type' => ClientSite::TYPE_WORDPRESS,
        'name' => 'Acme Site '.$slug,
        'site_url' => 'https://'.$slug.'.example.com',
        'base_url' => 'https://'.$slug.'.example.com',
        'allowed_domains' => [$slug.'.example.com'],
        'is_active' => true,
    ]);

    $user = User::factory()->create([
        'organization_id' => $organization->id,
        'role' => 'owner',
        'active' => true,
        'approved_at' => now(),
        'email_code_verified_at' => now(),
    ]);

    $companyProfile = CompanyProfile::query()->create([
        'workspace_id' => $workspace->id,
        'company_name' => 'Acme Visibility',
        'industry' => 'B2B SaaS',
        'key_services' => "AI visibility monitoring\nContent intelligence",
        'target_audience' => 'B2B marketing teams',
    ]);

    $companyIntelligence = CompanyIntelligenceProfile::query()->create([
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'company_profile_id' => $companyProfile->id,
        'brand_key' => 'primary',
        'company_name' => 'Acme Visibility',
        'market_category' => 'AI visibility platform',
        'products_services' => ['AI visibility monitoring', 'Signal intelligence'],
        'primary_topics' => ['AI visibility'],
        'authority_areas' => ['content intelligence'],
        'strategic_keywords' => ['AI search visibility'],
        'target_entities' => ['Acme Visibility'],
        'direct_competitors' => ['BrightSEO'],
        'status' => CompanyIntelligenceProfile::STATUS_ACTIVE,
        'is_default' => true,
    ]);

    $competitor = SiteCompetitor::query()->create([
        'workspace_id' => $workspace->id,
        'client_site_id' => $site->id,
        'name' => 'BrightSEO',
        'domain' => 'brightseo.example',
        'is_active' => true,
    ]);

    return compact('organization', 'workspace', 'site', 'user', 'companyProfile', 'companyIntelligence', 'competitor');
}

it('generates deterministic starter queries across categories', function (): void {
    $context = aiVisibilityStarterContext('service');

    $suggestions = app(AiVisibilityStarterQueryService::class)->suggest(
        $context['workspace'],
        $context['site'],
        $context['companyProfile'],
        $context['companyIntelligence'],
        collect([$context['competitor']]),
    );

    $categories = collect($suggestions->all())->pluck('category')->all();
    $texts = collect($suggestions->all())->pluck('queryText')->all();

    expect($suggestions->count())->toBeLessThanOrEqual(10)
        ->and($categories)->toContain('brand_visibility', 'competitor_comparison', 'buyer_intent', 'authority', 'category_leadership')
        ->and(collect($texts)->filter(fn (string $text): bool => str_contains($text, 'Acme Visibility'))->count())->toBeGreaterThan(0)
        ->and(collect($texts)->filter(fn (string $text): bool => str_contains($text, 'BrightSEO'))->count())->toBeGreaterThan(0);
});

it('does not suggest duplicate starter queries already stored for the site', function (): void {
    $context = aiVisibilityStarterContext('dedupe');

    LlmTrackingQuery::query()->create([
        'workspace_id' => $context['workspace']->id,
        'client_site_id' => $context['site']->id,
        'name' => 'Existing',
        'query_text' => 'what is Acme Visibility',
        'locale' => 'en',
        'frequency' => 'daily',
        'is_active' => true,
    ]);

    $suggestions = app(AiVisibilityStarterQueryService::class)->suggest(
        $context['workspace'],
        $context['site'],
        $context['companyProfile'],
        $context['companyIntelligence'],
        collect([$context['competitor']]),
    );

    expect(collect($suggestions->all())->pluck('queryText')->all())->not->toContain('what is Acme Visibility');
});

it('shows guided empty state and starter preview for sites without queries', function (): void {
    $context = aiVisibilityStarterContext('preview');

    $this->actingAs($context['user'])
        ->get(route('app.sites.llm-tracking.index', $context['site']))
        ->assertOk()
        ->assertSee('AI Visibility Setup')
        ->assertSee('Generate Starter Queries')
        ->assertSee('Create Query Manually');

    $this->actingAs($context['user'])
        ->get(route('app.sites.llm-tracking.starter.preview', $context['site']))
        ->assertOk()
        ->assertSee('AI Visibility Starter Queries')
        ->assertSee('Competitor Comparison')
        ->assertSee('BrightSEO')
        ->assertSee('Creating them does not start runs and does not use credits');
});

it('creates selected starter queries without dispatching runs or consuming credits', function (): void {
    Bus::fake();
    $context = aiVisibilityStarterContext('create');

    $beforeScore = app(FirstValueActivationService::class)->forWorkspace($context['workspace'])['score'];
    $beforeLedger = CreditLedgerEntry::query()->count();
    $beforeReservations = CreditReservation::query()->count();

    $this->actingAs($context['user'])
        ->post(route('app.sites.llm-tracking.starter.store', $context['site']), [
            'selected' => ['brand-1', 'competitor-1', 'buyer-1'],
        ])
        ->assertRedirect(route('app.sites.llm-tracking.index', $context['site']));

    $afterScore = app(FirstValueActivationService::class)->forWorkspace($context['workspace'])['score'];

    expect(LlmTrackingQuery::query()->where('client_site_id', $context['site']->id)->count())->toBe(3)
        ->and($afterScore)->toBeGreaterThan($beforeScore)
        ->and(CreditLedgerEntry::query()->count())->toBe($beforeLedger)
        ->and(CreditReservation::query()->count())->toBe($beforeReservations);

    Bus::assertNothingDispatched();
});

it('shows first run CTA after starter queries are created', function (): void {
    $context = aiVisibilityStarterContext('first-run');

    $this->actingAs($context['user'])
        ->post(route('app.sites.llm-tracking.starter.store', $context['site']), [
            'selected' => ['brand-1'],
        ]);

    $this->actingAs($context['user'])
        ->get(route('app.sites.llm-tracking.index', $context['site']))
        ->assertOk()
        ->assertSee('Your AI Visibility workspace is ready')
        ->assertSee('Run First Visibility Check')
        ->assertSee('Estimated credits')
        ->assertSee('1-3 min');
});

it('keeps starter query access isolated by organization and workspace', function (): void {
    $own = aiVisibilityStarterContext('own');
    $other = aiVisibilityStarterContext('other');

    LlmTrackingQuery::query()->create([
        'workspace_id' => $other['workspace']->id,
        'client_site_id' => $other['site']->id,
        'name' => 'Other existing',
        'query_text' => 'what is Acme Visibility',
        'locale' => 'en',
        'frequency' => 'daily',
        'is_active' => true,
    ]);

    $this->actingAs($own['user'])
        ->get(route('app.sites.llm-tracking.starter.preview', $other['site']))
        ->assertNotFound();

    $suggestions = app(AiVisibilityStarterQueryService::class)->suggest(
        $own['workspace'],
        $own['site'],
        $own['companyProfile'],
        $own['companyIntelligence'],
        collect([$own['competitor']]),
    );

    expect(collect($suggestions->all())->pluck('queryText')->all())->toContain('what is Acme Visibility');
});
