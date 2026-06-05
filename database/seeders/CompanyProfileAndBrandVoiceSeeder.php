<?php

namespace Database\Seeders;

use App\Models\BrandVoice;
use App\Models\CompanyIntelligenceProfile;
use App\Models\CompanyProfile;
use App\Models\Workspace;
use App\Services\CompanyIntelligence\CompanyIntelligenceNormalizer;
use Illuminate\Database\Seeder;

class CompanyProfileAndBrandVoiceSeeder extends Seeder
{
    public function run(): void
    {
        $workspace = Workspace::query()
            ->whereHas('organization', fn ($query) => $query->where('slug', 'demo-org'))
            ->orderBy('created_at')
            ->first();

        if (! $workspace) {
            return;
        }

        CompanyProfile::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'company_name' => 'Acme Corp',
                'industry' => 'Technology',
                'value_propositions' => "Enterprise grade security\n24 7 support",
                'proof_points' => "99.9 percent uptime\n500 plus enterprise clients",
                'compliance_rules' => 'All claims must be verifiable',
                'banned_claims' => 'Number one in the market',
            ],
        );

        $voice = BrandVoice::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'name' => 'Corporate Professional'],
            [
                'default_language' => 'en',
                'default_tone' => 'Professional',
                'style_guide' => 'Use clear, concise language. Avoid jargon.',
                'preferred_terminology' => "solution\npartnership\ninnovation",
                'disallowed_terminology' => "cheap\nbasic\nsimple",
                'is_default' => true,
            ],
        );

        BrandVoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', '!=', $voice->id)
            ->update(['is_default' => false]);

        $intelligencePayload = [
            'organization_id' => (int) $workspace->organization_id,
            'workspace_id' => (string) $workspace->id,
            'company_profile_id' => CompanyProfile::query()->where('workspace_id', $workspace->id)->value('id'),
            'brand_voice_id' => (string) $voice->id,
            'brand_key' => 'primary',
            'company_name' => 'Acme Corp',
            'company_description' => 'Acme Corp helps B2B teams plan, govern, optimize and publish AI-ready content across SEO and AEO workflows.',
            'market_category' => 'B2B content intelligence platform',
            'positioning' => 'The governed content intelligence layer for marketing teams that need strategy, search visibility and AI answer visibility in one workflow.',
            'uvp' => 'Turn stored content, SEO and AI visibility signals into prioritized marketing actions.',
            'products_services' => ['Content intelligence', 'AI visibility tracking', 'Agentic marketing planning', 'Content automation'],
            'pricing_model' => 'Subscription with credit-based AI usage',
            'regions' => ['United States', 'Netherlands', 'European Union'],
            'locales' => ['en', 'nl'],
            'icps' => ['B2B SaaS marketing teams', 'Agencies managing multiple content programs'],
            'personas' => ['Head of Marketing', 'SEO Lead', 'Content Operations Manager'],
            'buyer_roles' => ['Economic buyer', 'Editorial owner', 'Technical evaluator'],
            'pain_points' => ['Content decay', 'Weak internal linking', 'Low AI citation visibility', 'Manual content planning'],
            'objections' => ['AI quality control', 'Editorial review effort', 'Integration complexity'],
            'buying_triggers' => ['Organic traffic plateau', 'New market launch', 'AI search visibility concern'],
            'funnel_stages' => ['awareness', 'consideration', 'decision', 'retention'],
            'tone_of_voice' => 'Strategic, concrete, calm and practical.',
            'banned_phrases' => ['set and forget', 'magic AI'],
            'messaging_rules' => ['Lead with measurable content outcomes.', 'Explain governance before autonomy.'],
            'brand_differentiators' => ['Combines SEO, AEO, lifecycle, analytics and AI visibility signals.', 'Never auto-publishes without governance.'],
            'proof_points' => ['Laravel-native architecture', 'WordPress and Laravel publishing workflows', 'Credit governance and audit logs'],
            'primary_topics' => ['agentic marketing', 'content opportunity engine', 'AI visibility', 'content lifecycle'],
            'authority_areas' => ['SEO operations', 'AEO', 'internal linking', 'content refresh'],
            'target_entities' => ['PublishLayer', 'Agentic Marketing', 'Content Opportunity Engine'],
            'strategic_keywords' => ['AI content planning', 'content opportunity engine', 'AI visibility tracking'],
            'query_intents' => ['best AI content planning platform', 'how to improve AI search visibility', 'content refresh automation'],
            'direct_competitors' => ['MarketMuse', 'Clearscope', 'Frase'],
            'indirect_competitors' => ['Notion', 'Airtable', 'Asana'],
            'aspirational_competitors' => ['HubSpot', 'Semrush'],
            'source_type' => 'demo_seed',
            'is_default' => true,
            'status' => CompanyIntelligenceProfile::STATUS_ACTIVE,
        ];

        CompanyIntelligenceProfile::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'brand_key' => 'primary'],
            app(CompanyIntelligenceNormalizer::class)->persistencePayload($intelligencePayload),
        );
    }
}
