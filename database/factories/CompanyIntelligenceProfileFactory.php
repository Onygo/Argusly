<?php

namespace Database\Factories;

use App\Models\CompanyIntelligenceProfile;
use App\Models\Organization;
use App\Models\Workspace;
use App\Services\CompanyIntelligence\CompanyIntelligenceNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CompanyIntelligenceProfile>
 */
class CompanyIntelligenceProfileFactory extends Factory
{
    protected $model = CompanyIntelligenceProfile::class;

    public function definition(): array
    {
        $organization = Organization::query()->inRandomOrder()->first() ?: Organization::query()->create([
            'name' => 'Demo Org ' . Str::random(5),
            'slug' => 'demo-org-' . Str::lower(Str::random(6)),
            'status' => Organization::STATUS_ACTIVE,
            'approved_at' => now(),
        ]);

        $workspace = Workspace::query()->where('organization_id', $organization->id)->inRandomOrder()->first()
            ?: Workspace::query()->create([
                'name' => 'Demo Workspace',
                'organization_id' => $organization->id,
            ]);

        $payload = [
            'organization_id' => $organization->id,
            'workspace_id' => (string) $workspace->id,
            'brand_key' => 'primary-' . Str::lower(Str::random(5)),
            'company_name' => $this->faker->company(),
            'company_description' => $this->faker->paragraph(),
            'market_category' => 'B2B SaaS',
            'positioning' => 'AI-native content operations for growing marketing teams.',
            'uvp' => 'Turn content intelligence into planned, governed publishing actions.',
            'products_services' => ['Content intelligence', 'Agentic marketing planning', 'AI visibility tracking'],
            'pricing_model' => 'Subscription',
            'regions' => ['United States', 'Netherlands'],
            'locales' => ['en', 'nl'],
            'icps' => ['B2B SaaS marketing teams'],
            'personas' => ['Head of Marketing', 'Content Lead'],
            'buyer_roles' => ['Economic buyer', 'Technical evaluator'],
            'pain_points' => ['Content decay', 'Weak AI visibility'],
            'objections' => ['Editorial control', 'AI quality'],
            'buying_triggers' => ['Organic growth slowdown', 'New market launch'],
            'funnel_stages' => ['awareness', 'consideration', 'decision'],
            'tone_of_voice' => 'Clear, strategic, practical.',
            'banned_phrases' => ['set and forget'],
            'messaging_rules' => ['Be specific about governance and review.'],
            'brand_differentiators' => ['Combines SEO, AEO and lifecycle intelligence.'],
            'proof_points' => ['Built for Laravel and WordPress publishing workflows.'],
            'primary_topics' => ['agentic marketing', 'AI visibility', 'content operations'],
            'authority_areas' => ['SEO operations', 'AEO', 'content lifecycle'],
            'target_entities' => ['PublishLayer', 'Agentic Marketing'],
            'strategic_keywords' => ['AI content planning', 'content opportunity engine'],
            'query_intents' => ['best AI content planning platform', 'how to improve AI visibility'],
            'direct_competitors' => ['MarketMuse', 'Clearscope'],
            'indirect_competitors' => ['Notion', 'Airtable'],
            'aspirational_competitors' => ['HubSpot'],
            'source_type' => 'factory',
            'is_default' => false,
            'status' => CompanyIntelligenceProfile::STATUS_ACTIVE,
        ];

        return app(CompanyIntelligenceNormalizer::class)->persistencePayload($payload);
    }

    public function default(): self
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
