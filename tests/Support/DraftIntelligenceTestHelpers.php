<?php

use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

if (! function_exists('makeDraftIntelligenceContext')) {
    function makeDraftIntelligenceContext(string $prefix = 'draft-intelligence'): array
    {
        $organization = Organization::query()->create([
            'name' => 'Draft Intelligence Org',
            'slug' => $prefix . '-' . Str::random(6),
            'status' => 'active',
            'approved_at' => now(),
            'billing_company_name' => 'Draft Intelligence BV',
            'billing_address_line1' => 'Teststraat 1',
            'billing_country_code' => 'NL',
        ]);

        $workspace = Workspace::query()->create([
            'name' => 'Draft Intelligence Workspace',
            'organization_id' => $organization->id,
        ]);

        $site = ClientSite::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'wordpress',
            'name' => 'Draft Intelligence Site',
            'site_url' => 'https://draft-intelligence.example.com',
            'allowed_domains' => ['draft-intelligence.example.com'],
            'is_active' => true,
            'status' => 'connected',
        ]);

        $plan = Plan::query()->firstOrCreate(
            ['key' => 'draft-intelligence-plan'],
            [
                'name' => 'Draft Intelligence Plan',
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

        $user = User::query()->create([
            'name' => 'Draft Intelligence User',
            'email' => $prefix . '+' . Str::random(6) . '@example.com',
            'password' => bcrypt('secret'),
            'organization_id' => $organization->id,
            'role' => 'owner',
            'active' => true,
            'approved_at' => now(),
        ]);

        $brief = Brief::query()->create([
            'client_site_id' => $site->id,
            'created_by_user_id' => $user->id,
            'status' => 'draft',
            'source' => 'client_ui',
            'title' => 'Draft intelligence brief',
            'language' => 'en',
            'content_type' => 'blog',
            'output_type' => 'kb_article',
            'primary_keyword' => 'draft intelligence',
            'call_to_action' => 'Book a demo',
            'progress' => 0,
        ]);

        $draft = Draft::query()->create([
            'id' => (string) Str::uuid(),
            'brief_id' => $brief->id,
            'client_site_id' => $site->id,
            'status' => 'generated',
            'title' => 'Draft intelligence article',
            'output_type' => 'kb_article',
            'seo_title' => 'Draft intelligence article',
            'seo_meta_description' => 'Draft intelligence summary.',
            'seo_h1' => 'Draft intelligence article',
            'content_html' => '<h1>Draft intelligence</h1><p>This article explains SEO, readability, and CTA improvements.</p>',
        ]);

        return [$user, $draft];
    }
}
