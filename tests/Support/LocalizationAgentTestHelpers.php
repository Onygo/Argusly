<?php

use App\Enums\DraftType;
use App\Models\Brief;
use App\Models\ClientSite;
use App\Models\Content;
use App\Models\ContentVersion;
use App\Models\Draft;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

if (! function_exists('makeLocalizationAgentContext')) {
    function makeLocalizationAgentContext(string $prefix = 'localization-agent', bool $withSubscription = false): array
    {
        $organization = Organization::query()->create([
            'name' => 'Localization Agent Org',
            'slug' => $prefix . '-' . Str::lower(Str::random(6)),
            'status' => 'active',
            'approved_at' => now(),
            'billing_company_name' => 'Localization Agent BV',
            'billing_address_line1' => 'Teststraat 1',
            'billing_country_code' => 'NL',
        ]);

        $workspace = Workspace::query()->create([
            'name' => 'Localization Workspace',
            'organization_id' => $organization->id,
            'enabled_content_languages' => ['en', 'nl', 'de'],
        ]);

        $site = ClientSite::query()->create([
            'workspace_id' => $workspace->id,
            'type' => 'wordpress',
            'name' => 'Localization Site',
            'site_url' => 'https://' . $prefix . '.example.com',
            'base_url' => 'https://' . $prefix . '.example.com',
            'allowed_domains' => [$prefix . '.example.com'],
            'is_active' => true,
            'status' => 'connected',
        ]);

        $owner = User::query()->create([
            'name' => 'Localization Owner',
            'email' => $prefix . '+owner@example.com',
            'password' => bcrypt('secret'),
            'organization_id' => $organization->id,
            'role' => 'owner',
            'active' => true,
            'approved_at' => now(),
        ]);

        if ($withSubscription) {
            $plan = Plan::query()->firstOrCreate(
                ['key' => $prefix . '-plan'],
                [
                    'name' => 'Localization Plan',
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
        }

        return [$owner, $workspace, $site];
    }
}

if (! function_exists('makeLocalizedContent')) {
    function makeLocalizedContent(Workspace $workspace, ClientSite $site, User $owner, string $title, string $locale, array $overrides = []): Content
    {
        return Content::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'workspace_id' => (string) $workspace->id,
            'client_site_id' => (string) $site->id,
            'title' => $title,
            'language' => $locale,
            'type' => 'article',
            'status' => 'published',
            'publish_status' => 'published',
            'source' => 'manual',
            'primary_keyword' => Str::slug($title),
            'seo_title' => $title,
            'seo_meta_description' => $title . ' description',
            'seo_h1' => $title,
            'publish_url_key' => Str::slug($title),
            'external_key' => Str::slug($title) . '-' . $locale,
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
            'is_source_locale' => ! array_key_exists('translation_source_content_id', $overrides),
        ], $overrides));
    }
}

if (! function_exists('makeLocalizedDraft')) {
    function makeLocalizedDraft(Content $content, ClientSite $site, string $title, string $locale, string $html, array $overrides = []): Draft
    {
        $brief = Brief::query()->create([
            'id' => (string) Str::uuid(),
            'client_site_id' => $site->id,
            'content_id' => $content->id,
            'status' => 'draft',
            'source' => 'client_ui',
            'title' => $title,
            'language' => $locale,
            'content_type' => 'blog',
            'output_type' => 'kb_article',
            'progress' => 0,
        ]);

        return Draft::query()->create(array_merge([
            'id' => (string) Str::uuid(),
            'brief_id' => $brief->id,
            'content_id' => $content->id,
            'client_site_id' => $site->id,
            'status' => 'ready',
            'title' => $title,
            'output_type' => 'kb_article',
            'draft_type' => DraftType::ORIGINAL->value,
            'language' => $locale,
            'content_html' => $html,
            'seo_title' => $title,
            'seo_meta_description' => $title . ' description',
            'seo_h1' => $title,
        ], $overrides));
    }
}

if (! function_exists('attachLocalizedVersion')) {
    function attachLocalizedVersion(Content $content, string $body, \Carbon\CarbonInterface $updatedAt): ContentVersion
    {
        $version = ContentVersion::query()->create([
            'id' => (string) Str::uuid(),
            'content_id' => $content->id,
            'type' => ContentVersion::TYPE_REVISION,
            'body' => $body,
            'source' => ContentVersion::SOURCE_ARGUSLY,
        ]);

        ContentVersion::query()->whereKey($version->id)->update([
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);

        Content::query()->whereKey($content->id)->update([
            'current_version_id' => $version->id,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);

        return $version->fresh();
    }
}
