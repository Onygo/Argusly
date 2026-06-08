<?php

use App\Http\Resources\App\CompanyIntelligenceProfileResource;
use App\Models\CompanyIntelligenceProfile;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CompanyIntelligence\CompanyIntelligenceNormalizer;
use App\Services\CompanyIntelligence\CompanyIntelligenceContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('normalizes company intelligence into an ai readable payload with completeness scoring', function () {
    $normalized = app(CompanyIntelligenceNormalizer::class)->normalize([
        'brand_key' => 'primary',
        'company_name' => 'Argusly',
        'company_description' => 'AI content operations platform.',
        'market_category' => 'Content intelligence',
        'positioning' => 'Governed agentic marketing for B2B teams.',
        'uvp' => 'Plan content from stored signals.',
        'products_services' => "AI visibility\nContent automation",
        'regions' => "United States\nNetherlands",
        'locales' => "en\nnl",
        'icps' => 'B2B SaaS marketing teams',
        'personas' => "Head of Marketing\nSEO Lead",
        'buyer_roles' => 'Economic buyer',
        'pain_points' => 'Content decay',
        'objections' => 'AI quality',
        'buying_triggers' => 'Organic growth plateau',
        'funnel_stages' => "awareness\nconsideration",
        'tone_of_voice' => 'Clear and practical.',
        'banned_phrases' => 'magic AI',
        'messaging_rules' => 'Explain governance.',
        'brand_differentiators' => 'Laravel-native workflows',
        'proof_points' => 'Audit logs and credit governance',
        'primary_topics' => 'agentic marketing',
        'authority_areas' => 'AEO',
        'target_entities' => 'Argusly',
        'strategic_keywords' => 'content opportunity engine',
        'query_intents' => 'best AI content planning platform',
        'direct_competitors' => 'MarketMuse',
        'indirect_competitors' => 'Notion',
        'aspirational_competitors' => 'HubSpot',
    ]);

    expect($normalized->payload['business']['company_name'])->toBe('Argusly')
        ->and($normalized->payload['business']['products_services'])->toBe(['AI visibility', 'Content automation'])
        ->and($normalized->payload['seo_aeo']['strategic_keywords'])->toBe(['content opportunity engine'])
        ->and($normalized->completenessScore)->toBeGreaterThan(90)
        ->and($normalized->payloadHash)->toHaveLength(64)
        ->and($normalized->embeddingText)->toContain('business company_name: Argusly');
});

it('creates multi-brand company intelligence profiles from the app ui', function () {
    [$organization, $workspace, $owner] = companyIntelligenceTenant();

    $this->withoutMiddleware();

    $this->actingAs($owner)
        ->post(route('app.brand.company-intelligence.store'), [
            'brand_key' => 'primary',
            'company_name' => 'Argusly',
            'company_description' => 'AI content operations platform.',
            'market_category' => 'Content intelligence',
            'products_services' => "AI visibility\nContent automation",
            'regions' => "United States\nNetherlands",
            'locales' => "en\nnl",
            'icps' => 'B2B SaaS marketing teams',
            'personas' => 'Head of Marketing',
            'buyer_roles' => 'Economic buyer',
            'pain_points' => 'Content decay',
            'tone_of_voice' => 'Clear and practical.',
            'primary_topics' => 'agentic marketing',
            'strategic_keywords' => 'content opportunity engine',
            'direct_competitors' => 'MarketMuse',
            'status' => 'active',
            'is_default' => '1',
        ])
        ->assertRedirect();

    $profile = CompanyIntelligenceProfile::query()->firstOrFail();

    expect($profile->organization_id)->toBe($organization->id)
        ->and((string) $profile->workspace_id)->toBe((string) $workspace->id)
        ->and($profile->is_default)->toBeTrue()
        ->and($profile->normalized_payload['business']['locales'])->toBe(['en', 'nl'])
        ->and($profile->completeness_score)->toBeGreaterThan(30);
});

it('exposes an ai ready resource for agentic marketing consumers', function () {
    [, $workspace] = companyIntelligenceTenant();

    $profile = CompanyIntelligenceProfile::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'brand_key' => 'resource_test',
        'company_name' => 'Resource Co',
    ]);

    $resource = (new CompanyIntelligenceProfileResource($profile))->toArray(request());

    expect($resource['ai_payload']['business']['company_name'])->toBe('Resource Co')
        ->and($resource['embedding_text'])->toContain('business company_name: Resource Co')
        ->and($resource['normalized_payload_hash'])->toHaveLength(64);
});

it('resolves a prompt-ready company intelligence context for a workspace', function () {
    [, $workspace] = companyIntelligenceTenant();

    CompanyIntelligenceProfile::factory()->create([
        'organization_id' => $workspace->organization_id,
        'workspace_id' => $workspace->id,
        'brand_key' => 'primary_context',
        'company_name' => 'Context Co',
        'is_default' => true,
    ]);

    $context = app(CompanyIntelligenceContextService::class)->promptContext($workspace);

    expect($context['available'])->toBeTrue()
        ->and($context['company_intelligence']['business']['company_name'])->toBe('Context Co')
        ->and($context['payload_hash'])->toHaveLength(64);
});

function companyIntelligenceTenant(): array
{
    $organization = Organization::query()->create([
        'name' => 'Company Intelligence Org',
        'slug' => 'company-intel-' . Str::lower(Str::random(6)),
        'status' => Organization::STATUS_ACTIVE,
        'approved_at' => now(),
    ]);

    $workspace = Workspace::query()->create([
        'name' => 'Company Intelligence Workspace',
        'organization_id' => $organization->id,
    ]);

    $plan = Plan::query()->firstOrCreate(
        ['key' => 'company-intelligence-test'],
        [
            'name' => 'Company Intelligence Test',
            'is_active' => true,
            'price_cents' => 0,
            'currency' => 'EUR',
            'interval' => 'month',
            'included_credits_per_interval' => 100,
        ],
    );

    Subscription::query()->create([
        'id' => (string) Str::uuid(),
        'organization_id' => $organization->id,
        'workspace_id' => $workspace->id,
        'plan_id' => $plan->id,
        'status' => 'active',
        'interval' => 'month',
        'price_cents' => 0,
        'currency' => 'EUR',
        'included_credits_per_interval' => 100,
        'current_period_start' => now()->startOfMonth(),
        'current_period_end' => now()->endOfMonth(),
    ]);

    $owner = User::query()->create([
        'name' => 'Owner',
        'email' => 'owner+' . Str::lower(Str::random(6)) . '@example.com',
        'password' => bcrypt('password'),
        'organization_id' => $organization->id,
        'role' => 'owner',
        'approved_at' => now(),
        'active' => true,
    ]);

    return [$organization, $workspace, $owner];
}
